<?php
// Invoice and payment management
//
// Admin & Tech:
// GET /[role]/invoices - list invoices
// POST /[role]/invoices - create invoice
// GET /[role]/invoices/{id} - invoice detail
// PUT /[role]/invoices/{id} - update invoice
// POST /[role]/invoices/{id}/lines - add line item
// PUT /[role]/invoices/{id}/lines/{lid} - update line item
// DELETE /[role]/invoices/{id}/lines/{lid} - remove line item
// POST /[role]/invoices/{id}/payment - record payment
// POST /[role]/invoices/{id}/recalculate - recalculate totals
// GET /admin/tax-rates - list tax rates
// PUT /admin/tax-rates/{id} - update a rate
// GET /[role]/invoices/lookup-tax - get rate for city
//
// Taxable line types: parts, filter, equipment, salt
// Non-taxable: labor, service_call, warranty, discount, custom

function isTaxableLine(string $type): bool {
    return in_array($type, ['parts', 'filter', 'equipment', 'salt']);
}

function generateInvoiceNumber(PDO $db): string {
    $year = (int)date('Y');
    $db->beginTransaction();
    $db->prepare(
        "INSERT INTO invoice_counter (year, sequence) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE sequence = sequence + 1"
    )->execute([$year]);
    $stmt = $db->prepare("SELECT sequence FROM invoice_counter WHERE year = ?");
    $stmt->execute([$year]);
    $seq = (int)$stmt->fetchColumn();
    $db->commit();
    return sprintf('INV-%d-%04d', $year, $seq);
}

function getTaxRate(PDO $db, string $city, string $state = 'IL'): float {
    $stmt = $db->prepare(
        "SELECT rate FROM tax_rates WHERE LOWER(city) = LOWER(?) AND state = ?"
    );
    $stmt->execute([trim($city), $state]);
    $row = $stmt->fetch();
    return $row ? (float)$row['rate'] : 0.0625; // fallback to IL state rate
}

function recalculateInvoice(PDO $db, int $invoiceId): void {
    $stmt = $db->prepare(
        "SELECT il.*, i.customer_id, c.service_city, c.service_state
         FROM invoices i
         JOIN customers c ON i.customer_id = c.customer_id
         LEFT JOIN invoice_lines il ON il.invoice_id = i.invoice_id
         WHERE i.invoice_id = ?"
    );
    $stmt->execute([$invoiceId]);
    $lines = $stmt->fetchAll();

    if (empty($lines)) {
        $db->prepare(
            "UPDATE invoices SET subtotal=0, taxable_amount=0, tax_amount=0, total=0 WHERE invoice_id=?"
        )->execute([$invoiceId]);
        return;
    }

 // Get customer city for tax rate
    $stmt2 = $db->prepare(
        "SELECT c.service_city, c.service_state
         FROM invoices i JOIN customers c ON i.customer_id = c.customer_id
         WHERE i.invoice_id = ?"
    );
    $stmt2->execute([$invoiceId]);
    $customer = $stmt2->fetch();

    $taxRate      = getTaxRate($db, $customer['service_city'] ?? '', $customer['service_state'] ?? 'IL');
    $subtotal     = 0.00;
    $taxable      = 0.00;

    foreach ($lines as $line) {
        if ($line['line_id'] === null) continue;
        $subtotal += (float)$line['line_total'];
        if ($line['is_taxable']) {
            $taxable += (float)$line['line_total'];
        }
    }

    $taxAmount = round($taxable * $taxRate, 2);
    $baseTotal = round($subtotal + $taxAmount, 2);

 // Card fee: 3.5% applied after tax if card_fee_enabled
    $stmt3 = $db->prepare("SELECT card_fee_enabled FROM invoices WHERE invoice_id = ?");
    $stmt3->execute([$invoiceId]);
    $feeEnabled = (bool)($stmt3->fetchColumn());
    $cardFee    = $feeEnabled ? round($baseTotal * 0.035, 2) : 0.00;
    $total      = round($baseTotal + $cardFee, 2);

    $db->prepare(
        "UPDATE invoices
         SET subtotal = ?, taxable_amount = ?, tax_rate = ?, tax_amount = ?,
             card_fee_amount = ?, total = ?
         WHERE invoice_id = ?"
    )->execute([$subtotal, $taxable, $taxRate, $taxAmount, $cardFee, $total, $invoiceId]);
}

function invoiceDetail(PDO $db, int $invoiceId): ?array {
    $stmt = $db->prepare(
        "SELECT i.*,
                COALESCE(NULLIF(TRIM(c.company_name),''), CONCAT(c.first_name,' ',c.last_name)) AS customer_name,
                c.first_name AS customer_first_name, c.last_name AS customer_last_name, c.company_name,
                c.phone, c.email,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.billing_address, c.billing_city, c.billing_state, c.billing_zip, c.has_separate_billing,
                CONCAT(u.first_name,' ',u.last_name) AS created_by_name,
                a.confirmed_date, a.confirmed_time, a.office_notes AS appt_office_notes,
                a.customer_notes AS appt_customer_notes, a.status AS appt_status,
                st.name AS service_type,
                CONCAT(tech.first_name,' ',tech.last_name) AS technician_name,
                tech.user_id AS technician_user_id
         FROM invoices i
         JOIN customers c  ON i.customer_id  = c.customer_id
         JOIN users u      ON i.created_by   = u.user_id
         LEFT JOIN appointments a ON i.appointment_id = a.appointment_id
         LEFT JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN users tech ON a.technician_id = tech.user_id
         WHERE i.invoice_id = ?"
    );
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv) return null;

    $stmt2 = $db->prepare(
        "SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order, line_id"
    );
    $stmt2->execute([$invoiceId]);
    $inv['lines'] = $stmt2->fetchAll();

    $stmt3 = $db->prepare(
        "SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS recorded_by_name
         FROM payments p JOIN users u ON p.recorded_by = u.user_id
         WHERE p.invoice_id = ? ORDER BY p.payment_date DESC"
    );
    $stmt3->execute([$invoiceId]);
    $inv['payments'] = $stmt3->fetchAll();

 // Notes for this customer (from customer_notes)
    try {
        if (!empty($inv['appointment_id'])) {
            $stmt4 = $db->prepare(
                "SELECT n.note_id, n.note_text, n.is_visible_to_customer, n.created_at,
                        COALESCE(CONCAT(u.first_name,' ',u.last_name), u.email) AS author_name,
                        u.role AS author_role
                 FROM customer_notes n
                 JOIN users u ON n.author_id = u.user_id
                 WHERE n.customer_id = ?
                 ORDER BY n.created_at DESC LIMIT 5"
            );
            $stmt4->execute([$inv['customer_id']]);
            $inv['appt_notes'] = $stmt4->fetchAll();
        } else {
            $inv['appt_notes'] = [];
        }
    } catch (\Throwable $e) {
        $inv['appt_notes'] = [];
    }

    return $inv;
}

function autoCreateInvoiceForAppointment(PDO $db, int $appointmentId, int $createdBy): ?int {
 // Don't create if one already exists
    $existing = $db->prepare("SELECT invoice_id FROM invoices WHERE appointment_id = ? AND status != 'void' LIMIT 1");
    $existing->execute([$appointmentId]);
    if ($existing->fetch()) return null;

    $appt = $db->prepare(
        "SELECT a.customer_id, a.service_type_id, a.confirmed_date,
                st.name AS service_type,
                COALESCE(st.skip_auto_invoice_lines, 0) AS skip_auto_invoice_lines
         FROM appointments a
         LEFT JOIN service_types st ON a.service_type_id = st.type_id
         WHERE a.appointment_id = ?"
    );
    $appt->execute([$appointmentId]);
    $a = $appt->fetch();
    if (!$a) return null;

    $invoiceNumber = generateInvoiceNumber($db);
    $issueDate     = $a['confirmed_date'] ?: date('Y-m-d');

    $db->prepare(
        "INSERT INTO invoices (customer_id, appointment_id, invoice_number, status, issue_date, created_by)
         VALUES (?, ?, ?, 'draft', ?, ?)"
    )->execute([$a['customer_id'], $appointmentId, $invoiceNumber, $issueDate, $createdBy]);

    $invoiceId = (int)$db->lastInsertId();

 // Auto-populate lines from service type + equipment.
 // Service types flagged skip_auto_invoice_lines (e.g. installs) get the
 // draft shell only - the office bills the line items manually.
    if ((int)$a['skip_auto_invoice_lines'] === 0) {
        autoPopulateLines($db, $appointmentId, $invoiceId);
    }
    recalculateInvoice($db, $invoiceId);

 // Mark appointment as having auto-generated invoice
    $db->prepare("UPDATE appointments SET invoice_auto_generated = 1 WHERE appointment_id = ?")
       ->execute([$appointmentId]);

    return $invoiceId;
}
function autoPopulateLines(PDO $db, int $appointmentId, int $invoiceId): void {
    $stmt = $db->prepare(
        "SELECT st.name AS service_type, st.default_price AS service_default_price,
                ae.equipment_id, et.type_name, e.model,
                COALESCE(e.part_id, et.default_part_id) AS effective_part_id,
                p.name AS part_name, p.customer_description AS part_customer_description,
                p.sell_price AS part_sell_price, p.is_taxable AS part_is_taxable
         FROM appointments a
         JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN appointment_equipment ae ON ae.appointment_id = a.appointment_id
         LEFT JOIN equipment e  ON ae.equipment_id = e.equipment_id
         LEFT JOIN equipment_types et ON e.type_id = et.type_id
         LEFT JOIN parts_catalog p ON p.part_id = COALESCE(e.part_id, et.default_part_id)
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) return;

    $serviceType  = $rows[0]['service_type'];
    $serviceCallPrice = (float)($rows[0]['service_default_price'] ?? 0);
    $sort = 0;

 // Service call fee - pulled from service_types.default_price (e.g. $77 Service Call)
    $db->prepare(
        "INSERT INTO invoice_lines (invoice_id, line_type, line_name, description, quantity, unit_price, is_taxable, line_total, sort_order)
         VALUES (?, 'service_call', ?, '', 1, ?, 0, ?, ?)"
    )->execute([$invoiceId, $serviceType, $serviceCallPrice, $serviceCallPrice, $sort++]);

 // Labor line
    $db->prepare(
        "INSERT INTO invoice_lines (invoice_id, line_type, line_name, description, quantity, unit_price, is_taxable, line_total, sort_order)
         VALUES (?, 'labor', 'Labor', '', 1, 0.00, 0, 0.00, ?)"
    )->execute([$invoiceId, $sort++]);

 // Add a parts/filter line for each piece of equipment serviced
    foreach ($rows as $row) {
        if (!$row['equipment_id']) continue;
        $equipName = trim($row['type_name'] . ($row['model'] ? ' - ' . $row['model'] : ''));

 // Salt delivery gets a salt line type
        $lineType = (stripos($serviceType, 'salt') !== false) ? 'salt' : 'filter';

        if (!empty($row['effective_part_id'])) {
 // A catalog part is linked to this equipment (or its type) - use it
            $partName    = $row['part_name'] ?: $equipName;
            $description = $row['part_customer_description'] ?: '';
            $unitPrice   = (float)$row['part_sell_price'];
            $isTaxable   = (int)(bool)$row['part_is_taxable'];

            $db->prepare(
                "INSERT INTO invoice_lines (invoice_id, part_id, line_type, line_name, description, quantity, unit_price, is_taxable, line_total, sort_order)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
            )->execute([
                $invoiceId, (int)$row['effective_part_id'], $lineType, $partName, $description,
                $unitPrice, $isTaxable, $unitPrice, $sort++
            ]);
        } else {
 // No catalog part linked - fall back to existing blank placeholder behavior
            $db->prepare(
                "INSERT INTO invoice_lines (invoice_id, line_type, line_name, description, quantity, unit_price, is_taxable, line_total, sort_order)
                 VALUES (?, ?, ?, '', 1, 0.00, 1, 0.00, ?)"
            )->execute([$invoiceId, $lineType, $equipName, $sort++]);
        }
    }
}

// MAIN HANDLER - called from admin.php and tech.php
function handleInvoices(
    PDO $db, string $method, ?string $id, ?string $sub, ?string $subsub,
    int $userId, string $role
): void {

    if ($method === 'GET' && $id === 'lookup-tax') {
        $city  = trim($_GET['city']  ?? '');
        $state = trim($_GET['state'] ?? 'IL');
        if (!$city) sendError(400, 'city is required');
        $rate = getTaxRate($db, $city, $state);
        sendJson(['city' => $city, 'state' => $state, 'rate' => $rate, 'rate_percent' => round($rate * 100, 4)]);
    }

    if ($role === 'admin' && $id === 'tax-rates') {
 // This is handled in admin.php case 'tax-rates'
        sendError(404, 'Not found');
    }

    if ($method === 'POST' && $id === 'bulk-delete') {
        if ($role !== 'admin') sendError(403, 'Admin only');
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids  = array_filter(array_map('intval', $body['invoice_ids'] ?? []), fn($v) => $v > 0);
        if (empty($ids)) sendError(400, 'invoice_ids array is required and must not be empty');

        $deleted = 0;
        $voided  = 0;

        foreach ($ids as $iid) {
 // Check for payments
            $stmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ?");
            $stmt->execute([$iid]);
            $hasPay = (int)$stmt->fetchColumn() > 0;

            if ($hasPay) {
                $db->prepare("UPDATE invoices SET status = 'void' WHERE invoice_id = ?")->execute([$iid]);
                $voided++;
            } else {
                $db->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?")->execute([$iid]);
                $db->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$iid]);
                $deleted++;
            }
        }

        sendJson([
            'message' => "Processed " . count($ids) . " invoice(s): {$deleted} deleted, {$voided} voided (had payments).",
            'deleted' => $deleted,
            'voided'  => $voided,
        ]);
    }

    if ($method === 'GET' && $id === null) {
        $customerId = $_GET['customer_id'] ?? null;
        $status     = $_GET['status']      ?? null;
        $fromDate   = $_GET['from']        ?? null;
        $toDate     = $_GET['to']          ?? null;
        $customerId = $_GET['customer_id'] ?? null;
        $where  = ['1=1'];
        $params = [];
        if ($customerId) { $where[] = 'i.customer_id = ?'; $params[] = $customerId; }
        if ($status)     { $where[] = 'i.status = ?';      $params[] = $status; }

 // Technicians only see invoices tied to their own appointments
        if ($role === 'technician') {
            $where[] = "EXISTS (SELECT 1 FROM appointments tap
                                 WHERE tap.appointment_id = i.appointment_id
                                   AND tap.technician_id = ?)";
            $params[] = $userId;
        }

 // For paid invoices filter by payment date, not issue date
        if ($status === 'paid' && $fromDate) {
            $where[] = 'EXISTS (SELECT 1 FROM payments p WHERE p.invoice_id = i.invoice_id AND p.payment_date >= ?)';
            $params[] = $fromDate;
        } elseif ($fromDate) {
            $where[] = 'i.issue_date >= ?';
            $params[] = $fromDate;
        }
        if ($status === 'paid' && $toDate) {
            $where[] = 'EXISTS (SELECT 1 FROM payments p WHERE p.invoice_id = i.invoice_id AND p.payment_date <= ?)';
            $params[] = $toDate;
        } elseif ($toDate) {
            $where[] = 'i.issue_date <= ?';
            $params[] = $toDate;
        }

        $stmt = $db->prepare(
            "SELECT i.invoice_id, i.invoice_number, i.status, i.issue_date,
                    i.subtotal, i.tax_amount, i.total,
                    i.qbo_id, i.qbo_sync_status, i.appointment_id,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.company_name,
                    i.created_at,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.invoice_id), 0) AS amount_paid,
                    (i.total - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.invoice_id), 0)) AS balance_due,
                    (SELECT MAX(p.payment_date) FROM payments p WHERE p.invoice_id = i.invoice_id) AS last_payment_date
             FROM invoices i
             JOIN customers c ON i.customer_id = c.customer_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY i.created_at DESC"
        );
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
    }

    if ($method === 'POST' && $id === null) {
        $body          = json_decode(file_get_contents('php://input'), true) ?? [];
        $customerId    = (int)($body['customer_id']    ?? 0);
        $appointmentId = !empty($body['appointment_id']) ? (int)$body['appointment_id'] : null;
        $autoPopulate  = !empty($body['auto_populate']);
        $notes         = trim($body['notes'] ?? '');

        if (!$customerId) sendError(400, 'customer_id is required');

        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) sendError(404, 'Customer not found');

        $invoiceNumber = generateInvoiceNumber($db);

        $db->prepare(
            "INSERT INTO invoices
             (customer_id, appointment_id, invoice_number, status, issue_date, created_by, notes)
             VALUES (?, ?, ?, 'draft', CURDATE(), ?, ?)"
        )->execute([$customerId, $appointmentId, $invoiceNumber, $userId, $notes ?: null]);

        $invoiceId = (int)$db->lastInsertId();

 // Auto-populate lines from appointment if requested
        if ($autoPopulate && $appointmentId) {
            autoPopulateLines($db, $appointmentId, $invoiceId);
            recalculateInvoice($db, $invoiceId);
        }

        sendJson(invoiceDetail($db, $invoiceId), 201);
    }

    if ($id !== null) {
        $invoiceId = (int)$id;

 // Sub-resource: regenerate-lines (admin only) - re-run auto-population for a
 // still-untouched draft invoice, e.g. after fixing an equipment's linked catalog part.
 // Only allowed when the invoice is safely rebuildable: draft, never synced to QBO,
 // and has no payments recorded.
        if ($sub === 'regenerate-lines' && $method === 'POST') {
            if ($role !== 'admin') sendError(403, 'Admin only');

            $invStmt = $db->prepare("SELECT * FROM invoices WHERE invoice_id = ?");
            $invStmt->execute([$invoiceId]);
            $invoice = $invStmt->fetch();
            if (!$invoice) sendError(404, 'Invoice not found');

            if ($invoice['status'] !== 'draft') {
                sendError(409, 'Only draft invoices can be regenerated. This invoice has already been sent, paid, or voided.');
            }
            if (!empty($invoice['qbo_id'])) {
                sendError(409, 'This invoice has already been synced to QuickBooks and cannot be regenerated.');
            }
            $payStmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ?");
            $payStmt->execute([$invoiceId]);
            if ((int)$payStmt->fetchColumn() > 0) {
                sendError(409, 'This invoice has payments recorded and cannot be regenerated.');
            }
            if (empty($invoice['appointment_id'])) {
                sendError(409, 'This invoice is not linked to an appointment, so it cannot be auto-regenerated.');
            }

 // Wipe existing lines and rebuild from current appointment/equipment/catalog data
            $db->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?")->execute([$invoiceId]);
            autoPopulateLines($db, (int)$invoice['appointment_id'], $invoiceId);
            recalculateInvoice($db, $invoiceId);

            sendJson(invoiceDetail($db, $invoiceId));
            return;
        }

 // Sub-resource: lines
        if ($sub === 'lines') {
 // POST - add line
            if ($method === 'POST' && $subsub === null) {
                $body        = json_decode(file_get_contents('php://input'), true) ?? [];
                $lineType    = $body['line_type']   ?? 'custom';
                $lineName    = trim($body['line_name']    ?? '');
                $description = trim($body['description'] ?? '');
                $quantity    = (float)($body['quantity']   ?? 1);
                $unitPrice   = (float)($body['unit_price'] ?? 0);
                $isCustomTax = isset($body['is_taxable']) ? (int)(bool)$body['is_taxable'] : null;
                $partId      = !empty($body['part_id'])       ? (int)$body['part_id']       : null;
                $h2o2Prorate = isset($body['h2o2_prorate'])   ? (float)$body['h2o2_prorate'] : null;
                $discountNote = trim($body['discount_note']   ?? '') ?: null;

 // line_name is required; description is the optional detail line
                if (!$lineName && !$description) sendError(400, 'line_name is required');
 // Legacy fallback: if only description supplied, promote it to line_name
                if (!$lineName) { $lineName = $description; $description = ''; }

 // Determine taxability from type unless explicitly overridden
                $isTaxable  = $isCustomTax !== null ? $isCustomTax : (isTaxableLine($lineType) ? 1 : 0);

 // Apply H2O2 proration to unit price before storing
                if ($h2o2Prorate !== null && $h2o2Prorate > 0 && $lineType !== 'discount') {
                    $unitPrice = round($unitPrice * (1 - $h2o2Prorate / 100), 2);
                }

                $lineTotal  = round($quantity * $unitPrice, 2);
                $sortOrder  = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM invoice_lines WHERE invoice_id=$invoiceId")->fetchColumn();

                $db->prepare(
                    "INSERT INTO invoice_lines
                     (invoice_id, part_id, line_type, line_name, description, quantity, unit_price,
                      is_taxable, h2o2_prorate, discount_note, line_total, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $invoiceId, $partId, $lineType, $lineName, $description, $quantity, $unitPrice,
                    $isTaxable, $h2o2Prorate, $discountNote, $lineTotal, $sortOrder
                ]);

                recalculateInvoice($db, $invoiceId);
                sendJson(invoiceDetail($db, $invoiceId), 201);
            }

 // PUT - update line
            if ($method === 'PUT' && $subsub !== null) {
                $lineId = (int)$subsub;
                $body   = json_decode(file_get_contents('php://input'), true) ?? [];

                $stmt = $db->prepare("SELECT * FROM invoice_lines WHERE line_id = ? AND invoice_id = ?");
                $stmt->execute([$lineId, $invoiceId]);
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
                    "UPDATE invoice_lines
                     SET line_type=?, line_name=?, description=?, quantity=?, unit_price=?, is_taxable=?, line_total=?
                     WHERE line_id=?"
                )->execute([$lineType, $lineName, $desc, $quantity, $unitPrice, $isTaxable, $lineTotal, $lineId]);

                recalculateInvoice($db, $invoiceId);
                sendJson(invoiceDetail($db, $invoiceId));
            }

 // DELETE - remove line
            if ($method === 'DELETE' && $subsub !== null) {
                $lineId = (int)$subsub;
                $db->prepare("DELETE FROM invoice_lines WHERE line_id = ? AND invoice_id = ?")
                   ->execute([$lineId, $invoiceId]);
                recalculateInvoice($db, $invoiceId);
                sendJson(invoiceDetail($db, $invoiceId));
            }

            sendError(405, 'Method not allowed');
        }

 // Sub-resource: payment
        if ($sub === 'payment' && $method === 'POST') {
            $body            = json_decode(file_get_contents('php://input'), true) ?? [];
            $amount          = (float)($body['amount']            ?? 0);
            $paymentMethod   = $body['payment_method']             ?? '';
            $paymentNotes    = trim($body['payment_notes']         ?? '');
            $checkNumber     = trim($body['check_number']          ?? '');
            $paymentDate     = $body['payment_date']               ?? date('Y-m-d');
            $depositAccountId = trim($body['deposit_account_id']  ?? '');

            $validMethods = ['cash','check','card_office','card_field','card_online','warranty','gift_certificate','other'];
            if (!$amount)                              sendError(400, 'amount is required');
            if (!in_array($paymentMethod, $validMethods)) sendError(400, 'Invalid payment_method');

 // check_number is only meaningful for check payments and is sent to
 // QBO's PaymentRefNum field which has a 21-char limit. Validate up
 // front so users see a clear error rather than a QBO sync failure.
            if ($checkNumber !== '' && strlen($checkNumber) > 20) {
                sendError(400, 'check_number must be 20 characters or fewer');
            }
            if ($paymentMethod !== 'check') {
                $checkNumber = ''; // ignore for non-check methods
            }

            $db->prepare(
                "INSERT INTO payments (invoice_id, amount, payment_method, check_number, payment_notes, payment_date, recorded_by, deposit_account_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$invoiceId, $amount, $paymentMethod, $checkNumber ?: null, $paymentNotes ?: null, $paymentDate, $userId, $depositAccountId ?: null]);

 // Auto-mark paid if a captured method (cash, check, card_online) brings balance to zero.
 // card_field/card_office still require office review before auto-marking.
            $autoMark = in_array($paymentMethod, ['cash', 'check', 'card_online', 'warranty', 'gift_certificate']);

 // Check if fully paid
            $stmt = $db->prepare("SELECT total FROM invoices WHERE invoice_id = ?");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch();

            $stmt2 = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?");
            $stmt2->execute([$invoiceId]);
            $totalPaid = (float)$stmt2->fetchColumn();

            if ($autoMark && $totalPaid >= (float)$invoice['total']) {
                $db->prepare("UPDATE invoices SET status = 'paid' WHERE invoice_id = ?")
                   ->execute([$invoiceId]);
            }

            sendJson(invoiceDetail($db, $invoiceId), 201);
        }

 // DELETE payment: DELETE /[role]/invoices/{id}/payment/{paymentId}
        if ($sub === 'payment' && $method === 'DELETE' && $subsub !== null) {
            $paymentId = (int)$subsub;
 // Verify payment belongs to this invoice
            $stmt = $db->prepare("SELECT payment_id FROM payments WHERE payment_id = ? AND invoice_id = ?");
            $stmt->execute([$paymentId, $invoiceId]);
            if (!$stmt->fetch()) sendError(404, 'Payment not found');
            $db->prepare("DELETE FROM payments WHERE payment_id = ?")->execute([$paymentId]);
 // Revert to sent if status was paid
            $db->prepare(
                "UPDATE invoices SET status = CASE WHEN status = 'paid' THEN 'sent' ELSE status END WHERE invoice_id = ?"
            )->execute([$invoiceId]);
            sendJson(invoiceDetail($db, $invoiceId));
        }

 // Mark paid: POST /[role]/invoices/{id}/mark-paid
        if ($sub === 'mark-paid' && $method === 'POST') {
            $db->prepare("UPDATE invoices SET status = 'paid' WHERE invoice_id = ?")->execute([$invoiceId]);
            sendJson(invoiceDetail($db, $invoiceId));
        }

 // Toggle card fee: POST /[role]/invoices/{id}/toggle-card-fee
        if ($sub === 'toggle-card-fee' && $method === 'POST') {
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $enabled = isset($body['enabled']) ? (int)(bool)$body['enabled'] : null;
            if ($enabled === null) {
 // Toggle current state
                $stmt = $db->prepare("SELECT card_fee_enabled FROM invoices WHERE invoice_id = ?");
                $stmt->execute([$invoiceId]);
                $enabled = (int)!$stmt->fetchColumn();
            }
            $db->prepare("UPDATE invoices SET card_fee_enabled = ? WHERE invoice_id = ?")->execute([$enabled, $invoiceId]);
            recalculateInvoice($db, $invoiceId);
            sendJson(invoiceDetail($db, $invoiceId));
        }

 // Sub-resource: recalculate
        if ($sub === 'recalculate' && $method === 'POST') {
            recalculateInvoice($db, $invoiceId);
            sendJson(invoiceDetail($db, $invoiceId));
        }

 // DELETE - void/delete invoice (admin only)
        if ($method === 'DELETE' && $sub === null) {
            if ($role !== 'admin') sendError(403, 'Only admins can delete invoices');
 // Check for payments first
            $stmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ?");
            $stmt->execute([$invoiceId]);
            if ($stmt->fetchColumn() > 0) {
 // Has payments - void it instead of deleting
                $db->prepare("UPDATE invoices SET status = 'void' WHERE invoice_id = ?")->execute([$invoiceId]);
                sendJson(['message' => 'Invoice voided (has payments on record)', 'action' => 'voided']);
            }
 // No payments - hard delete
            $db->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?")->execute([$invoiceId]);
            $db->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$invoiceId]);
            sendJson(['message' => 'Invoice deleted', 'action' => 'deleted']);
        }

 // GET single invoice
        if ($method === 'GET' && $sub === null) {
            $inv = invoiceDetail($db, $invoiceId);
            if (!$inv) sendError(404, 'Invoice not found');
            sendJson($inv);
        }

 // PUT - update invoice header
        if ($method === 'PUT' && $sub === null) {
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = ['status', 'issue_date', 'due_date', 'notes'];
            $fields  = [];
            $values  = [];

            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    $values[] = $body[$f] === '' ? null : $body[$f];
                }
            }

            if (!empty($fields)) {
                $values[] = $invoiceId;
                $db->prepare("UPDATE invoices SET " . implode(', ', $fields) . " WHERE invoice_id = ?")
                   ->execute($values);
            }

            sendJson(invoiceDetail($db, $invoiceId));
        }

        sendError(405, 'Method not allowed');
    }

    sendError(404, 'Not found');
}

// TAX RATES HANDLER (admin only)
function handleTaxRates(PDO $db, string $method, ?string $id): void {
    if ($method === 'GET') {
        $stmt = $db->query("SELECT * FROM tax_rates ORDER BY city");
        sendJson($stmt->fetchAll());
    }

    if ($method === 'PUT' && $id !== null) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $rate = isset($body['rate']) ? (float)$body['rate'] : null;
        if ($rate === null) sendError(400, 'rate is required');
        if ($rate < 0 || $rate > 0.20) sendError(400, 'rate must be between 0 and 0.20 (e.g. 0.0925 for 9.25%)');
        $db->prepare("UPDATE tax_rates SET rate = ? WHERE rate_id = ?")->execute([$rate, $id]);
        sendJson(['message' => 'Tax rate updated']);
    }

    if ($method === 'POST') {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $city  = trim($body['city']  ?? '');
        $state = trim($body['state'] ?? 'IL');
        $rate  = isset($body['rate']) ? (float)$body['rate'] : null;
        if (!$city) sendError(400, 'city is required');
        if ($rate === null) sendError(400, 'rate is required');
        $db->prepare(
            "INSERT INTO tax_rates (city, state, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)"
        )->execute([$city, $state, $rate]);
        sendJson(['message' => 'Tax rate saved']);
    }

    sendError(405, 'Method not allowed');
}
