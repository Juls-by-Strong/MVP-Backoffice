<?php
// Manual invoice/receipt email sends + SMTP helper
// Gmail API is preferred when connected (via gmail_oauth.php)
//
// POST /admin/invoices/{id}/send-email - send invoice + PDF attachment
// POST /admin/invoices/{id}/send-receipt - send receipt + PDF attachment

function handleEmailSend(PDO $db, string $method, int $invoiceId, string $emailType): void {
    if ($method !== 'POST') sendError(405, 'Method not allowed');

    $settings = getCompanySettings($db);

    $stmt = $db->prepare(
        "SELECT i.*,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                c.email AS customer_email, c.first_name,
                c.last_name, c.phone AS customer_phone,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.user_id AS customer_user_id,
                st.name AS service_type,
                CONCAT(tech.first_name,' ',tech.last_name) AS technician_name
         FROM invoices i
         JOIN customers c ON i.customer_id = c.customer_id
         LEFT JOIN appointments a    ON i.appointment_id  = a.appointment_id
         LEFT JOIN service_types st  ON a.service_type_id = st.type_id
         LEFT JOIN users tech        ON a.technician_id   = tech.user_id
         WHERE i.invoice_id = ?"
    );
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv) sendError(404, 'Invoice not found');
    if (!$inv['customer_email']) sendError(400, 'Customer has no email address on file');

    $lineStmt = $db->prepare(
        "SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order, line_id"
    );
    $lineStmt->execute([$invoiceId]);
    $lines = $lineStmt->fetchAll();

    $payStmt = $db->prepare(
        "SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS recorded_by_name
         FROM payments p
         LEFT JOIN users u ON p.recorded_by = u.user_id
         WHERE p.invoice_id = ? ORDER BY p.payment_date, p.payment_id"
    );
    $payStmt->execute([$invoiceId]);
    $payments = $payStmt->fetchAll();

    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $toEmail   = $body['to_email'] ?? $inv['customer_email'];
    $toName    = $body['to_name']  ?? $inv['customer_name'];
    $customMsg = $body['message']  ?? '';

    if ($emailType === 'invoice') {
        $subject  = buildTemplateSubject($db, 'invoice', $inv, $settings);
        $htmlBody = buildInvoiceEmailHtml($db, $inv, $lines, $settings, $customMsg);
    } else {
        $subject  = buildTemplateSubject($db, 'receipt', $inv, $settings);
        $htmlBody = buildReceiptEmailHtml($db, $inv, $lines, $payments, $settings, $customMsg);
    }

    $pdfBytes    = null;
    $pdfFilename = null;
    try {
        require_once __DIR__ . '/invoice_pdf.php';
        $pdfSettings = [];
        try {
            $s = $db->query("SELECT setting_key, setting_value FROM company_settings");
            foreach ($s->fetchAll() as $r) $pdfSettings[$r['setting_key']] = $r['setting_value'];
        } catch (\Throwable $e) {}

        $pdf         = new InvoicePdf($pdfSettings);
        $pdfBytes    = $pdf->getBytes($inv, $lines, $payments);
        $pdfFilename = 'Invoice-' . preg_replace('/[^A-Za-z0-9\-]/', '', $inv['invoice_number'] ?? 'INV') . '.pdf';
    } catch (\Throwable $e) {
        error_log('[WAY email] PDF generation failed: ' . $e->getMessage());
 // Non-fatal - send without attachment if PDF fails
    }

    $sent  = false;
    $plain = strip_tags($htmlBody);
    $hasPdf = $pdfBytes !== null;

 // 1. Gmail API (OAuth) - preferred
    if (!$sent && function_exists('getValidGmailToken')) {
        $cfg   = getGmailConfig($db);
        $token = getValidGmailToken($db, $cfg);
        if ($token) {
            $from = $cfg['gmail_authorized_email'] ?? ($settings['company_email'] ?? '');
            $sent = $hasPdf
                ? sendViaGmailApiWithAttachment($token, $from, $toEmail, $toName, $subject, $htmlBody, $plain, $pdfBytes, $pdfFilename)
                : sendViaGmailApi($token, $from, $toEmail, $toName, $subject, $htmlBody, $plain);
        }
    }

 // 2. SMTP app password
    if (!$sent && !empty($settings['smtp_pass'])) {
        $sent = $hasPdf
            ? sendViaSMTPWithAttachment(
                $toEmail, $toName, $subject, $htmlBody,
                $settings['smtp_host']      ?? 'smtp.gmail.com',
                (int)($settings['smtp_port']  ?? 587),
                $settings['smtp_user']      ?? $settings['company_email'],
                $settings['smtp_pass'],
                $settings['company_email']  ?? '',
                $settings['smtp_from_name'] ?? $settings['company_name'],
                $pdfBytes, $pdfFilename
              )
            : sendViaSMTP(
                $toEmail, $toName, $subject, $htmlBody,
                $settings['smtp_host']      ?? 'smtp.gmail.com',
                (int)($settings['smtp_port']  ?? 587),
                $settings['smtp_user']      ?? $settings['company_email'],
                $settings['smtp_pass'],
                $settings['company_email']  ?? '',
                $settings['smtp_from_name'] ?? $settings['company_name']
              );
    }

 // 3. PHP mail() last resort (no attachment support)
    if (!$sent) {
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$settings['smtp_from_name']} <{$settings['company_email']}>\r\n";
        $sent = (bool)mail($toEmail, $subject, $htmlBody, $headers);
    }

    if ($sent) {
        try {
            $db->prepare(
                "INSERT INTO email_log (invoice_id, email_type, to_email, subject, sent_at, success)
                 VALUES (?, ?, ?, ?, NOW(), 1)"
            )->execute([$invoiceId, $emailType, $toEmail, $subject]);
        } catch (\Throwable $e) {
            try {
                $db->prepare(
                    "INSERT INTO email_log (invoice_id, email_type, to_email, subject, sent_at)
                     VALUES (?, ?, ?, ?, NOW())"
                )->execute([$invoiceId, $emailType, $toEmail, $subject]);
            } catch (\Throwable $e2) {}
        }
        sendJson(['message' => 'Email sent to ' . $toEmail . ($hasPdf ? ' (with PDF attachment)' : '')]);
    } else {
        sendError(500, 'Failed to send email - check Gmail OAuth or SMTP settings');
    }
}

function buildTemplateSubject(PDO $db, string $type, array $inv, array $s): string {
    $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE setting_key = ?");
    $stmt->execute(["tpl_{$type}_subject"]);
    $tpl = $stmt->fetchColumn();
    if (!$tpl) {
        return $type === 'invoice'
            ? 'Invoice ' . ($inv['invoice_number'] ?? '') . ' from ' . ($s['company_name'] ?? '')
            : 'Payment Receipt - ' . ($inv['invoice_number'] ?? '');
    }
    $vars = [
        'invoice_number' => $inv['invoice_number'] ?? '',
        'company_name'   => $s['company_name'] ?? '',
        'first_name'     => $inv['first_name'] ?? 'Customer',
        'total'          => '$' . number_format((float)($inv['total'] ?? 0), 2),
    ];
    foreach ($vars as $k => $v) $tpl = str_replace('{{' . $k . '}}', $v, $tpl);
    return preg_replace('/\{\{[a-z_]+\}\}/', '', $tpl);
}

function buildLineTable(array $lines, array $inv): string {
    $lineRows = '';
    foreach ($lines as $l) {
        $name   = htmlspecialchars($l['line_name'] ?: ($l['description'] ?? ''));
        $detail = (!empty($l['description']) && $l['description'] !== ($l['line_name'] ?? ''))
            ? '<br><span style="font-size:11px;color:#6b7280">' . htmlspecialchars($l['description']) . '</span>'
            : '';
        $qty    = (float)($l['quantity'] ?? 1);
        $price  = '$' . number_format((float)($l['unit_price'] ?? 0), 2);
        $tot    = '$' . number_format((float)($l['line_total'] ?? 0), 2);
        $lineRows .= '<tr>'
            . "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6'>{$name}{$detail}</td>"
            . "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6;text-align:center'>{$qty}</td>"
            . "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6;text-align:right'>{$price}</td>"
            . "<td style='padding:8px 6px;border-bottom:1px solid #f3f4f6;text-align:right'><strong>{$tot}</strong></td>"
            . '</tr>';
    }

    $subtotal = '$' . number_format((float)($inv['subtotal']   ?? 0), 2);
    $taxAmt   = '$' . number_format((float)($inv['tax_amount'] ?? 0), 2);
    $feeRow   = '';
    if ((float)($inv['card_fee_amount'] ?? 0) > 0) {
        $fee    = '$' . number_format((float)$inv['card_fee_amount'], 2);
        $feeRow = "<tr><td colspan='3' style='padding:8px 6px;text-align:right;color:#6b7280;font-size:13px'>Credit/Debit Fee (3.5%)</td>"
                . "<td style='padding:8px 6px;text-align:right'>{$fee}</td></tr>";
    }
    $total = '$' . number_format((float)($inv['total'] ?? 0), 2);

    return '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px">'
         . '<thead><tr style="background:#f3f4f6">'
         . "<th style='padding:8px 6px;text-align:left'>Item</th>"
         . "<th style='padding:8px 6px;text-align:center'>Qty</th>"
         . "<th style='padding:8px 6px;text-align:right'>Unit Price</th>"
         . "<th style='padding:8px 6px;text-align:right'>Total</th></tr></thead>"
         . "<tbody>{$lineRows}</tbody>"
         . '<tfoot>'
         . "<tr><td colspan='3' style='padding:8px 6px;text-align:right;color:#6b7280'>Subtotal</td>"
         . "<td style='padding:8px 6px;text-align:right'>{$subtotal}</td></tr>"
         . "<tr><td colspan='3' style='padding:8px 6px;text-align:right;color:#6b7280'>Tax</td>"
         . "<td style='padding:8px 6px;text-align:right'>{$taxAmt}</td></tr>"
         . $feeRow
         . "<tr style='background:#f0fdf4'>"
         . "<td colspan='3' style='padding:10px 6px;text-align:right;font-weight:700;font-size:15px'>Total Due</td>"
         . "<td style='padding:10px 6px;text-align:right;font-weight:700;font-size:15px;color:#6495ed'>{$total}</td>"
         . '</tr></tfoot></table>';
}

function buildPaymentTable(array $payments, float $invoiceTotal): string {
    $payRows   = '';
    $totalPaid = 0;
    foreach ($payments as $p) {
        $amt       = (float)$p['amount'];
        $totalPaid += $amt;
        $method    = htmlspecialchars(ucfirst(str_replace('_', ' ', $p['payment_method'] ?? '')));
        $date      = htmlspecialchars($p['payment_date'] ?? '');
        $payRows  .= "<tr><td style='padding:8px;border-bottom:1px solid #f3f4f6'>{$date}</td>"
                   . "<td style='padding:8px;border-bottom:1px solid #f3f4f6'>{$method}</td>"
                   . "<td style='padding:8px;border-bottom:1px solid #f3f4f6;text-align:right'><strong>\$" . number_format($amt, 2) . "</strong></td></tr>";
    }
    $balance = $invoiceTotal - $totalPaid;
    return '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px">'
         . '<thead><tr style="background:#f3f4f6">'
         . "<th style='padding:8px;text-align:left'>Date</th>"
         . "<th style='padding:8px;text-align:left'>Method</th>"
         . "<th style='padding:8px;text-align:right'>Amount</th></tr></thead>"
         . "<tbody>{$payRows}</tbody>"
         . '<tfoot><tr style="border-top:2px solid #6495ed">'
         . "<td colspan='2' style='padding:10px 8px;font-weight:700'>Balance Due</td>"
         . "<td style='padding:10px 8px;text-align:right;font-weight:700;color:#6495ed'>\$" . number_format($balance, 2) . "</td>"
         . '</tr></tfoot></table>';
}

function buildInvoiceEmailHtml(PDO $db, array $inv, array $lines, array $s, string $customMsg): string {
    $co    = $s['company_name']  ?? 'Acme Water Service';
    $ph    = $s['company_phone'] ?? '';
    $web   = $s['company_website'] ?? '';
    $num   = htmlspecialchars($inv['invoice_number'] ?? '');
    $total = '$' . number_format((float)($inv['total'] ?? 0), 2);
    $first = htmlspecialchars($inv['first_name'] ?? 'Customer');
    $msg   = $customMsg ? '<p>' . nl2br(htmlspecialchars($customMsg)) . '</p>' : '';
    $lineTable = buildLineTable($lines, $inv);

    $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE setting_key = 'tpl_invoice_body'");
    $stmt->execute();
    $tplBody = $stmt->fetchColumn();

    if ($tplBody) {
        $vars = [
            'first_name'      => $first,
            'invoice_number'  => $num,
            'total'           => $total,
            'line_table'      => $lineTable,
            'company_name'    => htmlspecialchars($co),
            'company_phone'   => htmlspecialchars($ph),
            'company_web'     => htmlspecialchars($web),
            'custom_message'  => $msg,
        ];
        $body = $tplBody;
        foreach ($vars as $k => $v) $body = str_replace('{{' . $k . '}}', $v, $body);
        $body = preg_replace('/\{\{[a-z_]+\}\}/', '', $body);
        return wrapEmailShell($body, $co, $ph, $web, $co, $s);
    }

 // Hard-coded fallback - always shows full line table
    $sCo    = htmlspecialchars($co); $sPh = htmlspecialchars($ph); $sWeb = htmlspecialchars($web);
    $extras = buildEmailExtras($s);
    return <<<HTML
<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto">
<div style="background:#6495ed;padding:24px;color:#fff;border-radius:8px 8px 0 0">
  <h1 style="margin:0;font-size:22px">Invoice $num</h1>
  <p style="margin:4px 0 0;opacity:.85">$sCo &nbsp;|&nbsp; $sPh</p>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px">
  <p>Hi $first,</p>
  <p>Thank you - here is your invoice for <strong>$total</strong>. A PDF copy is attached.</p>
  $msg
  $lineTable
  <p>To pay by check, make payable to <strong>$sCo</strong>. Questions? Call <strong>$sPh</strong>.</p>
  $extras
  <p style="margin-top:24px;color:#6b7280;font-size:12px">$sCo &nbsp;&bull;&nbsp; $sPh &nbsp;&bull;&nbsp; $sWeb</p>
</div></body></html>
HTML;
}

function buildReceiptEmailHtml(PDO $db, array $inv, array $lines, array $payments, array $s, string $customMsg): string {
    $co    = $s['company_name']  ?? 'Acme Water Service';
    $ph    = $s['company_phone'] ?? '';
    $web   = $s['company_website'] ?? '';
    $num   = htmlspecialchars($inv['invoice_number'] ?? '');
    $total = '$' . number_format((float)($inv['total'] ?? 0), 2);
    $first = htmlspecialchars($inv['first_name'] ?? 'Customer');
    $msg   = $customMsg ? '<p>' . nl2br(htmlspecialchars($customMsg)) . '</p>' : '';
    $lineTable  = buildLineTable($lines, $inv);
    $payTable   = buildPaymentTable($payments, (float)($inv['total'] ?? 0));

    $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE setting_key = 'tpl_receipt_body'");
    $stmt->execute();
    $tplBody = $stmt->fetchColumn();

    if ($tplBody) {
        $vars = [
            'first_name'     => $first,
            'invoice_number' => $num,
            'total'          => $total,
            'line_table'     => $lineTable,
            'payment_table'  => $payTable,
            'company_name'   => htmlspecialchars($co),
            'company_phone'  => htmlspecialchars($ph),
            'company_web'    => htmlspecialchars($web),
            'custom_message' => $msg,
        ];
        $body = $tplBody;
        foreach ($vars as $k => $v) $body = str_replace('{{' . $k . '}}', $v, $body);
        $body = preg_replace('/\{\{[a-z_]+\}\}/', '', $body);
        return wrapEmailShell($body, $co, $ph, $web, $co, $s);
    }

 // Hard-coded fallback - always shows line table + payment table
    $sCo    = htmlspecialchars($co); $sPh = htmlspecialchars($ph); $sWeb = htmlspecialchars($web);
    $extras = buildEmailExtras($s);
    return <<<HTML
<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto">
<div style="background:#6495ed;padding:24px;color:#fff;border-radius:8px 8px 0 0">
  <h1 style="margin:0;font-size:22px">Payment Receipt</h1>
  <p style="margin:4px 0 0;opacity:.85">$sCo &nbsp;|&nbsp; $sPh</p>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px">
  <p>Hi $first,</p>
  <p>Thank you for your payment on invoice <strong>$num</strong>. A PDF copy is attached.</p>
  $msg
  $lineTable
  $payTable
  $extras
  <p style="margin-top:24px;color:#6b7280;font-size:12px">$sCo &nbsp;&bull;&nbsp; $sPh &nbsp;&bull;&nbsp; $sWeb</p>
</div></body></html>
HTML;
}

// Review + social links block (injected into every email)
function buildEmailExtras(array $s): string {
    $googleUrl   = $s['google_review_url']   ?? 'https://feedback.ollyolly.com/q4mCLN';
    $facebookUrl = $s['facebook_review_url'] ?? 'https://feedback.ollyolly.com/b1IUhi';
    $fbPage      = $s['social_facebook_url'] ?? '';
    $bluesky     = $s['social_bluesky_url']  ?? '';
    $youtube     = $s['social_youtube_url']  ?? '';

    $reviewLinks = '';
    if ($googleUrl) {
        $u = htmlspecialchars($googleUrl);
        $reviewLinks .= "<a href='{$u}' style='display:inline-block;margin:4px 6px;padding:9px 18px;"
            . "background:#4285f4;color:#fff;border-radius:5px;text-decoration:none;"
            . "font-size:13px;font-weight:600'>&#9733; Google Review</a>";
    }
    if ($facebookUrl) {
        $u = htmlspecialchars($facebookUrl);
        $reviewLinks .= "<a href='{$u}' style='display:inline-block;margin:4px 6px;padding:9px 18px;"
            . "background:#1877f2;color:#fff;border-radius:5px;text-decoration:none;"
            . "font-size:13px;font-weight:600'>&#128077; Facebook Review</a>";
    }

    $reviewBlock = '';
    if ($reviewLinks) {
        $reviewBlock = "<div style='margin:28px 0 8px;padding:18px 16px;background:#eff6ff;"
            . "border-radius:8px;text-align:center;border:1px solid #bfdbfe'>"
            . "<p style='margin:0 0 6px;font-weight:700;font-size:14px;color:#1d4ed8'>"
            . "&#10084; We love referrals!</p>"
            . "<p style='margin:0 0 14px;font-size:13px;color:#374151'>"
            . "Leave us a review and ask us how to earn <strong>FREE filter changes!</strong></p>"
            . $reviewLinks
            . "</div>";
    }

 // Inline SVGs ensure logos render in all email clients without
 // relying on external image hosting (which many clients block).
    $socialItems = [];
    if ($fbPage) {
        $u = htmlspecialchars($fbPage);
 // Facebook "f" logo - official brand blue #1877F2
        $fbSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="36" height="36" style="display:block">'
            . '<circle cx="18" cy="18" r="18" fill="#1877F2"/>'
            . '<path d="M25 18h-4v-2.5c0-.9.7-1.5 1.5-1.5H25V10h-3c-3.3 0-5 2-5 5v3h-3v4h3v9h4v-9h3l1-4z" fill="#fff"/>'
            . '</svg>';
        $socialItems[] = "<a href='{$u}' target='_blank' style='display:inline-block;margin:0 5px;"
            . "text-decoration:none;vertical-align:middle' title='Follow us on Facebook'>{$fbSvg}</a>";
    }
    if ($bluesky) {
        $u = htmlspecialchars($bluesky);
 // Bluesky butterfly logo - official brand blue #0085FF
        $bskySvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="36" height="36" style="display:block">'
            . '<circle cx="18" cy="18" r="18" fill="#0085FF"/>'
            . '<path d="M18 13.2c-1.8-2.3-5.5-4.2-5.5-1.4 0 1.4.9 3.7 3.7 4.2-2.8.3-4.7 1.4-4.7 2.8s1.8 2.3 6.5 2.3 6.5-.5 6.5-2.3-1.9-2.5-4.7-2.8c2.8-.5 3.7-2.8 3.7-4.2 0-2.8-3.7-.9-5.5 1.4z" fill="#fff"/>'
            . '</svg>';
        $socialItems[] = "<a href='{$u}' target='_blank' style='display:inline-block;margin:0 5px;"
            . "text-decoration:none;vertical-align:middle' title='Follow us on Bluesky'>{$bskySvg}</a>";
    }
    if ($youtube) {
        $u = htmlspecialchars($youtube);
 // YouTube play-button logo - brand red #FF0000
        $ytSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="36" height="36" style="display:block">'
            . '<circle cx="18" cy="18" r="18" fill="#FF0000"/>'
            . '<path d="M26.3 13.6s-.3-1.9-1.1-2.7c-1.1-1.1-2.3-1.1-2.9-1.2C20.1 9.5 18 9.5 18 9.5s-2.1 0-4.3.2c-.6.1-1.8.1-2.9 1.2-.8.8-1.1 2.7-1.1 2.7S9.4 15.8 9.4 18s.3 4.4.3 4.4.3 1.9 1.1 2.7c1.1 1.1 2.5 1.1 3.2 1.2 2.3.2 9.7.2 9.7.2s2.1 0 3.9-.2c.6-.1 1.8-.1 2.9-1.2.8-.8 1.1-2.7 1.1-2.7s.3-2.2.3-4.4-.3-4.4-.3-4.4zm-10.6 8.5v-8.2l7.9 4.1-7.9 4.1z" fill="#fff"/>'
            . '</svg>';
        $socialItems[] = "<a href='{$u}' target='_blank' style='display:inline-block;margin:0 5px;"
            . "text-decoration:none;vertical-align:middle' title='Watch us on YouTube'>{$ytSvg}</a>";
    }

    $socialBlock = '';
    if ($socialItems) {
        $socialBlock = "<p style='margin:16px 0 0;text-align:center;line-height:1'>"
            . implode('', $socialItems)
            . "</p>";
    }

    return $reviewBlock . $socialBlock;
}

function wrapEmailShell(string $body, string $co, string $ph, string $web, string $title, array $s = []): string {
    $sCo = htmlspecialchars($co); $sPh = htmlspecialchars($ph);
    $sWeb = htmlspecialchars($web); $sT = htmlspecialchars($title);
    $extras = buildEmailExtras($s);
    return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto">'
         . '<div style="background:#6495ed;padding:24px;color:#fff;border-radius:8px 8px 0 0">'
         . "<h1 style='margin:0;font-size:22px'>{$sT}</h1>"
         . "<p style='margin:4px 0 0;opacity:.85'>{$sCo}" . ($sPh ? " &nbsp;|&nbsp; {$sPh}" : '') . '</p>'
         . '</div>'
         . '<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px">'
         . $body
         . $extras
         . "<p style='margin-top:24px;color:#6b7280;font-size:12px'>{$sCo}"
         . ($sPh  ? " &nbsp;&bull;&nbsp; {$sPh}"  : '')
         . ($sWeb ? " &nbsp;&bull;&nbsp; {$sWeb}" : '')
         . '</p></div></body></html>';
}

function sendViaSMTP(
    string $toEmail, string $toName, string $subject, string $htmlBody,
    string $host, int $port, string $user, string $pass,
    string $fromEmail, string $fromName
): bool {
    return _smtpSend($toEmail, $toName, $subject, $htmlBody,
                     $host, $port, $user, $pass, $fromEmail, $fromName,
                     null, null);
}

function sendViaSMTPWithAttachment(
    string $toEmail, string $toName, string $subject, string $htmlBody,
    string $host, int $port, string $user, string $pass,
    string $fromEmail, string $fromName,
    string $attachBytes, string $attachFilename
): bool {
    return _smtpSend($toEmail, $toName, $subject, $htmlBody,
                     $host, $port, $user, $pass, $fromEmail, $fromName,
                     $attachBytes, $attachFilename);
}

function _smtpSend(
    string  $toEmail, string $toName, string $subject, string $htmlBody,
    string  $host, int $port, string $user, string $pass,
    string  $fromEmail, string $fromName,
    ?string $attachBytes, ?string $attachFilename
): bool {
    $errno = 0; $errstr = '';
    $ssl   = ($port === 465) ? 'ssl://' : '';
    $sock  = @fsockopen($ssl . $host, $port, $errno, $errstr, 15);
    if (!$sock) return false;

    $read  = function() use ($sock) {
        $r = '';
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            $r .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $r;
    };
    $write = fn(string $cmd) => fputs($sock, $cmd . "\r\n");

    $read();
    $write("EHLO " . gethostname()); $read();

    if ($port === 587) {
        $write("STARTTLS"); $read();
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write("EHLO " . gethostname()); $read();
    }

    $write("AUTH LOGIN"); $read();
    $write(base64_encode($user)); $read();
    $write(base64_encode($pass));
    $resp = $read();
    if (strpos($resp, '235') === false) { fclose($sock); return false; }

    $write("MAIL FROM:<$fromEmail>"); $read();
    $write("RCPT TO:<$toEmail>");     $read();
    $write("DATA");                   $read();

    $plain = strip_tags($htmlBody);

    if ($attachBytes !== null) {
 // multipart/mixed multipart/alternative body + PDF attachment
        $outerB = '=_Mixed_' . md5(uniqid('', true));
        $altB   = '=_Alt_'   . md5(uniqid('', true));

        $msg  = "From: $fromName <$fromEmail>\r\n";
        $msg .= "To: $toName <$toEmail>\r\n";
        $msg .= "Subject: " . mb_encode_mimeheader($subject, 'UTF-8', 'B') . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: multipart/mixed; boundary=\"$outerB\"\r\n\r\n";

        $msg .= "--$outerB\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"$altB\"\r\n\r\n";

        $msg .= "--$altB\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $msg .= quoted_printable_encode($plain) . "\r\n";

        $msg .= "--$altB\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $msg .= quoted_printable_encode($htmlBody) . "\r\n";
        $msg .= "--$altB--\r\n";

        $safeName   = preg_replace('/[^A-Za-z0-9\-_\.]/', '_', $attachFilename);
        $encodedPdf = chunk_split(base64_encode($attachBytes), 76, "\r\n");
        $msg .= "--$outerB\r\n";
        $msg .= "Content-Type: application/pdf; name=\"$safeName\"\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "Content-Disposition: attachment; filename=\"$safeName\"\r\n\r\n";
        $msg .= $encodedPdf;
        $msg .= "--$outerB--\r\n";
    } else {
 // plain multipart/alternative (no attachment)
        $altB = '=_Alt_' . md5(uniqid('', true));
        $msg  = "From: $fromName <$fromEmail>\r\n";
        $msg .= "To: $toName <$toEmail>\r\n";
        $msg .= "Subject: " . mb_encode_mimeheader($subject, 'UTF-8', 'B') . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"$altB\"\r\n\r\n";

        $msg .= "--$altB\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $msg .= quoted_printable_encode($plain) . "\r\n";

        $msg .= "--$altB\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $msg .= quoted_printable_encode($htmlBody) . "\r\n";
        $msg .= "--$altB--\r\n";
    }

    $msg .= ".";
    $write($msg);
    $resp = $read();
    $write("QUIT"); $read();
    fclose($sock);
    return strpos($resp, '250') !== false;
}

// POST /admin/appointments/{id}/send-review
// Send a Google review request email for a completed appointment.
// Body params: to_email (required), subject (optional), message (optional)
function handleReviewRequest(PDO $db, string $method, int $appointmentId): void {
    $settings = getCompanySettings($db);

    $stmt = $db->prepare(
        "SELECT a.appointment_id, a.confirmed_date, a.status,
                COALESCE(NULLIF(TRIM(c.company_name),''), CONCAT(c.first_name,' ',c.last_name)) AS customer_name,
                c.first_name, c.email AS customer_email
         FROM appointments a
         JOIN customers c ON a.customer_id = c.customer_id
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) { sendError(404, 'Appointment not found'); exit; }
    if ($appt['status'] !== 'completed') { sendError(400, 'Appointment is not completed'); exit; }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $toEmail = trim($body['to_email'] ?? '');
    if (!$toEmail) { sendError(400, 'to_email is required'); exit; }

    $toName     = $appt['customer_name'];
    $firstName  = $appt['first_name'] ?: $appt['customer_name'];
    $co         = $settings['company_name']  ?? 'Acme Water Service';
    $ph         = $settings['company_phone'] ?? '555-555-5555';
    $web        = $settings['company_website'] ?? '';
    $googleLink = 'https://g.page/r/CbEpbpVb0RqOEBM/review';

    $subject    = $body['subject'] ?? 'Your feedback matters to us!';
    $customBody = isset($body['message']) && $body['message'] !== '' ? $body['message'] : null;

    $sFirstName = htmlspecialchars($firstName);
    $sCo        = htmlspecialchars($co);
    $sPh        = htmlspecialchars($ph);

    if ($customBody !== null) {
        $innerHtml = '<p>' . nl2br(htmlspecialchars($customBody)) . '</p>';
    } else {
        $innerHtml = "
<p>Hi {$sFirstName},</p>
<p>Thank you for choosing {$sCo} for your recent water service!</p>
<p>We love hearing from our customers. Would you mind taking 20 seconds to tell us how we did?</p>
<p>Clicking the link below opens our official Google page with 5 stars automatically selected &mdash; all you have to do is type your thoughts:</p>
<p style='text-align:center;margin:24px 0'>
  <a href='{$googleLink}' style='display:inline-block;background:#0d9488;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:15px'>&#x1F449; Click Here to Review Us on Google</a>
</p>
<p>Thank you for supporting a local Central Illinois business!</p>
<p>Best,<br>The {$sCo} Team<br>&#x1F4DE; {$sPh}</p>";
    }

 // Wrap in standard branded email shell
    require_once __DIR__ . '/autoEmail.php';
    $htmlBody = wrapEmailBody($innerHtml, $co, $ph, $web, 'Your Feedback Matters to Us!');
    $plain    = strip_tags($htmlBody);

    $sent = false;

    if (!$sent && function_exists('getValidGmailToken')) {
        $cfg   = getGmailConfig($db);
        $token = getValidGmailToken($db, $cfg);
        if ($token) {
            $from = $cfg['gmail_authorized_email'] ?? ($settings['company_email'] ?? '');
            $sent = sendViaGmailApi($token, $from, $toEmail, $toName, $subject, $htmlBody, $plain);
        }
    }

    if (!$sent && !empty($settings['smtp_pass'])) {
        $sent = sendViaSMTP(
            $toEmail, $toName, $subject, $htmlBody,
            $settings['smtp_host']      ?? 'smtp.gmail.com',
            (int)($settings['smtp_port']  ?? 587),
            $settings['smtp_user']      ?? ($settings['company_email'] ?? ''),
            $settings['smtp_pass'],
            $settings['company_email']  ?? '',
            $settings['smtp_from_name'] ?? $co
        );
    }

    if (!$sent) {
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$co} <{$settings['company_email']}>\r\n";
        $sent = (bool)mail($toEmail, $subject, $htmlBody, $headers);
    }

    if ($sent) {
        try {
            $db->prepare(
                "INSERT INTO email_log (appointment_id, email_type, to_email, subject, sent_at, success)
                 VALUES (?, 'review_request', ?, ?, NOW(), 1)"
            )->execute([$appointmentId, $toEmail, $subject]);
        } catch (\Throwable $e) { /* non-fatal - column may not exist */ }
        sendJson(['message' => 'Review request sent to ' . $toEmail]);
        exit;
    } else {
        sendError(500, 'Email delivery failed - check Gmail/SMTP settings');
        exit;
    }
}
