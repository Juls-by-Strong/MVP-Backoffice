<?php
// Weekly Service Report PDF
//
// Routes:
// GET /admin/weekly-report-pdf?from=YYYY-MM-DD&to=YYYY-MM-DD
//
// Also callable directly for the cron job:
// generateWeeklyReportPdfBytes(PDO $db, string $from, string $to) : string

if (!defined('MVP_VERSION')) require_once __DIR__ . '/../config/version.php';

define('WR_COMPANY_NAME',    'Acme Water Service');
define('WR_COMPANY_PHONE',   '(309) 555-0100');
define('WR_COMPANY_WEBSITE', 'example.com');
define('WR_LOGO_PATH',       __DIR__ . '/../../assets/logo.png');
define('WR_COLOR_R', 13);
define('WR_COLOR_G', 148);
define('WR_COLOR_B', 136);

function handleWeeklyReportPdf(PDO $db): void {
    $today  = new \DateTime('today');
    $dow    = (int)$today->format('N');
    $monday = (clone $today)->modify('-' . ($dow - 1) . ' days');
    $sunday = (clone $monday)->modify('+6 days');

    $from = $_GET['from'] ?? $monday->format('Y-m-d');
    $to   = $_GET['to']   ?? $sunday->format('Y-m-d');

    $bytes    = generateWeeklyReportPdfBytes($db, $from, $to);
    $filename = 'WeeklyReport-' . $from . '-to-' . $to . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-cache');
    echo $bytes;
    exit;
}

function generateWeeklyReportPdfBytes(PDO $db, string $from, string $to): string {
 // Load company settings
    $settings = [];
    try {
        $s = $db->query("SELECT setting_key, setting_value FROM company_settings");
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    } catch (\Throwable $e) {}

 // Appointments (all statuses, chronological)
    $apptStmt = $db->prepare(
        "SELECT
            a.appointment_id,
            a.confirmed_date,
            a.confirmed_time,
            a.status,
            a.office_notes,
            st.name AS service_type,
            CONCAT(c.first_name,' ',c.last_name) AS customer_name,
            c.phone AS customer_phone,
            c.service_address, c.service_city,
            COALESCE(
                GROUP_CONCAT(DISTINCT CONCAT(u2.first_name,' ',u2.last_name)
                             ORDER BY at2.role SEPARATOR ', '),
                CONCAT(u.first_name,' ',u.last_name)
            ) AS technician_name,
            i.invoice_number,
            i.status AS invoice_status,
            i.total  AS invoice_total,
            COALESCE(
                (SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.invoice_id),
                0
            ) AS amount_paid
         FROM appointments a
         JOIN service_types st ON a.service_type_id = st.type_id
         JOIN customers c      ON a.customer_id     = c.customer_id
         LEFT JOIN users u     ON a.technician_id   = u.user_id
         LEFT JOIN appointment_technicians at2 ON at2.appointment_id = a.appointment_id
         LEFT JOIN users u2    ON at2.technician_id = u2.user_id
         LEFT JOIN invoices i  ON i.appointment_id  = a.appointment_id AND i.status != 'void'
         WHERE a.confirmed_date BETWEEN ? AND ?
         GROUP BY a.appointment_id
         ORDER BY a.confirmed_date ASC, a.confirmed_time ASC"
    );
    $apptStmt->execute([$from, $to]);
    $appointments = $apptStmt->fetchAll(\PDO::FETCH_ASSOC);

 // Money collected
    $collStmt = $db->prepare(
        "SELECT COALESCE(SUM(p.amount), 0) AS total_collected,
                COUNT(DISTINCT p.payment_id) AS payment_count
         FROM payments p
         JOIN invoices i ON p.invoice_id = i.invoice_id
         WHERE p.payment_date BETWEEN ? AND ?"
    );
    $collStmt->execute([$from, $to]);
    $collected = $collStmt->fetch(\PDO::FETCH_ASSOC);

 // Outstanding invoices (up to 8 weeks back)
    $overdueFrom = (new \DateTime($from))->modify('-56 days')->format('Y-m-d');
    $ovStmt = $db->prepare(
        "SELECT
            i.invoice_id, i.invoice_number, i.status,
            i.total, i.issue_date,
            CONCAT(c.first_name,' ',c.last_name) AS customer_name,
            c.phone AS customer_phone,
            st.name AS service_type,
            COALESCE(SUM(p.amount), 0) AS amount_paid,
            (i.total - COALESCE(SUM(p.amount), 0)) AS balance_due
         FROM invoices i
         JOIN customers c      ON i.customer_id     = c.customer_id
         LEFT JOIN appointments a ON i.appointment_id = a.appointment_id
         LEFT JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN payments p   ON p.invoice_id     = i.invoice_id
         WHERE i.status IN ('draft','sent')
           AND i.issue_date BETWEEN ? AND ?
         GROUP BY i.invoice_id
         HAVING balance_due > 0
         ORDER BY i.issue_date ASC"
    );
    $ovStmt->execute([$overdueFrom, $to]);
    $overdue = $ovStmt->fetchAll(\PDO::FETCH_ASSOC);

    $pdf = new WeeklyReportPdf($settings);
    return $pdf->getBytes($from, $to, $appointments, $collected, $overdue);
}

// PDF Generator Class
class WeeklyReportPdf {

    private array  $objects    = [];
    private int    $objCount   = 0;
    private array  $pages      = [];
    private string $pageStream = '';
    private float  $y          = 0;
    private float  $pageHeight = 841.89;
    private float  $pageWidth  = 595.28;
    private float  $marginL    = 36;
    private float  $marginR    = 36;
    private float  $marginT    = 36;
    private float  $contentWidth;
    private ?array $logoInfo   = null;
    private array  $cfg        = [];

    public function __construct(array $settings = []) {
        $this->contentWidth = $this->pageWidth - $this->marginL - $this->marginR;
        $addr = $settings['company_address'] ?? '';
        $city = trim(
            ($settings['company_city']  ?? '') . ', ' .
            ($settings['company_state'] ?? '') . ' ' .
            ($settings['company_zip']   ?? '')
        );
        $this->cfg = [
            'name'         => $settings['company_name']    ?? WR_COMPANY_NAME,
            'phone'        => $settings['company_phone']   ?? WR_COMPANY_PHONE,
            'website'      => $settings['company_website'] ?? WR_COMPANY_WEBSITE,
            'logo'         => !empty($settings['logo_path']) ? $settings['logo_path'] : WR_LOGO_PATH,
            'address_full' => $addr . ($addr && $city ? ', ' . $city : $city),
            'r'            => WR_COLOR_R,
            'g'            => WR_COLOR_G,
            'b'            => WR_COLOR_B,
        ];
    }

    public function getBytes(string $from, string $to,
                             array $appointments, array $collected, array $overdue): string {
        $this->objects    = [];
        $this->objCount   = 0;
        $this->pages      = [];
        $this->pageStream = '';
        $this->logoInfo   = null;

        $this->beginPage();
        $this->drawHeader($from, $to);
        $this->drawSummary($appointments, $collected, $overdue);
        $this->drawAppointmentsSection($appointments);
        $this->drawCollectedSection($collected);
        $this->drawOverdueSection($overdue);
        $this->drawFooter();
        $this->endPage();

        return $this->buildPdfBytes();
    }

    private function beginPage(): void {
        $this->pageStream = '';
        $this->y = $this->pageHeight - $this->marginT;
    }

    private function endPage(): void {
        $this->pages[] = $this->pageStream;
    }

    private function newPage(): void {
        $this->endPage();
        $this->beginPage();
        $this->y = $this->pageHeight - $this->marginT;
    }

    private function checkPageBreak(float $needed): void {
        if ($this->y - $needed < 50) $this->newPage();
    }

    private function setColor(int $r, int $g, int $b, bool $fill = true): void {
        $op = $fill ? 'rg' : 'RG';
        $this->pageStream .= sprintf("%.3f %.3f %.3f $op\n", $r/255, $g/255, $b/255);
    }

    private function setGray(float $v, bool $fill = true): void {
        $op = $fill ? 'g' : 'G';
        $this->pageStream .= sprintf("%.3f $op\n", $v);
    }

    private function rect(float $x, float $y, float $w, float $h, string $op = 'f'): void {
        $py = $this->pageHeight - $y - $h;
        $this->pageStream .= sprintf("%.2f %.2f %.2f %.2f re $op\n", $x, $py, $w, $h);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $lw = 0.5): void {
        $py1 = $this->pageHeight - $y1;
        $py2 = $this->pageHeight - $y2;
        $this->pageStream .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $lw, $x1, $py1, $x2, $py2);
    }

    private function text(float $x, float $y, string $t, float $size, bool $bold = false,
                          int $r = 40, int $g = 40, int $b = 40): void {
        $font = $bold ? 'F2' : 'F1';
        $py   = $this->pageHeight - $y;
        $safe = $this->pdfString($t);
        $this->setColor($r, $g, $b);
        $this->pageStream .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $font, $size, $x, $py, $safe);
    }

    private function textRight(float $x, float $y, string $t, float $size, bool $bold = false,
                               int $r = 40, int $g = 40, int $b = 40): void {
        $w = strlen($t) * $size * 0.5;
        $this->text($x - $w, $y, $t, $size, $bold, $r, $g, $b);
    }

    private function pdfString(string $s): string {
        $s = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        return str_replace(['\\','(',')',"\r","\n"], ['\\\\','\\(','\\)','',''], $s);
    }

    private function truncate(string $s, int $max): string {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    private function drawHeader(string $from, string $to): void {
        $cfg = $this->cfg;

 // Accent bar
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect(0, 0, $this->pageWidth, 5);

        $topY = $this->marginT + 8;

 // Logo or company name
        $logoDrawn = false;
        if ($cfg['logo'] && file_exists($cfg['logo'])) {
            $imgData = @getimagesize($cfg['logo']);
            if ($imgData && $imgData[2] === IMAGETYPE_PNG) {
                $logoH = 32;
                $logoW = min($logoH * ($imgData[0] / $imgData[1]), 100);
                $this->logoInfo = ['path'=>$cfg['logo'],'x'=>$this->marginL,'y'=>$topY,'w'=>$logoW,'h'=>$logoH];
                $logoDrawn = true;
            }
        }
        $textX = $logoDrawn ? $this->marginL + ($this->logoInfo['w'] ?? 0) + 10 : $this->marginL;
        if (!$logoDrawn) {
            $this->text($this->marginL, $topY + 6, $cfg['name'], 14, true, $cfg['r'], $cfg['g'], $cfg['b']);
            $topY += 16;
        }
        $this->text($textX, $topY + 4,  $cfg['name'],         9,   true,  50, 50, 50);
        $this->text($textX, $topY + 14, $cfg['address_full'], 7.5, false, 100, 100, 100);
        $this->text($textX, $topY + 23, $cfg['phone'],        7.5, false, 100, 100, 100);

 // Report title - right
        $rx = $this->pageWidth - $this->marginR;
        $this->text($rx - 160, $topY + 4, 'WEEKLY SERVICE REPORT', 14, true, $cfg['r'], $cfg['g'], $cfg['b']);
        $period = $from . '  to  ' . $to;
        $this->text($rx - 160, $topY + 19, $period, 8, false, 100, 100, 100);
        $generated = 'Generated: ' . date('M j, Y g:i A');
        $this->text($rx - 160, $topY + 30, $generated, 7.5, false, 130, 130, 130);

        $this->y = $topY + 44;
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b'], false);
        $this->line($this->marginL, $this->y, $this->pageWidth - $this->marginR, $this->y, 1.0);
        $this->y += 12;
    }

    private function drawSummary(array $appts, array $collected, array $overdue): void {
        $this->checkPageBreak(50);
        $cfg       = $this->cfg;
        $collected_amt = (float)($collected['total_collected'] ?? 0);
        $outstanding   = array_sum(array_column($overdue, 'balance_due'));
        $completed     = count(array_filter($appts, fn($a) => $a['status'] === 'completed'));

        $kpis = [
            ['Total Appts',    (string)count($appts),  $cfg['r'], $cfg['g'], $cfg['b']],
            ['Completed',      (string)$completed,     22, 163, 74],
            ['Collected',      '$'.number_format($collected_amt, 2), 22, 163, 74],
            ['Outstanding',    '$'.number_format($outstanding, 2),   192, 57, 43],
        ];

        $kpiW = ($this->contentWidth - 12) / count($kpis);
        $x    = $this->marginL;
        $kpiH = 38;

        foreach ($kpis as $kpi) {
            $this->setGray(0.96);
            $this->rect($x, $this->y, $kpiW - 3, $kpiH);
            $this->setColor($kpi[2], $kpi[3], $kpi[4]);
            $this->rect($x, $this->y, 3, $kpiH);
            $this->text($x + 7, $this->y + 11, $kpi[0], 7, false, 110, 110, 110);
            $this->text($x + 7, $this->y + 28, $kpi[1], 13, true, $kpi[2], $kpi[3], $kpi[4]);
            $x += $kpiW;
        }
        $this->y += $kpiH + 14;
    }

    private function drawSectionHeader(string $title): void {
        $this->checkPageBreak(24);
        $cfg = $this->cfg;
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect($this->marginL, $this->y, $this->contentWidth, 16);
        $this->text($this->marginL + 6, $this->y + 11, strtoupper($title), 8, true, 255, 255, 255);
        $this->y += 16;
    }

    private function drawTableHeader(array $cols, array $widths): void {
        $this->checkPageBreak(16);
        $this->setGray(0.2);
        $this->rect($this->marginL, $this->y, $this->contentWidth, 14);
        $x = $this->marginL + 4;
        foreach ($cols as $i => $col) {
            $this->text($x, $this->y + 10, $col, 7, true, 255, 255, 255);
            $x += $widths[$i];
        }
        $this->y += 14;
    }

    private function drawAppointmentsSection(array $appts): void {
        $this->drawSectionHeader('Appointments');

        if (empty($appts)) {
            $this->text($this->marginL + 6, $this->y + 10, 'No appointments in this period.', 8.5, false, 130, 130, 130);
            $this->y += 18;
            return;
        }

 // Column widths - must sum to $this->contentWidth
        $cw = $this->contentWidth;
        $widths = [
            'date'  => $cw * 0.09,
            'time'  => $cw * 0.07,
            'cust'  => $cw * 0.16,
            'svc'   => $cw * 0.13,
            'tech'  => $cw * 0.13,
            'stat'  => $cw * 0.09,
            'inv'   => $cw * 0.09,
            'paid'  => $cw * 0.08,
            'notes' => $cw * 0.16,
        ];

        $cols   = ['Date','Time','Customer','Service','Technician(s)','Status','Invoice','Collected','Office Notes'];
        $wArr   = array_values($widths);
        $this->drawTableHeader($cols, $wArr);

        $rowNum = 0;
        foreach ($appts as $a) {
            $rowH = 16;
 // Extra height if there's a note
            $note = trim($a['office_notes'] ?? '');
            if ($note) $rowH = 28;

            $this->checkPageBreak($rowH + 2);

            if ($rowNum % 2 === 0) {
                $this->setGray(0.97);
                $this->rect($this->marginL, $this->y, $cw, $rowH);
            }

 // Status dot color
            [$sr, $sg, $sb] = match($a['status']) {
                'completed'   => [22, 163, 74],
                'confirmed'   => [37, 99, 235],
                'in_progress' => [37, 99, 235],
                'cancelled'   => [156, 163, 175],
                'tentative'   => [156, 163, 175],
                default       => [107, 114, 128],
            };

            $baseY = $this->y + ($note ? 10 : 14);
            $x     = $this->marginL + 4;

            $this->text($x, $baseY, $a['confirmed_date'] ?? '-', 7.5, false); $x += $widths['date'];
            $this->text($x, $baseY, $a['confirmed_time'] ?? '-', 7.5, false); $x += $widths['time'];
            $this->text($x, $baseY, $this->truncate($a['customer_name'] ?? '', 20), 7.5, true); $x += $widths['cust'];
            $this->text($x, $baseY, $this->truncate($a['service_type']  ?? '', 16), 7.5, false); $x += $widths['svc'];
            $this->text($x, $baseY, $this->truncate($a['technician_name'] ?? 'Unassigned', 18), 7.5, false); $x += $widths['tech'];
            $this->text($x, $baseY, $a['status'], 7, true, $sr, $sg, $sb); $x += $widths['stat'];
            $this->text($x, $baseY, $this->truncate($a['invoice_number'] ?? '-', 12), 7.5, false); $x += $widths['inv'];
            $paid = (float)($a['amount_paid'] ?? 0);
            $paidTxt = $paid > 0 ? '$'.number_format($paid, 2) : '-';
            $this->text($x, $baseY, $paidTxt, 7.5, $paid > 0, 22, 163, 74); $x += $widths['paid'];

            if ($note) {
                $noteLines = wordwrap($note, 28, "\n", true);
                $noteParts = explode("\n", $noteLines);
                $ny = $this->y + 9;
                foreach (array_slice($noteParts, 0, 2) as $part) {
                    $this->text($x, $ny, $this->truncate($part, 30), 7, false, 60, 60, 60);
                    $ny += 10;
                }
            }

 // Bottom separator
            $this->setGray(0.88, false);
            $this->line($this->marginL, $this->y + $rowH, $this->marginL + $cw, $this->y + $rowH, 0.3);

            $this->y += $rowH;
            $rowNum++;
        }
        $this->y += 10;
    }

    private function drawCollectedSection(array $collected): void {
        $this->checkPageBreak(50);
        $this->drawSectionHeader('Money Collected This Period');

        $amt   = (float)($collected['total_collected'] ?? 0);
        $count = (int)($collected['payment_count'] ?? 0);

        $this->setGray(0.97);
        $this->rect($this->marginL, $this->y, $this->contentWidth, 32);
        $this->text($this->marginL + 10, $this->y + 12, 'Total Collected', 8, false, 100, 100, 100);
        $this->text($this->marginL + 10, $this->y + 26, '$' . number_format($amt, 2), 14, true, 22, 163, 74);
        $this->text($this->marginL + 130, $this->y + 12, 'Payments Recorded', 8, false, 100, 100, 100);
        $this->text($this->marginL + 130, $this->y + 26, (string)$count, 14, true, 37, 99, 235);
        $this->y += 42;
    }

    private function drawOverdueSection(array $overdue): void {
        $this->checkPageBreak(40);
        $this->drawSectionHeader('Outstanding Invoices (Current Period + Up to 8 Weeks Back)');

        if (empty($overdue)) {
            $this->text($this->marginL + 6, $this->y + 12, 'No outstanding invoices - all clear!', 8.5, false, 22, 163, 74);
            $this->y += 22;
            return;
        }

        $cw = $this->contentWidth;
        $widths = [
            $cw * 0.13, // Invoice #
            $cw * 0.18, // Customer
            $cw * 0.12, // Phone
            $cw * 0.14, // Service
            $cw * 0.10, // Date
            $cw * 0.11, // Total
            $cw * 0.11, // Paid
            $cw * 0.11, // Balance
        ];
        $cols = ['Invoice #','Customer','Phone','Service','Date','Total','Paid','Balance Due'];
        $this->drawTableHeader($cols, $widths);

        $rowNum = 0;
        foreach ($overdue as $inv) {
            $this->checkPageBreak(16);
            if ($rowNum % 2 === 0) {
                $this->setGray(0.97);
                $this->rect($this->marginL, $this->y, $cw, 14);
            }

            $balance = (float)($inv['balance_due'] ?? 0);
            $x = $this->marginL + 4;
            $baseY = $this->y + 10;

            $this->text($x, $baseY, $this->truncate($inv['invoice_number'] ?? '#'.$inv['invoice_id'], 14), 7.5, true); $x += $widths[0];
            $this->text($x, $baseY, $this->truncate($inv['customer_name'] ?? '', 20), 7.5, false); $x += $widths[1];
            $this->text($x, $baseY, $this->truncate($inv['customer_phone'] ?? '-', 14), 7.5, false); $x += $widths[2];
            $this->text($x, $baseY, $this->truncate($inv['service_type'] ?? '-', 16), 7.5, false); $x += $widths[3];
            $this->text($x, $baseY, $inv['issue_date'] ?? '-', 7.5, false); $x += $widths[4];
            $this->text($x, $baseY, '$'.number_format((float)($inv['total'] ?? 0), 2), 7.5, false); $x += $widths[5];
            $this->text($x, $baseY, '$'.number_format((float)($inv['amount_paid'] ?? 0), 2), 7.5, false, 22, 163, 74); $x += $widths[6];
            $this->text($x, $baseY, '$'.number_format($balance, 2), 7.5, true, 192, 57, 43);

            $this->setGray(0.88, false);
            $this->line($this->marginL, $this->y + 14, $this->marginL + $cw, $this->y + 14, 0.3);
            $this->y += 14;
            $rowNum++;
        }

 // Total outstanding
        $totalOut = array_sum(array_column($overdue, 'balance_due'));
        $this->checkPageBreak(18);
        $this->setGray(0.90);
        $this->rect($this->marginL, $this->y, $cw, 16);
        $this->text($this->marginL + 6, $this->y + 11, 'TOTAL OUTSTANDING', 8, true, 60, 60, 60);
        $this->textRight($this->marginL + $cw - 4, $this->y + 11, '$'.number_format($totalOut, 2), 9, true, 192, 57, 43);
        $this->y += 24;
    }

    private function drawFooter(): void {
        $cfg = $this->cfg;
        $footY = $this->pageHeight - 20;
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect(0, $footY - 2, $this->pageWidth, 2);
        $this->text($this->marginL, $footY + 10,
            $cfg['name'] . '  ·  ' . $cfg['phone'] . '  ·  ' . $cfg['website'],
            7, false, 130, 130, 130);
        $this->textRight($this->pageWidth - $this->marginR, $footY + 10,
            'MVP Backoffice ' . MVP_VERSION . '  ·  Confidential', 7, false, 180, 180, 180);
    }

    private function addObj(string $content): int {
        $this->objects[++$this->objCount] = $content;
        return $this->objCount;
    }

    private function buildPdfBytes(): string {
        $fonts = "<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica\n/Encoding /WinAnsiEncoding\n>>";
        $fontId  = $this->addObj($fonts);
        $fontsB  = "<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica-Bold\n/Encoding /WinAnsiEncoding\n>>";
        $fontBId = $this->addObj($fontsB);

        $resourceDict = "<< /Font << /F1 $fontId 0 R /F2 $fontBId 0 R >> >>";

        $pageObjIds = [];
        foreach ($this->pages as $stream) {
            $logoExtra = '';
            if ($this->logoInfo) {
                $imgBytes = file_get_contents($this->logoInfo['path']);
                $imgId    = $this->addObj(
                    "<< /Type /XObject /Subtype /Image\n" .
                    "/Width {$this->logoInfo['w']} /Height {$this->logoInfo['h']}\n" .
                    "/ColorSpace /DeviceRGB /BitsPerComponent 8\n" .
                    "/Filter /FlateDecode\n" .
                    "/Length " . strlen($imgBytes) . " >>\nstream\n" .
                    $imgBytes . "\nendstream"
                );
                $ph   = $this->pageHeight - $this->logoInfo['y'] - $this->logoInfo['h'];
                $logoExtra = " /XObject << /Im1 $imgId 0 R >>";
                $stream = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q\n",
                    $this->logoInfo['w'], $this->logoInfo['h'],
                    $this->logoInfo['x'], $ph) . $stream;
            }
            $res    = "<< /Font << /F1 $fontId 0 R /F2 $fontBId 0 R >>$logoExtra >>";
            $sLen   = strlen($stream);
            $contId = $this->addObj("<< /Length $sLen >>\nstream\n{$stream}\nendstream");
            $pageObjIds[] = $this->addObj(
                "<< /Type /Page /Parent 2 0 R\n" .
                "/MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}]\n" .
                "/Contents $contId 0 R\n" .
                "/Resources $res >>"
            );
        }

        $kids  = implode(' 0 R ', $pageObjIds) . ' 0 R';
        $pagesId = $this->addObj("<< /Type /Pages /Kids [$kids] /Count " . count($pageObjIds) . " >>");
        $catId   = $this->addObj("<< /Type /Catalog /Pages $pagesId 0 R >>");

 // Build PDF bytes
        $out    = "%PDF-1.4\n";
        $xref   = [];
        $offsets = [];

        for ($i = 1; $i <= $this->objCount; $i++) {
            $offsets[$i] = strlen($out);
            $out .= "$i 0 obj\n{$this->objects[$i]}\nendobj\n";
        }

        $xrefPos = strlen($out);
        $out .= "xref\n0 " . ($this->objCount + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $this->objCount; $i++) {
            $out .= str_pad($offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $out .= "trailer << /Size " . ($this->objCount + 1) . " /Root $catId 0 R >>\n";
        $out .= "startxref\n$xrefPos\n%%EOF";
        return $out;
    }
}
