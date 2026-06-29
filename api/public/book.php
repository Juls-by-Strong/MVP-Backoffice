<?php
// Public lead booking form handler
// POST /book - no auth required
//
// Sends two emails:
// 1. Internal notification to NOTIFY_EMAIL
// 2. Confirmation to the lead (if email provided)

if (!defined('MVP_VERSION')) require_once __DIR__ . '/../config/version.php';
define('NOTIFY_EMAIL',   getenv('NOTIFY_EMAIL')   ?: 'info@example.com');
define('NOTIFY_NAME',    getenv('COMPANY_NAME')   ?: 'Acme Water Service');
define('COMPANY_PHONE',  getenv('COMPANY_PHONE')  ?: '555-555-5555');
define('CALLBACK_PHONE', getenv('CALLBACK_PHONE') ?: '(555) 555-5555');
define('COMPANY_NAME',   getenv('COMPANY_NAME')   ?: 'Acme Water Service');
define('COMPANY_WEB',    getenv('COMPANY_WEB')    ?: 'https://example.com');
define('ADMIN_URL',      getenv('ADMIN_URL')      ?: 'https://example.com');

function handleBooking(PDO $db, string $method): void {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $errors = [];
    $serviceType   = trim($body['service_type']   ?? '');
    $firstName     = trim($body['first_name']      ?? '');
    $lastName      = trim($body['last_name']       ?? '');
    $phone         = trim($body['phone']           ?? '');
    $email         = trim($body['email']           ?? '');
    $address       = trim($body['address']         ?? '');
    $city          = trim($body['city']            ?? '');
    $state         = trim($body['state']           ?? 'IL');
    $prefDate      = trim($body['preferred_date']  ?? '');
    $prefWindow    = trim($body['preferred_window'] ?? '');
    $referral      = trim($body['referral']        ?? '');
    $notes         = trim($body['notes']           ?? '');

    if (!$serviceType) $errors[] = 'service_type is required';
    if (!$firstName)   $errors[] = 'first_name is required';
    if (!$lastName)    $errors[] = 'last_name is required';
    if (preg_replace('/\D/', '', $phone) < 10) $errors[] = 'valid phone is required';
    if (!$address)     $errors[] = 'address is required';
    if (!$city)        $errors[] = 'city is required';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'invalid email format';

    if ($errors) {
        http_response_code(400);
        echo json_encode(['error' => implode('; ', $errors)]);
        exit;
    }

    $fullName    = $firstName . ' ' . $lastName;
    $fullAddress = $address . ', ' . $city . ', ' . $state;

    try {
        $db->prepare(
            "CREATE TABLE IF NOT EXISTS lead_requests (
                lead_id        INT AUTO_INCREMENT PRIMARY KEY,
                service_type   VARCHAR(100),
                first_name     VARCHAR(80),
                last_name      VARCHAR(80),
                phone          VARCHAR(30),
                email          VARCHAR(160),
                address        VARCHAR(200),
                city           VARCHAR(80),
                state          VARCHAR(10),
                preferred_date DATE NULL,
                preferred_window VARCHAR(40),
                referral       VARCHAR(80),
                notes          TEXT,
                submitted_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                converted      TINYINT(1) DEFAULT 0
            )"
        )->execute();

        $db->prepare(
            "INSERT INTO lead_requests
                (service_type, first_name, last_name, phone, email, address, city, state,
                 preferred_date, preferred_window, referral, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $serviceType, $firstName, $lastName, $phone, $email,
            $address, $city, $state,
            $prefDate ?: null, $prefWindow, $referral, $notes,
        ]);
        $leadId = (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[WAY book] DB error: ' . $e->getMessage());
        $leadId = 0;
    }

    require_once __DIR__ . '/settings.php';
    require_once __DIR__ . '/gmail_oauth.php';
    require_once __DIR__ . '/email.php';
    $settings = getCompanySettings($db);

    $svcColor = [
        'Free Water Test'                       => '#3B6D11',
        'Equipment Diagnostic'                  => '#185FA5',
        'Salt Delivery'                         => '#7C3AED',
        'Water Softener / Water Filter Service' => '#0891B2',
        'Reverse Osmosis Service'               => '#0891B2',
        'Hydrogen Peroxide Delivery'            => '#D97706',
        'Sales Inquiry / Contact Request'       => '#A32D2D',
    ][$serviceType] ?? '#185FA5';

    $sDate    = $prefDate   ? htmlspecialchars(date('l, F j, Y', strtotime($prefDate))) : 'Not specified';
    $sWindow  = $prefWindow ? htmlspecialchars($prefWindow) : 'No preference';
    $sRef     = $referral   ? htmlspecialchars($referral)   : 'Not specified';
    $sNotes   = $notes      ? nl2br(htmlspecialchars($notes)) : '<em style="color:#9ca3af">None provided</em>';
    $sEmail   = $email      ? '<a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a>' : 'Not provided';
    $sPhone   = htmlspecialchars($phone);
    $sName    = htmlspecialchars($fullName);
    $sAddr    = htmlspecialchars($fullAddress);
    $sSvcType = htmlspecialchars($serviceType);

 // Admin "Convert to Customer" deep-link
    $adminLink = ADMIN_URL . '/#customers/new?'
        . 'first_name=' . urlencode($firstName)
        . '&last_name='  . urlencode($lastName)
        . '&phone='      . urlencode($phone)
        . '&email='      . urlencode($email)
        . '&address='    . urlencode($address)
        . '&city='       . urlencode($city)
        . '&state='      . urlencode($state);

    $notifyHtml = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1E2640;max-width:620px;margin:0 auto;background:#f7f8fa;padding:0">'

 // Header
        . '<div style="background:#0C2D55;padding:28px 28px 20px;border-radius:10px 10px 0 0">'
        . '<p style="margin:0 0 6px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.55)">New Lead Request</p>'
        . '<h1 style="margin:0;font-size:22px;font-weight:400;color:#fff;font-family:Georgia,serif">' . $sName . '</h1>'
        . '<p style="margin:6px 0 0;font-size:14px;color:rgba(255,255,255,.65)">'
        . '<a href="tel:' . preg_replace('/\D/', '', $phone) . '" style="color:#B5D4F4;text-decoration:none">&#128222; ' . $sPhone . '</a>'
        . ($email ? ' &nbsp;&bull;&nbsp; <a href="mailto:' . htmlspecialchars($email) . '" style="color:#B5D4F4;text-decoration:none">&#9993; ' . htmlspecialchars($email) . '</a>' : '')
        . '</p>'
        . '</div>'

 // Service type badge
        . '<div style="background:#fff;padding:20px 28px 0;border-left:1px solid #EAEDF2;border-right:1px solid #EAEDF2">'
        . '<span style="display:inline-block;background:' . $svcColor . '1a;color:' . $svcColor . ';border:1px solid ' . $svcColor . '44;padding:5px 14px;border-radius:100px;font-size:13px;font-weight:600;margin-bottom:20px">'
        . $sSvcType . '</span>'
        . '</div>'

 // Details table
        . '<div style="background:#fff;padding:0 28px 24px;border-left:1px solid #EAEDF2;border-right:1px solid #EAEDF2">'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
        . _bookRow('Service Address', $sAddr)
        . _bookRow('Preferred Date',  $sDate)
        . _bookRow('Time Window',     $sWindow)
        . _bookRow('How They Found Us', $sRef)
        . '</table>'

 // Notes
        . '<div style="margin-top:16px;background:#f7f8fa;border-radius:8px;padding:14px 16px">'
        . '<p style="margin:0 0 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7694">Notes / Additional Info</p>'
        . '<p style="margin:0;font-size:14px;color:#1E2640;line-height:1.6">' . $sNotes . '</p>'
        . '</div>'

 // CTA button
        . '<div style="margin-top:24px;text-align:center;padding:20px;background:#E6F1FB;border-radius:8px">'
        . '<p style="margin:0 0 12px;font-size:13px;color:#185FA5;font-weight:600">Ready to add this lead as a customer?</p>'
        . '<a href="' . ADMIN_URL . '" style="display:inline-block;background:#378ADD;color:#fff;padding:11px 28px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px">Open MVP Backoffice &rarr;</a>'
        . '</div>'
        . '</div>'

 // Footer
        . '<div style="background:#1E2640;padding:16px 28px;border-radius:0 0 10px 10px;display:flex;justify-content:space-between;align-items:center">'
        . '<span style="font-size:12px;color:rgba(255,255,255,.4)">Generated from MVP Backoffice ' . MVP_VERSION . '</span>'
        . '<span style="font-size:12px;color:rgba(255,255,255,.4)">' . htmlspecialchars(COMPANY_NAME) . '</span>'
        . '</div>'

        . '</body></html>';

    $notifySubject = '[New Lead] ' . $serviceType . ' - ' . $fullName;
    $notifyPlain   = "New lead request\n\nName: $fullName\nPhone: $phone\nEmail: $email\nAddress: $fullAddress\nService: $serviceType\nPreferred Date: $sDate\nTime Window: $sWindow\nReferral: $sRef\nNotes: $notes\n\nOpen MVP Backoffice: " . ADMIN_URL;

    $confirmHtml = null;
    if ($email) {
        $confirmHtml = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1E2640;max-width:600px;margin:0 auto">'

            . '<div style="background:#0C2D55;padding:28px;border-radius:10px 10px 0 0">'
            . '<h1 style="margin:0 0 4px;font-size:22px;font-weight:400;color:#fff;font-family:Georgia,serif">We\'ve received your request!</h1>'
            . '<p style="margin:0;font-size:14px;color:rgba(255,255,255,.65)">Acme Water Service &mdash; Central Illinois</p>'
            . '</div>'

            . '<div style="padding:28px;border:1px solid #EAEDF2;border-top:none;border-radius:0 0 10px 10px">'
            . '<p style="font-size:15px;margin:0 0 16px">Hi ' . htmlspecialchars($firstName) . ',</p>'
            . '<p style="font-size:15px;line-height:1.7;margin:0 0 16px">Thanks for reaching out! We\'ve received your request for a <strong>' . $sSvcType . '</strong> and will be in touch shortly to confirm your appointment.</p>'

            . '<div style="background:#E6F1FB;border-radius:8px;padding:16px 20px;margin:20px 0">'
            . '<p style="margin:0 0 4px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#185FA5">Expect a call from</p>'
            . '<p style="margin:0;font-size:22px;font-weight:600;color:#0C2D55">' . CALLBACK_PHONE . '</p>'
            . '<p style="margin:4px 0 0;font-size:13px;color:#5A6480">Monday &ndash; Friday, 9am &ndash; 4pm</p>'
            . '</div>'

            . '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">'
            . _bookRow('Service Requested', $sSvcType)
            . _bookRow('Service Address',   $sAddr)
            . ($prefDate   ? _bookRow('Preferred Date',   $sDate)   : '')
            . ($prefWindow ? _bookRow('Time Window',       $sWindow) : '')
            . '</table>'

            . '<p style="font-size:14px;color:#5A6480;line-height:1.65;margin:0">If you need to reach us sooner, call <a href="tel:+15555555555" style="color:#378ADD">' . CALLBACK_PHONE . '</a> or visit <a href="' . COMPANY_WEB . '" style="color:#378ADD">example.com</a>.</p>'

            . '<p style="margin:24px 0 0;font-size:14px;color:#1E2640">Thank you for supporting a local Central Illinois business!</p>'
            . '<p style="margin:4px 0 0;font-size:14px;color:#1E2640">Best,<br><strong>The Acme Water Service Team</strong><br><a href="tel:+15555555555" style="color:#378ADD">' . CALLBACK_PHONE . '</a></p>'

            . '<p style="margin-top:32px;font-size:11px;color:#9ca3af;border-top:1px solid #EAEDF2;padding-top:16px">'
            . htmlspecialchars(COMPANY_NAME) . ' &bull; 123 Main Street, Anytown, ST 00000 &bull; '
            . '<a href="' . COMPANY_WEB . '" style="color:#9ca3af">' . COMPANY_WEB . '</a><br>'
            . 'Generated from MVP Backoffice ' . MVP_VERSION
            . '</p>'
            . '</div>'

            . '</body></html>';
    }

    $notifySent  = false;
    $confirmSent = false;

    if (function_exists('getValidGmailToken')) {
        $cfg   = getGmailConfig($db);
        $token = getValidGmailToken($db, $cfg);
        if ($token) {
            $from = $cfg['gmail_authorized_email'] ?? ($settings['company_email'] ?? NOTIFY_EMAIL);

            $notifySent = sendViaGmailApi(
                $token, $from,
                NOTIFY_EMAIL, NOTIFY_NAME,
                $notifySubject, $notifyHtml, $notifyPlain
            );

            if ($email && $confirmHtml) {
                $confirmSent = sendViaGmailApi(
                    $token, $from,
                    $email, $fullName,
                    'We received your request - Acme Water Service',
                    $confirmHtml, strip_tags($confirmHtml)
                );
            }
        }
    }

    if (!$notifySent && !empty($settings['smtp_pass'])) {
        $host = $settings['smtp_host']      ?? 'smtp.gmail.com';
        $port = (int)($settings['smtp_port']  ?? 587);
        $user = $settings['smtp_user']      ?? ($settings['company_email'] ?? NOTIFY_EMAIL);
        $pass = $settings['smtp_pass'];
        $from = $settings['company_email']  ?? NOTIFY_EMAIL;
        $name = $settings['smtp_from_name'] ?? COMPANY_NAME;

        $notifySent = sendViaSMTP(NOTIFY_EMAIL, NOTIFY_NAME, $notifySubject, $notifyHtml, $host, $port, $user, $pass, $from, $name);

        if ($email && $confirmHtml) {
            $confirmSent = sendViaSMTP($email, $fullName,
                'We received your request - Acme Water Service',
                $confirmHtml, $host, $port, $user, $pass, $from, $name);
        }
    }

    if (!$notifySent) {
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . COMPANY_NAME . " <" . NOTIFY_EMAIL . ">\r\n";
        $notifySent = (bool)mail(NOTIFY_EMAIL, $notifySubject, $notifyHtml, $headers);
        if ($email && $confirmHtml) {
            $confirmSent = (bool)mail($email, 'We received your request - Acme Water Service', $confirmHtml, $headers);
        }
    }

    http_response_code(200);
    echo json_encode([
        'success'       => true,
        'message'       => 'Request received',
        'notify_sent'   => $notifySent,
        'confirm_sent'  => $confirmSent,
        'lead_id'       => $leadId,
    ]);
    exit;
}

function _bookRow(string $label, string $value): string {
    return '<tr>'
        . '<td style="padding:8px 0;border-bottom:1px solid #EAEDF2;font-weight:600;color:#5A6480;font-size:12px;text-transform:uppercase;letter-spacing:.04em;width:38%;vertical-align:top">'
        . htmlspecialchars($label) . '</td>'
        . '<td style="padding:8px 0 8px 12px;border-bottom:1px solid #EAEDF2;color:#1E2640;font-size:14px">'
        . $value . '</td>'
        . '</tr>';
}
