<?php
// Gmail Service Account Mailer (Workspace Domain Delegation)
// For automatic system emails (appointments, invoices, receipts)

define('GMAIL_SEND_URL', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
define('GMAIL_SCOPE', 'https://www.googleapis.com/auth/gmail.send');

define('GMAIL_SERVICE_ACCOUNT_FILE', getenv('GMAIL_SERVICE_ACCOUNT_FILE') ?: __DIR__ . '/../private/service-account.json');
define('GMAIL_IMPERSONATE_USER', getenv('GMAIL_IMPERSONATE_USER') ?: '');

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getAccessToken(): string {

    $json = json_decode(file_get_contents(GMAIL_SERVICE_ACCOUNT_FILE), true);

    $header = base64UrlEncode(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT'
    ]));

    $now = time();

    $claims = base64UrlEncode(json_encode([
        'iss'   => $json['client_email'],
        'scope' => GMAIL_SCOPE,
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
        'sub'   => GMAIL_IMPERSONATE_USER
    ]));

    $signatureInput = $header . '.' . $claims;

    openssl_sign(
        $signatureInput,
        $signature,
        $json['private_key'],
        'sha256WithRSAEncryption'
    );

    $jwt = $signatureInput . '.' . base64UrlEncode($signature);

    $post = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($response['access_token'])) {
        throw new Exception('Failed to obtain Gmail access token');
    }

    return $response['access_token'];
}

function sendSystemEmail($to, $subject, $bodyText, $bodyHtml = null) {

    $accessToken = getAccessToken();

    $boundary = uniqid(rand(), true);

    $headers  = "From: " . GMAIL_IMPERSONATE_USER . "\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if ($bodyHtml) {
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";

        $message  = "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message .= $bodyText . "\r\n\r\n";

        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $message .= $bodyHtml . "\r\n\r\n";

        $message .= "--$boundary--";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message = $bodyText;
    }

    $rawMessage = base64UrlEncode($headers . $message);

    $payload = json_encode([
        'raw' => $rawMessage
    ]);

    $ch = curl_init(GMAIL_SEND_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ]
    ]);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }

    curl_close($ch);

    return $result;
}
