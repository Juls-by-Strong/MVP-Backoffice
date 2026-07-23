<?php
// Estimates / Quotes
//
// Admin & Tech:
// GET /[role]/estimates - list
// POST /[role]/estimates - create
// GET /[role]/estimates/{id} - detail
// PUT /[role]/estimates/{id} - update
// POST /[role]/estimates/{id}/lines - add line
// PUT /[role]/estimates/{id}/lines/{lid} - update line
// DELETE /[role]/estimates/{id}/lines/{lid} - remove line
// POST /[role]/estimates/{id}/recalculate - recalc totals
// POST /[role]/estimates/{id}/send - mark as sent
// POST /[role]/estimates/{id}/convert - to invoice (admin only)
// DELETE /[role]/estimates/{id} - delete (draft only)
//
// Customer:
// GET /customer/estimates - own estimates
// GET /customer/estimates/{id} - detail
// POST /customer/estimates/{id}/respond - approve / reject

require_once __DIR__ . '/invoices.php';

function generateEstimateNumber(PDO $db): string {
    $year = (int)date('Y');
    $db->beginTransaction();
    $db->prepare(
        "INSERT INTO estimate_counter (year, sequence) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE sequence = sequence + 1"
    )->execute([$year]);
    $stmt = $db->prepare("SELECT sequence FROM estimate_counter WHERE year = ?");
    $stmt->execute([$year]);
    $seq = (int)$stmt->fetchColumn();
    $db->commit();
    return sprintf('EST-%d-%04d', $year, $seq);
}

function recalculateEstimate(PDO $db, int $estimateId): void {
    $stmt = $db->prepare(
        "SELECT el.*, e.customer_id, c.service_city, c.service_state
         FROM estimates e
         JOIN customers c ON e.customer_id = c.customer_id
         LEFT JOIN estimate_lines el ON el.estimate_id = e.estimate_id
         WHERE e.estimate_id = ?"
    );
    $stmt->execute([$estimateId]);
    $lines = $stmt->fetchAll();

    if (empty($lines)) {
        $db->prepare(
            "UPDATE estimates SET subtotal=0, taxable_amount=0, tax_amount=0, total=0 WHERE estimate_id=?"
        )->execute([$estimateId]);
        return;
    }

    $stmt2 = $db->prepare(
        "SELECT c.service_city, c.service_state
         FROM estimates e JOIN customers c ON e.customer_id = c.customer_id
         WHERE e.estimate_id = ?"
    );
    $stmt2->execute([$estimateId]);
    $customer = $stmt2->fetch();

    $taxRate  = getTaxRate($db, $customer['service_city'] ?? '', $customer['service_state'] ?? 'IL');
    $subtotal = 0.00;
    $taxable  = 0.00;

    foreach ($lines as $line) {
        if ($line['line_id'] === null) continue;
        $subtotal += (float)$line['line_total'];
        if ($line['is_taxable']) {
            $taxable += (float)$line['line_total'];
        }
    }

    $taxAmount = round($taxable * $taxRate, 2);
    $total     = round($subtotal + $taxAmount, 2);

    $db->prepare(
        "UPDATE estimates
         SET subtotal = ?, taxable_amount = ?, tax_rate = ?, tax_amount = ?, total = ?
         WHERE estimate_id = ?"
    )->execute([$subtotal, $taxable, $taxRate, $taxAmount, $total, $estimateId]);
}

function estimateDetail(PDO $db, int $estimateId): ?array {
    $stmt = $db->prepare(
        "SELECT e.*,
                COALESCE(NULLIF(TRIM(c.company_name),''), CONCAT(c.first_name,' ',c.last_name)) AS customer_name,
                c.first_name AS customer_first_name, c.last_name AS customer_last_name, c.company_name,
                c.phone, c.email,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.billing_address, c.billing_city, c.billing_state, c.billing_zip, c.has_separate_billing,
                CONCAT(u.first_name,' ',u.last_name) AS created_by_name,
                a.confirmed_date, a.confirmed_time, a.office_notes AS appt_office_notes,
                a.status AS appt_status,
                st.name AS service_type,
                CONCAT(tech.first_name,' ',tech.last_name) AS technician_name
         FROM estimates e
         JOIN customers c  ON e.customer_id  = c.customer_id
         JOIN users u      ON e.created_by   = u.user_id
         LEFT JOIN appointments a ON e.appointment_id = a.appointment_id
         LEFT JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN users tech ON a.technician_id = tech.user_id
         WHERE e.estimate_id = ?"
    );
    $stmt->execute([$estimateId]);
    $est = $stmt->fetch();
    if (!$est) return null;

    $stmt2 = $db->prepare(
        "SELECT * FROM estimate_lines WHERE estimate_id = ? ORDER BY sort_order, line_id"
    );
    $stmt2->execute([$estimateId]);
    $est['lines'] = $stmt2->fetchAll();

    return $est;
}

// MAIN HANDLER
function handleEstimates(
    PDO $db, string $method, ?string $id, ?string $sub, ?string $subsub,
    int $userId, string $role
): void {

    if ($method === 'GET' && $id === null) {
        $customerId = $_GET['customer_id'] ?? null;
        $status     = $_GET['status']      ?? null;
        $fromDate   = $_GET['from']        ?? null;
        $toDate     = $_GET['to']          ?? null;

        $where  = ['1=1'];
        $params = [];
        if ($customerId) { $where[] = 'e.customer_id = ?'; $params[] = $customerId; }
        if ($status)     { $where[] = 'e.status = ?';      $params[] = $status; }

        if ($role === 'technician') {
            $where[] = "EXISTS (SELECT 1 FROM appointments tap
                                 WHERE tap.appointment_id = e.appointment_id
                                   AND tap.technician_id = ?)";
            $params[] = $userId;
        }

        if ($fromDate) { $where[] = 'e.issue_date >= ?'; $params[] = $fromDate; }
        if ($toDate)   { $where[] = 'e.issue_date <= ?'; $params[] = $toDate; }

        $stmt = $db->prepare(
            "SELECT e.estimate_id, e.estimate_number, e.status, e.issue_date, e.expiry_date,
                    e.subtotal, e.tax_amount, e.total,
                    e.appointment_id, e.contract_id,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.company_name,
                    e.created_at
             FROM estimates e
             JOIN customers c ON e.customer_id = c.customer_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY e.created_at DESC"
        );
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
    }

    if ($method === 'POST' && $id === null) {
        $body          = json_decode(file_get_contents('php://input'), true) ?? [];
        $customerId    = (int)($body['customer_id']    ?? 0);
        $appointmentId = !empty($body['appointment_id']) ? (int)$body['appointment_id'] : null;
        $contractId    = !empty($body['contract_id'])    ? (int)$body['contract_id']    : null;
        $notes         = trim($body['notes'] ?? '');
        $expiryDate    = !empty($body['expiry_date']) ? $body['expiry_date'] : null;

        if (!$customerId) sendError(400, 'customer_id is required');

        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) sendError(404, 'Customer not found');

        $estimateNumber = generateEstimateNumber($db);

        $db->prepare(
            "INSERT INTO estimates
             (customer_id, appointment_id, contract_id, estimate_number, status, issue_date, expiry_date, created_by, notes)
             VALUES (?, ?, ?, ?, 'draft', CURDATE(), ?, ?, ?)"
        )->execute([$customerId, $appointmentId, $contractId, $estimateNumber, $expiryDate, $userId, $notes ?: null]);

        $estimateId = (int)$db->lastInsertId();

        sendJson(estimateDetail($db, $estimateId), 201);
    }

    if ($id !== null) {
        $estimateId = (int)$id;

        if ($sub === 'lines') {
            if ($method === 'POST' && $subsub === null) {
                $body        = json_decode(file_get_contents('php://input'), true) ?? [];
                $lineType    = $body['line_type']   ?? 'custom';
                $lineName    = trim($body['line_name']    ?? '');
                $description = trim($body['description'] ?? '');
                $quantity    = (float)($body['quantity']   ?? 1);
                $unitPrice   = (float)($body['unit_price'] ?? 0);
                $isCustomTax = isset($body['is_taxable']) ? (int)(bool)$body['is_taxable'] : null;
                $partId      = !empty($body['part_id']) ? (int)$body['part_id'] : null;
                $h2o2Prorate = isset($body['h2o2_prorate']) ? (float)$body['h2o2_prorate'] : null;
                $discountNote = trim($body['discount_note'] ?? '') ?: null;

                if (!$lineName && !$description) sendError(400, 'line_name is required');
                if (!$lineName) { $lineName = $description; $description = ''; }

                $isTaxable  = $isCustomTax !== null ? $isCustomTax : (isTaxableLine($lineType) ? 1 : 0);

                if ($h2o2Prorate !== null && $h2o2Prorate > 0 && $lineType !== 'discount') {
                    $unitPrice = round($unitPrice * (1 - $h2o2Prorate / 100), 2);
                }

                $lineTotal  = round($quantity * $unitPrice, 2);
                $sortOrder  = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM estimate_lines WHERE estimate_id=$estimateId")->fetchColumn();

                $db->prepare(
                    "INSERT INTO estimate_lines
                     (estimate_id, part_id, line_type, line_name, description, quantity, unit_price,
                      is_taxable, h2o2_prorate, discount_note, line_total, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $estimateId, $partId, $lineType, $lineName, $description, $quantity, $unitPrice,
                    $isTaxable, $h2o2Prorate, $discountNote, $lineTotal, $sortOrder
                ]);

                recalculateEstimate($db, $estimateId);
                sendJson(estimateDetail($db, $estimateId), 201);
            }

            if ($method === 'PUT' && $subsub !== null) {
                $lineId = (int)$subsub;
                $body   = json_decode(file_get_contents('php://input'), true) ?? [];

                $stmt = $db->prepare("SELECT * FROM estimate_lines WHERE line_id = ? AND estimate_id = ?");
                $stmt->execute([$lineId, $estimateId]);
                $line = $stmt->fetch();
                if (!$line) sendError(404, 'Line item not found');

                $lineType   = $body['line_type']   ?? $line['line_type'];
                $lineName   = isset($body['line_name'])   ? trim($body['line_name'])   : $line['line_name'];
                $desc       = isset($body['description']) ? trim($body['description']) : $line['description'];
                $quantity   = isset($body['quantity'])    ? (float)$body['quantity']   : (float)$line['quantity'];
                $unitPrice  = isset($body['unit_price'])  ? (float)$body['unit_price'] : (float)$line['unit_price'];
                $isTaxable  = isset($body['is_taxable'])  ? (int)(bool)$body['is_taxable'] : (int)$line['is_taxable'];
                $lineTotal  = round($quantity * $unitPrice, 2);

                $db->prepare(
                    "UPDATE estimate_lines
                     SET line_type=?, line_name=?, description=?, quantity=?, unit_price=?, is_taxable=?, line_total=?
                     WHERE line_id=?"
                )->execute([$lineType, $lineName, $desc, $quantity, $unitPrice, $isTaxable, $lineTotal, $lineId]);

                recalculateEstimate($db, $estimateId);
                sendJson(estimateDetail($db, $estimateId));
            }

            if ($method === 'DELETE' && $subsub !== null) {
                $lineId = (int)$subsub;
                $db->prepare("DELETE FROM estimate_lines WHERE line_id = ? AND estimate_id = ?")
                   ->execute([$lineId, $estimateId]);
                recalculateEstimate($db, $estimateId);
                sendJson(estimateDetail($db, $estimateId));
            }

            sendError(405, 'Method not allowed');
        }

        if ($sub === 'recalculate' && $method === 'POST') {
            recalculateEstimate($db, $estimateId);
            sendJson(estimateDetail($db, $estimateId));
        }

        if ($sub === 'send' && $method === 'POST') {
            $stmt = $db->prepare("SELECT * FROM estimates WHERE estimate_id = ?");
            $stmt->execute([$estimateId]);
            $est = $stmt->fetch();
            if (!$est) sendError(404, 'Estimate not found');
            if ($est['status'] !== 'draft') sendError(409, 'Only draft estimates can be sent');

            $db->prepare("UPDATE estimates SET status = 'sent' WHERE estimate_id = ?")
               ->execute([$estimateId]);
            sendJson(estimateDetail($db, $estimateId));
        }

        if ($sub === 'convert' && $method === 'POST') {
            if ($role !== 'admin') sendError(403, 'Admin only');

            $stmt = $db->prepare("SELECT * FROM estimates WHERE estimate_id = ?");
            $stmt->execute([$estimateId]);
            $est = $stmt->fetch();
            if (!$est) sendError(404, 'Estimate not found');
            if ($est['status'] !== 'approved') sendError(409, 'Only approved estimates can be converted');
            if (!empty($est['converted_invoice_id'])) sendError(409, 'Already converted to invoice');

            $invoiceNumber = generateInvoiceNumber($db);

            $db->prepare(
                "INSERT INTO invoices
                 (customer_id, appointment_id, invoice_number, status, issue_date, created_by, notes)
                 VALUES (?, ?, ?, 'draft', CURDATE(), ?, ?)"
            )->execute([$est['customer_id'], $est['appointment_id'], $invoiceNumber, $userId, $est['notes']]);

            $invoiceId = (int)$db->lastInsertId();

            $lineStmt = $db->prepare("SELECT * FROM estimate_lines WHERE estimate_id = ? ORDER BY sort_order, line_id");
            $lineStmt->execute([$estimateId]);
            $lines = $lineStmt->fetchAll();

            foreach ($lines as $line) {
                $db->prepare(
                    "INSERT INTO invoice_lines
                     (invoice_id, part_id, line_type, line_name, description, quantity, unit_price,
                      is_taxable, h2o2_prorate, discount_note, line_total, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $invoiceId, $line['part_id'], $line['line_type'], $line['line_name'],
                    $line['description'], $line['quantity'], $line['unit_price'],
                    $line['is_taxable'], $line['h2o2_prorate'], $line['discount_note'],
                    $line['line_total'], $line['sort_order']
                ]);
            }

            recalculateInvoice($db, $invoiceId);

            $db->prepare(
                "UPDATE estimates SET status = 'converted', converted_invoice_id = ? WHERE estimate_id = ?"
            )->execute([$invoiceId, $estimateId]);

            sendJson([
                'message'     => 'Estimate converted to invoice',
                'invoice_id'  => $invoiceId,
                'estimate_id' => $estimateId,
            ], 201);
        }

        if ($method === 'DELETE' && $sub === null) {
            $stmt = $db->prepare("SELECT * FROM estimates WHERE estimate_id = ?");
            $stmt->execute([$estimateId]);
            $est = $stmt->fetch();
            if (!$est) sendError(404, 'Estimate not found');
            if ($est['status'] !== 'draft') sendError(409, 'Only draft estimates can be deleted');

            $db->prepare("DELETE FROM estimate_lines WHERE estimate_id = ?")->execute([$estimateId]);
            $db->prepare("DELETE FROM estimates WHERE estimate_id = ?")->execute([$estimateId]);
            sendJson(['message' => 'Estimate deleted']);
        }

        if ($method === 'GET' && $sub === null) {
            $est = estimateDetail($db, $estimateId);
            if (!$est) sendError(404, 'Estimate not found');

            if ($role === 'technician' && $est['appointment_id']) {
                $chk = $db->prepare(
                    "SELECT 1 FROM appointments WHERE appointment_id = ? AND technician_id = ?"
                );
                $chk->execute([$est['appointment_id'], $userId]);
                if (!$chk->fetch()) sendError(403, 'Access denied');
            }

            sendJson($est);
        }

        if ($method === 'PUT' && $sub === null) {
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = ['status', 'issue_date', 'expiry_date', 'notes'];
            $fields  = [];
            $values  = [];

            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    $values[] = $body[$f] === '' ? null : $body[$f];
                }
            }

            if (!empty($fields)) {
                $values[] = $estimateId;
                $db->prepare("UPDATE estimates SET " . implode(', ', $fields) . " WHERE estimate_id = ?")
                   ->execute($values);
            }

            sendJson(estimateDetail($db, $estimateId));
        }

        sendError(405, 'Method not allowed');
    }

    sendError(404, 'Not found');
}

// CUSTOMER HANDLER
function handleCustomerEstimates(
    PDO $db, string $method, ?string $id, ?string $sub, int $customerId
): void {

    if ($method === 'GET' && $id === null) {
        $stmt = $db->prepare(
            "SELECT e.estimate_id, e.estimate_number, e.status, e.issue_date, e.expiry_date,
                    e.subtotal, e.tax_amount, e.total, e.created_at
             FROM estimates e
             WHERE e.customer_id = ? AND e.status != 'draft'
             ORDER BY e.created_at DESC"
        );
        $stmt->execute([$customerId]);
        sendJson($stmt->fetchAll());
    }

    if ($method === 'GET' && $id !== null) {
        $est = estimateDetail($db, (int)$id);
        if (!$est || (int)$est['customer_id'] !== (int)$customerId) {
            sendError(404, 'Estimate not found');
        }
        sendJson($est);
    }

    if ($method === 'POST' && $id !== null && $sub === 'respond') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';

        if (!in_array($action, ['approve', 'reject'])) {
            sendError(400, 'action must be approve or reject');
        }

        $stmt = $db->prepare("SELECT * FROM estimates WHERE estimate_id = ? AND customer_id = ?");
        $stmt->execute([(int)$id, $customerId]);
        $est = $stmt->fetch();
        if (!$est) sendError(404, 'Estimate not found');
        if ($est['status'] !== 'sent') sendError(409, 'Only sent estimates can be responded to');

        if ($est['expiry_date'] && strtotime($est['expiry_date']) < time()) {
            $db->prepare("UPDATE estimates SET status = 'expired' WHERE estimate_id = ?")
               ->execute([(int)$id]);
            sendError(409, 'This estimate has expired');
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $responseNotes = trim($body['notes'] ?? '') ?: null;

        $db->prepare(
            "UPDATE estimates SET status = ?, customer_response_notes = ? WHERE estimate_id = ?"
        )->execute([$newStatus, $responseNotes, (int)$id]);

        sendJson([
            'message' => $action === 'approve' ? 'Estimate approved' : 'Estimate rejected',
            'status'  => $newStatus,
        ]);
    }

    sendError(405, 'Method not allowed');
}
