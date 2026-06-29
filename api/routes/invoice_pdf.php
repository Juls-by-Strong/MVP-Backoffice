<?php
// Generates a professional invoice PDF on-the-fly using PHP
// No external libraries required - pure PHP PDF generation
//
// Routes:
// GET /admin/invoices/{id}/pdf
// GET /tech/invoices/{id}/pdf
// GET /customer/invoices/{id}/pdf (customers can only see their own)

// Edit these values or set the matching environment variables.
if (!defined('MVP_VERSION')) require_once __DIR__ . '/../config/version.php';

define('PDF_COMPANY_NAME',    getenv('PDF_COMPANY_NAME')    ?: 'Acme Water Service');
define('PDF_COMPANY_ADDRESS', getenv('PDF_COMPANY_ADDRESS') ?: '123 Main Street, Anytown, ST 00000');
define('PDF_COMPANY_PHONE',   getenv('PDF_COMPANY_PHONE')   ?: '(555) 555-0100');
define('PDF_COMPANY_WEBSITE', getenv('PDF_COMPANY_WEBSITE') ?: 'example.com');
// Path to logo relative to this file - set to null to use text-only header
define('PDF_LOGO_PATH',       __DIR__ . '/../../assets/logo.png');
// Accent colour (R,G,B)
define('PDF_COLOR_R', 13);
define('PDF_COLOR_G', 148);
define('PDF_COLOR_B', 136);

function handleInvoicePdf(PDO $db, string $role, int $invoiceId, ?int $userId): void {

    $settings = [];
    try {
        $s = $db->query("SELECT setting_key, setting_value FROM company_settings");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $settings[$r['setting_key']] = $r['setting_value'];
    } catch (\Throwable $e) { /* table may not exist yet - fall back to constants */ }

    $stmt = $db->prepare(
        "SELECT i.*,
                COALESCE(NULLIF(TRIM(c.company_name),''), CONCAT(c.first_name,' ',c.last_name)) AS customer_name,
                c.first_name, c.last_name, c.company_name,
                c.phone AS customer_phone,
                c.email AS customer_email,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.billing_address, c.billing_city, c.billing_state, c.billing_zip,
                c.user_id AS customer_user_id,
                st.name AS service_type,
                a.confirmed_date,
                a.confirmed_time,
                CONCAT(tech.first_name,' ',tech.last_name) AS technician_name
         FROM invoices i
         JOIN customers c ON i.customer_id = c.customer_id
         LEFT JOIN appointments a ON i.appointment_id = a.appointment_id
         LEFT JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN users tech ON a.technician_id = tech.user_id
         WHERE i.invoice_id = ?"
    );
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { http_response_code(404); echo json_encode(['error'=>'Invoice not found']); exit; }

    if ($role === 'customer' && (int)$inv['customer_user_id'] !== $userId) {
        http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
    }

    $stmt2 = $db->prepare(
        "SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY line_id"
    );
    $stmt2->execute([$invoiceId]);
    $lines = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $stmt3 = $db->prepare(
        "SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS recorded_by_name
         FROM payments p
         LEFT JOIN users u ON p.recorded_by = u.user_id
         WHERE p.invoice_id = ? ORDER BY p.payment_date, p.payment_id"
    );
    $stmt3->execute([$invoiceId]);
    $payments = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    $apptNotes = [];
    if (!empty($inv['appointment_id'])) {
        $visClause = ($role === 'customer') ? 'AND n.is_visible_to_customer = 1' : '';
        $stmt4 = $db->prepare(
            "SELECT n.note_text, n.is_visible_to_customer, n.created_at,
                    COALESCE(CONCAT(u.first_name,' ',u.last_name), u.email) AS author_name,
                    u.role AS author_role
             FROM customer_notes n
             JOIN users u ON n.author_id = u.user_id
             WHERE n.appointment_id = ?
             $visClause
             ORDER BY n.created_at ASC"
        );
        $stmt4->execute([$inv['appointment_id']]);
        $apptNotes = $stmt4->fetchAll(PDO::FETCH_ASSOC);
    }

    $pdf = new InvoicePdf($settings);
    $pdf->generate($inv, $lines, $payments, $apptNotes, $role);
}

// Minimal self-contained PDF generator class
// Produces proper PDF 1.4 output with no dependencies
class InvoicePdf {

    protected array  $objects = [];
    protected int    $objCount = 0;
    protected array  $pages = [];
    protected int    $currentPage = 0;
    protected string $pageStream = '';
    protected float  $y = 0;
    protected float  $pageHeight = 841.89; // A4
    protected float  $pageWidth  = 595.28;
    protected float  $marginL = 45;
    protected float  $marginR = 45;
    protected float  $marginT = 45;
    protected float  $contentWidth;
    protected int    $fontId = 1;
    protected int    $imageId = 0;
    protected ?array $logoInfo = null;
    protected array  $images   = [];   // extra embedded images (QR codes etc.)
    protected array  $cfg = [];
    protected float  $headerMetaStartY = 0; // set by drawHeader, used by drawInvoiceMeta

    public function __construct(array $settings = []) {
        $this->contentWidth = $this->pageWidth - $this->marginL - $this->marginR;
        $this->cfg = [
            'name'    => $settings['company_name']    ?? PDF_COMPANY_NAME,
            'address' => $settings['company_address'] ?? '',
            'city'    => $settings['company_city']    ?? '',
            'state'   => $settings['company_state']   ?? '',
            'zip'     => $settings['company_zip']     ?? '',
            'phone'   => $settings['company_phone']   ?? PDF_COMPANY_PHONE,
            'website' => $settings['company_website'] ?? PDF_COMPANY_WEBSITE,
            'logo'    => !empty($settings['logo_path']) ? $settings['logo_path'] : PDF_LOGO_PATH,
            'r'       => PDF_COLOR_R,
            'g'       => PDF_COLOR_G,
            'b'       => PDF_COLOR_B,
            'google_review_url'   => $settings['google_review_url']   ?? '',
            'facebook_review_url' => $settings['facebook_review_url'] ?? '',
            'nextdoor_review_url' => $settings['nextdoor_review_url'] ?? '',
            'customer_app_url'    => $settings['customer_app_url']    ?? 'https://example.com/customer.html',
        ];
 // Build full address string
        $addr = $this->cfg['address'];
        $city = trim($this->cfg['city'] . ', ' . $this->cfg['state'] . ' ' . $this->cfg['zip']);
        $this->cfg['address_full'] = $addr . ($addr && $city ? ', ' . $city : $city);
    }

    public function generate(array $inv, array $lines, array $payments, array $apptNotes = [], string $role = 'admin'): void {
        $this->build($inv, $lines, $payments, $apptNotes, $role);
        $bytes    = $this->buildPdfBytes($inv['invoice_number']);
        $filename = 'Invoice-' . preg_replace('/[^A-Za-z0-9\-]/', '', $inv['invoice_number']) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-cache');
        echo $bytes;
        exit;
    }

 // ENTRY POINT: return raw PDF bytes (for email attachment)
    public function getBytes(array $inv, array $lines, array $payments, array $apptNotes = [], string $role = 'admin'): string {
        $this->build($inv, $lines, $payments, $apptNotes, $role);
        return $this->buildPdfBytes($inv['invoice_number']);
    }

    protected function build(array $inv, array $lines, array $payments, array $apptNotes = [], string $role = 'admin'): void {
 // Reset state so the object can be reused
        $this->objects    = [];
        $this->objCount   = 0;
        $this->pages      = [];
        $this->pageStream = '';
        $this->logoInfo   = null;
        $this->images     = [];

        $this->beginPage();
        $this->drawHeader($inv);
        $this->drawBillTo($inv);
        $this->drawInvoiceMeta($inv);
        if (!empty($apptNotes)) $this->drawAppointmentNotes($apptNotes, $role);
        $this->y += 18;
        $this->drawLinesTable($lines);
        $this->drawTotals($inv, $payments);
        if ($inv['notes']) $this->drawNotes($inv['notes']);
        $this->drawReviewSection();
        $this->drawFooter();
        $this->endPage();
    }

    protected function beginPage(): void {
        $this->pageStream = '';
        $this->y = $this->pageHeight - $this->marginT;
    }

    protected function endPage(): void {
        $this->pages[] = $this->pageStream;
    }

    protected function newPage(): void {
        $this->endPage();
        $this->beginPage();
        $this->y = $this->pageHeight - $this->marginT;
    }

    protected function checkPageBreak(float $needed): void {
        if ($this->y - $needed < 60) $this->newPage();
    }

    protected function setColor(int $r, int $g, int $b, bool $fill = true): void {
        $op = $fill ? 'rg' : 'RG';
        $this->pageStream .= sprintf("%.3f %.3f %.3f $op\n", $r/255, $g/255, $b/255);
    }

    protected function setGray(float $v, bool $fill = true): void {
        $op = $fill ? 'g' : 'G';
        $this->pageStream .= sprintf("%.3f $op\n", $v);
    }

    protected function rect(float $x, float $y, float $w, float $h, string $op = 'f'): void {
        $py = $this->pageHeight - $y - $h;
        $this->pageStream .= sprintf("%.2f %.2f %.2f %.2f re $op\n", $x, $py, $w, $h);
    }

    protected function line(float $x1, float $y1, float $x2, float $y2, float $lw = 0.5): void {
        $py1 = $this->pageHeight - $y1;
        $py2 = $this->pageHeight - $y2;
        $this->pageStream .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $lw, $x1, $py1, $x2, $py2);
    }

    protected function text(float $x, float $y, string $text, float $size, bool $bold = false,
                           int $r = 40, int $g = 40, int $b = 40): void {
        $font  = $bold ? 'F2' : 'F1';
        $py    = $this->pageHeight - $y;
        $safe  = $this->pdfString($text);
        $this->setColor($r, $g, $b);
        $this->pageStream .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $font, $size, $x, $py, $safe);
    }

    protected function textRight(float $x, float $y, string $text, float $size, bool $bold = false,
                                int $r = 40, int $g = 40, int $b = 40): void {
        $w = $this->stringWidth($text, $size, $bold);
        $this->text($x - $w, $y, $text, $size, $bold, $r, $g, $b);
    }

 // Approximate string width using Helvetica metrics (avg 0.5 * size per char)
    protected function stringWidth(string $text, float $size, bool $bold = false): float {
        return strlen($text) * $size * 0.5;
    }

    protected function pdfString(string $s): string {
 // Replace unicode chars that have no WinAnsiEncoding equivalent
        $s = str_replace(["\xe2\x80\x94", "\xe2\x80\x93", "\xe2\x80\x98", "\xe2\x80\x99",
                          "\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x9c\x93", "\xc2\xa0"],
                         [' - ',          ' - ',          "'",             "'",
                          '"',             '"',            'Y',             ' '], $s);
        $s = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        return str_replace(['\\','(',')',"\r","\n"], ['\\\\','\\(','\\)','',''], $s);
    }

    protected function drawHeader(array $inv): void {
        $cfg = $this->cfg;
 // Teal bar across top
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect(0, 0, $this->pageWidth, 6);

        $topY = $this->marginT + 10;

 // Try to draw logo
        $logoDrawn = false;
        $logoPath  = $cfg['logo'];
        if ($logoPath && file_exists($logoPath)) {
            $imgData = @getimagesize($logoPath);
            if ($imgData && $imgData[2] === IMAGETYPE_PNG) {
                $logoH = 38;
                $logoW = $logoH * ($imgData[0] / $imgData[1]);
                $logoW = min($logoW, 110);
                $this->logoInfo = ['path'=>$logoPath,'x'=>$this->marginL,'y'=>$topY,'w'=>$logoW,'h'=>$logoH];
                $logoDrawn = true;
                $textX = $this->marginL + $logoW + 12;
            }
        }

        if (!$logoDrawn) {
            $this->text($this->marginL, $topY + 6, $cfg['name'], 16, true, $cfg['r'], $cfg['g'], $cfg['b']);
            $textX = $this->marginL;
            $topY += 20;
        } else {
            $textX = $this->marginL + ($this->logoInfo['w'] ?? 0) + 12;
        }

 // Company info
        $this->text($textX, $topY + 4,  $cfg['name'],         10, true,  50, 50, 50);
        $this->text($textX, $topY + 16, $cfg['address_full'], 8.5, false, 90, 90, 90);
        $this->text($textX, $topY + 27, $cfg['phone'],        8.5, false, 90, 90, 90);
        $this->text($textX, $topY + 38, $cfg['website'],      8.5, false, 90, 90, 90);

 // INVOICE title - right side
        $this->text($this->pageWidth - $this->marginR - 80, $topY + 4, 'INVOICE', 20, true,
            $cfg['r'], $cfg['g'], $cfg['b']);
        $this->text($this->pageWidth - $this->marginR - 80, $topY + 26,
            '#' . $inv['invoice_number'], 10, false, 100, 100, 100);

 // Status badge
        $statusColors = [
            'paid'  => [22,163,74],
            'sent'  => [37,99,235],
            'void'  => [239,68,68],
            'draft' => [107,114,128],
        ];
        $sc = $statusColors[$inv['status']] ?? [107,114,128];
        $statusText = strtoupper($inv['status']);
        $this->setColor($sc[0], $sc[1], $sc[2]);
        $this->rect($this->pageWidth - $this->marginR - 80, $topY + 30, 50, 14);
        $this->text($this->pageWidth - $this->marginR - 77, $topY + 40, $statusText, 8, true, 255, 255, 255);

 // Divider
        $this->y = $topY + 56;
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b'], false);
        $this->line($this->marginL, $this->y, $this->pageWidth - $this->marginR, $this->y, 0.8);
        $this->y += 14;

 // Meta block starts at the same Y as BILL TO (after the divider)
        $this->headerMetaStartY = $this->y;
    }

    protected function drawBillTo(array $inv): void {
        $cfg      = $this->cfg;
        $startY   = $this->y;   // remember Y so SERVICE ADDRESS can align beside BILL TO
        $midX     = $this->marginL + $this->contentWidth * 0.42; // service address column X

        $this->text($this->marginL, $this->y, 'BILL TO', 7.5, true, $cfg['r'], $cfg['g'], $cfg['b']);
        $this->y += 13;

 // Company name (business customers)
        if (!empty($inv['company_name'])) {
            $this->text($this->marginL, $this->y, $inv['company_name'], 10, true);
            $this->y += 13;
 // Contact name (first + last) on next line, slightly smaller
            $contactName = trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? ''));
            if ($contactName) {
                $this->text($this->marginL, $this->y, $contactName, 9, false, 60, 60, 60);
                $this->y += 13;
            }
        } else {
            $this->text($this->marginL, $this->y, $inv['customer_name'], 10, true);
            $this->y += 13;
        }

 // Use billing address if set, otherwise fall back to service address
        $hasBilling = !empty($inv['billing_address']) || !empty($inv['billing_city']);
        $addr     = $hasBilling ? ($inv['billing_address'] ?? '') : ($inv['service_address'] ?? '');
        $city     = $hasBilling ? ($inv['billing_city']    ?? '') : ($inv['service_city']    ?? '');
        $state    = $hasBilling ? ($inv['billing_state']   ?? '') : ($inv['service_state']   ?? '');
        $zip      = $hasBilling ? ($inv['billing_zip']     ?? '') : ($inv['service_zip']     ?? '');

        if ($addr) {
            $this->text($this->marginL, $this->y, trim($addr), 9, false, 80, 80, 80);
            $this->y += 12;
        }
        $cityLine = trim($city . ', ' . $state . ' ' . $zip);
        if ($cityLine !== ', ') {
            $this->text($this->marginL, $this->y, $cityLine, 9, false, 80, 80, 80);
            $this->y += 12;
        }

        if ($inv['customer_phone']) {
            $this->text($this->marginL, $this->y, $inv['customer_phone'], 9, false, 80, 80, 80);
            $this->y += 12;
        }
        if ($inv['customer_email']) {
            $this->text($this->marginL, $this->y, $inv['customer_email'], 9, false, 80, 80, 80);
            $this->y += 12;
        }

 // SERVICE ADDRESS column (middle) - only shown when billing address differs
 // When there's no separate billing address, service address is already shown in
 // BILL TO, so repeating it in this column is redundant.
        if ($hasBilling) {
            $svcY = $startY;
            $this->text($midX, $svcY, 'SERVICE ADDRESS', 7.5, true, $cfg['r'], $cfg['g'], $cfg['b']);
            $svcY += 13;
            $svcAddr = trim($inv['service_address'] ?? '');
            if ($svcAddr) {
                $this->text($midX, $svcY, $svcAddr, 9, false, 80, 80, 80);
                $svcY += 12;
            }
            $svcCity = trim(($inv['service_city'] ?? '') . ', ' . ($inv['service_state'] ?? '') . ' ' . ($inv['service_zip'] ?? ''));
            if ($svcCity !== ', ') {
                $this->text($midX, $svcY, $svcCity, 9, false, 80, 80, 80);
            }
        }
    }

    protected function drawInvoiceMeta(array $inv): void {
        $rx      = $this->pageWidth - $this->marginR;
        $labelX  = $rx - 120;       // left edge of meta block
        $valueX  = $rx - 4;         // right edge for values (4pt inset from margin)
        $maxValW = 76;              // max value width before wrapping
        $lineH   = 13;
        $metaY   = $this->headerMetaStartY > 0 ? $this->headerMetaStartY : ($this->marginT + 70);

        $rows = [
            ['Issue Date', $inv['issue_date'] ?? '-'],
            ['Due Date',   $inv['due_date']   ?? 'On Receipt'],
        ];
        if (!empty($inv['service_type'])) {
            $rows[] = ['Service Type', $inv['service_type']];
        }
        if (!empty($inv['confirmed_date'])) {
            $timeStr = '';
            if (!empty($inv['confirmed_time'])) {
 // confirmed_time may be 'HH:MM:SS' or 'HH:MM' - format as 12-hr, no space before am/pm
                $t = date_create_from_format('H:i:s', $inv['confirmed_time'])
                  ?: date_create_from_format('H:i',   $inv['confirmed_time']);
                $timeStr = $t ? ' ' . date_format($t, 'g:ia') : ' ' . substr($inv['confirmed_time'], 0, 5);
            }
            $rows[] = ['Appt Date', $inv['confirmed_date'] . $timeStr];
        }
        if (!empty($inv['technician_name'])) {
            $rows[] = ['Technician', $inv['technician_name']];
        }

        foreach ($rows as $row) {
            [$label, $value] = $row;
            $this->text($labelX, $metaY, $label . ':', 8.5, true, 90, 90, 90);

 // Value left-aligned after the label column
            $words    = explode(' ', $value);
            $line     = '';
            $valLines = [];
            foreach ($words as $word) {
                $test = $line ? $line . ' ' . $word : $word;
                if ($this->stringWidth($test, 8.5) > $maxValW && $line) {
                    $valLines[] = $line;
                    $line = $word;
                } else {
                    $line = $test;
                }
            }
            if ($line) $valLines[] = $line;

            foreach ($valLines as $i => $vl) {
                $this->textRight($valueX, $metaY, $vl, 8.5, false, 60, 60, 60);
                if ($i < count($valLines) - 1) $metaY += $lineH;
            }
            $metaY += $lineH;
        }
    }

    protected function drawLinesTable(array $lines): void {
        $this->checkPageBreak(50);

        $colW = [
            'desc'  => $this->contentWidth * 0.50,
            'qty'   => $this->contentWidth * 0.10,
            'price' => $this->contentWidth * 0.18,
            'tax'   => $this->contentWidth * 0.08,
            'total' => $this->contentWidth * 0.14,
        ];

        $x = $this->marginL;
        $hH = 18; // header height

 // Header background
        $this->setColor($this->cfg['r'], $this->cfg['g'], $this->cfg['b']);
        $this->rect($x, $this->y, $this->contentWidth, $hH);

 // Header text
        $cols = [
            ['Description', $x + 6,                                               'left'],
            ['Qty',         $x + $colW['desc'] + $colW['qty'] - 4,               'right'],
            ['Unit Price',  $x + $colW['desc'] + $colW['qty'] + $colW['price']-4,'right'],
            ['Tax',         $x + $colW['desc'] + $colW['qty'] + $colW['price'] + $colW['tax'] - 4, 'right'],
            ['Total',       $x + $this->contentWidth - 4,                         'right'],
        ];

        foreach ($cols as $col) {
            if ($col[2] === 'left') {
                $this->text($col[1], $this->y + 12, $col[0], 8.5, true, 255, 255, 255);
            } else {
                $this->textRight($col[1], $this->y + 12, $col[0], 8.5, true, 255, 255, 255);
            }
        }
        $this->y += $hH;

 // Rows
        $rowNum = 0;
        foreach ($lines as $line) {
            $lineName = $line['line_name'] ?: $line['description'];
            $detail   = ($line['line_name'] && $line['description'] && $line['description'] !== $line['line_name'])
                        ? $line['description'] : '';
            $hasDetail = $detail !== '';
            $rowH = $hasDetail ? 34 : 22;
            $this->checkPageBreak($rowH + 4);

 // Alternate row shading
            if ($rowNum % 2 === 0) {
                $this->setGray(0.965);
                $this->rect($x, $this->y, $this->contentWidth, $rowH);
            }

 // Clip name to fit column
            if (strlen($lineName) > 55) $lineName = substr($lineName, 0, 52) . '...';

            $typeLabel = str_replace('_',' ', strtoupper($line['line_type'] ?? ''));
 // type label at top, name below with enough gap to avoid overlap
            $nameY = $hasDetail ? $this->y + 13 : $this->y + 17;
            $this->text($x + 6, $this->y + 5,  $typeLabel, 6.5, false, 140, 140, 140);
            $this->text($x + 6, $nameY,         $lineName,  8.5, true);
            if ($hasDetail) {
                $detail = strlen($detail) > 60 ? substr($detail, 0, 57) . '...' : $detail;
                $this->text($x + 6, $this->y + 25, $detail, 7.5, false, 100, 100, 100);
            }

            $numY   = $hasDetail ? $this->y + 19 : $this->y + 17;
            $qtyX   = $x + $colW['desc'] + $colW['qty'] - 4;
            $priceX = $x + $colW['desc'] + $colW['qty'] + $colW['price'] - 4;
            $taxX   = $x + $colW['desc'] + $colW['qty'] + $colW['price'] + $colW['tax'] - 4;
            $totX   = $x + $this->contentWidth - 4;

            $this->textRight($qtyX,   $numY, (string)(float)$line['quantity'],               8.5);
            $this->textRight($priceX, $numY, '$'.number_format((float)$line['unit_price'],2), 8.5);
            $this->textRight($taxX,   $numY, $line['is_taxable'] ? 'Y' : '',                 8.5);
            $this->textRight($totX,   $numY, '$'.number_format((float)$line['line_total'],2), 8.5, true);

 // Bottom border
            $this->setGray(0.85, false);
            $this->line($x, $this->y + $rowH, $x + $this->contentWidth, $this->y + $rowH, 0.3);
            $this->y += $rowH;
            $rowNum++;
        }
    }

    protected function drawTotals(array $inv, array $payments): void {
        $this->y += 8;
        $this->checkPageBreak(100);
        $cfg   = $this->cfg;
        $rx    = $this->pageWidth - $this->marginR;
        $lx    = $rx - 160;
        $lineH = 16;

        $subtotal  = (float)($inv['subtotal']    ?? 0);
        $taxAmt    = (float)($inv['tax_amount']  ?? 0);
        $taxRate   = round((float)($inv['tax_rate'] ?? 0) * 100, 2);
        $cardFee   = (float)($inv['card_fee_amount'] ?? 0);
        $total     = (float)($inv['total']       ?? 0);

 // Subtotal
        $this->text($lx, $this->y + 11, 'Subtotal', 8.5, false, 90, 90, 90);
        $this->textRight($rx, $this->y + 11, '$'.number_format($subtotal, 2), 8.5, false, 60, 60, 60);
        $this->y += $lineH;

 // Tax
        $this->text($lx, $this->y + 11, "Tax ({$taxRate}%)", 8.5, false, 90, 90, 90);
        $this->textRight($rx, $this->y + 11, '$'.number_format($taxAmt, 2), 8.5, false, 60, 60, 60);
        $this->y += $lineH;

 // Card fee (after tax, before grand total)
        if ($cardFee > 0) {
            $this->text($lx, $this->y + 11, 'Credit/Debit Service Fee (3.5%)', 8.5, false, 90, 90, 90);
            $this->textRight($rx, $this->y + 11, '$'.number_format($cardFee, 2), 8.5, false, 60, 60, 60);
            $this->y += $lineH;
        }

 // Total - highlighted
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect($lx - 4, $this->y - 2, 164, $lineH + 2);
        $this->text($lx + 6, $this->y + 11, 'Total', 9, true, 255, 255, 255);
        $this->textRight($rx - 4, $this->y + 11, '$'.number_format($total, 2), 9, true, 255, 255, 255);
        $this->y += $lineH;

 // Payments received
        if (!empty($payments)) {
            $this->y += 4;
            $this->text($lx, $this->y + 11, 'PAYMENTS RECEIVED', 7.5, true, $cfg['r'], $cfg['g'], $cfg['b']);
            $this->y += $lineH;
            $totalPaid = 0;
            foreach ($payments as $p) {
                $amount     = (float)$p['amount'];
                $totalPaid += $amount;
                $method     = ucfirst(str_replace('_', ' ', $p['payment_method'] ?? ''));
                $date       = $p['payment_date'] ?? '';
                $label      = $date . ' - ' . $method;
                $this->text($lx, $this->y + 11, $label, 8, false, 90, 90, 90);
                $this->textRight($rx, $this->y + 11, '-$'.number_format($amount, 2), 8, false, 22, 163, 74);
                $this->y += $lineH;
 // Payment notes (e.g. check number)
                if (!empty($p['payment_notes'])) {
                    $pNote = trim($p['payment_notes']);
                    if (strlen($pNote) > 80) $pNote = substr($pNote, 0, 77) . '...';
                    $this->text($lx, $this->y + 9, $pNote, 7.5, false, 130, 130, 130);
                    $this->y += 13;
                }
            }
 // Balance due
            $balance = $total - $totalPaid;
            $this->setGray(0.94);
            $this->rect($lx - 4, $this->y - 2, 164, $lineH + 2);
            $balLabel  = $balance <= 0 ? 'PAID IN FULL' : 'Balance Due';
            $balColor  = $balance <= 0 ? [22,163,74] : [239,68,68];
            $this->text($lx + 6, $this->y + 11, $balLabel, 9, true, $balColor[0], $balColor[1], $balColor[2]);
            $this->textRight($rx - 4, $this->y + 11, '$'.number_format(max($balance,0), 2), 9, true, $balColor[0], $balColor[1], $balColor[2]);
            $this->y += $lineH;
        }
    }

    protected function drawAppointmentNotes(array $notes, string $role): void {
        $cfg  = $this->cfg;
        $this->y += 14;
        $this->checkPageBreak(40);

 // Section divider line
        $this->setGray(0.82, false);
        $this->line($this->marginL, $this->y, $this->pageWidth - $this->marginR, $this->y, 0.4);
        $this->y += 10;

        $this->text($this->marginL, $this->y, 'SERVICE NOTES', 7.5, true, $cfg['r'], $cfg['g'], $cfg['b']);
        $this->y += 13;

        $maxW = $this->contentWidth - 10;
        foreach ($notes as $note) {
            $this->checkPageBreak(30);

 // Author / date line
            $roleLabel = $note['author_role'] === 'admin' ? 'Office' : 'Technician';
 // Customers see role label; admin/tech see full name
            $authorStr = ($role === 'customer')
                ? $roleLabel
                : ($note['author_name'] . ' (' . $roleLabel . ')');
            $date = substr($note['created_at'], 0, 10);
            $meta = $authorStr . '  ·  ' . $date;
            $this->text($this->marginL, $this->y, $meta, 7.5, false, 130, 130, 130);

 // Visibility badge for admin/tech
            if ($role !== 'customer') {
                $badge = $note['is_visible_to_customer'] ? 'Visible to customer' : 'Internal only';
                $badgeColor = $note['is_visible_to_customer'] ? [22,163,74] : [150,150,150];
                $bw = $this->stringWidth($badge, 7);
                $bx = $this->pageWidth - $this->marginR - $bw - 8;
                $this->setColor($badgeColor[0], $badgeColor[1], $badgeColor[2]);
                $this->rect($bx - 4, $this->y - 8, $bw + 8, 11);
                $this->text($bx, $this->y, $badge, 7, false, 255, 255, 255);
            }
            $this->y += 12;

 // Note text - word-wrapped
            $words = explode(' ', $note['note_text']);
            $line  = '';
            foreach ($words as $word) {
                $test = $line ? $line . ' ' . $word : $word;
                if ($this->stringWidth($test, 8.5) > $maxW && $line) {
                    $this->checkPageBreak(14);
                    $this->text($this->marginL + 6, $this->y, $line, 8.5, false, 55, 55, 55);
                    $this->y += 12;
                    $line = $word;
                } else {
                    $line = $test;
                }
            }
            if ($line) {
                $this->text($this->marginL + 6, $this->y, $line, 8.5, false, 55, 55, 55);
                $this->y += 12;
            }
            $this->y += 4; // spacing between notes
        }

 // Closing divider
        $this->setGray(0.82, false);
        $this->line($this->marginL, $this->y, $this->pageWidth - $this->marginR, $this->y, 0.4);
    }

    protected function drawNotes(string $notes): void {
        $this->y += 20;
        $this->checkPageBreak(40);
        $this->text($this->marginL, $this->y, 'NOTES', 7.5, true, $this->cfg['r'], $this->cfg['g'], $this->cfg['b']);
        $this->y += 12;
 // Word-wrap notes
        $words = explode(' ', $notes);
        $line  = '';
        $maxW  = $this->contentWidth * 0.6;
        foreach ($words as $word) {
            $test = $line ? $line . ' ' . $word : $word;
            if ($this->stringWidth($test, 8.5) > $maxW && $line) {
                $this->text($this->marginL, $this->y, $line, 8.5, false, 80, 80, 80);
                $this->y += 12;
                $line = $word;
            } else {
                $line = $test;
            }
        }
        if ($line) {
            $this->text($this->marginL, $this->y, $line, 8.5, false, 80, 80, 80);
            $this->y += 12;
        }
    }

 // This section is always pinned to the bottom of the page.
 // It draws upward from a fixed Y anchor just above the footer,
 // so it never overlaps content and never floats mid-page.
    protected function drawReviewSection(): void {
        $cfg          = $this->cfg;
        $googleUrl    = $cfg['google_review_url']   ?? '';
        $fbUrl        = $cfg['facebook_review_url'] ?? '';
        $nextdoorUrl  = $cfg['nextdoor_review_url'] ?? '';
        $appUrl       = $cfg['customer_app_url']    ?? '';
        $hasReview    = $googleUrl || $fbUrl || $nextdoorUrl;

 // Layout tightened so the whole block fits on page 1 for typical
 // (~5-6 line) invoices. Longer invoices fall back to page 2 with a
 // continuation marker on page 1.
 // Top divider + advance: ~12pt
 // Review section (if present): heading + subtext + QR row + referral + divider = ~127pt
 // App section: heading + QR + 4 text rows = ~63pt
        $footerY      = $this->pageHeight - 22;        // top of footer line
        $blockH       = 12 + ($hasReview ? 127 : 0) + 63;
        $blockStartY  = $footerY - $blockH - 10;       // pin block above footer

 // If content has already flowed past the block start, leave a
 // continuation marker above the footer on the current page, then
 // push the block to a new page.
        if ($this->y > $blockStartY - 10) {
            $contY = $this->pageHeight - 38;
            $this->setGray(0.85, false);
            $this->line($this->marginL, $contY - 8, $this->pageWidth - $this->marginR, $contY - 8, 0.3);
            $this->text($this->marginL, $contY,
                'Review, referrals, and customer app information continued on the next page.',
                8.5, false, 110, 110, 110);

            $this->newPage();
            $footerY     = $this->pageHeight - 22;
            $blockStartY = $footerY - $blockH - 10;
        }

 // Jump directly to the pinned start Y
        $this->y = $blockStartY;

        $this->setGray(0.85, false);
        $this->line($this->marginL, $this->y, $this->pageWidth - $this->marginR, $this->y, 0.3);
        $this->y += 12;

        if ($hasReview) {
            $this->text($this->marginL, $this->y, 'Enjoying our service? Leave us a review!',
                10, true, $cfg['r'], $cfg['g'], $cfg['b']);
            $this->y += 12;
            $this->text($this->marginL, $this->y,
                'Scan a QR code below - it only takes 30 seconds and means the world to us.',
                8.5, false, 100, 100, 100);
            $this->y += 14;

            $qrSize = 50;
            $gap    = 18;
            $curX   = $this->marginL;
            if ($googleUrl) {
                $this->drawQrCode($curX, $this->y, $qrSize, $googleUrl);
                $this->text($curX, $this->y + $qrSize + 8, 'Google Review', 8, true, 60, 60, 60);
                $curX += $qrSize + $gap;
            }
            if ($fbUrl) {
                $this->drawQrCode($curX, $this->y, $qrSize, $fbUrl);
                $this->text($curX, $this->y + $qrSize + 8, 'Facebook Review', 8, true, 60, 60, 60);
                $curX += $qrSize + $gap;
            }
            if ($nextdoorUrl) {
                $this->drawQrCode($curX, $this->y, $qrSize, $nextdoorUrl);
                $this->text($curX, $this->y + $qrSize + 8, 'Nextdoor', 8, true, 60, 60, 60);
            }
            $this->y += $qrSize + 14;

            $this->text($this->marginL, $this->y, 'We love referrals!', 9, true, $cfg['r'], $cfg['g'], $cfg['b']);
            $this->y += 11;
            $this->text($this->marginL, $this->y, 'Ask us how to earn FREE filter changes when you refer a friend.', 8.5, false, 80, 80, 80);
            $this->y += 14;

            $this->setGray(0.85, false);
            $this->line($this->marginL, $this->y, $this->pageWidth - $this->marginR, $this->y, 0.3);
            $this->y += 12;
        }

        $this->text($this->marginL, $this->y, 'Get the MVP Customer App',
            10, true, $cfg['r'], $cfg['g'], $cfg['b']);
        $this->y += 12;

        $qrSize = 48;
        if ($appUrl) {
            $this->drawQrCode($this->marginL, $this->y, $qrSize, $appUrl);
        } else {
 // Grey placeholder when no URL set
            $this->setGray(0.88);
            $this->rect($this->marginL, $this->y, $qrSize, $qrSize);
        }

        $textX = $this->marginL + $qrSize + 14;
        $this->text($textX, $this->y + 9,  'Scan to open the customer portal', 8.5, true, 60, 60, 60);
        $this->text($textX, $this->y + 21, 'View invoices, service history, water test', 8, false, 100, 100, 100);
        $this->text($textX, $this->y + 31, 'results, and more - on any device.', 8, false, 100, 100, 100);

 // Human-readable URL on its own line below the description
        $displayUrl = $appUrl ?: 'example.com/customer.html';
        $this->text($textX, $this->y + 43, $displayUrl, 8, false, $cfg['r'], $cfg['g'], $cfg['b']);
    }

    protected function drawQrCode(float $x, float $y, float $size, string $data): void {
        $px  = 200;
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $px . 'x' . $px
             . '&ecc=M&qzone=2&data=' . rawurlencode($data);

        $pngBytes = false;
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw && strlen($raw) > 100) $pngBytes = $raw;

        if ($pngBytes && function_exists('imagecreatefrompng')) {
            $tmp = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
            file_put_contents($tmp, $pngBytes);
            $imgInfo = $this->parsePng($tmp);
            @unlink($tmp);
            if ($imgInfo) {
 // Page index = number of pages already finalized via endPage().
 // $this->currentPage is unreliable here (never incremented), so use
 // count($this->pages) - it equals the 0-indexed page currently being drawn into.
                $this->images[] = array_merge($imgInfo, ['x'=>$x,'y'=>$y,'w'=>$size,'h'=>$size,'page'=>count($this->pages)]);
                return;
            }
        }

 // Fallback: grey placeholder with X
        $this->setGray(0.88);
        $this->rect($x, $y, $size, $size);
        $this->setGray(0.55, false);
        $this->line($x, $y, $x + $size, $y + $size, 0.5);
        $this->line($x + $size, $y, $x, $y + $size, 0.5);
    }

    protected function drawFooter(): void {
        $fy = $this->pageHeight - 22;
        $this->setGray(0.85, false);
        $this->line($this->marginL, $fy - 4, $this->pageWidth - $this->marginR, $fy - 4, 0.3);
        $this->text($this->marginL, $fy + 6, 'Thank you for your business!', 8, false, 130, 130, 130);
        $genText = 'Generated by MVP Backoffice ' . MVP_VERSION . ' on ' . date('m-d-Y');
        $this->textRight($this->pageWidth - $this->marginR, $fy + 6, $genText, 8, false, 130, 130, 130);
    }

    public function buildPdfBytes(string $invoiceNumber): string {
        $out     = "%PDF-1.4\n";
        $offsets = [];

 // Helper to add object
        $addObj = function(string $content) use (&$out, &$offsets): int {
            $id = count($offsets) + 1;
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$content\nendobj\n";
            return $id;
        };

 // Fonts
        $f1 = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
        $f2 = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");

 // Resources dict (built after we know font IDs)
 // Collect all images: logo first, then QR codes etc.
        $allImages = [];
        if ($this->logoInfo && file_exists($this->logoInfo['path'])) {
            $pd = $this->parsePng($this->logoInfo['path']);
            if ($pd) $allImages[] = array_merge($pd, [
                'x' => $this->logoInfo['x'],
                'y' => $this->logoInfo['y'],
                'w' => $this->logoInfo['w'],
                'h' => $this->logoInfo['h'],
                'page' => 0,
            ]);
        }
        foreach ($this->images as $img) {
            $allImages[] = array_merge($img, ['page' => $img['page'] ?? 0]);
        }

        $xobjDict  = '';
        $xobjCmds  = array_fill(0, count($this->pages), ''); // per-page image commands
        foreach ($allImages as $idx => $img) {
            $name  = 'Im' . ($idx + 1);
            $imgId = $addObj(
                "<< /Type /XObject /Subtype /Image"
                . " /Width {$img['w_px']} /Height {$img['h_px']}"
                . " /ColorSpace /{$img['cs']}"
                . " /BitsPerComponent {$img['bpc']}"
                . " /Filter /FlateDecode"
                . " /Length " . strlen($img['data'])
                . " >>\nstream\n" . $img['data'] . "\nendstream"
            );
            $xobjDict .= " /$name $imgId 0 R";
            $pdfY = $this->pageHeight - $img['y'] - $img['h'];
            $pg   = (int)($img['page'] ?? 0);
            $xobjCmds[$pg] .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n",
                $img['w'], $img['h'], $img['x'], $pdfY, $name);
        }

        if ($xobjDict) {
            $resources = "<< /Font << /F1 $f1 0 R /F2 $f2 0 R >> /XObject <<$xobjDict >> /ProcSet [/PDF /Text /ImageB /ImageC] >>";
            foreach ($this->pages as $pg => $stream) {
                if (!empty($xobjCmds[$pg])) $this->pages[$pg] = $xobjCmds[$pg] . $stream;
            }
        } else {
            $resources = "<< /Font << /F1 $f1 0 R /F2 $f2 0 R >> /ProcSet [/PDF /Text /ImageB /ImageC] >>";
        }

 // Page streams
        $pageIds = [];
        $streamIds = [];
        foreach ($this->pages as $i => $stream) {
            $sId = $addObj(
                "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream"
            );
            $streamIds[] = $sId;
        }

 // Pages parent - placeholder ID
        $pagesId = count($offsets) + 1 + count($this->pages);

 // Page objects
        foreach ($streamIds as $i => $sId) {
            $pid = $addObj(
                "<< /Type /Page /Parent $pagesId 0 R"
                . " /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}]"
                . " /Contents $sId 0 R"
                . " /Resources $resources >>"
            );
            $pageIds[] = $pid;
        }

 // Pages dict
        $kidsStr = implode(' 0 R ', $pageIds) . ' 0 R';
        $pagesObjId = $addObj(
            "<< /Type /Pages /Kids [$kidsStr] /Count " . count($pageIds) . " >>"
        );

 // Catalog
        $catId = $addObj("<< /Type /Catalog /Pages $pagesObjId 0 R >>");

 // Info
        $safeNum = $this->pdfString($invoiceNumber);
        $infoId  = $addObj("<< /Title (Invoice $safeNum) /Creator (MVP Backoffice) >>");

 // XRef
        $xrefOffset = strlen($out);
        $objCount   = count($offsets);
        $out .= "xref\n0 " . ($objCount + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= str_pad($off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $out .= "trailer\n<< /Size " . ($objCount + 1) . " /Root $catId 0 R /Info $infoId 0 R >>\n";
        $out .= "startxref\n$xrefOffset\n%%EOF";

        return $out;
    }

 // Parse PNG for PDF embedding.
 // Uses GD to decode the PNG into a raw 24-bit RGB byte stream, then
 // re-wraps it with FlateDecode so PDF readers render it correctly.
    protected function parsePng(string $path): ?array {
        if (!function_exists('imagecreatefrompng')) return null;
        $img = @imagecreatefrompng($path);
        if (!$img) return null;

        $w = imagesx($img);
        $h = imagesy($img);

 // Flatten onto white background (handles RGBA / palette PNGs)
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

 // Extract raw 24-bit RGB pixels
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c    = imagecolorat($flat, $x, $y);
                $raw .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
            }
        }
        imagedestroy($flat);

 // Compress the raw RGB stream
        $compressed = gzcompress($raw, 6);
        if ($compressed === false) return null;

        return [
            'w_px'=> $w,
            'h_px'=> $h,
            'cs'  => 'DeviceRGB',
            'bpc' => 8,
            'data'=> $compressed,
        ];
    }
}
// PdfQrCode - pure-PHP QR matrix generator (no GD, no libs)
// Encodes a UTF-8 string into a QR matrix (2D boolean array).
// Supports versions 1-10, ECC Level L.
class PdfQrCode {

 // Entry point: returns $size×$size 2-D array of bool
    public static function encode(string $data): array {
 // Try versions 1-10 until the data fits
        for ($version = 1; $version <= 10; $version++) {
            $capacity = self::byteCapacity($version, 0); // ECC-L
            if (strlen($data) <= $capacity) break;
        }
        $size      = 4 * $version + 17;
        $matrix    = array_fill(0, $size, array_fill(0, $size, null)); // null = unset
        $reserved  = array_fill(0, $size, array_fill(0, $size, false));

 // Finder patterns + separators
        self::placeFinderPattern($matrix, $reserved, 0, 0);
        self::placeFinderPattern($matrix, $reserved, $size - 7, 0);
        self::placeFinderPattern($matrix, $reserved, 0, $size - 7);

 // Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $v = ($i % 2 === 0);
            if ($matrix[6][$i] === null) { $matrix[6][$i] = $v; $reserved[6][$i] = true; }
            if ($matrix[$i][6] === null) { $matrix[$i][6] = $v; $reserved[$i][6] = true; }
        }

 // Dark module
        $matrix[$size - 8][8] = true; $reserved[$size - 8][8] = true;

 // Alignment patterns (version ≥ 2)
        $alignPos = self::alignmentPositions($version);
        foreach ($alignPos as $ar) {
            foreach ($alignPos as $ac) {
                if ($matrix[$ar][$ac] === null) self::placeAlignment($matrix, $reserved, $ar, $ac);
            }
        }

 // Format info areas (reserve only - fill after masking)
        self::reserveFormat($reserved, $size);

 // Data encoding: byte mode
        $bits = '';
        $bits .= '0100'; // mode indicator: byte
        $bits .= str_pad(decbin(strlen($data)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }

 // Terminator + padding
        $totalBits = self::totalDataBits($version, 0);
        $bits .= str_repeat('0', min(4, $totalBits - strlen($bits)));
        while (strlen($bits) % 8 !== 0) $bits .= '0';
        $padBytes = ['11101100','00010001'];
        $pi = 0;
        while (strlen($bits) < $totalBits) { $bits .= $padBytes[$pi++ % 2]; }

 // Error correction
        $dataBytes = [];
        for ($i = 0; $i < strlen($bits); $i += 8)
            $dataBytes[] = bindec(substr($bits, $i, 8));
        $ecBytes = self::reedSolomon($dataBytes, self::ecCount($version, 0));
        $allBytes = array_merge($dataBytes, $ecBytes);

 // Place data in matrix
        $bitStr = '';
        foreach ($allBytes as $b) $bitStr .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        self::placeData($matrix, $reserved, $bitStr, $size);

 // Apply best mask
        $best = -1; $bestScore = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $candidate = self::applyMask($matrix, $reserved, $m, $size);
            $score     = self::penaltyScore($candidate, $size);
            if ($score < $bestScore) { $bestScore = $score; $best = $m; $bestMatrix = $candidate; }
        }

 // Write format info (ECC-L + chosen mask)
        self::writeFormat($bestMatrix, $size, 0, $best);

        return $bestMatrix;
    }

    private static function byteCapacity(int $v, int $ecc): int {
 // ECC-L capacities for byte mode, versions 1-10
        $cap = [0,17,32,53,78,106,134,154,192,230,271];
        return $cap[$v] ?? 271;
    }

    private static function totalDataBits(int $v, int $ecc): int {
 // Total data codewords * 8, ECC-L
        $cw = [0,19,34,55,80,108,136,156,194,232,274];
        return ($cw[$v] ?? 274) * 8;
    }

    private static function ecCount(int $v, int $ecc): int {
 // EC codewords per block, ECC-L, single block (v1-v10 simplified)
        $ec = [0,7,10,15,20,26,18,20,24,30,18];
        return $ec[$v] ?? 18;
    }

    private static function alignmentPositions(int $version): array {
        $pos = [
            [],[], [6,18],[6,22],[6,26],[6,30],[6,34],
            [6,22,38],[6,24,42],[6,26,46],[6,28,50],
        ];
        return $pos[$version] ?? [];
    }

    private static function placeFinderPattern(array &$m, array &$r, int $row, int $col): void {
        $pat = [
            [1,1,1,1,1,1,1],
            [1,0,0,0,0,0,1],
            [1,0,1,1,1,0,1],
            [1,0,1,1,1,0,1],
            [1,0,1,1,1,0,1],
            [1,0,0,0,0,0,1],
            [1,1,1,1,1,1,1],
        ];
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $rr = $row + $dr; $cc = $col + $dc;
                if ($rr < 0 || $cc < 0 || $rr >= count($m) || $cc >= count($m)) continue;
                $r[$rr][$cc] = true;
                if ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6)
                    $m[$rr][$cc] = (bool)$pat[$dr][$dc];
                else
                    $m[$rr][$cc] = false; // separator
            }
        }
    }

    private static function placeAlignment(array &$m, array &$r, int $row, int $col): void {
        $pat = [[1,1,1,1,1],[1,0,0,0,1],[1,0,1,0,1],[1,0,0,0,1],[1,1,1,1,1]];
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                $rr = $row + $dr; $cc = $col + $dc;
                $r[$rr][$cc] = true;
                $m[$rr][$cc] = (bool)$pat[$dr+2][$dc+2];
            }
        }
    }

    private static function reserveFormat(array &$r, int $size): void {
        for ($i = 0; $i <= 8; $i++) { $r[8][$i] = true; $r[$i][8] = true; }
        for ($i = $size - 8; $i < $size; $i++) { $r[8][$i] = true; $r[$i][8] = true; }
    }

    private static function placeData(array &$m, array $r, string $bits, int $size): void {
        $bi = 0;
        $up = true;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5; // skip timing col
            for ($vert = 0; $vert < $size; $vert++) {
                $row = $up ? $size - 1 - $vert : $vert;
                foreach ([0, 1] as $delta) {
                    $col = $right - $delta;
                    if (!$r[$row][$col] && $m[$row][$col] === null) {
                        $m[$row][$col] = ($bi < strlen($bits)) ? ($bits[$bi++] === '1') : false;
                    }
                }
            }
            $up = !$up;
        }
    }

    private static function applyMask(array $m, array $r, int $mask, int $size): array {
        $out = $m;
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($r[$row][$col]) continue;
                $flip = match($mask) {
                    0 => ($row + $col) % 2 === 0,
                    1 => $row % 2 === 0,
                    2 => $col % 3 === 0,
                    3 => ($row + $col) % 3 === 0,
                    4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
                    5 => ($row * $col) % 2 + ($row * $col) % 3 === 0,
                    6 => (($row * $col) % 2 + ($row * $col) % 3) % 2 === 0,
                    7 => (($row + $col) % 2 + ($row * $col) % 3) % 2 === 0,
                    default => false,
                };
                if ($flip) $out[$row][$col] = !$out[$row][$col];
            }
        }
        return $out;
    }

    private static function penaltyScore(array $m, int $size): int {
        $score = 0;
 // Rule 1: 5+ in a row
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c-1]) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; }
                else $run = 1;
            }
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$c][$r] === $m[$c-1][$r]) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; }
                else $run = 1;
            }
        }
        return $score;
    }

    private static function writeFormat(array &$m, int $size, int $ecc, int $mask): void {
 // ECC-L indicator = 01, combined with mask
        $data  = ($ecc << 3) | $mask; // ecc-L=01 (but index 0 here = L)
 // Actually ECC L = 0b01 in QR spec
        $eccBits = 1; // ECC L indicator bits = 01
        $format = (($eccBits << 1 | (($ecc >> 0) & 1)) << 3) | $mask;
 // Full format string computation
        $genPoly = 0b10100110111;
        $fmtData = $format << 10;
        $tmp = $fmtData;
        for ($i = 14; $i >= 10; $i--) {
            if ($tmp & (1 << $i)) $tmp ^= ($genPoly << ($i - 10));
        }
        $full = ($format << 10) | $tmp;
        $full ^= 0b101010000010010; // mask pattern

        $bits = str_pad(decbin($full), 15, '0', STR_PAD_LEFT);
        $seq1 = []; for ($i=0;$i<8;$i++) $seq1[] = $i <= 5 ? $i : $i + 1;
        $seq2 = array_reverse(array_merge(range($size-8,$size-1), range($size-7,$size-1)));
        $seq2 = array_reverse([...range($size-7,$size-1), $size-8]);

 // Place along row 8 and col 8
        $bitArr = array_map(fn($b) => $b === '1', str_split($bits));
 // Column 8 from top
        $positions = [0,1,2,3,4,5,7,8];
        foreach ($positions as $i => $r) {
            if ($i < 8) $m[$r][8] = $bitArr[$i];
        }
        $m[8][7] = $bitArr[8]; // skip timing row 6
        for ($i = 0; $i < 7; $i++) $m[8][5-$i] = $bitArr[9+$i]; // wait - simplified
 // Row 8 from right
        for ($i = 0; $i < 8; $i++) $m[8][$size-1-$i] = $bitArr[$i];
        for ($i = 0; $i < 7; $i++) $m[$size-7+$i][8] = $bitArr[8+$i];
    }

    private static function reedSolomon(array $data, int $ecCount): array {
        $poly = self::generatorPoly($ecCount);
        $msg  = array_merge($data, array_fill(0, $ecCount, 0));
        $log  = self::gfLog();
        $exp  = self::gfExp();
        for ($i = 0; $i < count($data); $i++) {
            $coeff = $msg[$i];
            if ($coeff === 0) continue;
            for ($j = 1; $j <= count($poly); $j++) {
                $msg[$i+$j] ^= $exp[($log[$coeff] + $log[$poly[$j-1]]) % 255];
            }
        }
        return array_slice($msg, count($data));
    }

    private static function generatorPoly(int $degree): array {
        $g = [1];
        $exp = self::gfExp();
        for ($i = 0; $i < $degree; $i++) {
            $mult = [1, $exp[$i]];
            $result = array_fill(0, count($g) + count($mult) - 1, 0);
            foreach ($g as $gi => $gv) {
                foreach ($mult as $mi => $mv) {
                    $result[$gi+$mi] ^= self::gfMul($gv, $mv);
                }
            }
            $g = $result;
        }
        return array_slice($g, 1);
    }

    private static function gfMul(int $a, int $b): int {
        if ($a === 0 || $b === 0) return 0;
        $log = self::gfLog(); $exp = self::gfExp();
        return $exp[($log[$a] + $log[$b]) % 255];
    }

    private static function gfLog(): array {
        static $log = null;
        if ($log !== null) return $log;
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) { $log[$x] = $i; $x = ($x << 1) ^ ($x >= 128 ? 0x11d : 0); }
        return $log;
    }

    private static function gfExp(): array {
        static $exp = null;
        if ($exp !== null) return $exp;
        $exp = array_fill(0, 512, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $exp[$i+255] = $x; $x = ($x << 1) ^ ($x >= 128 ? 0x11d : 0); }
        return $exp;
    }

    public function getPages(): array  { return $this->pages; }
    public function getImages(): array { return $this->images; }
    public function getLogoInfo(): ?array { return $this->logoInfo; }

    public function buildForMerge(array $inv, array $lines, array $payments, array $apptNotes = [], string $role = 'admin'): void {
        $this->objects    = [];
        $this->objCount   = 0;
        $this->pages      = [];
        $this->pageStream = '';
        $this->logoInfo   = null;
        $this->images     = [];

        $this->beginPage();
        $this->drawHeader($inv);
        $this->drawBillTo($inv);
        $this->drawInvoiceMeta($inv);
        if (!empty($apptNotes)) $this->drawAppointmentNotes($apptNotes, $role);
        $this->y += 18;
        $this->drawLinesTable($lines);
        $this->drawTotals($inv, $payments);
        if ($inv['notes']) $this->drawNotes($inv['notes']);
        $this->drawReviewSection();
        $this->drawFooter();
        $this->endPage();
    }

    public function appendPages(array $pages, array $images = [], ?array $logoInfo = null): void {
        $offset = count($this->pages);
        foreach ($pages as $stream) {
            $this->pages[] = $stream;
        }
        foreach ($images as $img) {
            $img['page'] = ($img['page'] ?? 0) + $offset;
            $this->images[] = $img;
        }
        if ($logoInfo && !$this->logoInfo) {
            $this->logoInfo = $logoInfo;
        }
    }
}
