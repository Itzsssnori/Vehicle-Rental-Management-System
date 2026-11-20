<?php
/**
 * VELO VRMS — Offline-First Vehicle Catalog & Pricing Rules API
 * Endpoint: GET /api/vehicles.php
 *
 * Returns normalized 3NF vehicle inventory and active discount rules.
 * Guarantees local asset URLs (never broken external CDN links) and
 * provides a built-in static fallback catalog if MariaDB is unavailable.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$fallbackCatalog = [
    [
        'id' => 1, 'brand' => 'Toyota', 'model' => 'Vios 1.3 XLE', 'category' => 'Sedan',
        'transmission' => 'CVT Automatic', 'fuel_type' => 'Gasoline', 'seats' => 5,
        'daily_rate' => 2200.00, 'plate_number' => 'NGA-1024', 'status' => 'operational',
        'image' => '/assets/vehicles/toyota-vios.jpg',
        'description' => 'Reliable Metro Manila city sedan with fuel-saving Eco mode.'
    ],
    [
        'id' => 2, 'brand' => 'Mitsubishi', 'model' => 'Montero Sport GLS', 'category' => 'SUV',
        'transmission' => 'Automatic', 'fuel_type' => 'Diesel', 'seats' => 7,
        'daily_rate' => 4200.00, 'plate_number' => 'NGB-2048', 'status' => 'operational',
        'image' => '/assets/vehicles/mitsubishi-montero-sport.jpg',
        'description' => '7-seater turbo-diesel SUV built for provincial and highway trips.'
    ],
    [
        'id' => 3, 'brand' => 'Toyota', 'model' => 'HiAce Commuter Deluxe', 'category' => 'Van',
        'transmission' => 'Manual', 'fuel_type' => 'Diesel', 'seats' => 15,
        'daily_rate' => 4800.00, 'plate_number' => 'NGC-3072', 'status' => 'operational',
        'image' => '/assets/img/vehicle-placeholder.svg',
        'description' => '15-seater corporate and family commuter van with dual rear A/C.'
    ],
    [
        'id' => 4, 'brand' => 'Ford', 'model' => 'Ranger Wildtrak 4x4', 'category' => 'Pickup',
        'transmission' => 'Automatic', 'fuel_type' => 'Diesel', 'seats' => 5,
        'daily_rate' => 4500.00, 'plate_number' => 'NGD-4096', 'status' => 'operational',
        'image' => '/assets/vehicles/ford-ranger.jpg',
        'description' => '4x4 bi-turbo utility pickup with spacious cargo bed.'
    ],
];

$fallbackDiscounts = [
    ['code' => 'STATUTORY_SENIOR', 'name' => 'Senior Citizen 20% + VAT Exempt (RA 9994)', 'rate' => 20.0, 'mode' => 'percentage', 'vat_exempt' => true],
    ['code' => 'STATUTORY_PWD', 'name' => 'PWD 20% + VAT Exempt (RA 10754)', 'rate' => 20.0, 'mode' => 'percentage', 'vat_exempt' => true],
    ['code' => 'WEEKLY_7D', 'name' => 'Weekly Rental Saver (7+ Days)', 'rate' => 10.0, 'mode' => 'percentage', 'vat_exempt' => false],
    ['code' => 'MONTHLY_30D', 'name' => 'Monthly Corporate Tier (30+ Days)', 'rate' => 20.0, 'mode' => 'percentage', 'vat_exempt' => false],
    ['code' => 'VELO10', 'name' => 'VELO Welcome 10% Voucher', 'rate' => 10.0, 'mode' => 'percentage', 'vat_exempt' => false],
];

/**
 * Resolve any vehicle image to a verified local public asset path.
 */
function resolve_local_asset(?string $img): string
{
    $placeholder = '/assets/img/vehicle-placeholder.svg';
    $img = trim((string)$img);
    if ($img === '' || preg_match('#^https?://#i', $img)) {
        return $placeholder;
    }
    $relPath = str_starts_with($img, '/assets/') ? $img : '/assets/vehicles/' . basename($img);
    $absPath = dirname(__DIR__) . $relPath;
    return is_file($absPath) ? $relPath : $placeholder;
}

try {
    require_once __DIR__ . '/../../config.php';
    $pdo = db();

    $sql = "SELECT v.id,
                   COALESCE(mk.name, v.brand) AS brand,
                   COALESCE(vm.name, v.model) AS model,
                   vc.name AS category,
                   COALESCE(tt.label, v.transmission) AS transmission,
                   COALESCE(ft.label, v.fuel_type, 'Gasoline') AS fuel_type,
                   v.seats,
                   v.daily_rate,
                   v.plate_number,
                   v.status,
                   v.image,
                   v.description
            FROM vehicles v
            INNER JOIN vehicle_categories vc ON vc.id = v.category_id
            LEFT JOIN vehicle_models vm ON vm.id = v.model_id
            LEFT JOIN vehicle_makes mk ON mk.id = vm.make_id
            LEFT JOIN transmission_types tt ON tt.id = v.transmission_type_id
            LEFT JOIN fuel_types ft ON ft.id = v.fuel_type_id
            WHERE v.status = 'operational' AND v.deleted_at IS NULL
            ORDER BY vc.name ASC, v.daily_rate ASC";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id']         = (int)$row['id'];
        $row['seats']      = (int)$row['seats'];
        $row['daily_rate'] = (float)$row['daily_rate'];
        $row['image']      = resolve_local_asset($row['image'] ?? '');
    }
    unset($row);

    $discounts = $fallbackDiscounts;
    try {
        $dRows = $pdo->query(
            "SELECT dt.code, dr.name, dr.discount_value AS rate, dr.calculation_mode AS mode, dr.vat_exempt_eligible AS vat_exempt
             FROM discount_rules dr
             INNER JOIN discount_types dt ON dt.id = dr.discount_type_id
             WHERE dr.is_active = 1
             ORDER BY dr.priority ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($dRows)) {
            $discounts = $dRows;
        }
    } catch (Throwable $ignore) {
        // Fallback if migration 004 has not been applied yet
    }

    echo json_encode([
        'source'       => 'database',
        'generated_at' => gmdate('c'),
        'count'        => count($rows),
        'vehicles'     => !empty($rows) ? $rows : $fallbackCatalog,
        'discounts'    => $discounts,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode([
        'source'       => 'static_fallback',
        'generated_at' => gmdate('c'),
        'count'        => count($fallbackCatalog),
        'vehicles'     => $fallbackCatalog,
        'discounts'    => $fallbackDiscounts,
    ], JSON_UNESCAPED_SLASHES);
}
