<?php
// Service Contracts
//
// Admin:
// GET /admin/contracts - list
// POST /admin/contracts - create
// GET /admin/contracts/{id} - detail
// PUT /admin/contracts/{id} - update
// POST /admin/contracts/{id}/activate - activate draft
// POST /admin/contracts/{id}/cancel - cancel active
// POST /admin/contracts/{id}/renew - renew
// DELETE /admin/contracts/{id} - delete (draft only)
// POST /admin/contracts/{id}/equipment - add equipment
// DELETE /admin/contracts/{id}/equipment/{equipId} - remove equipment
// POST /admin/contracts/{id}/service-types - add service type
// DELETE /admin/contracts/{id}/service-types/{stId} - remove service type
// GET /admin/contracts/{id}/invoices - invoice history
// GET /admin/contracts/{id}/appointments - appointment history
// POST /admin/contracts/{id}/schedule-appointment - schedule visit
// POST /admin/contracts/{id}/generate-invoice - bill for cycle
// GET /admin/contracts/expiring - expiring within N days
//
// Tech:
// GET /tech/contracts - assigned customers' contracts
// GET /tech/contracts/{id} - detail (read-only)
//
// Customer:
// GET /customer/contracts - own contracts
// GET /customer/contracts/{id} - detail

require_once __DIR__ . '/invoices.php';

function generateContractNumber(PDO $db): string {
    $year = (int)date('Y');
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM service_contracts WHERE contract_number LIKE ?"
    );
    $stmt->execute(["SC-$year-%"]);
    $count = (int)$stmt->fetchColumn() + 1;
    return sprintf('SC-%d-%04d', $year, $count);
}

function contractDetail(PDO $db, int $contractId): ?array {
    $stmt = $db->prepare(
        "SELECT sc.*,
                COALESCE(NULLIF(TRIM(c.company_name),''), CONCAT(c.first_name,' ',c.last_name)) AS customer_name,
                c.first_name AS customer_first_name, c.last_name AS customer_last_name, c.company_name,
                c.phone, c.email,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                CONCAT(u.first_name,' ',u.last_name) AS created_by_name
         FROM service_contracts sc
         JOIN customers c  ON sc.customer_id  = c.customer_id
         JOIN users u      ON sc.created_by   = u.user_id
         WHERE sc.contract_id = ?"
    );
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch();
    if (!$contract) return null;

    $stmt2 = $db->prepare(
        "SELECT ce.id, ce.equipment_id, et.type_name, e.model, e.install_date,
                e.next_service_due, e.is_active
         FROM contract_equipment ce
         JOIN equipment e ON ce.equipment_id = e.equipment_id
         JOIN equipment_types et ON e.type_id = et.type_id
         WHERE ce.contract_id = ?"
    );
    $stmt2->execute([$contractId]);
    $contract['equipment'] = $stmt2->fetchAll();

    $stmt3 = $db->prepare(
        "SELECT cst.id, cst.service_type_id, st.name AS service_type_name,
                cst.included_visits
         FROM contract_service_types cst
         JOIN service_types st ON cst.service_type_id = st.type_id
         WHERE cst.contract_id = ?"
    );
    $stmt3->execute([$contractId]);
    $contract['service_types'] = $stmt3->fetchAll();

    return $contract;
}

// MAIN HANDLER
function handleContracts(
    PDO $db, string $method, ?string $id, ?string $sub, ?string $subsub,
    int $userId, string $role
): void {

    if ($role === 'admin' && $id === 'expiring' && $method === 'GET') {
        $days = (int)($_GET['days'] ?? 30);
        $stmt = $db->prepare(
            "SELECT sc.contract_id, sc.contract_number, sc.name, sc.status,
                    sc.start_date, sc.end_date, sc.auto_renew, sc.renew_term_months,
                    sc.frequency, sc.cycle_price,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.phone, c.email
             FROM service_contracts sc
             JOIN customers c ON sc.customer_id = c.customer_id
             WHERE sc.status = 'active'
               AND sc.end_date IS NOT NULL
               AND sc.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND sc.auto_renew = 0
             ORDER BY sc.end_date ASC"
        );
        $stmt->execute([$days]);
        sendJson($stmt->fetchAll());
    }

    if ($method === 'GET' && $id === null) {
        $customerId = $_GET['customer_id'] ?? null;
        $status     = $_GET['status']      ?? null;

        $where  = ['1=1'];
        $params = [];
        if ($customerId) { $where[] = 'sc.customer_id = ?'; $params[] = $customerId; }
        if ($status)     { $where[] = 'sc.status = ?';      $params[] = $status; }

        if ($role === 'technician') {
            $where[] = "EXISTS (
                SELECT 1 FROM contract_equipment ce2
                JOIN equipment eq ON ce2.equipment_id = eq.equipment_id
                WHERE ce2.contract_id = sc.contract_id
                  AND eq.assigned_technician = ?
            )";
            $params[] = $userId;
        }

        $stmt = $db->prepare(
            "SELECT sc.contract_id, sc.contract_number, sc.name, sc.status,
                    sc.start_date, sc.end_date, sc.auto_renew, sc.frequency,
                    sc.billing_cycle, sc.cycle_price, sc.discount_percent,
                    sc.created_at,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.company_name, c.phone
             FROM service_contracts sc
             JOIN customers c ON sc.customer_id = c.customer_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY sc.created_at DESC"
        );
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
    }

    if ($method === 'POST' && $id === null) {
        $body             = json_decode(file_get_contents('php://input'), true) ?? [];
        $customerId       = (int)($body['customer_id']       ?? 0);
        $name             = trim($body['name']               ?? '');
        $startDate        = $body['start_date']              ?? null;
        $endDate          = !empty($body['end_date'])         ? $body['end_date']         : null;
        $autoRenew        = !empty($body['auto_renew'])       ? 1 : 0;
        $renewTermMonths  = !empty($body['renew_term_months']) ? (int)$body['renew_term_months'] : 12;
        $frequency        = $body['frequency']               ?? 'annual';
        $customInterval   = !empty($body['custom_interval_days']) ? (int)$body['custom_interval_days'] : null;
        $visitsPerCycle   = !empty($body['visits_per_cycle']) ? (int)$body['visits_per_cycle'] : 1;
        $billingCycle     = $body['billing_cycle']           ?? 'annual';
        $cyclePrice       = isset($body['cycle_price'])       ? (float)$body['cycle_price'] : 0;
        $perVisitPrice    = isset($body['per_visit_price'])   ? (float)$body['per_visit_price'] : null;
        $discountPercent  = isset($body['discount_percent'])  ? (float)$body['discount_percent'] : null;
        $notes            = trim($body['notes']              ?? '');
        $equipmentIds     = array_map('intval', $body['equipment_ids'] ?? []);
        $serviceTypeIds   = $body['service_types'] ?? [];

        if (!$customerId) sendError(400, 'customer_id is required');
        if (!$name)       sendError(400, 'name is required');
        if (!$startDate)  sendError(400, 'start_date is required');

        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) sendError(404, 'Customer not found');

        $contractNumber = generateContractNumber($db);

        $db->prepare(
            "INSERT INTO service_contracts
             (customer_id, contract_number, name, status, start_date, end_date,
              auto_renew, renew_term_months, frequency, custom_interval_days,
              visits_per_cycle, billing_cycle, cycle_price, per_visit_price,
              discount_percent, notes, created_by)
             VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $customerId, $contractNumber, $name, $startDate, $endDate,
            $autoRenew, $renewTermMonths, $frequency, $customInterval,
            $visitsPerCycle, $billingCycle, $cyclePrice, $perVisitPrice,
            $discountPercent, $notes ?: null, $userId
        ]);

        $contractId = (int)$db->lastInsertId();

        if (!empty($equipmentIds)) {
            $placeholders = implode(',', array_fill(0, count($equipmentIds), '?'));
            $stmt = $db->prepare(
                "SELECT equipment_id FROM equipment
                 WHERE equipment_id IN ($placeholders) AND customer_id = ? AND is_active = 1"
            );
            $stmt->execute([...$equipmentIds, $customerId]);
            $valid = array_column($stmt->fetchAll(), 'equipment_id');
            foreach ($valid as $eId) {
                $db->prepare(
                    "INSERT INTO contract_equipment (contract_id, equipment_id) VALUES (?, ?)"
                )->execute([$contractId, $eId]);
            }
        }

        if (!empty($serviceTypeIds) && is_array($serviceTypeIds)) {
            foreach ($serviceTypeIds as $st) {
                $stId = (int)($st['service_type_id'] ?? 0);
                $visits = (int)($st['included_visits'] ?? 1);
                if ($stId > 0) {
                    $db->prepare(
                        "INSERT INTO contract_service_types (contract_id, service_type_id, included_visits)
                         VALUES (?, ?, ?)"
                    )->execute([$contractId, $stId, $visits]);
                }
            }
        }

        sendJson(contractDetail($db, $contractId), 201);
    }

    if ($id !== null) {
        $contractId = (int)$id;

        if ($sub === 'equipment') {
            if ($method === 'POST' && $subsub === null) {
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                $equipmentId = (int)($body['equipment_id'] ?? 0);
                if (!$equipmentId) sendError(400, 'equipment_id is required');

                $stmt = $db->prepare(
                    "SELECT customer_id FROM equipment WHERE equipment_id = ? AND is_active = 1"
                );
                $stmt->execute([$equipmentId]);
                $equip = $stmt->fetch();
                if (!$equip) sendError(404, 'Equipment not found');

                $cStmt = $db->prepare("SELECT customer_id FROM service_contracts WHERE contract_id = ?");
                $cStmt->execute([$contractId]);
                $contract = $cStmt->fetch();
                if (!$contract || (int)$contract['customer_id'] !== (int)$equip['customer_id']) {
                    sendError(400, 'Equipment does not belong to this contract customer');
                }

                $db->prepare(
                    "INSERT INTO contract_equipment (contract_id, equipment_id) VALUES (?, ?)"
                )->execute([$contractId, $equipmentId]);

                sendJson(['message' => 'Equipment added to contract']);
            }

            if ($method === 'DELETE' && $subsub !== null) {
                $equipId = (int)$subsub;
                $db->prepare(
                    "DELETE FROM contract_equipment WHERE contract_id = ? AND equipment_id = ?"
                )->execute([$contractId, $equipId]);
                sendJson(['message' => 'Equipment removed from contract']);
            }

            sendError(405, 'Method not allowed');
        }

        if ($sub === 'service-types') {
            if ($method === 'POST' && $subsub === null) {
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                $serviceTypeId = (int)($body['service_type_id'] ?? 0);
                $includedVisits = (int)($body['included_visits'] ?? 1);
                if (!$serviceTypeId) sendError(400, 'service_type_id is required');

                $stmt = $db->prepare("SELECT type_id FROM service_types WHERE type_id = ? AND is_active = 1");
                $stmt->execute([$serviceTypeId]);
                if (!$stmt->fetch()) sendError(404, 'Service type not found');

                $db->prepare(
                    "INSERT INTO contract_service_types (contract_id, service_type_id, included_visits)
                     VALUES (?, ?, ?)"
                )->execute([$contractId, $serviceTypeId, $includedVisits]);

                sendJson(['message' => 'Service type added to contract']);
            }

            if ($method === 'DELETE' && $subsub !== null) {
                $stId = (int)$subsub;
                $db->prepare(
                    "DELETE FROM contract_service_types WHERE contract_id = ? AND service_type_id = ?"
                )->execute([$contractId, $stId]);
                sendJson(['message' => 'Service type removed from contract']);
            }

            sendError(405, 'Method not allowed');
        }

        if ($sub === 'invoices' && $method === 'GET') {
            $stmt = $db->prepare(
                "SELECT i.invoice_id, i.invoice_number, i.status, i.issue_date,
                        i.subtotal, i.tax_amount, i.total, i.created_at,
                        cil.cycle_start, cil.cycle_end, cil.amount AS cycle_amount
                 FROM contract_invoice_log cil
                 LEFT JOIN invoices i ON cil.invoice_id = i.invoice_id
                 WHERE cil.contract_id = ?
                 ORDER BY cil.cycle_start DESC"
            );
            $stmt->execute([$contractId]);
            sendJson($stmt->fetchAll());
        }

        if ($sub === 'appointments' && $method === 'GET') {
            $stmt = $db->prepare(
                "SELECT cal.id, cal.appointment_id, cal.scheduled_date, cal.created_at,
                        a.status AS appointment_status, a.confirmed_date, a.completed_at,
                        CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                        st.name AS service_type
                 FROM contract_appointment_log cal
                 LEFT JOIN appointments a ON cal.appointment_id = a.appointment_id
                 LEFT JOIN service_types st ON a.service_type_id = st.type_id
                 JOIN customers c ON a.customer_id = c.customer_id
                 WHERE cal.contract_id = ?
                 ORDER BY cal.scheduled_date DESC"
            );
            $stmt->execute([$contractId]);
            sendJson($stmt->fetchAll());
        }

        if ($sub === 'schedule-appointment' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $scheduledDate = $body['scheduled_date'] ?? null;
            $serviceTypeId = (int)($body['service_type_id'] ?? 0);
            $window = $body['requested_window'] ?? 'Either';
            $notes = trim($body['notes'] ?? '');

            if (!$scheduledDate) sendError(400, 'scheduled_date is required');
            if (!$serviceTypeId) sendError(400, 'service_type_id is required');

            $cStmt = $db->prepare("SELECT customer_id FROM service_contracts WHERE contract_id = ?");
            $cStmt->execute([$contractId]);
            $contract = $cStmt->fetch();
            if (!$contract) sendError(404, 'Contract not found');

            $db->prepare(
                "INSERT INTO appointments
                 (customer_id, service_type_id, requested_date, requested_window,
                  confirmed_date, booking_source, status, office_notes)
                 VALUES (?, ?, ?, ?, ?, 'phone', 'confirmed', ?)"
            )->execute([
                $contract['customer_id'], $serviceTypeId, $scheduledDate, $window,
                $scheduledDate, $notes ?: "[Contract #" . $contractId . "] " . ($contract['name'] ?? '')
            ]);

            $appointmentId = (int)$db->lastInsertId();

            $db->prepare(
                "INSERT INTO contract_appointment_log (contract_id, appointment_id, scheduled_date)
                 VALUES (?, ?, ?)"
            )->execute([$contractId, $appointmentId, $scheduledDate]);

            sendJson([
                'message'        => 'Appointment scheduled for contract',
                'appointment_id' => $appointmentId,
            ], 201);
        }

        if ($sub === 'generate-invoice' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $cycleStart = $body['cycle_start'] ?? date('Y-m-d');
            $cycleEnd = $body['cycle_end'] ?? date('Y-m-d', strtotime('+1 year'));

            $cStmt = $db->prepare("SELECT * FROM service_contracts WHERE contract_id = ?");
            $cStmt->execute([$contractId]);
            $contract = $cStmt->fetch();
            if (!$contract) sendError(404, 'Contract not found');
            if ($contract['status'] !== 'active') sendError(409, 'Contract must be active to generate invoice');

            $invoiceNumber = generateInvoiceNumber($db);
            $amount = (float)$contract['cycle_price'];

            $db->prepare(
                "INSERT INTO invoices
                 (customer_id, invoice_number, status, issue_date, due_date, created_by, notes)
                 VALUES (?, ?, 'draft', CURDATE(), ?, ?, ?)"
            )->execute([
                $contract['customer_id'], $invoiceNumber,
                date('Y-m-d', strtotime('+30 days')), $userId,
                "Service contract: " . $contract['name'] . " (Cycle: $cycleStart to $cycleEnd)"
            ]);

            $invoiceId = (int)$db->lastInsertId();

            if ($amount > 0) {
                $db->prepare(
                    "INSERT INTO invoice_lines
                     (invoice_id, line_type, line_name, description, quantity, unit_price, is_taxable, line_total, sort_order)
                     VALUES (?, 'service_call', ?, ?, 1, ?, 0, ?, 0)"
                )->execute([
                    $invoiceId,
                    'Service Contract - ' . $contract['name'],
                    "Contract #{$contract['contract_number']} | Cycle: $cycleStart to $cycleEnd",
                    $amount, $amount
                ]);
                recalculateInvoice($db, $invoiceId);
            }

            $db->prepare(
                "INSERT INTO contract_invoice_log (contract_id, invoice_id, cycle_start, cycle_end, amount)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$contractId, $invoiceId, $cycleStart, $cycleEnd, $amount]);

            sendJson([
                'message'    => 'Invoice generated for contract cycle',
                'invoice_id' => $invoiceId,
            ], 201);
        }

        if ($sub === 'activate' && $method === 'POST') {
            $stmt = $db->prepare("SELECT * FROM service_contracts WHERE contract_id = ?");
            $stmt->execute([$contractId]);
            $contract = $stmt->fetch();
            if (!$contract) sendError(404, 'Contract not found');
            if ($contract['status'] !== 'draft') sendError(409, 'Only draft contracts can be activated');

            $db->prepare("UPDATE service_contracts SET status = 'active' WHERE contract_id = ?")
               ->execute([$contractId]);
            sendJson(contractDetail($db, $contractId));
        }

        if ($sub === 'cancel' && $method === 'POST') {
            $stmt = $db->prepare("SELECT * FROM service_contracts WHERE contract_id = ?");
            $stmt->execute([$contractId]);
            $contract = $stmt->fetch();
            if (!$contract) sendError(404, 'Contract not found');
            if ($contract['status'] !== 'active') sendError(409, 'Only active contracts can be cancelled');

            $db->prepare("UPDATE service_contracts SET status = 'cancelled' WHERE contract_id = ?")
               ->execute([$contractId]);
            sendJson(contractDetail($db, $contractId));
        }

        if ($sub === 'renew' && $method === 'POST') {
            $stmt = $db->prepare("SELECT * FROM service_contracts WHERE contract_id = ?");
            $stmt->execute([$contractId]);
            $contract = $stmt->fetch();
            if (!$contract) sendError(404, 'Contract not found');
            if ($contract['status'] !== 'active') sendError(409, 'Only active contracts can be renewed');

            $termMonths = (int)($contract['renew_term_months'] ?? 12);
            $newEndDate = date('Y-m-d', strtotime(($contract['end_date'] ?? $contract['start_date']) . " +{$termMonths} months"));

            $db->prepare(
                "UPDATE service_contracts SET end_date = ? WHERE contract_id = ?"
            )->execute([$newEndDate, $contractId]);
            sendJson(contractDetail($db, $contractId));
        }

        if ($method === 'DELETE' && $sub === null) {
            $stmt = $db->prepare("SELECT * FROM service_contracts WHERE contract_id = ?");
            $stmt->execute([$contractId]);
            $contract = $stmt->fetch();
            if (!$contract) sendError(404, 'Contract not found');
            if ($contract['status'] !== 'draft') sendError(409, 'Only draft contracts can be deleted');

            $db->prepare("DELETE FROM contract_equipment WHERE contract_id = ?")->execute([$contractId]);
            $db->prepare("DELETE FROM contract_service_types WHERE contract_id = ?")->execute([$contractId]);
            $db->prepare("DELETE FROM service_contracts WHERE contract_id = ?")->execute([$contractId]);
            sendJson(['message' => 'Contract deleted']);
        }

        if ($method === 'GET' && $sub === null) {
            $contract = contractDetail($db, $contractId);
            if (!$contract) sendError(404, 'Contract not found');

            if ($role === 'technician') {
                $chk = $db->prepare(
                    "SELECT 1 FROM contract_equipment ce
                     JOIN equipment eq ON ce.equipment_id = eq.equipment_id
                     WHERE ce.contract_id = ? AND eq.assigned_technician = ?"
                );
                $chk->execute([$contractId, $userId]);
                if (!$chk->fetch()) sendError(403, 'Access denied');
            }

            sendJson($contract);
        }

        if ($method === 'PUT' && $sub === null) {
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = ['name','status','start_date','end_date','auto_renew','renew_term_months',
                        'frequency','custom_interval_days','visits_per_cycle','billing_cycle',
                        'cycle_price','per_visit_price','discount_percent','notes'];
            $fields  = [];
            $values  = [];

            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    if (in_array($f, ['auto_renew'])) {
                        $values[] = (int)(bool)$body[$f];
                    } elseif (in_array($f, ['renew_term_months','custom_interval_days','visits_per_cycle'])) {
                        $values[] = $body[$f] === '' ? null : (int)$body[$f];
                    } elseif (in_array($f, ['cycle_price','per_visit_price','discount_percent'])) {
                        $values[] = $body[$f] === '' ? null : (float)$body[$f];
                    } else {
                        $values[] = $body[$f] === '' ? null : $body[$f];
                    }
                }
            }

            if (!empty($fields)) {
                $values[] = $contractId;
                $db->prepare("UPDATE service_contracts SET " . implode(', ', $fields) . " WHERE contract_id = ?")
                   ->execute($values);
            }

            sendJson(contractDetail($db, $contractId));
        }

        sendError(405, 'Method not allowed');
    }

    sendError(404, 'Not found');
}

// CUSTOMER HANDLER
function handleCustomerContracts(
    PDO $db, string $method, ?string $id, ?string $sub, int $customerId
): void {

    if ($method === 'GET' && $id === null) {
        $stmt = $db->prepare(
            "SELECT sc.contract_id, sc.contract_number, sc.name, sc.status,
                    sc.start_date, sc.end_date, sc.auto_renew, sc.frequency,
                    sc.billing_cycle, sc.cycle_price,
                    sc.created_at
             FROM service_contracts sc
             WHERE sc.customer_id = ? AND sc.status IN ('active', 'expired')
             ORDER BY sc.created_at DESC"
        );
        $stmt->execute([$customerId]);
        sendJson($stmt->fetchAll());
    }

    if ($method === 'GET' && $id !== null) {
        $contract = contractDetail($db, (int)$id);
        if (!$contract || (int)$contract['customer_id'] !== (int)$customerId) {
            sendError(404, 'Contract not found');
        }
        sendJson($contract);
    }

    sendError(405, 'Method not allowed');
}
