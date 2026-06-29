<?php
// apns.php - Apple Push Notification Service helper
//
// Usage:
// require_once __DIR__ . '/apns.php';
// sendApnsToCustomer($db, $customerId, 'Title', 'Body', ['type' => 'service_due']);
// sendApnsToDeviceToken($token, 'Title', 'Body', ['appointment_id' => 5]);

// Send a push notification to ALL active device tokens for a customer
function sendApnsToCustomer(PDO $db, int $customerId, string $title, string $body, array $extra = []): array
{
    $stmt = $db->prepare(
        "SELECT device_token FROM customer_device_tokens
         WHERE customer_id = ? AND is_active = 1"
    );
    $stmt->execute([$customerId]);
    $tokens  = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $results = [];

    foreach ($tokens as $token) {
        $result = sendApnsToDeviceToken($token, $title, $body, $extra);
        $results[$token] = $result;

 // Deactivate tokens Apple says are gone (410 = uninstalled)
        if (($result['http_code'] ?? 0) === 410) {
            $db->prepare(
                "UPDATE customer_device_tokens SET is_active = 0
                 WHERE device_token = ?"
            )->execute([$token]);
        }

 // Optionally log
        logApnsAttempt($db, $customerId, $token, $title, $body, $extra, $result);
    }

    return $results;
}

// Send a single push notification to one device token
function sendApnsToDeviceToken(string $deviceToken, string $title, string $body, array $extra = []): array
{
    $keyPath    = getenv('APNS_KEY_PATH')   ?: '/path/to/AuthKey.p8';
    $keyId      = getenv('APNS_KEY_ID')     ?: '';
    $teamId     = getenv('APNS_TEAM_ID')    ?: '';
    $bundleId   = getenv('APNS_BUNDLE_ID')  ?: 'com.yourcompany.mvpcustomer';
    $production = (getenv('APNS_ENVIRONMENT') ?: 'sandbox') === 'production';
    $host       = $production ? 'api.push.apple.com' : 'api.sandbox.push.apple.com';

 // Build payload
    $payload = json_encode([
        'aps' => [
            'alert' => ['title' => $title, 'body' => $body],
            'sound' => 'default',
            'badge' => 1,
            'mutable-content' => 1,
        ],
    ] + $extra);

 // Generate JWT for APNs
    $jwt = generateApnsJwt($keyPath, $keyId, $teamId);
    if (!$jwt) {
        return ['error' => 'Could not generate APNs JWT - check APNS_KEY_PATH, APNS_KEY_ID, APNS_TEAM_ID'];
    }

    $url = "https://{$host}/3/device/{$deviceToken}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "apns-topic: {$bundleId}",
            "apns-push-type: alert",
            "authorization: bearer {$jwt}",
            "Content-Type: application/json",
        ],
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response'  => $response,
        'curl_error'=> $curlError ?: null,
        'success'   => $httpCode === 200,
    ];
}

// Generate an APNs JWT signed with ES256 using the .p8 private key
function generateApnsJwt(string $keyPath, string $keyId, string $teamId): ?string
{
    if (!file_exists($keyPath)) {
        error_log("APNs: key file not found at {$keyPath}");
        return null;
    }

    $privateKey = openssl_pkey_get_private(file_get_contents($keyPath));
    if (!$privateKey) {
        error_log('APNs: failed to load private key');
        return null;
    }

    $header  = base64UrlEncode(json_encode(['alg' => 'ES256', 'kid' => $keyId]));
    $payload = base64UrlEncode(json_encode(['iss' => $teamId, 'iat' => time()]));

    $data = "{$header}.{$payload}";
    $signature = '';
    if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        error_log('APNs: openssl_sign failed');
        return null;
    }

    return "{$data}." . base64UrlEncode($signature);
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Log notification attempts to notification_logs table (if it exists)
function logApnsAttempt(
    PDO $db, int $customerId, string $token,
    string $title, string $body, array $extra,
    array $result
): void {
    try {
        $db->prepare(
            "INSERT INTO notification_logs
             (customer_id, device_token, notification_type, title, body, payload,
              status, response_code, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $customerId,
            $token,
            $extra['type'] ?? 'unknown',
            $title,
            $body,
            json_encode($extra),
            $result['success'] ? 'sent' : 'failed',
            $result['http_code'] ?? null,
        ]);
    } catch (Throwable $e) {
 // Table may not exist yet - fail silently
    }
}
