<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';

$provider = $_GET['provider'] ?? '';
$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!in_array($provider, ['paymongo', 'paypal'], true) || !is_array($payload)) {
    http_response_code(400);
    exit;
}

function webhook_response(int $status): never
{
    http_response_code($status);
    exit;
}

if ($provider === 'paymongo') {
    $signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
    $secret = (string)getenv('PAYMONGO_WEBHOOK_SECRET');
    if ($secret === '' || !preg_match('/(?:^|,)t=(\d+)(?:,|$)/', $signature, $time) || !preg_match('/(?:^|,)v1=([^,]+)(?:,|$)/', $signature, $hash)) {
        webhook_response(401);
    }
    $expected = hash_hmac('sha256', $time[1] . '.' . $raw, $secret);
    if (!hash_equals($expected, $hash[1]) || abs(time() - (int)$time[1]) > 300) {
        webhook_response(401);
    }
    $eventId = (string)($payload['data']['id'] ?? '');
    $eventType = (string)($payload['data']['attributes']['type'] ?? '');
    $providerSessionId = (string)($payload['data']['attributes']['data']['id'] ?? $payload['data']['id'] ?? '');
    $providerPaymentId = (string)($payload['data']['attributes']['payment_intent']['id'] ?? $providerSessionId);
    $paid = str_contains($eventType, 'paid') || str_contains($eventType, 'succeeded');
} else {
    $eventId = (string)($payload['id'] ?? '');
    $eventType = (string)($payload['event_type'] ?? '');
    $providerSessionId = (string)($payload['resource']['supplementary_data']['related_ids']['order_id'] ?? $payload['resource']['id'] ?? '');
    $providerPaymentId = (string)($payload['resource']['id'] ?? '');
    $paid = $eventType === 'PAYMENT.CAPTURE.COMPLETED';
    if (!payment_provider_configured('paypal') || getenv('PAYPAL_WEBHOOK_ID') === '') {
        webhook_response(503);
    }
    $verify = payment_json_request(
        (getenv('PAYPAL_ENVIRONMENT') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com') . '/v1/notifications/verify-webhook-signature',
        ['Authorization: Bearer ' . paypal_access_token()],
        [
            'auth_algo' => $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? '',
            'cert_url' => $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? '',
            'transmission_id' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '',
            'transmission_sig' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '',
            'transmission_time' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
            'webhook_id' => getenv('PAYPAL_WEBHOOK_ID'),
            'webhook_event' => $payload,
        ]
    );
    if (($verify['verification_status'] ?? '') !== 'SUCCESS') {
        webhook_response(401);
    }
}

if ($eventId === '') {
    webhook_response(400);
}
$pdo = db();
try {
    $event = $pdo->prepare('INSERT INTO payment_webhook_events (provider, provider_event_id, event_type, payload) VALUES (?, ?, ?, ?)');
    $event->execute([$provider, $eventId, $eventType, $raw]);
} catch (PDOException $exception) {
    if ((int)$exception->errorInfo[1] === 1062) {
        webhook_response(200);
    }
    webhook_response(500);
}

if ($paid && $providerSessionId !== '') {
    $attempt = $pdo->prepare("SELECT id FROM payment_attempts WHERE provider = ? AND provider_session_id = ? LIMIT 1");
    $attempt->execute([$provider, $providerSessionId]);
    $attemptId = $attempt->fetchColumn();
    if ($attemptId) {
        $pdo->beginTransaction();
        try {
            settle_online_payment($pdo, (int)$attemptId, $providerPaymentId);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            error_log('Online payment settlement failed: ' . $exception->getMessage());
            webhook_response(500);
        }
    }
}
webhook_response(200);
