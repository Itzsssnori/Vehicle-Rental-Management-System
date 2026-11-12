<?php

/**
 * VELO Vehicle Rental Management System
 * Mail Delivery Engine (SMTP Sockets, PHP mail, and Demo Fallback)
 */

if (!function_exists('mail_config')) {
    function mail_config(): array
    {
        return [
            'smtp_host'   => (string)(getenv('SMTP_HOST') ?: ''),
            'smtp_port'   => (int)(getenv('SMTP_PORT') ?: 587),
            'smtp_user'   => (string)(getenv('SMTP_USER') ?: ''),
            'smtp_pass'   => (string)(getenv('SMTP_PASS') ?: ''),
            'smtp_secure' => strtolower((string)(getenv('SMTP_SECURE') ?: 'tls')), // 'tls', 'ssl', or 'none'
            'from_email'  => (string)(getenv('MAIL_FROM_ADDRESS') ?: 'noreply@velo.local'),
            'from_name'   => (string)(getenv('MAIL_FROM_NAME') ?: 'VELO Vehicle Rental System'),
        ];
    }
}

/**
 * Send an email directly via SMTP socket connection.
 * Zero external dependencies; works with Gmail, Mailtrap, Brevo, SendGrid, etc.
 */
function send_smtp_mail(array $config, string $to, string $subject, string $htmlBody, string $textBody = ''): array
{
    $host = trim($config['smtp_host'] ?? '');
    $port = (int)($config['smtp_port'] ?? 587);
    $user = trim($config['smtp_user'] ?? '');
    $pass = (string)($config['smtp_pass'] ?? '');
    $secure = strtolower($config['smtp_secure'] ?? 'tls');
    $fromEmail = trim($config['from_email'] ?? 'noreply@velo.local');
    $fromName = trim($config['from_name'] ?? 'VELO Vehicle Rental System');

    if ($host === '') {
        return ['success' => false, 'error' => 'SMTP_HOST is not configured.'];
    }

    $timeout = 10;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);

    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $socket = @stream_socket_client("{$prefix}{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['success' => false, 'error' => "SMTP connection to {$host}:{$port} failed: {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, $timeout);

    $read = function () use ($socket): string {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };

    $send = function (string $cmd) use ($socket, $read): string {
        fwrite($socket, $cmd . "\r\n");
        return $read();
    };

    $res = $read();
    if (!str_starts_with($res, '220')) {
        fclose($socket);
        return ['success' => false, 'error' => "SMTP greeting failed: {$res}"];
    }

    $hostname = gethostname() ?: 'localhost';
    $send("EHLO {$hostname}");

    if ($secure === 'tls') {
        $res = $send('STARTTLS');
        if (!str_starts_with($res, '220')) {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS failed: {$res}"];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['success' => false, 'error' => 'TLS cryptographic handshake failed with SMTP host.'];
        }
        $send("EHLO {$hostname}");
    }

    if ($user !== '' && $pass !== '') {
        $res = $send('AUTH LOGIN');
        if (!str_starts_with($res, '334')) {
            fclose($socket);
            return ['success' => false, 'error' => "AUTH LOGIN command rejected: {$res}"];
        }
        $res = $send(base64_encode($user));
        if (!str_starts_with($res, '334')) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP username rejected: {$res}"];
        }
        $res = $send(base64_encode($pass));
        if (!str_starts_with($res, '235')) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP password authentication failed: {$res}"];
        }
    }

    $res = $send("MAIL FROM: <{$fromEmail}>");
    if (!str_starts_with($res, '250')) {
        fclose($socket);
        return ['success' => false, 'error' => "MAIL FROM rejected: {$res}"];
    }

    $res = $send("RCPT TO: <{$to}>");
    if (!str_starts_with($res, '250')) {
        fclose($socket);
        return ['success' => false, 'error' => "RCPT TO rejected: {$res}"];
    }

    $res = $send('DATA');
    if (!str_starts_with($res, '354')) {
        fclose($socket);
        return ['success' => false, 'error' => "DATA initiation rejected: {$res}"];
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

    $headers = [
        'MIME-Version: 1.0',
        'Date: ' . date('r'),
        'Content-Type: text/html; charset=UTF-8',
        "From: {$encodedFromName} <{$fromEmail}>",
        "To: <{$to}>",
        "Subject: {$encodedSubject}",
        'X-Mailer: VELO-VRMS-Mailer/1.0',
    ];

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.\r\n";
    fwrite($socket, $payload);
    $res = $read();

    $send('QUIT');
    fclose($socket);

    if (str_starts_with($res, '250')) {
        return ['success' => true, 'response' => trim($res)];
    }
    return ['success' => false, 'error' => "Email delivery failed on message submission: {$res}"];
}

/**
 * High-level system mail sender.
 * Tries SMTP first if configured, then PHP mail() if available.
 */
function send_system_mail(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): array
{
    $config = mail_config();

    // 1. Try SMTP if configured
    if (!empty($config['smtp_host'])) {
        $result = send_smtp_mail($config, $toEmail, $subject, $htmlBody, $textBody);
        if ($result['success']) {
            return ['success' => true, 'mode' => 'smtp', 'error' => null];
        }
        error_log("VELO SMTP delivery failed for {$toEmail}: " . ($result['error'] ?? 'Unknown error'));
    }

    // 2. Try native PHP mail() if SMTP is not configured or failed
    if (function_exists('mail') && ini_get('sendmail_path')) {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($config['from_name']) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            "From: {$encodedFromName} <{$config['from_email']}>",
            'X-Mailer: VELO-VRMS-Mailer/1.0',
        ];
        if (@mail($toEmail, $encodedSubject, $htmlBody, implode("\r\n", $headers))) {
            return ['success' => true, 'mode' => 'mail', 'error' => null];
        }
    }

    return ['success' => false, 'mode' => 'none', 'error' => 'No active mail transport configured.'];
}

/**
 * Build responsive HTML template for authentication emails.
 */
function build_auth_email_html(string $kind, string $email, string $url): string
{
    $isVerification = str_contains(strtolower($kind), 'verification');
    $title = $isVerification ? 'Confirm Your Email Address' : 'Reset Your Password';
    $heading = $isVerification ? 'Verify Your VELO Account' : 'Password Reset Request';
    $buttonText = $isVerification ? 'Verify Email Address' : 'Reset My Password';
    $expiryText = $isVerification ? 'This link will remain valid for 24 hours.' : 'This link will expire in 1 hour.';
    $messageBody = $isVerification
        ? 'Thank you for registering with VELO Vehicle Rental Management System. Please confirm your email address to activate your account and access our vehicle fleet.'
        : 'We received a request to reset the password for your VELO account. Click the button below to choose a new password.';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1e293b;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:32px 16px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:540px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,0.06);">
          <!-- Brand Header -->
          <tr>
            <td style="background-color:#161a22; padding:28px 32px; text-align:left;">
              <div style="font-size:1.15rem; font-weight:800; color:#ffffff; letter-spacing:-0.02em;">
                VELO <span style="color:#e53e3e; font-weight:400; font-style:italic;">FLEET</span>
              </div>
              <div style="font-size:0.75rem; color:#94a3b8; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; margin-top:2px;">
                Vehicle Rental Management System
              </div>
            </td>
          </tr>

          <!-- Main Body -->
          <tr>
            <td style="padding:36px 32px 28px;">
              <h1 style="font-size:1.35rem; font-weight:800; color:#0f172a; margin:0 0 12px; letter-spacing:-0.01em;">
                {$heading}
              </h1>
              <p style="font-size:0.92rem; line-height:1.6; color:#475569; margin:0 0 24px;">
                {$messageBody}
              </p>

              <!-- Action Button -->
              <table cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
                <tr>
                  <td align="center" style="background-color:#e53e3e; border-radius:10px;">
                    <a href="{$url}" target="_blank" style="display:inline-block; padding:12px 28px; font-size:0.92rem; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                      {$buttonText}
                    </a>
                  </td>
                </tr>
              </table>

              <p style="font-size:0.8rem; line-height:1.5; color:#64748b; margin:0 0 14px;">
                {$expiryText} If the button above does not work, copy and paste this address into your browser:
              </p>
              <div style="background-color:#f1f5f9; padding:12px 14px; border-radius:8px; font-family:monospace; font-size:0.75rem; color:#334155; word-break:break-all; margin:0 0 24px;">
                {$url}
              </div>

              <p style="font-size:0.78rem; line-height:1.5; color:#94a3b8; margin:0; border-top:1px solid #f1f5f9; padding-top:20px;">
                If you did not create an account or request this action, no further steps are necessary.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f8fafc; padding:20px 32px; border-top:1px solid #f1f5f9; text-align:center; font-size:0.75rem; color:#94a3b8;">
              &copy; 2026 VELO VRMS &bull; Regional Fleet Operations Hub
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Deliver authentication link (verification or password reset).
 * Dispatches real email if SMTP/mail is configured; also retains local session link fallback.
 */
function deliver_auth_link(string $kind, string $email, string $url): void
{
    error_log("VELO {$kind} link for {$email}: {$url}");

    // Always store session demo link for local development and offline convenience
    $_SESSION['demo_auth_link'] = $url;

    $isVerification = str_contains(strtolower($kind), 'verification');
    $subject = $isVerification ? 'Verify Your Email Address - VELO Car Rental' : 'Reset Your Password - VELO Car Rental';
    $html = build_auth_email_html($kind, $email, $url);

    $dispatch = send_system_mail($email, $subject, $html);
    $_SESSION['auth_mail_dispatch'] = [
        'mode'    => $dispatch['mode'],
        'success' => $dispatch['success'],
        'email'   => $email,
        'error'   => $dispatch['error'] ?? null,
    ];
}
