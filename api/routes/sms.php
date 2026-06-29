<?php
// SMS scaffolding via Twilio - NOT yet wired to live routes
// When ready, add to index.php and admin.php routing
//
// POST /admin/sms/send - send a message to a customer

function handleSms(PDO $db, string $method): void {
    if ($method !== 'POST') sendError(405, 'Method not allowed');

    $settings = [];
    $stmt = $db->query("SELECT setting_key, setting_value FROM company_settings");
    foreach ($stmt->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];

    if (empty($settings['twilio_enabled']) || !$settings['twilio_enabled']) {
        sendError(503, 'SMS is not enabled. Configure Twilio credentials in Settings.');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $to   = $body['to']      ?? '';
    $msg  = $body['message'] ?? '';

    if (!$to || !$msg) sendError(400, 'to and message are required');

    $result = sendTwilioSms(
        $settings['twilio_account_sid'],
        $settings['twilio_auth_token'],
        $settings['twilio_from_number'],
        $to,
        $msg
    );

    sendJson($result);
}

function sendTwilioSms(string $sid, string $token, string $from, string $to, string $body): array {
    $url  = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $data = http_build_query(['To' => $to, 'From' => $from, 'Body' => $body]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$sid:$token",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true) ?? [];

    if ($httpCode >= 400) {
        throw new RuntimeException('Twilio error: ' . ($result['message'] ?? 'Unknown error'));
    }

    return ['message' => 'SMS sent', 'sid' => $result['sid'] ?? null];
}

// SMS TEMPLATE HELPERS (call from other routes when ready)
function smsInvoiceReady(array $settings, string $customerPhone, string $invoiceNum, float $total): bool {
    if (!$customerPhone) return false;
    $co  = $settings['company_name'] ?? 'Acme Water Service';
    $msg = "Hi! Your invoice $invoiceNum for \$$" . number_format($total, 2)
         . " from $co is ready. Reply STOP to opt out.";
    try {
        sendTwilioSms(
            $settings['twilio_account_sid'],
            $settings['twilio_auth_token'],
            $settings['twilio_from_number'],
            $customerPhone, $msg
        );
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function smsAppointmentConfirmation(array $settings, string $customerPhone, string $date, string $time, string $serviceType): bool {
    if (!$customerPhone) return false;
    $co  = $settings['company_name'] ?? 'Acme Water Service';
    $msg = "Your $serviceType appointment with $co is confirmed for $date at $time. Reply STOP to opt out.";
    try {
        sendTwilioSms(
            $settings['twilio_account_sid'],
            $settings['twilio_auth_token'],
            $settings['twilio_from_number'],
            $customerPhone, $msg
        );
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
