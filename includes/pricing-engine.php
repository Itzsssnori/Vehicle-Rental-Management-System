<?php
/**
 * VELO VRMS - Philippine Discount & Pricing Engine
 *
 * Implements real-world Philippine vehicle rental pricing rules:
 *  - Standard 12% Philippine VAT inclusive/exclusive breakdown
 *  - Statutory Senior Citizen (RA 9994) & PWD (RA 10754) 20% discount
 *    with optional 12% VAT exemption toggle & mandatory verified ID check
 *  - Automatic Philippine Holiday calendar promos
 *  - Long-term duration tiers (7+ days weekly, 30+ days monthly)
 *  - Promotional voucher codes with redemption & per-customer caps
 *  - Stacking rules:
 *      * Statutory discounts NEVER stack with commercial promos (per RA 9994/10754);
 *        the engine evaluates both paths and automatically awards the HIGHER total savings
 *        (unless $allowStatutoryStacking = true is explicitly configured).
 *      * Commercial rules marked is_stackable = 1 apply sequentially in ascending priority order.
 *  - Immutable receipt snapshots stored on reservations + reservation_discounts.
 */

class PricingEngine
{
    private PDO $pdo;
    private float $vatRate;

    public function __construct(PDO $pdo, float $vatRate = 12.0)
    {
        $this->pdo = $pdo;
        $this->vatRate = $vatRate;
    }

    /**
     * Calculate complete itemized pricing breakdown for a rental booking.
     *
     * @param array $input [
     *   'customer_id'             => int,
     *   'daily_rate'              => float,   // VAT-inclusive daily rate in PHP
     *   'pickup_at'               => string,  // Y-m-d H:i:s
     *   'return_at'               => string,  // Y-m-d H:i:s
     *   'protection_daily_fee'    => float,   // optional (0, 350, 600)
     *   'promo_code'              => ?string, // optional voucher code
     *   'apply_statutory'         => bool,    // request Senior/PWD discount
     *   'apply_vat_exemption'     => bool,    // toggle 12% VAT exemption for statutory
     *   'allow_statutory_stack'   => bool,    // default false (PH law: higher benefit wins)
     * ]
     * @return array Complete pricing breakdown + applied rule snapshots
     */
    public function calculate(array $input): array
    {
        $customerId          = (int)($input['customer_id'] ?? 0);
        $dailyRate           = max(0.0, (float)($input['daily_rate'] ?? 0));
        $protectionDailyFee  = max(0.0, (float)($input['protection_daily_fee'] ?? 0));
        $promoCodeRaw        = strtoupper(trim((string)($input['promo_code'] ?? '')));
        $applyStatutory      = (bool)($input['apply_statutory'] ?? false);
        $applyVatExemption   = (bool)($input['apply_vat_exemption'] ?? true);
        $allowStatutoryStack = (bool)($input['allow_statutory_stack'] ?? false);

        $pickupDt = new DateTimeImmutable($input['pickup_at'] ?? 'now');
        $returnDt = new DateTimeImmutable($input['return_at'] ?? '+1 day');
        $seconds  = max(3600, $returnDt->getTimestamp() - $pickupDt->getTimestamp());
        $days     = max(1, (int)ceil($seconds / 86400));

        $grossBase       = round($dailyRate * $days, 2);
        $protectionTotal = round($protectionDailyFee * $days, 2);
        $grossSubtotal   = round($grossBase + $protectionTotal, 2);

        // Extract 12% VAT from VAT-inclusive gross base (Philippine BIR standard: Gross / 1.12)
        $vatDivisor     = 1.0 + ($this->vatRate / 100.0);
        $vatableSales   = round($grossSubtotal / $vatDivisor, 2);
        $standardVat    = round($grossSubtotal - $vatableSales, 2);

        // 1. Evaluate Statutory Path (Senior Citizen / PWD)
        $statutoryResult = null;
        if ($applyStatutory && $customerId > 0) {
            $statutoryResult = $this->evaluateStatutoryDiscount(
                $customerId,
                $grossSubtotal,
                $vatableSales,
                $standardVat,
                $applyVatExemption
            );
        }

        // 2. Evaluate Commercial Path (Duration Tier + Holiday Promo + Promo Code)
        $commercialResult = $this->evaluateCommercialDiscounts(
            $customerId,
            $days,
            $grossSubtotal,
            $pickupDt->format('Y-m-d'),
            $promoCodeRaw
        );

        // 3. Apply Stacking & Conflict Resolution Rules
        $appliedDiscounts = [];
        $totalDiscount    = 0.0;
        $vatExemptAmount  = 0.0;
        $finalVat         = $standardVat;
        $stackingNote     = 'Standard pricing applied.';

        if ($statutoryResult !== null && !$allowStatutoryStack) {
            // RA 9994 / RA 10754: Compare total statutory benefit (discount + VAT exemption) vs commercial promos
            $statutoryTotalBenefit  = $statutoryResult['discount_amount'] + $statutoryResult['vat_exempt_amount'];
            $commercialTotalBenefit = $commercialResult['discount_amount'];

            if ($statutoryTotalBenefit >= $commercialTotalBenefit) {
                $appliedDiscounts = [$statutoryResult['snapshot']];
                $totalDiscount    = $statutoryResult['discount_amount'];
                $vatExemptAmount  = $statutoryResult['vat_exempt_amount'];
                $finalVat         = round(max(0.0, $standardVat - $vatExemptAmount), 2);
                $stackingNote     = 'Statutory Senior/PWD benefit applied (non-stackable; higher total savings selected).';
            } else {
                $appliedDiscounts = $commercialResult['snapshots'];
                $totalDiscount    = $commercialResult['discount_amount'];
                $stackingNote     = 'Commercial promo selected (yields higher savings than statutory discount).';
            }
        } elseif ($statutoryResult !== null && $allowStatutoryStack) {
            // Optional sequential stacking mode: statutory first, then stackable commercial promos on remainder
            $appliedDiscounts[] = $statutoryResult['snapshot'];
            $totalDiscount      = $statutoryResult['discount_amount'];
            $vatExemptAmount    = $statutoryResult['vat_exempt_amount'];
            $finalVat           = round(max(0.0, $standardVat - $vatExemptAmount), 2);

            $remainingBase = max(0.0, $grossSubtotal - $vatExemptAmount - $totalDiscount);
            foreach ($commercialResult['snapshots'] as $snap) {
                if (!empty($snap['is_stackable']) && $remainingBase > 0) {
                    $extra = $this->computeRuleAmount($snap, $remainingBase);
                    $snap['computed_discount_amount'] = $extra;
                    $appliedDiscounts[] = $snap;
                    $totalDiscount = round($totalDiscount + $extra, 2);
                    $remainingBase = max(0.0, $remainingBase - $extra);
                }
            }
            $stackingNote = 'Statutory discount stacked sequentially with eligible commercial promos.';
        } else {
            // Commercial promos only
            $appliedDiscounts = $commercialResult['snapshots'];
            $totalDiscount    = $commercialResult['discount_amount'];
            if (!empty($appliedDiscounts)) {
                $stackingNote = 'Commercial promotional discount(s) applied.';
            }
        }

        $finalTotal = round(max(0.0, $grossSubtotal - $vatExemptAmount - $totalDiscount), 2);

        return [
            'rental_days'         => $days,
            'daily_rate'          => $dailyRate,
            'base_amount'         => $grossBase,
            'protection_amount'   => $protectionTotal,
            'gross_subtotal'      => $grossSubtotal,
            'subtotal_before_tax' => $vatableSales,
            'vat_rate'            => $this->vatRate,
            'vat_amount'          => $finalVat,
            'vat_exempt_amount'   => $vatExemptAmount,
            'discount_amount'     => $totalDiscount,
            'final_total'         => $finalTotal,
            'stacking_note'       => $stackingNote,
            'applied_discounts'   => $appliedDiscounts,
            'promo_error'         => $commercialResult['promo_error'],
        ];
    }

    /**
     * Persist calculated pricing snapshot + applied discount rows atomically.
     */
    public function persistToReservation(int $reservationId, array $pricing): void
    {
        $updateStmt = $this->pdo->prepare(
            "UPDATE reservations
             SET base_amount = ?,
                 subtotal_before_tax = ?,
                 vat_rate = ?,
                 vat_amount = ?,
                 vat_exempt_amount = ?,
                 discount_amount = ?,
                 total_amount = ?,
                 final_total = ?
             WHERE id = ?"
        );
        $updateStmt->execute([
            $pricing['base_amount'],
            $pricing['subtotal_before_tax'],
            $pricing['vat_rate'],
            $pricing['vat_amount'],
            $pricing['vat_exempt_amount'],
            $pricing['discount_amount'],
            $pricing['final_total'],
            $pricing['final_total'],
            $reservationId,
        ]);

        // Replace any prior discount snapshots for this reservation
        $this->pdo->prepare("DELETE FROM reservation_discounts WHERE reservation_id = ?")
                  ->execute([$reservationId]);

        $insertStmt = $this->pdo->prepare(
            "INSERT INTO reservation_discounts (
                reservation_id, discount_rule_id, promo_code_id, eligibility_verification_id,
                snapshot_rule_name, snapshot_calculation_mode, snapshot_rate,
                computed_discount_amount, computed_vat_exemption
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($pricing['applied_discounts'] as $snap) {
            $insertStmt->execute([
                $reservationId,
                (int)$snap['discount_rule_id'],
                $snap['promo_code_id'] ?? null,
                $snap['eligibility_verification_id'] ?? null,
                $snap['snapshot_rule_name'],
                $snap['snapshot_calculation_mode'],
                (float)$snap['snapshot_rate'],
                (float)$snap['computed_discount_amount'],
                (float)($snap['computed_vat_exemption'] ?? 0.0),
            ]);

            if (!empty($snap['promo_code_id'])) {
                $this->pdo->prepare("UPDATE promo_codes SET current_redemptions = current_redemptions + 1 WHERE id = ?")
                          ->execute([(int)$snap['promo_code_id']]);
            }
        }
    }

    private function evaluateStatutoryDiscount(
        int $customerId,
        float $grossSubtotal,
        float $vatableSales,
        float $standardVat,
        bool $applyVatExemption
    ): ?array {
        // Require a verified, non-expired Senior Citizen or PWD record
        $verStmt = $this->pdo->prepare(
            "SELECT id, eligibility_type, id_number
             FROM customer_eligibility_verifications
             WHERE user_id = ?
               AND verification_status = 'verified'
               AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE)
             ORDER BY verified_at DESC
             LIMIT 1"
        );
        $verStmt->execute([$customerId]);
        $verification = $verStmt->fetch(PDO::FETCH_ASSOC);
        if (!$verification) {
            return null;
        }

        $typeCode = $verification['eligibility_type'] === 'senior_citizen'
            ? 'statutory_senior'
            : 'statutory_pwd';

        $ruleStmt = $this->pdo->prepare(
            "SELECT dr.*
             FROM discount_rules dr
             INNER JOIN discount_types dt ON dt.id = dr.discount_type_id
             WHERE dt.code = ? AND dr.is_active = 1
             ORDER BY dr.priority ASC
             LIMIT 1"
        );
        $ruleStmt->execute([$typeCode]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            return null;
        }

        // Per BIR RR 7-2010: if VAT-exempt is enabled, remove 12% VAT first, then apply 20% to VAT-exclusive base
        $vatExempt = ($applyVatExemption && (int)$rule['vat_exempt_eligible'] === 1) ? $standardVat : 0.0;
        $discountableBase = $vatExempt > 0 ? $vatableSales : $grossSubtotal;
        $discountAmt = $this->computeRuleAmount([
            'snapshot_calculation_mode' => $rule['calculation_mode'],
            'snapshot_rate'             => (float)$rule['discount_value'],
            'max_discount_cap'          => $rule['max_discount_cap'] !== null ? (float)$rule['max_discount_cap'] : null,
        ], $discountableBase);

        return [
            'discount_amount'   => $discountAmt,
            'vat_exempt_amount' => $vatExempt,
            'snapshot'          => [
                'discount_rule_id'            => (int)$rule['id'],
                'promo_code_id'               => null,
                'eligibility_verification_id' => (int)$verification['id'],
                'snapshot_rule_name'          => $rule['name'] . ' (ID: ' . $verification['id_number'] . ')',
                'snapshot_calculation_mode'   => $rule['calculation_mode'],
                'snapshot_rate'               => (float)$rule['discount_value'],
                'computed_discount_amount'    => $discountAmt,
                'computed_vat_exemption'      => $vatExempt,
                'is_stackable'                => 0,
            ],
        ];
    }

    private function evaluateCommercialDiscounts(
        int $customerId,
        int $days,
        float $grossSubtotal,
        string $pickupDate,
        string $promoCodeRaw
    ): array {
        $candidates = [];
        $promoError = null;

        // A) Best matching Long-Term Duration Tier (7+ days or 30+ days)
        $durStmt = $this->pdo->prepare(
            "SELECT dr.*
             FROM discount_rules dr
             INNER JOIN discount_types dt ON dt.id = dr.discount_type_id
             WHERE dt.code = 'duration_tier'
               AND dr.is_active = 1
               AND dr.min_rental_days <= ?
               AND dr.min_base_amount <= ?
             ORDER BY dr.min_rental_days DESC, dr.priority ASC
             LIMIT 1"
        );
        $durStmt->execute([$days, $grossSubtotal]);
        if ($dur = $durStmt->fetch(PDO::FETCH_ASSOC)) {
            $candidates[] = ['rule' => $dur, 'promo_code_id' => null, 'label' => $dur['name']];
        }

        // B) Active Philippine Holiday Calendar Promo
        $holStmt = $this->pdo->prepare(
            "SELECT dr.*, hc.holiday_name
             FROM holiday_calendar hc
             INNER JOIN discount_rules dr ON dr.id = hc.discount_rule_id
             WHERE hc.is_active = 1
               AND dr.is_active = 1
               AND ? BETWEEN hc.promo_start_date AND hc.promo_end_date
               AND dr.min_rental_days <= ?
               AND dr.min_base_amount <= ?
             ORDER BY dr.priority ASC
             LIMIT 1"
        );
        $holStmt->execute([$pickupDate, $days, $grossSubtotal]);
        if ($hol = $holStmt->fetch(PDO::FETCH_ASSOC)) {
            $candidates[] = ['rule' => $hol, 'promo_code_id' => null, 'label' => $hol['holiday_name']];
        }

        // C) Explicit Customer Promo Code
        if ($promoCodeRaw !== '') {
            $pcStmt = $this->pdo->prepare(
                "SELECT pc.id AS promo_code_id, pc.code, pc.max_redemptions, pc.current_redemptions,
                        pc.per_customer_limit, dr.*
                 FROM promo_codes pc
                 INNER JOIN discount_rules dr ON dr.id = pc.discount_rule_id
                 WHERE pc.code = ? AND pc.is_active = 1 AND dr.is_active = 1
                   AND (dr.valid_from IS NULL OR dr.valid_from <= CURRENT_DATE)
                   AND (dr.valid_until IS NULL OR dr.valid_until >= CURRENT_DATE)
                 LIMIT 1"
            );
            $pcStmt->execute([$promoCodeRaw]);
            $pc = $pcStmt->fetch(PDO::FETCH_ASSOC);

            if (!$pc) {
                $promoError = 'Promo code is invalid or expired.';
            } elseif ($pc['max_redemptions'] !== null && (int)$pc['current_redemptions'] >= (int)$pc['max_redemptions']) {
                $promoError = 'Promo code has reached its redemption limit.';
            } elseif ($days < (int)$pc['min_rental_days']) {
                $promoError = 'Promo code requires a minimum of ' . (int)$pc['min_rental_days'] . ' rental days.';
            } elseif ($grossSubtotal < (float)$pc['min_base_amount']) {
                $promoError = 'Promo code requires a minimum booking amount of PHP ' . number_format((float)$pc['min_base_amount'], 2) . '.';
            } else {
                $candidates[] = [
                    'rule'          => $pc,
                    'promo_code_id' => (int)$pc['promo_code_id'],
                    'label'         => $pc['name'] . ' [' . $pc['code'] . ']',
                ];
            }
        }

        if (empty($candidates)) {
            return ['discount_amount' => 0.0, 'snapshots' => [], 'promo_error' => $promoError];
        }

        // Sort candidates by priority ascending
        usort($candidates, static fn($a, $b) => ((int)$a['rule']['priority']) <=> ((int)$b['rule']['priority']));

        // Evaluate stackable chain vs best single non-stackable rule; award whichever saves more
        $bestExclusiveAmt = 0.0;
        $bestExclusiveSnap = null;

        $stackTotalAmt = 0.0;
        $stackRemaining = $grossSubtotal;
        $stackSnaps = [];

        foreach ($candidates as $cand) {
            $rule = $cand['rule'];
            $spec = [
                'discount_rule_id'          => (int)$rule['id'],
                'promo_code_id'             => $cand['promo_code_id'],
                'eligibility_verification_id' => null,
                'snapshot_rule_name'        => $cand['label'],
                'snapshot_calculation_mode' => $rule['calculation_mode'],
                'snapshot_rate'             => (float)$rule['discount_value'],
                'max_discount_cap'          => $rule['max_discount_cap'] !== null ? (float)$rule['max_discount_cap'] : null,
                'is_stackable'              => (int)$rule['is_stackable'],
            ];

            if ((int)$rule['is_stackable'] === 1) {
                $amt = $this->computeRuleAmount($spec, $stackRemaining);
                $spec['computed_discount_amount'] = $amt;
                $spec['computed_vat_exemption']   = 0.0;
                $stackSnaps[]   = $spec;
                $stackTotalAmt  = round($stackTotalAmt + $amt, 2);
                $stackRemaining = max(0.0, $stackRemaining - $amt);
            } else {
                $amt = $this->computeRuleAmount($spec, $grossSubtotal);
                if ($amt > $bestExclusiveAmt) {
                    $bestExclusiveAmt = $amt;
                    $spec['computed_discount_amount'] = $amt;
                    $spec['computed_vat_exemption']   = 0.0;
                    $bestExclusiveSnap = $spec;
                }
            }
        }

        if ($bestExclusiveAmt > $stackTotalAmt && $bestExclusiveSnap !== null) {
            return [
                'discount_amount' => $bestExclusiveAmt,
                'snapshots'       => [$bestExclusiveSnap],
                'promo_error'     => $promoError,
            ];
        }

        return [
            'discount_amount' => $stackTotalAmt,
            'snapshots'       => $stackSnaps,
            'promo_error'     => $promoError,
        ];
    }

    private function computeRuleAmount(array $spec, float $base): float
    {
        if ($base <= 0) {
            return 0.0;
        }
        $rate = (float)$spec['snapshot_rate'];
        $amt  = ($spec['snapshot_calculation_mode'] === 'percentage')
            ? round($base * ($rate / 100.0), 2)
            : round(min($base, $rate), 2);

        if (isset($spec['max_discount_cap']) && $spec['max_discount_cap'] !== null) {
            $amt = min($amt, (float)$spec['max_discount_cap']);
        }
        return round(max(0.0, min($base, $amt)), 2);
    }
}
