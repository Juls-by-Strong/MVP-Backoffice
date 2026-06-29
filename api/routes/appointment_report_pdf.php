<?php
// Generates a service report PDF that matches the invoice style.
//
// Page 1+: Service report (customer, appt details, equipment,
// office/customer notes, staff notes)
// Appended: Invoice PDF pages (most recent, if exists)
//
// Routes (wired in index.php):
// GET /admin/appointments/{id}/report-pdf
// GET /tech/appointments/{id}/report-pdf

require_once __DIR__ . '/invoice_pdf.php';

function handleAppointmentReportPdf(PDO $db, string $role, int $appointmentId): void {

 // Clear the output buffer started by index.php before we stream binary PDF data
    while (ob_get_level()) ob_end_clean();

    $settings = [];
    try {
        $s = $db->query("SELECT setting_key, setting_value FROM company_settings");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $settings[$r['setting_key']] = $r['setting_value'];
    } catch (\Throwable $e) {}

    $stmt = $db->prepare(
        "SELECT a.*,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                c.first_name, c.last_name,
                c.phone, c.email,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.notes AS customer_account_notes,
                st.name AS service_type,
                CONCAT(tu.first_name,' ',tu.last_name) AS technician_name
         FROM appointments a
         JOIN customers c      ON a.customer_id     = c.customer_id
         JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN users tu    ON a.technician_id   = tu.user_id
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$appt) {
        http_response_code(404);
        echo json_encode(['error' => 'Appointment not found']);
        exit;
    }

    $techNames = [];
    try {
        $stmt = $db->prepare(
            "SELECT CONCAT(u.first_name,' ',u.last_name) AS name, at2.role
             FROM appointment_technicians at2
             JOIN users u ON at2.technician_id = u.user_id
             WHERE at2.appointment_id = ?
             ORDER BY FIELD(at2.role,'lead','technician'), u.first_name"
        );
        $stmt->execute([$appointmentId]);
        $techNames = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
    if (empty($techNames) && !empty($appt['technician_name'])) {
        $techNames = [['name' => $appt['technician_name'], 'role' => 'lead']];
    }

    $stmt = $db->prepare(
        "SELECT et.type_name, e.model, e.install_date, e.last_service_date
         FROM appointment_equipment ae
         JOIN equipment e        ON ae.equipment_id = e.equipment_id
         JOIN equipment_types et ON e.type_id       = et.type_id
         WHERE ae.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $visitEquipment = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare(
        "SELECT et.type_name, e.model, e.install_date, e.last_service_date, e.next_service_due,
                COALESCE(e.service_interval_days, et.default_interval_days) AS interval_days
         FROM equipment e
         JOIN equipment_types et ON e.type_id = et.type_id
         WHERE e.customer_id = ?
         ORDER BY e.next_service_due ASC"
    );
    $stmt->execute([$appt['customer_id']]);
    $allEquipment = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $apptNotes = [];
    try {
 // First try: notes with explicit appointment_id (post-migration)
        $stmt = $db->prepare(
            "SELECT n.note_text, n.is_visible_to_customer, n.created_at,
                    COALESCE(CONCAT(u.first_name,' ',u.last_name), u.email) AS author_name,
                    u.role AS author_role
             FROM customer_notes n
             JOIN users u ON n.author_id = u.user_id
             WHERE n.appointment_id = ?
             ORDER BY n.created_at ASC"
        );
        $stmt->execute([$appointmentId]);
        $apptNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

 // Fallback: if none found, get notes for this customer created on the appointment date
        if (empty($apptNotes) && !empty($appt['confirmed_date'])) {
            $stmt = $db->prepare(
                "SELECT n.note_text, n.is_visible_to_customer, n.created_at,
                        COALESCE(CONCAT(u.first_name,' ',u.last_name), u.email) AS author_name,
                        u.role AS author_role
                 FROM customer_notes n
                 JOIN users u ON n.author_id = u.user_id
                 WHERE n.customer_id = ?
                   AND DATE(n.created_at) = ?
                   AND (n.appointment_id IS NULL OR n.appointment_id = ?)
                 ORDER BY n.created_at ASC"
            );
            $stmt->execute([$appt['customer_id'], $appt['confirmed_date'], $appointmentId]);
            $apptNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {}

    $photos = [];
    try {
        $stmt = $db->prepare(
            "SELECT ci.filename, ci.caption
             FROM customer_images ci
             WHERE ci.appointment_id = ?
             ORDER BY ci.created_at ASC"
        );
        $stmt->execute([$appointmentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $uploadDir = __DIR__ . '/../../customer_images/';
        foreach ($rows as $r) {
            $photos[] = [
                'path'    => $uploadDir . basename($r['filename']),
                'caption' => $r['caption'] ?? '',
            ];
        }
    } catch (\Throwable $e) {}

    $invoice         = null;
    $invoiceLines    = [];
    $invoicePayments = [];
    $stmt = $db->prepare(
        "SELECT i.*,
                COALESCE(NULLIF(TRIM(c.company_name),''), CONCAT(c.first_name,' ',c.last_name)) AS customer_name,
                c.first_name, c.last_name, c.company_name,
                c.phone AS customer_phone, c.email AS customer_email,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.billing_address, c.billing_city, c.billing_state, c.billing_zip,
                c.user_id AS customer_user_id,
                st.name AS service_type,
                a.confirmed_date, a.confirmed_time,
                CONCAT(tu.first_name,' ',tu.last_name) AS technician_name
         FROM invoices i
         JOIN customers c      ON i.customer_id      = c.customer_id
         LEFT JOIN appointments a  ON i.appointment_id = a.appointment_id
         LEFT JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN users tu    ON a.technician_id    = tu.user_id
         WHERE i.appointment_id = ? AND i.status != 'void'
         ORDER BY i.created_at DESC LIMIT 1"
    );
    $stmt->execute([$appointmentId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($invoice) {
        $stmt = $db->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY line_id");
        $stmt->execute([$invoice['invoice_id']]);
        $invoiceLines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare(
            "SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS recorded_by_name
             FROM payments p LEFT JOIN users u ON p.recorded_by = u.user_id
             WHERE p.invoice_id = ? ORDER BY p.payment_date, p.payment_id"
        );
        $stmt->execute([$invoice['invoice_id']]);
        $invoicePayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $report = new AppointmentReportPdf($settings);
    $report->rawSettings = $settings;
    $report->generateReport(
        $appt, $techNames, $visitEquipment, $allEquipment,
        $apptNotes, $invoice, $invoiceLines, $invoicePayments, $role, $photos
    );
}

class AppointmentReportPdf extends InvoicePdf {

    public array $rawSettings = [];

    public function generateReport(
        array  $appt,
        array  $techNames,
        array  $visitEquipment,
        array  $allEquipment,
        array  $apptNotes,
        ?array $invoice,
        array  $invoiceLines,
        array  $invoicePayments,
        string $role,
        array  $photos = []
    ): void {
        $this->objects    = [];
        $this->objCount   = 0;
        $this->pages      = [];
        $this->pageStream = '';
        $this->logoInfo   = null;
        $this->images     = [];

        try {
            $this->beginPage();
            $this->drawReportHeader($appt);
            $this->drawCustomerAndMeta($appt, $techNames);
            $this->y += 20;

            if (!empty($visitEquipment) || !empty($allEquipment)) {
                $this->drawEquipmentSection($visitEquipment, $allEquipment);
            }
            if (!empty($appt['office_notes']) || !empty($appt['customer_notes']) || !empty($appt['tech_notes'])) {
                $this->drawApptNotesSection($appt);
            }
            if (!empty($apptNotes)) {
                $this->drawStaffNotesSection($apptNotes);
            }
            if (!empty($photos)) {
                $this->drawPhotosSection($photos);
            }

            $this->drawReportFooter();
            $this->endPage();
        } catch (\Throwable $e) {
 // Stream error as plain text PDF page for diagnosis
            $this->pageStream = '';
            $this->text(45, 700, 'Report generation error:', 10, true, 200, 0, 0);
            $this->text(45, 685, $e->getMessage(), 8.5, false, 50, 50, 50);
            $this->text(45, 670, $e->getFile() . ' line ' . $e->getLine(), 8, false, 100, 100, 100);
            $this->endPage();
        }

        if ($invoice) {
            $invPdf   = new MergeableInvoicePdf($this->rawSettings);
            $invPdf->buildForMerge($invoice, $invoiceLines, $invoicePayments, $apptNotes, $role);
            $invBytes = $invPdf->buildPdfBytes($invoice['invoice_number']);

 // Get report bytes first, then merge at byte level
            $reportBytes = $this->buildPdfBytesWithPhotos('Report-' . $appt['appointment_id']);
            $merged      = $this->mergePdfs($reportBytes, $invBytes);

            $apptDate = !empty($appt['confirmed_date']) ? $appt['confirmed_date'] : date('Y-m-d');
            $custSlug = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $appt['customer_name']));
            $filename = 'ServiceReport-' . $custSlug . '-' . $apptDate . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($merged));
            header('Cache-Control: no-cache');
            echo $merged;
            exit;
        }

        $apptDate = !empty($appt['confirmed_date']) ? $appt['confirmed_date'] : date('Y-m-d');
        $custSlug = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $appt['customer_name']));
        $filename = 'ServiceReport-' . $custSlug . '-' . $apptDate . '.pdf';
        $bytes    = $this->buildPdfBytesWithPhotos('Report-' . $appt['appointment_id']);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-cache');
        echo $bytes;
        exit;
    }

 // Merges two well-formed PDF byte strings by renumbering all
 // objects in the second PDF and combining their page trees.
    private function mergePdfs(string $pdf1, string $pdf2): string {
 // Parse all numbered objects from a PDF byte string
        $parseObjs = function(string $pdf): array {
            $objs = [];
            preg_match_all('/(\d+)\s+0\s+obj\s*(.*?)\s*endobj/s', $pdf, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $objs[(int)$match[1]] = $match[2];
            }
            return $objs;
        };

 // Extract /Kids page IDs from the /Pages dictionary object
        $getPageIds = function(array $objs): array {
            foreach ($objs as $id => $content) {
                if (strpos($content, '/Type /Pages') !== false) {
                    if (preg_match('/\/Kids\s*\[([^\]]+)\]/', $content, $km)) {
                        preg_match_all('/(\d+)\s+0\s+R/', $km[1], $pm);
                        return array_map('intval', $pm[1]);
                    }
                }
            }
            return [];
        };

        $objs1  = $parseObjs($pdf1);
        $objs2  = $parseObjs($pdf2);
        $maxId1 = max(array_keys($objs1));
        $offset = $maxId1;

 // Renumber all object references in pdf2
        $renumbered = [];
        foreach ($objs2 as $id => $content) {
            $content = preg_replace_callback('/(\d+)\s+0\s+R/', function($m) use ($offset) {
                return ($m[1] + $offset) . ' 0 R';
            }, $content);
            $renumbered[$id + $offset] = $content;
        }

        $pages1Ids  = $getPageIds($objs1);
        $pages2Ids  = array_map(fn($id) => $id + $offset, $getPageIds($objs2));
        $allPageIds = array_merge($pages1Ids, $pages2Ids);
        $totalPages = count($allPageIds);

 // Drop structural objects (Catalog, Pages) - we rebuild them
        $isStructural = fn(string $c) =>
            strpos($c, '/Type /Catalog') !== false ||
            strpos($c, '/Type /Pages')   !== false;

        $merged = [];
        foreach ($objs1 as $id => $c) {
            if (!$isStructural($c)) $merged[$id] = $c;
        }
        foreach ($renumbered as $id => $c) {
            if (!$isStructural($c)) $merged[$id] = $c;
        }

        $nextId     = max(array_keys($merged)) + 1;
        $newPagesId = $nextId++;

 // Update /Parent reference on each page object
        foreach ($allPageIds as $pid) {
            if (isset($merged[$pid])) {
                $merged[$pid] = preg_replace(
                    '/\/Parent\s+\d+\s+0\s+R/',
                    '/Parent ' . $newPagesId . ' 0 R',
                    $merged[$pid]
                );
            }
        }

        $kidsStr             = implode(' 0 R ', $allPageIds) . ' 0 R';
        $merged[$newPagesId] = '<< /Type /Pages /Kids [' . $kidsStr . '] /Count ' . $totalPages . ' >>';
        $newCatId            = $nextId;
        $merged[$newCatId]   = '<< /Type /Catalog /Pages ' . $newPagesId . ' 0 R >>';

        ksort($merged);

 // Serialise
        $out     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($merged as $id => $content) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $content . "\nendobj\n";
        }

        $maxObjId   = max(array_keys($offsets));
        $xrefOffset = strlen($out);
        $out .= "xref\n0 " . ($maxObjId + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObjId; $i++) {
            $off = isset($offsets[$i]) ? $offsets[$i] : null;
            $out .= $off !== null
                ? str_pad($off, 10, '0', STR_PAD_LEFT) . " 00000 n \n"
                : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($maxObjId + 1) . " /Root " . $newCatId . " 0 R >>\n";
        $out .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $out;
    }

    private function drawReportHeader(array $appt): void {
        $cfg = $this->cfg;

        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect(0, 0, $this->pageWidth, 6);

        $topY = $this->marginT + 10;

        $logoDrawn = false;
        $logoPath  = $cfg['logo'];
        if ($logoPath && file_exists($logoPath)) {
            $imgData = @getimagesize($logoPath);
            if ($imgData && $imgData[2] === IMAGETYPE_PNG) {
                $logoH = 38;
                $logoW = min($logoH * ($imgData[0] / $imgData[1]), 110);
                $this->logoInfo = ['path' => $logoPath, 'x' => $this->marginL, 'y' => $topY, 'w' => $logoW, 'h' => $logoH];
                $logoDrawn = true;
            }
        }

        if (!$logoDrawn) {
            $this->text($this->marginL, $topY + 6, $cfg['name'], 16, true, $cfg['r'], $cfg['g'], $cfg['b']);
            $topY += 20;
            $textX = $this->marginL;
        } else {
            $textX = $this->marginL + (float)($this->logoInfo['w'] ?? 0) + 12;
        }

        $this->text($textX, $topY + 4,  $cfg['name'],         10, true,  50, 50, 50);
        $this->text($textX, $topY + 16, $cfg['address_full'], 8.5, false, 90, 90, 90);
        $this->text($textX, $topY + 27, $cfg['phone'],        8.5, false, 90, 90, 90);
        $this->text($textX, $topY + 38, $cfg['website'],      8.5, false, 90, 90, 90);

 // Title - same right-side position as "INVOICE"
        $this->text($this->pageWidth - $this->marginR - 120, $topY + 4,
            'SERVICE REPORT', 18, true, $cfg['r'], $cfg['g'], $cfg['b']);
        $this->text($this->pageWidth - $this->marginR - 120, $topY + 26,
            'Appt #' . $appt['appointment_id'], 10, false, 100, 100, 100);

 // Status badge
        $statusColors = [
            'completed'   => [22,  163, 74],
            'confirmed'   => [37,  99,  235],
            'in_progress' => [234, 179, 8],
            'cancelled'   => [239, 68,  68],
            'tentative'   => [107, 114, 128],
            'pending'     => [107, 114, 128],
        ];
        $sc   = $statusColors[$appt['status']] ?? [107, 114, 128];
        $slab = strtoupper(str_replace('_', ' ', $appt['status']));
        $this->setColor($sc[0], $sc[1], $sc[2]);
        $this->rect($this->pageWidth - $this->marginR - 120, $topY + 30, 80, 14);
        $this->text($this->pageWidth - $this->marginR - 117, $topY + 40, $slab, 8, true, 255, 255, 255);

 // Divider - identical to invoice
        $this->y = $topY + 56;
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b'], false);
        $this->line($this->marginL, $this->y, $this->pageWidth - $this->marginR, $this->y, 0.8);
        $this->y += 14;
        $this->headerMetaStartY = $this->y;
    }

 // Three-column layout mirroring BILL TO / SERVICE ADDRESS / meta
    private function drawCustomerAndMeta(array $appt, array $techNames): void {
        $cfg    = $this->cfg;
        $startY = $this->y;
        $midX   = $this->marginL + $this->contentWidth * 0.42;
        $rx     = $this->pageWidth - $this->marginR;
        $labelX = $rx - 120;
        $valueX = $rx;
        $lineH  = 13;
        $metaY  = $this->headerMetaStartY > 0 ? $this->headerMetaStartY : $startY;

        $this->text($this->marginL, $this->y, 'CUSTOMER', 7.5, true, $cfg['r'], $cfg['g'], $cfg['b']);
        $this->y += 13;
        $this->text($this->marginL, $this->y, $appt['customer_name'], 10, true);
        $this->y += 13;
        if (!empty($appt['phone'])) {
            $this->text($this->marginL, $this->y, $appt['phone'], 9, false, 80, 80, 80);
            $this->y += 12;
        }
        if (!empty($appt['email'])) {
            $this->text($this->marginL, $this->y, $appt['email'], 9, false, 80, 80, 80);
            $this->y += 12;
        }

        $svcY = $startY;
        $this->text($midX, $svcY, 'SERVICE ADDRESS', 7.5, true, $cfg['r'], $cfg['g'], $cfg['b']);
        $svcY += 13;
        if (!empty($appt['service_address'])) {
            $this->text($midX, $svcY, trim($appt['service_address']), 9, false, 80, 80, 80);
            $svcY += 12;
        }
        $cityLine = trim(($appt['service_city'] ?? '') . ', ' . ($appt['service_state'] ?? '') . ' ' . ($appt['service_zip'] ?? ''));
        if ($cityLine !== ',  ' && $cityLine !== ', ') {
            $this->text($midX, $svcY, $cityLine, 9, false, 80, 80, 80);
        }

        $date = $appt['confirmed_date'] ?? $appt['requested_date'] ?? '-';
        $time = '';
        if (!empty($appt['confirmed_time'])) {
            $t = date_create_from_format('H:i:s', $appt['confirmed_time'])
              ?: date_create_from_format('H:i',   $appt['confirmed_time']);
            $time = $t ? ' ' . date_format($t, 'g:ia') : '';
        }

        $techStr = empty($techNames)
            ? 'Unassigned'
            : implode(', ', array_map(
                fn($t) => $t['name'] . (count($techNames) > 1 && $t['role'] === 'lead' ? ' (Lead)' : ''),
                $techNames
              ));

        $metaRows = [
            ['Date',         $date . $time],
            ['Service Type', $appt['service_type'] ?? '-'],
            ['Technician',   $techStr],
            ['Status',       ucwords(str_replace('_', ' ', $appt['status']))],
        ];
        if (!empty($appt['booking_source'])) {
            $metaRows[] = ['Booked Via', $appt['booking_source']];
        }
        if (!empty($appt['completed_at'])) {
            $metaRows[] = ['Completed', substr($appt['completed_at'], 0, 16)];
        }

        foreach ($metaRows as [$label, $value]) {
            $this->text($labelX, $metaY, $label . ':', 8.5, true, 90, 90, 90);
            $words    = explode(' ', (string)$value);
            $line     = '';
            $valLines = [];
            foreach ($words as $word) {
                $test = $line ? $line . ' ' . $word : $word;
                if ($this->stringWidth($test, 8.5) > 80 && $line) {
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

        $this->y = max($this->y, $metaY) + 4;
    }

    private function drawEquipmentSection(array $visitEquip, array $allEquip): void {
        $cfg = $this->cfg;
        $x   = $this->marginL;

        if (!empty($visitEquip)) {
            $this->checkPageBreak(50);
            $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
            $this->rect($x, $this->y, $this->contentWidth, 18);
            $this->text($x + 6, $this->y + 12, 'EQUIPMENT SERVICED THIS VISIT', 8.5, true, 255, 255, 255);
            $this->y += 18;

            foreach ($visitEquip as $i => $e) {
                $this->checkPageBreak(20);
                if ($i % 2 === 0) {
                    $this->setGray(0.965);
                    $this->rect($x, $this->y, $this->contentWidth, 20);
                }
                $label = $e['type_name'] . (!empty($e['model']) ? ' - ' . $e['model'] : '');
                $this->text($x + 6, $this->y + 13, $label, 8.5, true);
                $meta = [];
                if (!empty($e['install_date']))      $meta[] = 'Installed: ' . $e['install_date'];
                if (!empty($e['last_service_date'])) $meta[] = 'Last service: ' . $e['last_service_date'];
                if ($meta) {
                    $this->textRight($this->pageWidth - $this->marginR - 4, $this->y + 13,
                        implode('  ·  ', $meta), 7.5, false, 120, 120, 120);
                }
                $this->setGray(0.85, false);
                $this->line($x, $this->y + 20, $x + $this->contentWidth, $this->y + 20, 0.3);
                $this->y += 20;
            }
            $this->y += 8;
        }

        if (!empty($allEquip)) {
            $this->checkPageBreak(60);

 // Give type and model more space; squeeze the date columns
            $colW = [
                'type'  => $this->contentWidth * 0.26,
                'model' => $this->contentWidth * 0.30,
                'inst'  => $this->contentWidth * 0.14,
                'last'  => $this->contentWidth * 0.14,
                'next'  => $this->contentWidth * 0.16,
            ];
            $cx = [
                'type'  => $x + 6,
                'model' => $x + $colW['type'],
                'inst'  => $x + $colW['type'] + $colW['model'],
                'last'  => $x + $colW['type'] + $colW['model'] + $colW['inst'],
                'next'  => $x + $colW['type'] + $colW['model'] + $colW['inst'] + $colW['last'],
            ];

            $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
            $this->rect($x, $this->y, $this->contentWidth, 18);
            foreach ([
                [$cx['type'],  'Equipment'],
                [$cx['model'], 'Model'],
                [$cx['inst'],  'Installed'],
                [$cx['last'],  'Last Service'],
                [$cx['next'],  'Next Due'],
            ] as [$colX, $lbl]) {
                $this->text($colX, $this->y + 12, $lbl, 8, true, 255, 255, 255);
            }
            $this->y += 18;

            $modelMaxW = $colW['model'] - 8;

            foreach ($allEquip as $i => $e) {
 // Pre-wrap model text to calculate row height
                $modelText  = $e['model'] ?? '-';
                $modelLines = $this->wrapLines($modelText, $modelMaxW, 8);
                $rowH       = max(18, 6 + count($modelLines) * 12);

                $this->checkPageBreak($rowH);
                if ($i % 2 === 0) {
                    $this->setGray(0.965);
                    $this->rect($x, $this->y, $this->contentWidth, $rowH);
                }
                $nd      = $e['next_service_due'] ?? '-';
                $overdue = ($nd !== '-' && $nd < date('Y-m-d'));
                $ndc     = $overdue ? [220, 38, 38] : [40, 40, 40];

 // Vertically centre the single-line fields
                $midY = $this->y + (int)($rowH / 2) + 4;
                $this->text($cx['type'],  $midY, $e['type_name'],               8.5, false, 40,  40,  40);
                $this->text($cx['inst'],  $midY, $e['install_date']      ?? '-', 8,   false, 80,  80,  80);
                $this->text($cx['last'],  $midY, $e['last_service_date'] ?? '-', 8,   false, 80,  80,  80);
                $this->text($cx['next'],  $midY, $nd, 8, $overdue, $ndc[0], $ndc[1], $ndc[2]);

 // Wrapped model text - starts near top of row
                $lineY = $this->y + 12;
                foreach ($modelLines as $ml) {
                    $this->text($cx['model'], $lineY, $ml, 8, false, 80, 80, 80);
                    $lineY += 12;
                }

                $this->setGray(0.85, false);
                $this->line($x, $this->y + $rowH, $x + $this->contentWidth, $this->y + $rowH, 0.3);
                $this->y += $rowH;
            }
            $this->y += 8;
        }
    }

    private function drawApptNotesSection(array $appt): void {
        $cfg = $this->cfg;
        $x   = $this->marginL;
        $this->checkPageBreak(50);

        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect($x, $this->y, $this->contentWidth, 18);
        $this->text($x + 6, $this->y + 12, 'APPOINTMENT NOTES', 8.5, true, 255, 255, 255);
        $this->y += 18;

        $maxW  = $this->contentWidth - 12;
        $rowNum = 0;
        foreach ([
            ['Office Notes',      $appt['office_notes']   ?? ''],
            ['Technician Notes',  $appt['tech_notes']     ?? ''],
            ['Customer Notes',    $appt['customer_notes'] ?? ''],
        ] as [$label, $text]) {
            if (empty(trim($text))) continue;
            $lines = $this->wrapLines($text, $maxW);
            $rowH  = max(20, 14 + count($lines) * 12);
            $this->checkPageBreak($rowH);
            if ($rowNum % 2 === 0) {
                $this->setGray(0.965);
                $this->rect($x, $this->y, $this->contentWidth, $rowH);
            }
            $this->text($x + 6, $this->y + 9, $label . ':', 7.5, true, $cfg['r'], $cfg['g'], $cfg['b']);
            $lineY = $this->y + 9;
            foreach ($lines as $ln) {
                $this->text($x + 90, $lineY, $ln, 8.5, false, 40, 40, 40);
                $lineY += 12;
            }
            $this->setGray(0.85, false);
            $this->line($x, $this->y + $rowH, $x + $this->contentWidth, $this->y + $rowH, 0.3);
            $this->y += $rowH;
            $rowNum++;
        }
        $this->y += 8;
    }

    private function drawStaffNotesSection(array $notes): void {
        $cfg = $this->cfg;
        $x   = $this->marginL;
        $this->checkPageBreak(50);

        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect($x, $this->y, $this->contentWidth, 18);
        $this->text($x + 6, $this->y + 12, 'STAFF NOTES', 8.5, true, 255, 255, 255);
        $this->y += 18;

        $maxW = $this->contentWidth - 12;
        foreach ($notes as $i => $note) {
            $lines = $this->wrapLines($note['note_text'], $maxW);
            $rowH  = max(30, 18 + count($lines) * 12);
            $this->checkPageBreak($rowH);

            if ($i % 2 === 0) {
                $this->setGray(0.965);
                $this->rect($x, $this->y, $this->contentWidth, $rowH);
            }

 // Author / date line
            $roleLabel = $note['author_role'] === 'admin' ? 'Office' : 'Technician';
            $meta      = $note['author_name'] . '  ·  ' . $roleLabel . '  ·  ' . substr($note['created_at'], 0, 10);
            $this->text($x + 6, $this->y + 10, $meta, 7.5, false, 110, 110, 110);

 // Visibility badge
            $badge      = $note['is_visible_to_customer'] ? 'Customer visible' : 'Internal';
            $badgeColor = $note['is_visible_to_customer'] ? [22, 163, 74] : [150, 150, 150];
            $bw = $this->stringWidth($badge, 7);
            $bx = $this->pageWidth - $this->marginR - $bw - 10;
            $this->setColor($badgeColor[0], $badgeColor[1], $badgeColor[2]);
            $this->rect($bx - 3, $this->y + 2, $bw + 6, 10);
            $this->text($bx, $this->y + 10, $badge, 7, false, 255, 255, 255);

 // Note text
            $lineY = $this->y + 10;
            foreach ($lines as $ln) {
                $this->text($x + 6, $lineY + 11, $ln, 8.5, false, 40, 40, 40);
                $lineY += 12;
            }

            $this->setGray(0.85, false);
            $this->line($x, $this->y + $rowH, $x + $this->contentWidth, $this->y + $rowH, 0.3);
            $this->y += $rowH;
        }
        $this->y += 8;
    }

 // Draws appointment photos in a 2-up grid. Each photo entry:
 // ['path' => absolute filesystem path, 'caption' => string|null]
 // Photos are embedded as PNG XObjects. Non-PNG or missing files are skipped.
 // Photo data is stored in $this->photoEmbeds for use by buildPdfBytes override.
    private array $photoEmbeds = [];  // [{name, data, w, h, x, y, pageIdx, drawW, drawH}]

    private function drawPhotosSection(array $photos): void {
        $cfg = $this->cfg;
        $x   = $this->marginL;

 // Collect embeddable photos first so we don't draw headers for empty sets
        $embeddable = [];
        foreach ($photos as $photo) {
            $path = $photo['path'] ?? '';
            if (!$path || !file_exists($path)) continue;
            $parsed = $this->parsePngForEmbed($path);
            if (!$parsed) continue;
            $embeddable[] = ['parsed' => $parsed, 'caption' => $photo['caption'] ?? ''];
        }
        if (empty($embeddable)) return;

        $this->checkPageBreak(50);
        $this->setColor($cfg['r'], $cfg['g'], $cfg['b']);
        $this->rect($x, $this->y, $this->contentWidth, 18);
        $this->text($x + 6, $this->y + 12, 'SERVICE PHOTOS', 8.5, true, 255, 255, 255);
        $this->y += 18 + 8;

        $colW    = ($this->contentWidth - 12) / 2;   // two columns with 12pt gutter
        $maxImgH = 180.0;                              // max photo height in points
        $col     = 0;
        $rowTopY = $this->y;
        $rowMaxH = 0.0;

        foreach ($embeddable as $item) {
            $parsed  = $item['parsed'];
            $caption = $item['caption'];

 // Scale to fit column width, cap height
            $ratio  = $parsed['w'] > 0 ? $parsed['h'] / $parsed['w'] : 1;
            $drawW  = $colW;
            $drawH  = min($drawW * $ratio, $maxImgH);
            if ($drawH >= $maxImgH) $drawW = $drawH / $ratio;

            $captionH = $caption ? 16 : 0;
            $cellH    = $drawH + $captionH + 4;

 // Start new row if right column finished or page break needed
            if ($col === 0) {
                $this->checkPageBreak($cellH + 8);
                $rowTopY = $this->y;
                $rowMaxH = $cellH;
            } else {
                $rowMaxH = max($rowMaxH, $cellH);
            }

            $imgX = $this->marginL + $col * ($colW + 12);
            $imgY = $rowTopY;

 // Register embed - page index is count of pages already completed
            $pageIdx = count($this->pages);   // current in-progress page
            $name    = 'Rpt' . count($this->photoEmbeds);
            $this->photoEmbeds[] = [
                'name'    => $name,
                'data'    => $parsed['data'],
                'w'       => $parsed['w'],
                'h'       => $parsed['h'],
                'cs'      => $parsed['cs'],
                'bpc'     => $parsed['bpc'],
                'x'       => $imgX,
                'y'       => $imgY,
                'pageIdx' => $pageIdx,
                'drawW'   => $drawW,
                'drawH'   => $drawH,
            ];

 // Placeholder rect so layout flows correctly
            $this->setGray(0.93);
            $this->rect($imgX, $imgY, $drawW, $drawH);
            $this->setGray(0.6, false);
            $this->line($imgX, $imgY, $imgX + $drawW, $imgY + $drawH, 0.3);
            $this->line($imgX + $drawW, $imgY, $imgX, $imgY + $drawH, 0.3);

            if ($caption) {
                $this->text($imgX, $imgY + $drawH + 12, $caption, 7.5, false, 90, 90, 90);
            }

            $col++;
            if ($col >= 2) {
                $this->y = $rowTopY + $rowMaxH + 10;
                $col     = 0;
                $rowMaxH = 0.0;
            }
        }

 // Flush incomplete last row
        if ($col > 0) {
            $this->y = $rowTopY + $rowMaxH + 10;
        }
        $this->y += 8;
    }

 // The base class only supports one logo XObject (/Im1).
 // We override here to inject photo images without touching invoice_pdf.php.
    public function buildPdfBytesWithPhotos(string $label): string {
 // Delegate to parent to get base bytes with logo handled correctly
        $baseBytes = $this->buildPdfBytes($label);

        if (empty($this->photoEmbeds)) return $baseBytes;

 // We need to re-parse the PDF and inject the photo XObjects.
 // Strategy: re-serialise from scratch using our own object list
 // built by mirroring what buildPdfBytes does, then adding photo objects.
 // Since we cannot call buildPdfBytes internals directly, we use the
 // mergePdfs infrastructure - but it's simpler to just re-build the PDF
 // from the page streams we already have in $this->pages.

        $out     = "%PDF-1.4
";
        $offsets = [];
        $addObj  = function(string $c) use (&$out, &$offsets): int {
            $id = count($offsets) + 1;
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj
$c
endobj
";
            return $id;
        };

        $f1 = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
        $f2 = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");

 // Logo (same logic as parent)
        $logoXObjId  = null;
        $logoImgCmd  = '';
        if ($this->logoInfo && file_exists($this->logoInfo['path'])) {
            $pngData = $this->parsePng($this->logoInfo['path']);
            if ($pngData) {
                $logoXObjId = $addObj(
                    "<< /Type /XObject /Subtype /Image"
                    . " /Width {$pngData['w']} /Height {$pngData['h']}"
                    . " /ColorSpace /{$pngData['cs']} /BitsPerComponent {$pngData['bpc']}"
                    . " /Filter /FlateDecode /Length " . strlen($pngData['data'])
                    . " >>
stream
" . $pngData['data'] . "
endstream"
                );
                $li = $this->logoInfo;
                $pdfY = $this->pageHeight - $li['y'] - $li['h'];
                $logoImgCmd = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q
",
                    $li['w'], $li['h'], $li['x'], $pdfY);
            }
        }

 // Photo XObjects - one per photo
        $photoXObjIds = [];  // name => obj id
        foreach ($this->photoEmbeds as $pe) {
            $oid = $addObj(
                "<< /Type /XObject /Subtype /Image"
                . " /Width {$pe['w']} /Height {$pe['h']}"
                . " /ColorSpace /{$pe['cs']} /BitsPerComponent {$pe['bpc']}"
                . " /Filter /FlateDecode /Length " . strlen($pe['data'])
                . " >>
stream
" . $pe['data'] . "
endstream"
            );
            $photoXObjIds[$pe['name']] = $oid;
        }

 // Build XObject dict string for resources
        $xObjEntries = [];
        if ($logoXObjId !== null) $xObjEntries[] = "/Im1 $logoXObjId 0 R";
        foreach ($photoXObjIds as $name => $oid) {
            $xObjEntries[] = "/$name $oid 0 R";
        }
        $xObjDict   = '/XObject << ' . implode(' ', $xObjEntries) . ' >>';
        $resources  = "<< /Font << /F1 $f1 0 R /F2 $f2 0 R >> $xObjDict /ProcSet [/PDF /Text /ImageB /ImageC] >>";

 // Build per-page draw commands for photos
        $photoDrawCmds = [];  // pageIdx => string
        foreach ($this->photoEmbeds as $pe) {
            $pdfY   = $this->pageHeight - $pe['y'] - $pe['drawH'];
            $cmd    = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q
",
                $pe['drawW'], $pe['drawH'], $pe['x'], $pdfY, $pe['name']);
            $photoDrawCmds[$pe['pageIdx']] = ($photoDrawCmds[$pe['pageIdx']] ?? '') . $cmd;
        }

 // Page streams
        $pageIds = [];
        foreach ($this->pages as $pi => $stream) {
 // Prepend logo draw cmd to page 0 if logo exists
            if ($pi === 0 && $logoImgCmd) $stream = $logoImgCmd . $stream;
 // Append photo draw commands for this page index
            if (isset($photoDrawCmds[$pi])) $stream .= $photoDrawCmds[$pi];

            $sId = $addObj("<< /Length " . strlen($stream) . " >>
stream
" . $stream . "
endstream");
            $pId = $addObj(
                "<< /Type /Page /Parent 999999 0 R"
                . " /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}]"
                . " /Contents $sId 0 R /Resources $resources >>"
            );
            $pageIds[] = $pId;
        }

 // Fix up /Parent placeholder - re-serialise with correct pages ID
        $kidsStr    = implode(' 0 R ', $pageIds) . ' 0 R';
        $pagesId    = $addObj("<< /Type /Pages /Kids [$kidsStr] /Count " . count($pageIds) . " >>");
        $catId      = $addObj("<< /Type /Catalog /Pages $pagesId 0 R >>");
        $infoId     = $addObj("<< /Title (Service Report) /Creator (MVP Backoffice) >>");

 // Fix /Parent refs in page objects
        $out = preg_replace('/\/Parent 999999 0 R/', "/Parent $pagesId 0 R", $out);

        $xrefOffset = strlen($out);
        $objCount   = count($offsets);
        $out .= "xref
0 " . ($objCount + 1) . "
";
        $out .= "0000000000 65535 f
";
        foreach ($offsets as $off) {
            $out .= str_pad($off, 10, '0', STR_PAD_LEFT) . " 00000 n
";
        }
        $out .= "trailer
<< /Size " . ($objCount + 1) . " /Root $catId 0 R /Info $infoId 0 R >>
";
        $out .= "startxref
$xrefOffset
%%EOF";

        return $out;
    }

 // Parse PNG for photo embedding - same logic as parent parsePng but public-accessible
    private function parsePngForEmbed(string $path): ?array {
        $data = @file_get_contents($path);
        if (!$data) return null;
        if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
        $w = unpack('N', substr($data, 16, 4))[1];
        $h = unpack('N', substr($data, 20, 4))[1];
        $bpc       = ord($data[24]);
        $colorType = ord($data[25]);
        $cs = match($colorType) { 0,4 => 'DeviceGray', default => 'DeviceRGB' };
        $idat = '';
        $pos  = 8;
        while ($pos < strlen($data)) {
            $len  = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            if ($type === 'IDAT') $idat .= substr($data, $pos + 8, $len);
            if ($type === 'IEND') break;
            $pos += 12 + $len;
        }
        if (!$idat) return null;
        return ['w'=>$w,'h'=>$h,'cs'=>$cs,'bpc'=>$bpc,'data'=>$idat];
    }

    private function drawReportFooter(): void {
 // Add a small gap after the last content block
        $this->y += 20;
        $this->checkPageBreak(24);
        $this->setGray(0.85, false);
        $this->line($this->marginL, $this->y, $this->pageWidth - $this->marginR, $this->y, 0.3);
        $this->y += 8;
        $this->text($this->marginL, $this->y,
            'Confidential Service Record  -  Acme Water Service', 8, false, 130, 130, 130);
        $this->textRight($this->pageWidth - $this->marginR, $this->y,
            'Generated by MVP Backoffice ' . MVP_VERSION . ' on ' . date('m-d-Y'), 8, false, 130, 130, 130);
    }

    private function wrapLines(string $text, float $maxW, float $fontSize = 8.5): array {
        $words  = explode(' ', $text);
        $line   = '';
        $result = [];
        foreach ($words as $word) {
            $test = $line ? $line . ' ' . $word : $word;
            if ($this->stringWidth($test, $fontSize) > $maxW && $line) {
                $result[] = $line;
                $line     = $word;
            } else {
                $line = $test;
            }
        }
        if ($line) $result[] = $line;
        return $result ?: [''];
    }
}

// Local subclass that adds buildForMerge without requiring
// the updated invoice_pdf.php methods on the server
class MergeableInvoicePdf extends InvoicePdf {
    public function buildForMerge(
        array $inv, array $lines, array $payments,
        array $apptNotes = [], string $role = 'admin'
    ): void {
 // Reset internal state
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

    public function getPages(): array    { return $this->pages; }
    public function getImages(): array   { return $this->images; }
    public function getLogoInfo(): ?array { return $this->logoInfo; }
}
