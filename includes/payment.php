<?php

function payment_http_request(string $url, array $headers, string $body, ?string $basicAuth = null): array
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        $curlHeaders = $headers;
        if ($basicAuth !== null) {
            $curlHeaders[] = 'Authorization: Basic ' . base64_encode($basicAuth);
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 20,
        ]);
        $responseBody = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($responseBody === false || $error !== '') {
            throw new RuntimeException('Payment provider request failed.');
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . ($basicAuth !== null ? "\r\nAuthorization: Basic " . base64_encode($basicAuth) : ''),
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $responseBody = file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        if ($responseBody === false) {
            throw new RuntimeException('Payment provider request failed.');
        }
    }
    $decoded = json_decode($responseBody, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException('Payment provider rejected the checkout request.');
    }
    return $decoded;
}

function payment_json_request(string $url, array $headers, array $payload, ?string $basicAuth = null): array
{
    return payment_http_request(
        $url,
        array_merge(['Content-Type: application/json'], $headers),
        json_encode($payload, JSON_THROW_ON_ERROR),
        $basicAuth
    );
}

function payment_provider_configured(string $provider): bool
{
    if ($provider === 'online') {
        return true;
    }
    return $provider === 'paymongo'
        ? (bool)getenv('PAYMONGO_SECRET_KEY')
        : (bool)(getenv('PAYPAL_CLIENT_ID') && getenv('PAYPAL_CLIENT_SECRET'));
}

function create_online_checkout(array $reservation, int $attemptId): array
{
    return [
        'session_id' => 'online-' . $attemptId,
        'checkout_url' => app_url('/online-checkout.php?attempt=' . $attemptId),
    ];
}

function create_paymongo_checkout(array $reservation): array
{
    if (!payment_provider_configured('paymongo')) {
        throw new RuntimeException('PayMongo sandbox credentials are not configured.');
    }
    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => (int)round((float)$reservation['total_amount'] * 100),
                    'description' => $reservation['brand'] . ' ' . $reservation['model'] . ' rental',
                    'name' => 'VELO reservation ' . $reservation['reference_number'],
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['card', 'gcash'],
                'success_url' => app_url('/payment-return.php?provider=paymongo&reservation=' . (int)$reservation['id']),
                'cancel_url' => app_url('/customer/rental.php?id=' . (int)$reservation['id']),
                'description' => 'VELO car rental reservation ' . $reservation['reference_number'],
                'metadata' => ['reservation_id' => (string)$reservation['id']],
            ],
        ],
    ];
    $response = payment_json_request(
        'https://api.paymongo.com/v1/checkout_sessions',
        ['Authorization: Basic ' . base64_encode((string)getenv('PAYMONGO_SECRET_KEY') . ':')],
        $payload
    );
    $attributes = $response['data']['attributes'] ?? [];
    $url = $attributes['checkout_url'] ?? '';
    if ($url === '') {
        throw new RuntimeException('PayMongo did not return a checkout URL.');
    }
    return ['session_id' => $response['data']['id'] ?? null, 'checkout_url' => $url];
}

function paypal_access_token(): string
{
    $base = getenv('PAYPAL_ENVIRONMENT') === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
    $decoded = payment_http_request(
        $base . '/v1/oauth2/token',
        ['Accept: application/json', 'Accept-Language: en_US', 'Content-Type: application/x-www-form-urlencoded'],
        'grant_type=client_credentials',
        getenv('PAYPAL_CLIENT_ID') . ':' . getenv('PAYPAL_CLIENT_SECRET')
    );
    if (empty($decoded['access_token'])) {
        throw new RuntimeException('PayPal sandbox authentication failed.');
    }
    return $decoded['access_token'];
}

function create_paypal_checkout(array $reservation): array
{
    $token = paypal_access_token();
    $base = getenv('PAYPAL_ENVIRONMENT') === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
    $response = payment_json_request($base . '/v2/checkout/orders', [
        'Authorization: Bearer ' . $token,
        'PayPal-Request-Id: velo-' . $reservation['id'] . '-' . bin2hex(random_bytes(6)),
    ], [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $reservation['reference_number'],
            'description' => 'VELO car rental reservation',
            'amount' => ['currency_code' => 'PHP', 'value' => number_format((float)$reservation['total_amount'], 2, '.', '')],
        ]],
        'application_context' => [
            'return_url' => app_url('/payment-return.php?provider=paypal&reservation=' . (int)$reservation['id']),
            'cancel_url' => app_url('/customer/rental.php?id=' . (int)$reservation['id']),
            'user_action' => 'PAY_NOW',
        ],
    ]);
    foreach ($response['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
            return ['session_id' => $response['id'] ?? null, 'checkout_url' => $link['href']];
        }
    }
    throw new RuntimeException('PayPal did not return an approval URL.');
}

function system_payment_actor(PDO $pdo): int
{
    $actor = $pdo->query("SELECT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.name IN ('admin', 'staff') ORDER BY r.name = 'admin' DESC, u.id LIMIT 1")->fetchColumn();
    if (!$actor) {
        throw new RuntimeException('No staff or administrator account is available for payment settlement.');
    }
    return (int)$actor;
}

function settle_online_payment(PDO $pdo, int $attemptId, string $providerPaymentId): void
{
    $stmt = $pdo->prepare('SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE');
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
    if (!$attempt || $attempt['status'] === 'paid') {
        return;
    }
    $actor = system_payment_actor($pdo);
    $payment = $pdo->prepare('INSERT INTO payments (reservation_id, amount, method, reference_number, recorded_by) VALUES (?, ?, ?, ?, ?)');
    $payment->execute([(int)$attempt['reservation_id'], $attempt['amount'], $attempt['provider'], $providerPaymentId, $actor]);
    $paymentId = (int)$pdo->lastInsertId();
    $receipt = $pdo->prepare('INSERT INTO payment_receipts (payment_id, receipt_number, issued_by) VALUES (?, ?, ?)');
    $receipt->execute([$paymentId, 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))), $actor]);
    $pdo->prepare("UPDATE payment_attempts SET status = 'paid', provider_payment_id = ?, paid_at = NOW() WHERE id = ?")->execute([$providerPaymentId, $attemptId]);
    write_audit($pdo, $actor, 'online_payment_settled', 'payments', $paymentId, 'Online payment verified by provider webhook.');
}
