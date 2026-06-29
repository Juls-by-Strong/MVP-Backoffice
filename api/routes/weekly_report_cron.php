<?php
// Generates the Weekly Service Report PDF and emails it.
//
// Schedule via cron, e.g.:
// 0 18 * * 5 php /path/to/api/routes/weekly_report_cron.php
//
// (Runs every Friday at 6:00 PM server time)
//
// Recipients are loaded from the WEEKLY_REPORT_TO and WEEKLY_REPORT_CC
// environment variables.

define('CRON_MODE', true);

// Resolve paths relative to this file's location
$baseDir = __DIR__;

require_once $baseDir . '/../private/config.php';   // defines DB credentials
require_once $baseDir . '/weekly_report_pdf.php';
require_once $baseDir . '/gmail_service.php';

$today  = new \DateTime('today');
$dow    = (int)$today->format('N'); // 1=Mon…7=Sun
$monday = (clone $today)->modify('-' . ($dow - 1) . ' days');
$sunday = (clone $monday)->modify('+6 days');

$from = $monday->format('Y-m-d');
$to   = $sunday->format('Y-m-d');

function getCronDb(): PDO {
    $db = new \PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]
    );
    return $db;
}

try {
    $db      = getCronDb();
    $pdfBytes = generateWeeklyReportPdfBytes($db, $from, $to);
} catch (\Throwable $e) {
    error_log('[WeeklyReportCron] PDF generation failed: ' . $e->getMessage());
    exit(1);
}

$filename   = 'WeeklyServiceReport-' . $from . '-to-' . $to . '.pdf';
$subject    = 'Weekly Service Report - ' . date('M j', strtotime($from)) . ' to ' . date('M j, Y', strtotime($to));
$bodyText   = "Please find the weekly service report attached.\n\nPeriod: $from to $to\nGenerated: " . date('Y-m-d H:i:s');
$bodyHtml   = "
<div style='font-family:Arial,sans-serif;font-size:14px;color:#1a1a2e'>
  <p>Please find the <strong>Weekly Service Report</strong> attached.</p>
  <table style='border-collapse:collapse;margin:8px 0'>
    <tr><td style='color:#6b7a8d;padding-right:12px'>Period:</td><td><strong>$from</strong> to <strong>$to</strong></td></tr>
    <tr><td style='color:#6b7a8d;padding-right:12px'>Generated:</td><td>" . date('M j, Y g:i A') . "</td></tr>
  </table>
  <p style='color:#6b7a8d;font-size:12px;margin-top:16px'>Acme Water Service &mdash; MVP Backoffice " . MVP_VERSION . "</p>
</div>";

try {
    sendWeeklyReportEmail(
        to:          getenv('WEEKLY_REPORT_TO') ?: 'info@example.com',
        cc:          getenv('WEEKLY_REPORT_CC') ?: '',
        subject:     $subject,
        bodyText:    $bodyText,
        bodyHtml:    $bodyHtml,
        pdfBytes:    $pdfBytes,
        pdfFilename: $filename
    );
    error_log('[WeeklyReportCron] Report emailed successfully for ' . $from . ' to ' . $to);
    echo "OK: Weekly report emailed for $from to $to\n";
} catch (\Throwable $e) {
    error_log('[WeeklyReportCron] Email failed: ' . $e->getMessage());
    exit(1);
}

// Send email with a PDF attachment via Gmail service account
// Extends the pattern in gmail_service.php but adds:
// - CC header
// - multipart/mixed for PDF attachment
function sendWeeklyReportEmail(
    string $to,
    string $cc,
    string $subject,
    string $bodyText,
    string $bodyHtml,
    string $pdfBytes,
    string $pdfFilename
): void {
    $accessToken = getAccessToken(); // from gmail_service.php

    $boundary     = 'MixedBound_' . bin2hex(random_bytes(8));
    $altBoundary  = 'AltBound_'   . bin2hex(random_bytes(8));

    $pdfB64 = chunk_split(base64_encode($pdfBytes));

    $headers  = "From: info@example.com\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Cc: $cc\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";

    $body .= "--$altBoundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $bodyText . "\r\n\r\n";

    $body .= "--$altBoundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $bodyHtml . "\r\n\r\n";

    $body .= "--$altBoundary--\r\n\r\n";

    $body .= "--$boundary\r\n";
    $body .= "Content-Type: application/pdf; name=\"$pdfFilename\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"$pdfFilename\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= $pdfB64 . "\r\n";
    $body .= "--$boundary--";

    $rawMessage = base64UrlEncode($headers . $body);

    $payload = json_encode(['raw' => $rawMessage]);

    $ch = curl_init(GMAIL_SEND_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json",
        ],
    ]);

    $result = curl_exec($ch);
    $err    = curl_errno($ch);
    curl_close($ch);

    if ($err) throw new \Exception('Gmail send error: ' . curl_error($ch));

    $decoded = json_decode($result, true);
    if (!empty($decoded['error'])) {
        throw new \Exception('Gmail API error: ' . ($decoded['error']['message'] ?? $result));
    }
}
