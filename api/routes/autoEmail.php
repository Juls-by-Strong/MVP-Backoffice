<?php
// Automated transactional emails: confirmation, reminder, service-due, invoice completion.
// Loads after: gmail_oauth.php, email.php (for buildLineTable, sendViaSMTP*), settings.php

// TEMPLATE ENGINE

function getEmailTemplate(PDO $db, string $type): array {
    $stmt = $db->prepare(
        "SELECT setting_key, setting_value FROM company_settings
         WHERE setting_key IN (?, ?)"
    );
    $stmt->execute(["tpl_{$type}_subject", "tpl_{$type}_body"]);
    $rows = $stmt->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    return [
        'subject' => $out["tpl_{$type}_subject"] ?? '',
        'body'    => $out["tpl_{$type}_body"]    ?? '',
    ];
}

function applyTemplate(string $template, array $vars): string {
    foreach ($vars as $key => $value) {
        $template = str_replace('{{' . $key . '}}', (string)$value, $template);
    }
    return preg_replace('/\{\{[a-z_]+\}\}/', '', $template);
}

function wrapEmailBody(string $bodyHtml, string $co, string $ph, string $web, string $title = ''): string {
    $sCo  = htmlspecialchars($co);
    $sPh  = htmlspecialchars($ph);
    $sWeb = htmlspecialchars($web);
    $sH   = $title ? htmlspecialchars($title) : $sCo;
    return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto">'
         . '<div style="background:#0d9488;padding:24px;color:#fff;border-radius:8px 8px 0 0">'
         . "<h1 style='margin:0;font-size:22px'>{$sH}</h1>"
         . "<p style='margin:4px 0 0;opacity:.85'>{$sCo}" . ($sPh ? " &nbsp;|&nbsp; {$sPh}" : '') . '</p>'
         . '</div>'
         . '<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px">'
         . $bodyHtml
         . "<p style='margin-top:32px;color:#6b7280;font-size:12px'>{$sCo}"
         . ($sPh  ? " &nbsp;&bull;&nbsp; {$sPh}"  : '')
         . ($sWeb ? " &nbsp;&bull;&nbsp; {$sWeb}" : '')
         . '</p></div></body></html>';
}

// SEND: APPOINTMENT CONFIRMATION
function sendConfirmationEmail(PDO $db, int $appointmentId): bool {
    $appt = getAppointmentEmailData($db, $appointmentId);
    if (!$appt || !$appt['customer_email']) return false;

    $settings = getCompanySettings($db);
    $co  = $settings['company_name']  ?? 'Acme Water Service';
    $ph  = $settings['company_phone'] ?? '';
    $web = $settings['company_website'] ?? '';

    $date = date('l, F j, Y', strtotime($appt['confirmed_date'] ?? 'today'));
    $time = $appt['confirmed_time'] ? date('g:i A', strtotime($appt['confirmed_time'])) : '';

    $vars = [
        'first_name'    => htmlspecialchars($appt['first_name'] ?? 'Customer'),
        'service_type'  => htmlspecialchars($appt['service_type'] ?? 'Service'),
        'date'          => $date,
        'time'          => $time ? "at $time" : '',
        'technician'    => htmlspecialchars($appt['technician_name'] ?? 'our team'),
        'address'       => htmlspecialchars($appt['service_address'] ?? ''),
        'company_name'  => htmlspecialchars($co),
        'company_phone' => htmlspecialchars($ph),
        'company_web'   => htmlspecialchars($web),
    ];

    $tpl     = getEmailTemplate($db, 'confirmation');
    $subject = $tpl['subject'] ? applyTemplate($tpl['subject'], $vars)
        : 'Appointment Confirmed - ' . $vars['service_type'] . ' on ' . $date;
    $body    = $tpl['body']    ? applyTemplate($tpl['body'], $vars)
        : _defaultConfirmationBody($vars);
    $html    = wrapEmailBody($body, $co, $ph, $web, ' Appointment Confirmed');

    return dispatchEmail($db, $settings, $appt['customer_email'], $appt['customer_name'],
                         $subject, $html, 'confirmation', null, $appointmentId);
}

function _defaultConfirmationBody(array $v): string {
    return "<p>Hi {$v['first_name']},</p>"
         . "<p>Your <strong>{$v['service_type']}</strong> appointment has been confirmed!</p>"
         . "<div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;margin:16px 0'>"
         . "<p style='margin:0 0 8px'><strong> Date:</strong> {$v['date']} {$v['time']}</p>"
         . "<p style='margin:0 0 8px'><strong> Service:</strong> {$v['service_type']}</p>"
         . "<p style='margin:0'><strong> Location:</strong> {$v['address']}</p></div>"
         . "<p>Your technician will be <strong>{$v['technician']}</strong>.</p>"
         . "<p>We'll send a reminder the day before. To reschedule call <strong>{$v['company_phone']}</strong>.</p>";
}

// SEND: APPOINTMENT REMINDER (24h before)
function sendReminderEmail(PDO $db, int $appointmentId): bool {
    $appt = getAppointmentEmailData($db, $appointmentId);
    if (!$appt || !$appt['customer_email']) return false;

    $settings = getCompanySettings($db);
    $co  = $settings['company_name']  ?? 'Acme Water Service';
    $ph  = $settings['company_phone'] ?? '';
    $web = $settings['company_website'] ?? '';

    $date = date('l, F j, Y', strtotime($appt['confirmed_date']));
    $time = $appt['confirmed_time']
        ? date('g:i A', strtotime($appt['confirmed_time']))
        : 'during your scheduled window';

    $vars = [
        'first_name'    => htmlspecialchars($appt['first_name'] ?? 'Customer'),
        'service_type'  => htmlspecialchars($appt['service_type'] ?? 'Service'),
        'date'          => $date,
        'time'          => $time,
        'technician'    => htmlspecialchars($appt['technician_name'] ?? 'our team'),
        'address'       => htmlspecialchars($appt['service_address'] ?? ''),
        'company_name'  => htmlspecialchars($co),
        'company_phone' => htmlspecialchars($ph),
        'company_web'   => htmlspecialchars($web),
    ];

    $tpl     = getEmailTemplate($db, 'reminder');
    $subject = $tpl['subject'] ? applyTemplate($tpl['subject'], $vars)
        : 'Reminder: ' . $vars['service_type'] . ' Appointment Tomorrow - ' . $date;
    $body    = $tpl['body']    ? applyTemplate($tpl['body'], $vars)
        : _defaultReminderBody($vars);
    $html    = wrapEmailBody($body, $co, $ph, $web, '⏰ Appointment Reminder');

    return dispatchEmail($db, $settings, $appt['customer_email'], $appt['customer_name'],
                         $subject, $html, 'reminder', null, $appointmentId);
}

function _defaultReminderBody(array $v): string {
    return "<p>Hi {$v['first_name']},</p>"
         . "<p>This is a friendly reminder that your appointment is <strong>tomorrow</strong>!</p>"
         . "<div style='background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:16px;margin:16px 0'>"
         . "<p style='margin:0 0 8px'><strong> Date:</strong> {$v['date']}</p>"
         . "<p style='margin:0 0 8px'><strong> Time:</strong> {$v['time']}</p>"
         . "<p style='margin:0 0 8px'><strong> Service:</strong> {$v['service_type']}</p>"
         . "<p style='margin:0 0 8px'><strong> Technician:</strong> {$v['technician']}</p>"
         . "<p style='margin:0'><strong> Location:</strong> {$v['address']}</p></div>"
         . "<p>Please ensure someone is available. To reschedule call <strong>{$v['company_phone']}</strong> ASAP.</p>";
}

// SEND: INSTALL REMINDER (7-day / 3-day / 1-day cadence)
// Fires for appointments on service types flagged extended_reminders=1.
// $daysOut must be 7, 3, or 1. Each cadence dedupes independently in
// email_log via its own email_type tag.
function sendInstallReminderEmail(PDO $db, int $appointmentId, int $daysOut): bool {
    $appt = getAppointmentEmailData($db, $appointmentId);
    if (!$appt || !$appt['customer_email']) return false;

 // Map cadence to phrasing + tag (no em dashes in copy)
    $cadence = [
        7 => ['when' => 'one week away',  'tag' => 'install_reminder_7d', 'tplKey' => 'install_reminder_7d'],
        3 => ['when' => 'in 3 days',      'tag' => 'install_reminder_3d', 'tplKey' => 'install_reminder_3d'],
        1 => ['when' => 'tomorrow',       'tag' => 'install_reminder_1d', 'tplKey' => 'install_reminder_1d'],
    ];
    if (!isset($cadence[$daysOut])) return false;
    $c = $cadence[$daysOut];

    $settings = getCompanySettings($db);
    $co  = $settings['company_name']  ?? 'Acme Water Service';
    $ph  = $settings['company_phone'] ?? '';
    $web = $settings['company_website'] ?? '';

    $date = date('l, F j, Y', strtotime($appt['confirmed_date']));
    $time = $appt['confirmed_time']
        ? date('g:i A', strtotime($appt['confirmed_time']))
        : 'during your scheduled window';

    $vars = [
        'first_name'    => htmlspecialchars($appt['first_name'] ?? 'Customer'),
        'service_type'  => htmlspecialchars($appt['service_type'] ?? 'Installation'),
        'date'          => $date,
        'time'          => $time,
        'technician'    => htmlspecialchars($appt['technician_name'] ?? 'our team'),
        'address'       => htmlspecialchars($appt['service_address'] ?? ''),
        'when'          => $c['when'],
        'days_out'      => (string)$daysOut,
        'company_name'  => htmlspecialchars($co),
        'company_phone' => htmlspecialchars($ph),
        'company_web'   => htmlspecialchars($web),
    ];

    $tpl     = getEmailTemplate($db, $c['tplKey']);
    $subject = $tpl['subject'] ? applyTemplate($tpl['subject'], $vars)
        : 'Your ' . $vars['service_type'] . ' is ' . $c['when'] . ' (' . $date . ')';
    $body    = $tpl['body']    ? applyTemplate($tpl['body'], $vars)
        : _defaultInstallReminderBody($vars);
    $html    = wrapEmailBody($body, $co, $ph, $web, ' Installation Reminder');

    return dispatchEmail($db, $settings, $appt['customer_email'], $appt['customer_name'],
                         $subject, $html, $c['tag'], null, $appointmentId);
}

function _defaultInstallReminderBody(array $v): string {
    return "<p>Hi {$v['first_name']},</p>"
         . "<p>Just a heads-up that your <strong>{$v['service_type']}</strong> appointment is <strong>{$v['when']}</strong>.</p>"
         . "<div style='background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px;margin:16px 0'>"
         . "<p style='margin:0 0 8px'><strong> Date:</strong> {$v['date']}</p>"
         . "<p style='margin:0 0 8px'><strong> Time:</strong> {$v['time']}</p>"
         . "<p style='margin:0 0 8px'><strong> Service:</strong> {$v['service_type']}</p>"
         . "<p style='margin:0 0 8px'><strong> Technician:</strong> {$v['technician']}</p>"
         . "<p style='margin:0'><strong> Location:</strong> {$v['address']}</p></div>"
         . "<p>A few things that help us get in and out quickly:</p>"
         . "<ul style='margin:8px 0 16px 20px;padding:0'>"
         . "<li>Please clear a path to the install location</li>"
         . "<li>Know where your main water shut-off is, in case we need it</li>"
         . "<li>Someone 18 or older should be available the day of</li>"
         . "</ul>"
         . "<p>Need to reschedule? Call us at <strong>{$v['company_phone']}</strong> as soon as you can.</p>";
}

// SEND: SERVICE DUE REMINDER (~30-day advance, opt-in only)
function sendServiceReminderEmail(PDO $db, int $customerId, int $equipmentId): bool {
    $stmt = $db->prepare(
        "SELECT c.customer_id, c.first_name, c.last_name, c.email,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                et.type_name AS equipment_type,
                e.next_service_due, e.model
         FROM customers c
         JOIN equipment e        ON e.customer_id  = c.customer_id
         JOIN equipment_types et ON e.type_id      = et.type_id
         WHERE c.customer_id  = ?
           AND e.equipment_id = ?
           AND c.do_not_service = 0
           AND c.email IS NOT NULL AND c.email != ''"
    );
    $stmt->execute([$customerId, $equipmentId]);
    $row = $stmt->fetch();
    if (!$row) return false;

    $settings = getCompanySettings($db);
    $co  = $settings['company_name']  ?? 'Acme Water Service';
    $ph  = $settings['company_phone'] ?? '';
    $web = $settings['company_website'] ?? '';

    $dueDate  = $row['next_service_due'] ? date('F j, Y', strtotime($row['next_service_due'])) : 'soon';
    $equipStr = $row['equipment_type'] . ($row['model'] ? ' (' . $row['model'] . ')' : '');

    $vars = [
        'first_name'     => htmlspecialchars($row['first_name'] ?? 'Customer'),
        'equipment_type' => htmlspecialchars($equipStr),
        'due_date'       => $dueDate,
        'company_name'   => htmlspecialchars($co),
        'company_phone'  => htmlspecialchars($ph),
        'company_web'    => htmlspecialchars($web),
    ];

    $tpl     = getEmailTemplate($db, 'service_reminder');
    $subject = $tpl['subject'] ? applyTemplate($tpl['subject'], $vars)
        : 'Time to Schedule Your ' . $vars['equipment_type'] . ' Service - ' . htmlspecialchars($co);
    $body    = $tpl['body']    ? applyTemplate($tpl['body'], $vars)
        : _defaultServiceReminderBody($vars);
    $html    = wrapEmailBody($body, $co, $ph, $web, 'Service Reminder - ' . htmlspecialchars($co));

    return dispatchEmail($db, $settings, $row['email'], $row['customer_name'],
                         $subject, $html, 'service_reminder', null, null);
}

function _defaultServiceReminderBody(array $v): string {
    return "<p>Hi {$v['first_name']},</p>"
         . "<p>Just a heads-up - your <strong>{$v['equipment_type']}</strong> is due for service around <strong>{$v['due_date']}</strong>.</p>"
         . "<p>Give us a call at <strong>{$v['company_phone']}</strong> or reply to this email to schedule at your convenience.</p>"
         . "<p>Regular service keeps your system running at peak performance and helps avoid costly repairs.</p>"
         . "<p>Thank you for being a valued customer!</p>";
}

// SEND: INVOICE COMPLETION (job marked complete)
// Uses invoice template + full line table + PDF attachment
function sendCompletionInvoiceEmail(PDO $db, int $invoiceId, int $appointmentId): bool {
    $settings = getCompanySettings($db);

 // Full invoice + customer + service type data
    $stmt = $db->prepare(
        "SELECT i.*,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                c.company_name,
                COALESCE(NULLIF(a.notification_email_override,''), c.email) AS customer_email,
                c.first_name,
                c.last_name, c.phone AS customer_phone,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.user_id AS customer_user_id,
                st.name AS service_type,
                a.confirmed_date,
                CONCAT(tech.first_name,' ',tech.last_name) AS technician_name
         FROM invoices i
         JOIN customers c        ON i.customer_id     = c.customer_id
         LEFT JOIN appointments a    ON i.appointment_id  = a.appointment_id
         LEFT JOIN service_types st  ON a.service_type_id = st.type_id
         LEFT JOIN users tech        ON a.technician_id   = tech.user_id
         WHERE i.invoice_id = ?"
    );
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv || !$inv['customer_email']) return false;

 // Lines + payments
    $lineStmt = $db->prepare(
        "SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order, line_id"
    );
    $lineStmt->execute([$invoiceId]);
    $lines = $lineStmt->fetchAll();

    $payStmt = $db->prepare(
        "SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS recorded_by_name
         FROM payments p LEFT JOIN users u ON p.recorded_by = u.user_id
         WHERE p.invoice_id = ? ORDER BY p.payment_date, p.payment_id"
    );
    $payStmt->execute([$invoiceId]);
    $payments = $payStmt->fetchAll();

    $co    = $settings['company_name']  ?? 'Acme Water Service';
    $ph    = $settings['company_phone'] ?? '';
    $web   = $settings['company_website'] ?? '';
    $total = '$' . number_format((float)($inv['total'] ?? 0), 2);
    $num   = htmlspecialchars($inv['invoice_number'] ?? '');
    $svc   = htmlspecialchars($inv['service_type'] ?? 'Service');

 // Build the line table HTML (reuse email.php helper)
    $lineTable = function_exists('buildLineTable')
        ? buildLineTable($lines, $inv)
        : _fallbackLineTable($lines, $inv);

    $vars = [
        'first_name'      => htmlspecialchars($inv['first_name'] ?? 'Customer'),
        'invoice_number'  => $num,
        'total'           => $total,
        'service_type'    => $svc,
        'line_table'      => $lineTable,
        'custom_message'  => '',
        'company_name'    => htmlspecialchars($co),
        'company_phone'   => htmlspecialchars($ph),
        'company_web'     => htmlspecialchars($web),
    ];

    $tpl     = getEmailTemplate($db, 'invoice');
    $subject = $tpl['subject'] ? applyTemplate($tpl['subject'], $vars)
        : "Invoice {$num} - {$svc} - {$total} Due";
    $body    = $tpl['body']    ? applyTemplate($tpl['body'], $vars)
        : _defaultCompletionBody($vars);
    $html    = wrapEmailBody($body, $co, $ph, $web, "Invoice {$num}");

 // Generate PDF attachment
    $pdfBytes    = null;
    $pdfFilename = null;
    try {
        require_once __DIR__ . '/invoice_pdf.php';
        $pdfSettings = [];
        $ps = $db->query("SELECT setting_key, setting_value FROM company_settings");
        foreach ($ps->fetchAll() as $r) $pdfSettings[$r['setting_key']] = $r['setting_value'];
        $pdf         = new InvoicePdf($pdfSettings);
        $pdfBytes    = $pdf->getBytes($inv, $lines, $payments);
        $pdfFilename = 'Invoice-' . preg_replace('/[^A-Za-z0-9\-]/', '', $inv['invoice_number'] ?? 'INV') . '.pdf';
    } catch (\Throwable $e) {
        error_log('[WAY autoEmail] PDF generation failed: ' . $e->getMessage());
    }

    return dispatchEmail($db, $settings, $inv['customer_email'], $inv['customer_name'],
                         $subject, $html, 'invoice', $invoiceId, $appointmentId,
                         $pdfBytes, $pdfFilename);
}

function _defaultCompletionBody(array $v): string {
    return "<p>Hi {$v['first_name']},</p>"
         . "<p>Thank you - your <strong>{$v['service_type']}</strong> service is complete. Here is your invoice:</p>"
         . $v['line_table']
         . "<p>To pay by check, make payable to <strong>{$v['company_name']}</strong>. "
         . "Questions? Call <strong>{$v['company_phone']}</strong>.</p>";
}

// Inline fallback if email.php's buildLineTable isn't loaded yet
function _fallbackLineTable(array $lines, array $inv): string {
    $rows = '';
    foreach ($lines as $l) {
        $name = htmlspecialchars($l['line_name'] ?: ($l['description'] ?? ''));
        $qty  = (float)($l['quantity'] ?? 1);
        $tot  = '$' . number_format((float)($l['line_total'] ?? 0), 2);
        $rows .= "<tr><td style='padding:6px;border-bottom:1px solid #f3f4f6'>{$name}</td>"
               . "<td style='padding:6px;border-bottom:1px solid #f3f4f6;text-align:center'>{$qty}</td>"
               . "<td style='padding:6px;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:600'>{$tot}</td></tr>";
    }
    $total = '$' . number_format((float)($inv['total'] ?? 0), 2);
    $tax   = '$' . number_format((float)($inv['tax_amount'] ?? 0), 2);
    $sub   = '$' . number_format((float)($inv['subtotal']   ?? 0), 2);
    $feeRow = '';
    if ((float)($inv['card_fee_amount'] ?? 0) > 0) {
        $fee    = '$' . number_format((float)$inv['card_fee_amount'], 2);
        $feeRow = "<tr><td colspan='2' style='padding:6px;text-align:right;color:#6b7280'>Card Fee</td>"
                . "<td style='padding:6px;text-align:right'>{$fee}</td></tr>";
    }
    return '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px">'
         . '<thead><tr style="background:#f3f4f6"><th style="padding:8px;text-align:left">Item</th>'
         . '<th style="padding:8px;text-align:center">Qty</th><th style="padding:8px;text-align:right">Total</th></tr></thead>'
         . "<tbody>{$rows}</tbody><tfoot>"
         . "<tr><td colspan='2' style='padding:6px;text-align:right;color:#6b7280'>Subtotal</td><td style='padding:6px;text-align:right'>{$sub}</td></tr>"
         . "<tr><td colspan='2' style='padding:6px;text-align:right;color:#6b7280'>Tax</td><td style='padding:6px;text-align:right'>{$tax}</td></tr>"
         . $feeRow
         . "<tr style='background:#f0fdf4'><td colspan='2' style='padding:8px;text-align:right;font-weight:700'>Total Due</td>"
         . "<td style='padding:8px;text-align:right;font-weight:700;color:#0d9488'>{$total}</td></tr>"
         . "</tfoot></table>";
}

// SEND: NEW SERVICE REQUEST office notification
// Triggered when a customer submits a request from the PWA.
// Goes to the company office email (info@example.com or
// company_email setting) - NOT to the customer.
function sendNewRequestNotification(PDO $db, int $appointmentId): bool {
    $appt = getAppointmentEmailData($db, $appointmentId);
    if (!$appt) return false;

    $settings = getCompanySettings($db);
    $co  = $settings['company_name']    ?? 'Acme Water Service';
    $ph  = $settings['company_phone']   ?? '';
    $web = $settings['company_website'] ?? '';

 // Recipient: prefer service_request_notify_to, then company_email, then a hard fallback.
    $to = $settings['service_request_notify_to']
       ?: ($settings['company_email']
       ?: 'info@example.com');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

 // Pull equipment list attached to the request
    $stmt = $db->prepare(
        "SELECT et.type_name, e.model
         FROM appointment_equipment ae
         JOIN equipment e        ON ae.equipment_id = e.equipment_id
         JOIN equipment_types et ON e.type_id      = et.type_id
         WHERE ae.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $equipRows = $stmt->fetchAll();
    $equipList = '';
    if ($equipRows) {
        $equipList = '<ul style="margin:6px 0 0;padding-left:18px">';
        foreach ($equipRows as $e) {
            $name = htmlspecialchars($e['type_name']);
            if (!empty($e['model'])) $name .= ' &mdash; ' . htmlspecialchars($e['model']);
            $equipList .= "<li>{$name}</li>";
        }
        $equipList .= '</ul>';
    } else {
        $equipList = '<em style="color:#6b7280">None specified</em>';
    }

    $custName  = htmlspecialchars($appt['customer_name'] ?? 'Customer');
    $custEmail = htmlspecialchars($appt['customer_email'] ?? '');
    $custPhone = htmlspecialchars($appt['phone'] ?? '');
    $svcType   = htmlspecialchars($appt['service_type'] ?? 'Service');
    $reqDate   = $appt['requested_date']
        ? date('l, F j, Y', strtotime($appt['requested_date']))
        : '(no date specified)';
    $window    = htmlspecialchars($appt['requested_window'] ?? 'Either');
    $address   = htmlspecialchars($appt['service_address'] ?? '');
    $notes     = trim((string)($appt['customer_notes'] ?? ''));
    $notesHtml = $notes !== ''
        ? '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 14px;margin-top:12px;border-radius:4px;white-space:pre-wrap">'
            . htmlspecialchars($notes) . '</div>'
        : '<em style="color:#6b7280">No notes</em>';

 // Add-on services flagged on the request
    $addons = [];
    if (!empty($appt['salt_delivery'])) $addons[] = ' <strong>Salt delivery</strong>';
    if (!empty($appt['oxyblast']))      $addons[] = ' <strong>Hydrogen Peroxide / OxyBlast</strong>';
    $addonsHtml = $addons
        ? '<ul style="margin:6px 0 0;padding-left:18px">' . implode('', array_map(fn($a) => "<li>$a</li>", $addons)) . '</ul>'
        : '<em style="color:#6b7280">None requested</em>';

    $subject = sprintf('New service request - %s for %s', $svcType, $custName);

    $body = "<p style='font-size:15px'><strong>A customer has submitted a service request from the PWA.</strong></p>"
          . "<table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:14px'>"
          . "<tr><td style='padding:8px 6px;color:#6b7280;width:140px;vertical-align:top'>Customer</td>"
          .   "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6;font-weight:600'>{$custName}</td></tr>"
          . ($custEmail ? "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Email</td>"
                       . "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6'>{$custEmail}</td></tr>" : '')
          . ($custPhone ? "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Phone</td>"
                       . "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6'>{$custPhone}</td></tr>" : '')
          . ($address   ? "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Service address</td>"
                       . "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6'>{$address}</td></tr>" : '')
          . "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Service type</td>"
          .   "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6;font-weight:600'>{$svcType}</td></tr>"
          . "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Requested date</td>"
          .   "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6;font-weight:600'>{$reqDate}</td></tr>"
          . "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Time preference</td>"
          .   "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6'>{$window}</td></tr>"
          . "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Equipment</td>"
          .   "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6'>{$equipList}</td></tr>"
          . "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Add-on services</td>"
          .   "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6'>{$addonsHtml}</td></tr>"
          . "<tr><td style='padding:8px 6px;color:#6b7280;vertical-align:top'>Customer notes</td>"
          .   "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6'>{$notesHtml}</td></tr>"
          . "</table>"
          . "<p style='font-size:13px;color:#6b7280'>"
          .   "Sign in to MVP Backoffice to confirm or reschedule this request "
          .   "(appointment #{$appointmentId})."
          . "</p>";

    $html = wrapEmailBody($body, $co, $ph, $web, ' New Service Request');

    return dispatchEmail(
        $db, $settings, $to, $co,
        $subject, $html,
        'service_request',
        null, $appointmentId
    );
}

// DISPATCH: Gmail API SMTP mail() fallback
// Optional $pdfBytes / $pdfFilename for invoice/receipt emails
function dispatchEmail(
    PDO    $db,
    array  $settings,
    string $toEmail,
    string $toName,
    string $subject,
    string $html,
    string $emailType,
    ?int   $invoiceId,
    ?int   $appointmentId,
    ?string $pdfBytes    = null,
    ?string $pdfFilename = null
): bool {
    $sent    = false;
    $plain   = strip_tags($html);
    $hasPdf  = $pdfBytes !== null && strlen($pdfBytes) > 0;

 // 1. Gmail API (OAuth) - preferred
    if (!$sent && function_exists('getValidGmailToken')) {
        $cfg   = getGmailConfig($db);
        $token = getValidGmailToken($db, $cfg);
        if ($token) {
            $from = $cfg['gmail_authorized_email'] ?? ($settings['company_email'] ?? '');
            $sent = $hasPdf
                ? sendViaGmailApiWithAttachment($token, $from, $toEmail, $toName, $subject, $html, $plain, $pdfBytes, $pdfFilename)
                : sendViaGmailApi($token, $from, $toEmail, $toName, $subject, $html, $plain);
        }
    }

 // 2. SMTP app password
    if (!$sent && !empty($settings['smtp_pass'])) {
        $host  = $settings['smtp_host']      ?? 'smtp.gmail.com';
        $port  = (int)($settings['smtp_port']  ?? 587);
        $user  = $settings['smtp_user']      ?? $settings['company_email'] ?? '';
        $pass  = $settings['smtp_pass'];
        $from  = $settings['company_email']  ?? '';
        $name  = $settings['smtp_from_name'] ?? $settings['company_name']  ?? 'Acme Water Service';

        $sent = $hasPdf
            ? sendViaSMTPWithAttachment($toEmail, $toName, $subject, $html, $host, $port, $user, $pass, $from, $name, $pdfBytes, $pdfFilename)
            : sendViaSMTP($toEmail, $toName, $subject, $html, $host, $port, $user, $pass, $from, $name);
    }

 // 3. PHP mail() last resort (no attachment support)
    if (!$sent) {
        $fromName  = $settings['smtp_from_name'] ?? $settings['company_name'] ?? 'Acme Water Service';
        $fromEmail = $settings['company_email'] ?? '';
        $hdrs      = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $hdrs     .= "From: $fromName <$fromEmail>\r\n";
        $sent      = (bool)mail($toEmail, $subject, $html, $hdrs);
    }

 // Log every attempt
    try {
        $db->prepare(
            "INSERT INTO email_log
             (invoice_id, appointment_id, email_type, to_email, subject, sent_at, success)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)"
        )->execute([$invoiceId, $appointmentId, $emailType, $toEmail, $subject, $sent ? 1 : 0]);
    } catch (\Throwable $e) {
        try {
            $db->prepare(
                "INSERT INTO email_log (invoice_id, email_type, to_email, subject, sent_at)
                 VALUES (?, ?, ?, ?, NOW())"
            )->execute([$invoiceId, $emailType, $toEmail, $subject]);
        } catch (\Throwable $e2) {}
    }

    return $sent;
}

// DATA LOADER
function getAppointmentEmailData(PDO $db, int $appointmentId): ?array {
    $stmt = $db->prepare(
        "SELECT a.*,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                COALESCE(NULLIF(a.notification_email_override,''), c.email) AS customer_email,
                c.first_name,
                c.phone,
                c.service_address,
                st.name AS service_type,
                CONCAT(u.first_name,' ',u.last_name) AS technician_name
         FROM appointments a
         JOIN customers c ON a.customer_id = c.customer_id
         LEFT JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN users u ON a.technician_id = u.user_id
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    return $stmt->fetch() ?: null;
}

// CRON - runs daily via scheduled task
// GET /cron/reminders?secret=xxx
function runReminderCron(PDO $db): void {
    $settings = getCompanySettings($db);
    $secret   = $settings['cron_secret'] ?? '';
    $provided = $_GET['secret'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');

    if ($secret && $provided !== $secret) {
        sendError(403, 'Invalid cron secret');
    }

    $result = ['appointment_reminders' => 0, 'install_reminders_7d' => 0, 'install_reminders_3d' => 0, 'install_reminders_1d' => 0, 'service_reminders' => 0, 'failures' => 0];

 // Skip service types flagged extended_reminders=1 - those get their
 // own 7/3/1 cadence (handled below) and shouldn't get a dup at +1d.
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $stmt = $db->prepare(
        "SELECT a.appointment_id
         FROM appointments a
         JOIN customers c ON a.customer_id = c.customer_id
         LEFT JOIN service_types st ON a.service_type_id = st.type_id
         WHERE a.status = 'confirmed'
           AND a.confirmed_date = ?
           AND c.do_not_service = 0
           AND c.email IS NOT NULL AND c.email != ''
           AND COALESCE(st.extended_reminders, 0) = 0
           AND NOT EXISTS (
               SELECT 1 FROM email_log el
               WHERE el.appointment_id = a.appointment_id
                 AND el.email_type = 'reminder'
           )"
    );
    $stmt->execute([$tomorrow]);
    foreach ($stmt->fetchAll() as $row) {
        $ok = sendReminderEmail($db, (int)$row['appointment_id']);
        $ok ? $result['appointment_reminders']++ : $result['failures']++;
    }

 // Each cadence dedupes on its own email_type tag in email_log.
    foreach ([7, 3, 1] as $daysOut) {
        $targetDate = date('Y-m-d', strtotime('+' . $daysOut . ' days'));
        $tag        = 'install_reminder_' . $daysOut . 'd';
        $stmtI = $db->prepare(
            "SELECT a.appointment_id
             FROM appointments a
             JOIN customers c ON a.customer_id = c.customer_id
             JOIN service_types st ON a.service_type_id = st.type_id
             WHERE a.status = 'confirmed'
               AND a.confirmed_date = ?
               AND c.do_not_service = 0
               AND c.email IS NOT NULL AND c.email != ''
               AND COALESCE(st.extended_reminders, 0) = 1
               AND NOT EXISTS (
                   SELECT 1 FROM email_log el
                   WHERE el.appointment_id = a.appointment_id
                     AND el.email_type = ?
               )"
        );
        $stmtI->execute([$targetDate, $tag]);
        foreach ($stmtI->fetchAll() as $row) {
            $ok = sendInstallReminderEmail($db, (int)$row['appointment_id'], $daysOut);
            $bucket = 'install_reminders_' . $daysOut . 'd';
            $ok ? $result[$bucket]++ : $result['failures']++;
        }
    }

    $from30 = date('Y-m-d', strtotime('+28 days'));
    $to30   = date('Y-m-d', strtotime('+35 days'));
    $stmt2  = $db->prepare(
        "SELECT e.equipment_id, c.customer_id
         FROM equipment e
         JOIN customers c ON e.customer_id = c.customer_id
         WHERE e.is_active = 1
           AND COALESCE(e.self_service, 0) = 0
           AND c.do_not_service = 0
           AND c.auto_service_reminder = 1
           AND c.email IS NOT NULL AND c.email != ''
           AND e.next_service_due BETWEEN ? AND ?
           AND NOT EXISTS (
               SELECT 1 FROM email_log el2
               WHERE el2.to_email = c.email
                 AND el2.email_type = 'service_reminder'
                 AND el2.sent_at >= DATE_SUB(NOW(), INTERVAL 25 DAY)
                 AND el2.success = 1
           )"
    );
    $stmt2->execute([$from30, $to30]);
    foreach ($stmt2->fetchAll() as $row) {
        $ok = sendServiceReminderEmail($db, (int)$row['customer_id'], (int)$row['equipment_id']);
        $ok ? $result['service_reminders']++ : $result['failures']++;
    }

    sendJson(array_merge(['message' => 'Cron complete', 'date' => date('Y-m-d')], $result));
}
