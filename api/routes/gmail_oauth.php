<?php
//
// Gmail OAuth 2.0 - replaces SMTP app-password authentication.
// Required because Google no longer issues App Passwords for
// accounts that don't have 2-Step Verification enabled.
//
// Endpoints (all under /admin/gmail/):
// GET /admin/gmail/status - connection status
// GET /admin/gmail/auth-url - get Google consent URL
// GET /admin/gmail/callback - OAuth callback (popup closes itself)
// POST /admin/gmail/disconnect - revoke + clear tokens
// POST /admin/gmail/send-test - send a test message via Gmail API
//
// Public (no auth prefix):
// GET /gmail/callback - OAuth redirect target
//
// Storage keys in company_settings:
// gmail_client_id
// gmail_client_secret
// gmail_access_token
// gmail_refresh_token
// gmail_token_expires (unix timestamp)
// gmail_authorized_email (the account that granted access)
// gmail_enabled ('1' / '0')

define('GMAIL_AUTH_URL',   'https://accounts.google.com/o/oauth2/v2/auth');
define('GMAIL_TOKEN_URL',  'https://oauth2.googleapis.com/token');
define('GMAIL_REVOKE_URL', 'https://oauth2.googleapis.com/revoke');
define('GMAIL_SEND_URL',   'https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
define('GMAIL_INFO_URL',   'https://www.googleapis.com/oauth2/v3/userinfo');
// Scope: send email on behalf of the user
define('GMAIL_SCOPE',      'https://www.googleapis.com/auth/gmail.send');

function handleGmailOauth(string $method, ?string $sub): void {
    $db = getDB();

 // Callback is public - no auth required
    if ($sub === 'callback' && $method === 'GET') {
        handleGmailCallback($db);
        return;
    }

    requireRole('admin');
    $cfg = getGmailConfig($db);

    switch ($sub) {

        case 'status':
            if ($method !== 'GET') sendError(405, 'GET only');
 // Try a silent token refresh if expired
            if (!empty($cfg['gmail_access_token']) && (int)$cfg['gmail_token_expires'] < time()) {
                refreshGmailToken($db, $cfg);
                $cfg = getGmailConfig($db);
            }
            $connected = !empty($cfg['gmail_access_token'])
                && $cfg['gmail_enabled'] === '1'
                && (int)$cfg['gmail_token_expires'] > time();
            sendJson([
                'connected'        => $connected,
                'authorized_email' => $cfg['gmail_authorized_email'] ?? null,
                'expires_at'       => $cfg['gmail_token_expires']
                    ? date('Y-m-d H:i:s', (int)$cfg['gmail_token_expires'])
                    : null,
            ]);
            break;

        case 'auth-url':
            if ($method !== 'GET') sendError(405, 'GET only');
            if (empty($cfg['gmail_client_id'])) {
                sendError(400, 'Gmail Client ID not saved - enter your Client ID and click Save first');
            }
            $state    = bin2hex(random_bytes(16));
            $redirect = getGmailCallbackUrl();
            $url = GMAIL_AUTH_URL . '?' . http_build_query([
                'client_id'             => $cfg['gmail_client_id'],
                'redirect_uri'          => $redirect,
                'response_type'         => 'code',
                'scope'                 => GMAIL_SCOPE,
                'access_type'           => 'offline',   // gives us a refresh token
                'prompt'                => 'consent',   // force consent so refresh token is always issued
                'state'                 => $state,
            ]);
            sendJson(['url' => $url, 'redirect_uri' => $redirect]);
            break;

        case 'disconnect':
            if ($method !== 'POST') sendError(405, 'POST only');
            if (!empty($cfg['gmail_access_token'])) {
 // Best-effort revoke
                @file_get_contents(
                    GMAIL_REVOKE_URL . '?token=' . urlencode($cfg['gmail_access_token'])
                );
            }
            foreach (['gmail_access_token','gmail_refresh_token','gmail_token_expires','gmail_authorized_email'] as $k) {
                updateSetting($db, $k, '');
            }
            updateSetting($db, 'gmail_enabled', '0');
            sendJson(['message' => 'Gmail disconnected']);
            break;

        case 'send-test':
            if ($method !== 'POST') sendError(405, 'POST only');
            $body  = json_decode(file_get_contents('php://input'), true) ?? [];
            $to    = trim($body['to'] ?? '');
            if (!$to) sendError(400, 'to address is required');

            $token = getValidGmailToken($db, $cfg);
            if (!$token) sendError(503, 'Gmail not connected or token refresh failed');

            $coName  = getCompanySettings($db)['company_name'] ?? 'Acme Water Service';
            $subject = 'Test email from ' . $coName;
            $text    = "This is a test email confirming Gmail API sending is working correctly.\n\nSent by $coName CRM.";
            $html    = "<p>This is a test email confirming Gmail API sending is working correctly.</p><p>Sent by <strong>$coName</strong> CRM.</p>";

            $sent = sendViaGmailApi(
                $token,
                $cfg['gmail_authorized_email'] ?? '',
                $to, $to, $subject, $html, $text
            );
            if ($sent) {
                sendJson(['message' => 'Test email sent to ' . $to]);
            } else {
                sendError(500, 'Gmail API send failed - check server error log');
            }
            break;

        default:
            sendError(404, 'Gmail endpoint not found: ' . ($sub ?? ''));
    }
}

function handleGmailCallback(PDO $db): void {
    header('Content-Type: text/html');

    $sendResult = function(string $status, string $message) {
        $safe = htmlspecialchars($message, ENT_QUOTES);
        echo "<!DOCTYPE html><html><head><title>Gmail Auth</title></head><body>
<script>
try {
    window.opener && window.opener.postMessage(
        { gmail: '{$status}', message: '{$safe}' }, '*'
    );
} catch(e) {}
setTimeout(function(){ window.close(); }, 1500);
</script>
<p style='font-family:sans-serif;padding:40px;text-align:center'>{$safe}</p>
</body></html>";
        exit;
    };

    $error = $_GET['error'] ?? '';
    $code  = $_GET['code']  ?? '';

    if ($error) {
        $sendResult('error', 'Google returned an error: ' . htmlspecialchars($error));
    }
    if (!$code) {
        $sendResult('error', 'No authorization code received');
    }

    $cfg      = getGmailConfig($db);
    $redirect = getGmailCallbackUrl();

 // Exchange code for tokens
    $ch = curl_init(GMAIL_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => $cfg['gmail_client_id']     ?? '',
            'client_secret' => $cfg['gmail_client_secret'] ?? '',
            'redirect_uri'  => $redirect,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $tokens = json_decode($resp ?: '{}', true) ?? [];

    if (empty($tokens['access_token'])) {
        $detail = $tokens['error_description'] ?? $tokens['error'] ?? 'Unknown error';
        $sendResult('error', 'Token exchange failed: ' . $detail);
    }

    $expires = time() + (int)($tokens['expires_in'] ?? 3600) - 60;
    updateSetting($db, 'gmail_access_token',  $tokens['access_token']);
    updateSetting($db, 'gmail_token_expires', (string)$expires);
    if (!empty($tokens['refresh_token'])) {
        updateSetting($db, 'gmail_refresh_token', $tokens['refresh_token']);
    }
    updateSetting($db, 'gmail_enabled', '1');

 // Fetch authorized email via userinfo
    $email = '';
    try {
        $ch2 = curl_init(GMAIL_INFO_URL);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokens['access_token']],
        ]);
        $info = json_decode(curl_exec($ch2) ?: '{}', true) ?? [];
        curl_close($ch2);
        $email = $info['email'] ?? '';
        if ($email) updateSetting($db, 'gmail_authorized_email', $email);
    } catch (\Throwable $e) { /* non-fatal */ }

    $sendResult('connected', 'Gmail connected' . ($email ? ' as ' . $email : '') . '. This window will close.');
}

function refreshGmailToken(PDO $db, array $cfg): bool {
    if (empty($cfg['gmail_refresh_token'])) return false;

    $ch = curl_init(GMAIL_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => http_build_query([
            'refresh_token' => $cfg['gmail_refresh_token'],
            'client_id'     => $cfg['gmail_client_id']     ?? '',
            'client_secret' => $cfg['gmail_client_secret'] ?? '',
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $tokens = json_decode($resp ?: '{}', true) ?? [];

    if (empty($tokens['access_token'])) {
        error_log('[WAY Gmail] Token refresh failed: ' . ($tokens['error'] ?? 'unknown'));
        return false;
    }

    $expires = time() + (int)($tokens['expires_in'] ?? 3600) - 60;
    updateSetting($db, 'gmail_access_token',  $tokens['access_token']);
    updateSetting($db, 'gmail_token_expires', (string)$expires);
    return true;
}

// Returns a valid access token, refreshing if needed. Returns null if unavailable.
function getValidGmailToken(PDO $db, array $cfg): ?string {
    if (empty($cfg['gmail_access_token']) || $cfg['gmail_enabled'] !== '1') return null;

 // Still valid
    if ((int)$cfg['gmail_token_expires'] > time()) {
        return $cfg['gmail_access_token'];
    }

 // Expired - try to refresh
    if (refreshGmailToken($db, $cfg)) {
        $fresh = getGmailConfig($db);
        return $fresh['gmail_access_token'] ?? null;
    }

    return null;
}

// Sends a multipart/alternative message (plain + HTML) via the
// Gmail REST API using an OAuth access token. No SMTP involved.
function sendViaGmailApi(
    string $accessToken,
    string $fromEmail,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainBody
): bool {
    $boundary  = '=_GmailPart_' . md5(uniqid('', true));
    $messageId = '<way-' . uniqid('', true) . '@gmail.com>';

 // Build RFC 2822 message
    $raw  = "From: $fromEmail\r\n";
    $raw .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <$toEmail>\r\n";
    $raw .= "Subject: " . mb_encode_mimeheader($subject, 'UTF-8', 'B') . "\r\n";
    $raw .= "Message-ID: $messageId\r\n";
    $raw .= "Date: " . date('r') . "\r\n";
    $raw .= "MIME-Version: 1.0\r\n";
    $raw .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $raw .= "X-Mailer: WAY-CRM\r\n";
    $raw .= "\r\n";

    $raw .= "--$boundary\r\n";
    $raw .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $raw .= "Content-Transfer-Encoding: quoted-printable\r\n";
    $raw .= "\r\n";
    $raw .= quoted_printable_encode($plainBody) . "\r\n";

    $raw .= "--$boundary\r\n";
    $raw .= "Content-Type: text/html; charset=UTF-8\r\n";
    $raw .= "Content-Transfer-Encoding: quoted-printable\r\n";
    $raw .= "\r\n";
    $raw .= quoted_printable_encode($htmlBody) . "\r\n";

    $raw .= "--$boundary--\r\n";

 // Gmail API wants URL-safe base64 with no padding '='
    $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    $ch = curl_init(GMAIL_SEND_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['raw' => $encoded]),
    ]);
    $resp    = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('[WAY Gmail] curl error: ' . $curlErr);
        return false;
    }
    if ($status < 200 || $status >= 300) {
        error_log('[WAY Gmail] API error ' . $status . ': ' . substr($resp, 0, 300));
        return false;
    }
    return true;
}

function getGmailConfig(PDO $db): array {
    $stmt = $db->query(
        "SELECT setting_key, setting_value FROM company_settings WHERE setting_key LIKE 'gmail_%'"
    );
    $cfg = [];
    foreach ($stmt->fetchAll() as $row) $cfg[$row['setting_key']] = $row['setting_value'];
    return $cfg;
}

function getGmailCallbackUrl(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host . '/api/public/gmail/callback';
}

function sendViaGmailApiWithAttachment(
    string $accessToken,
    string $fromEmail,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainBody,
    string $pdfBytes,
    string $pdfFilename
): bool {
    $outerBoundary = '=_MixedPart_'  . md5(uniqid('', true));
    $altBoundary   = '=_AltPart_'    . md5(uniqid('', true));
    $messageId     = '<way-' . uniqid('', true) . '@gmail.com>';

    $raw  = "From: $fromEmail\r\n";
    $raw .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <$toEmail>\r\n";
    $raw .= "Subject: " . mb_encode_mimeheader($subject, 'UTF-8', 'B') . "\r\n";
    $raw .= "Message-ID: $messageId\r\n";
    $raw .= "Date: " . date('r') . "\r\n";
    $raw .= "MIME-Version: 1.0\r\n";
    $raw .= "Content-Type: multipart/mixed; boundary=\"$outerBoundary\"\r\n";
    $raw .= "X-Mailer: WAY-CRM\r\n\r\n";

    $raw .= "--$outerBoundary\r\n";
    $raw .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";

    $raw .= "--$altBoundary\r\n";
    $raw .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $raw .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $raw .= quoted_printable_encode($plainBody) . "\r\n";

    $raw .= "--$altBoundary\r\n";
    $raw .= "Content-Type: text/html; charset=UTF-8\r\n";
    $raw .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $raw .= quoted_printable_encode($htmlBody) . "\r\n";
    $raw .= "--$altBoundary--\r\n";

    $safeName   = preg_replace('/[^A-Za-z0-9\-_\.]/', '_', $pdfFilename);
    $encodedPdf = chunk_split(base64_encode($pdfBytes), 76, "\r\n");
    $raw .= "--$outerBoundary\r\n";
    $raw .= "Content-Type: application/pdf; name=\"$safeName\"\r\n";
    $raw .= "Content-Transfer-Encoding: base64\r\n";
    $raw .= "Content-Disposition: attachment; filename=\"$safeName\"\r\n\r\n";
    $raw .= $encodedPdf;
    $raw .= "--$outerBoundary--\r\n";

    $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    $ch = curl_init(GMAIL_SEND_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['raw' => $encoded]),
    ]);
    $resp    = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) { error_log('[WAY Gmail] attachment curl error: ' . $curlErr); return false; }
    if ($status < 200 || $status >= 300) {
        error_log('[WAY Gmail] attachment API error ' . $status . ': ' . substr($resp, 0, 300));
        return false;
    }
    return true;
}
