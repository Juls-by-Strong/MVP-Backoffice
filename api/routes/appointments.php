<?php
// Shared appointment logic for all roles
//
// Customer:
// GET /customer/appointments - own appointments
// POST /customer/appointments - request appointment
// DELETE /customer/appointments/{id} - cancel pending request
// GET /customer/appointments/service-types - available service types
//
// Tech:
// GET /tech/appointments - own confirmed schedule
// GET /tech/appointments/unassigned - unassigned confirmed jobs
// GET /tech/appointments/{id} - single appointment detail
// POST /tech/appointments/{id}/claim - self-assign
// POST /tech/appointments - create for phone-in customer
//
// Admin:
// GET /admin/appointments - all appointments
// GET /admin/appointments/pending - pending queue
// POST /admin/appointments - create manually
// PUT /admin/appointments/{id} - confirm / assign / reschedule
// DELETE /admin/appointments/{id} - cancel

function syncAppointmentTechnicians(PDO $db, int $appointmentId, array $technicianIds): void {
    try {
        if (empty($technicianIds)) {
            $db->prepare("DELETE FROM appointment_technicians WHERE appointment_id = ?")
               ->execute([$appointmentId]);
            return;
        }
 // Remove techs no longer assigned
        $placeholders = implode(',', array_fill(0, count($technicianIds), '?'));
        $db->prepare("DELETE FROM appointment_technicians WHERE appointment_id = ? AND technician_id NOT IN ($placeholders)")
           ->execute([$appointmentId, ...$technicianIds]);
 // Insert new techs (first is lead, rest are technician)
        $isFirst = true;
        foreach ($technicianIds as $techId) {
            $role = $isFirst ? 'lead' : 'technician';
            $db->prepare(
                "INSERT INTO appointment_technicians (appointment_id, technician_id, role)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE role = VALUES(role)"
            )->execute([$appointmentId, (int)$techId, $role]);
            $isFirst = false;
        }
 // Keep appointments.technician_id in sync with the lead tech
        $db->prepare("UPDATE appointments SET technician_id = ? WHERE appointment_id = ?")
           ->execute([(int)$technicianIds[0], $appointmentId]);
    } catch (\Throwable $e) { /* graceful - table may not exist yet */ }
}

function appointmentDetail(PDO $db, int $appointmentId): ?array {
    $stmt = $db->prepare(
        "SELECT
            a.appointment_id,
            a.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        c.company_name,
            c.phone,
            c.email,
            c.service_address, c.service_city, c.service_state, c.service_zip,
            st.type_id        AS service_type_id,
            st.name           AS service_type,
            st.min_days_out,
            a.requested_date,
            a.requested_window,
            a.customer_notes,
            a.confirmed_date,
            a.confirmed_time,
            a.technician_id,
            CONCAT(tu.first_name, ' ', tu.last_name) AS technician_name,
            tu.email          AS technician_email,
            a.booking_source,
            a.status,
            a.office_notes,
            a.completed_at,
            a.created_at,
            a.updated_at
         FROM appointments a
         JOIN customers c   ON a.customer_id     = c.customer_id
         JOIN service_types st ON a.service_type_id = st.type_id
         LEFT JOIN users tu ON a.technician_id   = tu.user_id
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) return null;

 // Attached equipment
    $stmt = $db->prepare(
        "SELECT ae.equipment_id, et.type_name, e.model
         FROM appointment_equipment ae
         JOIN equipment e       ON ae.equipment_id = e.equipment_id
         JOIN equipment_types et ON e.type_id      = et.type_id
         WHERE ae.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $appt['equipment'] = $stmt->fetchAll();

 // All assigned technicians (multi-tech support)
    try {
        $stmt = $db->prepare(
            "SELECT at2.technician_id, at2.role,
                    CONCAT(u.first_name,' ',u.last_name) AS technician_name,
                    u.email AS technician_email
             FROM appointment_technicians at2
             JOIN users u ON at2.technician_id = u.user_id
             WHERE at2.appointment_id = ?
             ORDER BY FIELD(at2.role,'lead','technician'), u.first_name"
        );
        $stmt->execute([$appointmentId]);
        $appt['technicians'] = $stmt->fetchAll();
    } catch (\Throwable $e) {
        $appt['technicians'] = [];
    }

 // Linked invoice (if any)
    $stmt = $db->prepare(
        "SELECT invoice_id, invoice_number, status, total, issue_date, qbo_id
         FROM invoices WHERE appointment_id = ? AND status != 'void'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$appointmentId]);
    $appt['invoice'] = $stmt->fetch() ?: null;

 // Customer notes (from customer_notes table)
    try {
        $stmt = $db->prepare(
            "SELECT n.note_id, n.note_text, n.is_visible_to_customer, n.created_at,
                    COALESCE(CONCAT(u.first_name,' ',u.last_name), u.email) AS author_name,
                    u.role AS author_role
             FROM customer_notes n
             JOIN users u ON n.author_id = u.user_id
             WHERE n.customer_id = ?
             ORDER BY n.created_at DESC LIMIT 10"
        );
        $stmt->execute([$appt['customer_id']]);
        $appt['notes'] = $stmt->fetchAll();
    } catch (\Throwable $e) {
        $appt['notes'] = [];
    }

 // Water test results (PDF records)
    try {
        $stmt = $db->prepare(
            "SELECT test_id, label, test_date, is_current, filename
             FROM water_tests WHERE customer_id = ?
             ORDER BY test_date DESC LIMIT 5"
        );
        $stmt->execute([$appt['customer_id']]);
        $appt['water_tests'] = $stmt->fetchAll();
    } catch (\Throwable $e) {
        $appt['water_tests'] = [];
    }

    return $appt;
}

function saveAppointmentEquipment(PDO $db, int $appointmentId, array $equipmentIds, int $customerId): void {
    $db->prepare("DELETE FROM appointment_equipment WHERE appointment_id = ?")
       ->execute([$appointmentId]);

    if (empty($equipmentIds)) return;

 // Verify all equipment belongs to customer
    $placeholders = implode(',', array_fill(0, count($equipmentIds), '?'));
    $stmt = $db->prepare(
        "SELECT equipment_id FROM equipment
         WHERE equipment_id IN ($placeholders) AND customer_id = ? AND is_active = 1"
    );
    $stmt->execute([...$equipmentIds, $customerId]);
    $valid = array_column($stmt->fetchAll(), 'equipment_id');

    foreach ($valid as $eId) {
        $db->prepare(
            "INSERT INTO appointment_equipment (appointment_id, equipment_id) VALUES (?, ?)"
        )->execute([$appointmentId, $eId]);
    }
}

function validateRequestedDate(string $date, int $minDaysOut): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 'requested_date must be in YYYY-MM-DD format';
    }
    $today     = new DateTime('today');
    $requested = new DateTime($date);
    $diff      = (int)$today->diff($requested)->days;

    if ($requested <= $today) {
        return 'requested_date must be in the future';
    }
    if ($diff < $minDaysOut) {
        return "This service type requires at least {$minDaysOut} days notice";
    }
    if ($diff > 14) {
        return 'requested_date must be within 14 days from today';
    }
    return null;
}

// Earliest: 9:30am Latest: 4:00pm
function validateConfirmedTime(?string $time): ?string {
    if (empty($time)) return null; // time is optional
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        return 'confirmed_time must be in HH:MM format';
    }
 // Normalize to H:i for comparison
    $t        = substr($time, 0, 5);
    $earliest = '09:30';
    $latest   = '16:00';
    if ($t < $earliest) return 'Earliest appointment time is 9:30 AM';
    if ($t > $latest)   return 'Latest appointment time is 4:00 PM';
    return null;
}

// CUSTOMER APPOINTMENT HANDLER
function handleCustomerAppointments(
    PDO $db, string $method, ?string $id, ?string $sub, int $customerId
): void {

 // GET /customer/appointments/service-types
 // Customers only see types flagged customer_requestable = 1.
 // Office/tech-only types (e.g. installs) are filtered out here.
    if ($method === 'GET' && $id === 'service-types') {
        $stmt = $db->query(
            "SELECT type_id, name, min_days_out FROM service_types
             WHERE is_active = 1
               AND COALESCE(customer_requestable, 1) = 1
             ORDER BY name"
        );
        sendJson($stmt->fetchAll());
    }

 // GET /customer/appointments
    if ($method === 'GET' && $id === null) {
        $stmt = $db->prepare(
            "SELECT
                a.appointment_id,
                st.name           AS service_type,
                a.requested_date,
                a.requested_window,
                a.confirmed_date,
                a.confirmed_time,
                a.status,
                a.customer_notes,
                a.salt_delivery,
                a.oxyblast,
                a.created_at
             FROM appointments a
             JOIN service_types st ON a.service_type_id = st.type_id
             WHERE a.customer_id = ?
               AND a.status NOT IN ('cancelled')
             ORDER BY COALESCE(a.confirmed_date, a.requested_date) ASC"
        );
        $stmt->execute([$customerId]);
        $appts = $stmt->fetchAll();

 // Attach equipment for each
        foreach ($appts as &$appt) {
            $stmt2 = $db->prepare(
                "SELECT ae.equipment_id, et.type_name, e.model
                 FROM appointment_equipment ae
                 JOIN equipment e        ON ae.equipment_id = e.equipment_id
                 JOIN equipment_types et ON e.type_id       = et.type_id
                 WHERE ae.appointment_id = ?"
            );
            $stmt2->execute([$appt['appointment_id']]);
            $appt['equipment'] = $stmt2->fetchAll();
        }
        sendJson($appts);
    }

 // POST /customer/appointments - request new appointment
    if ($method === 'POST' && $id === null) {
 // Guard 1: customer must not be flagged "do not service"
        $stmt = $db->prepare("SELECT do_not_service FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $custFlag = $stmt->fetch();
        if ($custFlag && (int)$custFlag['do_not_service'] === 1) {
            sendError(403, 'Your account is not currently eligible for online service requests. Please call our office and we will be happy to help.');
        }

 // Guard 2: customer must have at least one piece of equipment on file
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM equipment WHERE customer_id = ? AND is_active = 1"
        );
        $stmt->execute([$customerId]);
        if ((int)$stmt->fetchColumn() === 0) {
            sendError(403, 'No equipment is on file for your account yet. Please call our office to schedule your first visit.');
        }

 // Guard 3: one pending at a time
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM appointments
             WHERE customer_id = ? AND status = 'pending'"
        );
        $stmt->execute([$customerId]);
        if ((int)$stmt->fetchColumn() > 0) {
            sendError(409, 'You already have a pending appointment request. Please wait for it to be confirmed or cancel it before requesting another.');
        }

        $body          = json_decode(file_get_contents('php://input'), true) ?? [];
        $serviceTypeId = (int)($body['service_type_id']  ?? 0);
        $requestedDate = trim($body['requested_date']     ?? '');
        $window        = $body['requested_window']        ?? 'Either';
        $customerNotes = trim($body['customer_notes']     ?? '');
        $equipmentIds  = array_map('intval', $body['equipment_ids'] ?? []);
        $saltDelivery  = !empty($body['salt_delivery']) ? 1 : 0;
        $oxyblast      = !empty($body['oxyblast'])      ? 1 : 0;

        if (!$serviceTypeId)  sendError(400, 'service_type_id is required');
        if (!$requestedDate)  sendError(400, 'requested_date is required');
        if (!in_array($window, ['Morning','Afternoon','Either'])) {
            sendError(400, 'requested_window must be Morning, Afternoon, or Either');
        }

 // Weekend block - applies to customer-side requests only.
 // Admin/tech can still book any date via their respective endpoints.
        $dow = (int)date('w', strtotime($requestedDate));
        if ($dow === 0 || $dow === 6) {
            sendError(400, "We don't have weekend visits - please pick a weekday (Mon-Fri).");
        }

 // Get service type and validate
        $stmt = $db->prepare("SELECT * FROM service_types WHERE type_id = ? AND is_active = 1");
        $stmt->execute([$serviceTypeId]);
        $serviceType = $stmt->fetch();
        if (!$serviceType) sendError(404, 'Service type not found');

 // Office/tech-only types (customer_requestable=0) must not be bookable
 // by customers even if a type_id is hand-crafted in the request body.
        if (isset($serviceType['customer_requestable'])
            && (int)$serviceType['customer_requestable'] === 0) {
            sendError(403, 'This service type can only be scheduled by our office. Please call us to set this up.');
        }

        $dateError = validateRequestedDate($requestedDate, (int)$serviceType['min_days_out']);
        if ($dateError) sendError(400, $dateError);

 // Create appointment
        $db->prepare(
            "INSERT INTO appointments
             (customer_id, service_type_id, requested_date, requested_window,
              customer_notes, salt_delivery, oxyblast,
              booking_source, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'customer_app', 'pending')"
        )->execute([$customerId, $serviceTypeId, $requestedDate, $window,
                    $customerNotes ?: null, $saltDelivery, $oxyblast]);

        $appointmentId = (int)$db->lastInsertId();

        if (!empty($equipmentIds)) {
            saveAppointmentEquipment($db, $appointmentId, $equipmentIds, $customerId);
        }

 // Fire admin notification (best-effort - don't block on failure)
        try {
            require_once __DIR__ . '/autoEmail.php';
            if (function_exists('sendNewRequestNotification')) {
                sendNewRequestNotification($db, $appointmentId);
            }
        } catch (\Throwable $e) {
            error_log('sendNewRequestNotification failed: ' . $e->getMessage());
        }

        sendJson([
            'message'        => 'Appointment request submitted. We will confirm your appointment shortly.',
            'appointment_id' => $appointmentId,
        ], 201);
    }

 // DELETE /customer/appointments/{id} - cancel pending request
    if ($method === 'DELETE' && $id !== null) {
        $stmt = $db->prepare(
            "SELECT * FROM appointments WHERE appointment_id = ? AND customer_id = ?"
        );
        $stmt->execute([$id, $customerId]);
        $appt = $stmt->fetch();

        if (!$appt)                        sendError(404, 'Appointment not found');
        if ($appt['status'] !== 'pending') sendError(409, 'Only pending requests can be cancelled. Please call us to cancel a confirmed appointment.');

        $db->prepare(
            "UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?"
        )->execute([$id]);

        sendJson(['message' => 'Appointment request cancelled']);
    }

    sendError(405, 'Method not allowed');
}

// TECH APPOINTMENT HANDLER
function handleTechAppointments(
    PDO $db, string $method, ?string $id, ?string $sub, int $techUserId
): void {

 // GET /tech/appointments/unassigned
    if ($method === 'GET' && $id === 'unassigned') {
        $stmt = $db->prepare(
            "SELECT
                a.appointment_id,
                a.customer_id,
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        c.company_name,
                c.phone,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                st.name       AS service_type,
                a.confirmed_date,
                a.confirmed_time,
                a.requested_window,
                a.office_notes,
                a.tech_notes
             FROM appointments a
             JOIN customers c    ON a.customer_id    = c.customer_id
             JOIN service_types st ON a.service_type_id = st.type_id
             WHERE a.status = 'confirmed'
               AND a.technician_id IS NULL
             ORDER BY a.confirmed_date ASC"
        );
        $stmt->execute();
        sendJson($stmt->fetchAll());
        return;
    }

 // GET /tech/appointments - own schedule
 // Optional ?from=YYYY-MM-DD&to=YYYY-MM-DD to scope a date range (used by
 // the Dashboard "today" view and the Appts monthly/weekly views).
 // Optional ?status=... to filter to a single status.
 // Without filters, returns all non-cancelled appointments assigned to this tech.
    if ($method === 'GET' && $id === null) {
        $fromDate = $_GET['from']   ?? null;
        $toDate   = $_GET['to']     ?? null;
        $status   = $_GET['status'] ?? null;

        $where  = ['a.technician_id = ?'];
        $params = [$techUserId];

        if ($status) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        } else {
            $where[] = "a.status != 'cancelled'";
        }

        if ($fromDate || $toDate) {
            $effectiveDate = "CASE
                WHEN a.status = 'completed' AND a.completed_at IS NOT NULL
                    THEN DATE(a.completed_at)
                WHEN a.status = 'completed'
                    THEN DATE(a.updated_at)
                ELSE a.confirmed_date
            END";
            if ($fromDate) { $where[] = "($effectiveDate) >= ?"; $params[] = $fromDate; }
            if ($toDate)   { $where[] = "($effectiveDate) <= ?"; $params[] = $toDate; }
        }

        $stmt = $db->prepare(
            "SELECT
                a.appointment_id,
                a.customer_id,
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        c.company_name,
                c.phone,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                st.name       AS service_type,
                a.confirmed_date,
                a.confirmed_time,
                a.requested_window,
                a.status,
                a.completed_at,
                a.updated_at,
                a.office_notes,
                a.tech_notes,
                a.customer_notes
             FROM appointments a
             JOIN customers c    ON a.customer_id    = c.customer_id
             JOIN service_types st ON a.service_type_id = st.type_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(a.confirmed_date, a.requested_date) ASC, a.confirmed_time ASC"
        );
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
        return;
    }

 // GET /tech/appointments/{id} - single appointment detail
    if ($method === 'GET' && $id !== null) {
        $appt = appointmentDetail($db, (int)$id);
        if (!$appt) sendError(404, 'Appointment not found');
 // Verify this tech is assigned, or the appointment is unassigned
        if ($appt['technician_id'] !== null && (int)$appt['technician_id'] !== $techUserId) {
            sendError(403, 'This appointment is not assigned to you');
        }
        sendJson($appt);
        return;
    }

 // POST /tech/appointments/{id}/claim - self-assign
    if ($method === 'POST' && $id !== null && $sub === 'claim') {
        $stmt = $db->prepare(
            "SELECT * FROM appointments WHERE appointment_id = ? AND status = 'confirmed'"
        );
        $stmt->execute([$id]);
        $appt = $stmt->fetch();
        if (!$appt) sendError(404, 'Appointment not found or not available to claim');

        $db->prepare(
            "UPDATE appointments
             SET technician_id = ?, assigned_by = ?
             WHERE appointment_id = ?"
        )->execute([$techUserId, $techUserId, $id]);

        sendJson(['message' => 'Appointment claimed successfully']);
        return;
    }

 // POST /tech/appointments - create for phone-in customer
    if ($method === 'POST' && $id === null) {
        $body          = json_decode(file_get_contents('php://input'), true) ?? [];
        $customerId    = (int)($body['customer_id']     ?? 0);
        $serviceTypeId = (int)($body['service_type_id'] ?? 0);
        $confirmedDate = trim($body['confirmed_date']   ?? '');
        $confirmedTime = trim($body['confirmed_time']   ?? '');
        $window        = $body['requested_window']      ?? 'Either';
        $officeNotes   = trim($body['office_notes']     ?? '');
        $equipmentIds  = array_map('intval', $body['equipment_ids'] ?? []);
        $selfAssign    = !empty($body['self_assign']);

        if (!$customerId)    sendError(400, 'customer_id is required');
        if (!$serviceTypeId) sendError(400, 'service_type_id is required');
        if (!$confirmedDate) sendError(400, 'confirmed_date is required');

        $timeError = validateConfirmedTime($confirmedTime ?: null);
        if ($timeError) sendError(400, $timeError);

 // Verify customer exists
        $chk = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $chk->execute([$customerId]);
        if (!$chk->fetch()) sendError(404, 'Customer not found');

 // Verify service type exists
        $stChk = $db->prepare("SELECT type_id FROM service_types WHERE type_id = ? AND is_active = 1");
        $stChk->execute([$serviceTypeId]);
        if (!$stChk->fetch()) sendError(400, 'Invalid service_type_id');

        $db->prepare(
            "INSERT INTO appointments
             (customer_id, service_type_id, requested_date, requested_window,
              confirmed_date, confirmed_time, technician_id, assigned_by,
              booking_source, status, office_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'phone', 'confirmed', ?)"
        )->execute([
            $customerId, $serviceTypeId, $confirmedDate, $window,
            $confirmedDate, $confirmedTime ?: null,
            $selfAssign ? $techUserId : null,
            $selfAssign ? $techUserId : null,
            $officeNotes ?: null,
        ]);

        $appointmentId = (int)$db->lastInsertId();

        if (!empty($equipmentIds)) {
            saveAppointmentEquipment($db, $appointmentId, $equipmentIds, $customerId);
        }

        sendJson([
            'message'        => 'Appointment created successfully',
            'appointment_id' => $appointmentId,
        ], 201);
        return;
    }

 // PUT /tech/appointments/{id} - full edit: reschedule, reassign, status,
 // notes, service type, equipment. Mirrors admin edit behavior including
 // confirmation email/push, auto-invoice creation, and completion email/push.
    if ($method === 'PUT' && $id !== null) {
        require_once __DIR__ . '/invoices.php';

 // Verify appointment exists and belongs to this tech (or is unassigned)
        $stmt = $db->prepare(
            "SELECT * FROM appointments WHERE appointment_id = ?
             AND (technician_id = ? OR technician_id IS NULL)"
        );
        $stmt->execute([(int)$id, $techUserId]);
        $appt = $stmt->fetch();
        if (!$appt) sendError(404, 'Appointment not found or not assigned to you');

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $allowed = ['confirmed_date','confirmed_time','technician_id',
                    'requested_window','status','office_notes','tech_notes','service_type_id'];
        $fields  = [];
        $values  = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "$f = ?";
                $values[] = $body[$f] === '' ? null : $body[$f];
            }
        }

 // Validate time if being set
        if (isset($body['confirmed_time']) && $body['confirmed_time']) {
            $timeError = validateConfirmedTime($body['confirmed_time']);
            if ($timeError) sendError(400, $timeError);
        }

 // Validate status values techs are allowed to set
        if (isset($body['status'])) {
            $allowedStatuses = ['confirmed', 'in_progress', 'completed', 'cancelled'];
            if (!in_array($body['status'], $allowedStatuses)) {
                sendError(400, 'Invalid status. Allowed: ' . implode(', ', $allowedStatuses));
            }
        }

 // When confirming (e.g. reassigning an unassigned appt) set assigned_by
        if (isset($body['status']) && $body['status'] === 'confirmed') {
            $fields[] = 'assigned_by = ?';
            $values[] = $techUserId;
        }

 // Set completed_at when marking complete
        if (isset($body['status']) && $body['status'] === 'completed') {
            $fields[] = 'completed_at = NOW()';
        }

        if (!empty($fields)) {
            $values[] = (int)$id;
            $db->prepare(
                "UPDATE appointments SET " . implode(', ', $fields) . " WHERE appointment_id = ?"
            )->execute($values);
        }

 // Send confirmation email/push if status just changed to confirmed
        if (isset($body['status']) && $body['status'] === 'confirmed') {
            try {
                require_once __DIR__ . '/autoEmail.php';
                require_once __DIR__ . '/settings.php';
                sendConfirmationEmail($db, (int)$id);
            } catch (\Throwable $e) { /* non-fatal */ }

            try {
                require_once __DIR__ . '/push.php';
                $confDate = !empty($body['confirmed_date']) ? $body['confirmed_date'] : ($appt['confirmed_date'] ?? '');
                $when = $confDate ? date('D, M j', strtotime($confDate)) : 'soon';
                sendPushToCustomer(
                    $db,
                    (int)$appt['customer_id'],
                    'Appointment confirmed',
                    "Your service visit is set for $when. We'll see you then!",
                    '/?page=appointments',
                    'appointment_confirmed',
                    ['appointment_id' => (int)$id]
                );
            } catch (\Throwable $e) { /* non-fatal */ }

            try {
                autoCreateInvoiceForAppointment($db, (int)$id, $techUserId);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

 // Reassign technician (single-tech sync, mirrors admin behavior)
        if (isset($body['technician_id'])) {
            $techId = $body['technician_id'] ? [(int)$body['technician_id']] : [];
            syncAppointmentTechnicians($db, (int)$id, $techId);
        }

 // Auto-send invoice email when job is marked completed
        if (isset($body['status']) && $body['status'] === 'completed') {
            try {
                require_once __DIR__ . '/autoEmail.php';
                require_once __DIR__ . '/settings.php';
                $invStmt = $db->prepare(
                    "SELECT invoice_id FROM invoices WHERE appointment_id = ? AND status != 'void' ORDER BY created_at DESC LIMIT 1"
                );
                $invStmt->execute([$id]);
                $invRow = $invStmt->fetch();
                if ($invRow) {
                    sendCompletionInvoiceEmail($db, (int)$invRow['invoice_id'], (int)$id);
                }
            } catch (\Throwable $e) { /* non-fatal */ }

            try {
                require_once __DIR__ . '/push.php';
                sendPushToCustomer(
                    $db,
                    (int)$appt['customer_id'],
                    'Service complete',
                    "Thanks for choosing Acme Water Service! Your invoice is ready to view.",
                    '/?page=invoices',
                    'service_complete',
                    ['appointment_id' => (int)$id]
                );
            } catch (\Throwable $e) { /* non-fatal */ }
        }

 // Update equipment list if provided
        if (isset($body['equipment_ids'])) {
            saveAppointmentEquipment($db, (int)$id,
                array_map('intval', $body['equipment_ids']), (int)$appt['customer_id']);
        }

        sendJson(appointmentDetail($db, (int)$id));
        return;
    }

 // DELETE /tech/appointments/{id} - cancel
    if ($method === 'DELETE' && $id !== null) {
        $stmt = $db->prepare(
            "SELECT appointment_id FROM appointments WHERE appointment_id = ?
             AND (technician_id = ? OR technician_id IS NULL)"
        );
        $stmt->execute([(int)$id, $techUserId]);
        if (!$stmt->fetch()) sendError(404, 'Appointment not found or not assigned to you');

        $db->prepare(
            "UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?"
        )->execute([(int)$id]);

        sendJson(['message' => 'Appointment cancelled']);
        return;
    }

    sendError(405, 'Method not allowed');
}

// ADMIN APPOINTMENT HANDLER
function handleAdminAppointments(
    PDO $db, string $method, ?string $id, ?string $sub, int $adminUserId
): void {
    require_once __DIR__ . '/invoices.php';

 // GET /admin/appointments/pending
    if ($method === 'GET' && $id === 'pending') {
        $stmt = $db->prepare(
            "SELECT
                a.appointment_id,
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        c.company_name,
                c.phone,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                st.name           AS service_type,
                a.requested_date,
                a.requested_window,
                a.customer_notes,
                a.created_at
             FROM appointments a
             JOIN customers c    ON a.customer_id    = c.customer_id
             JOIN service_types st ON a.service_type_id = st.type_id
             WHERE a.status = 'pending'
             ORDER BY a.requested_date ASC, a.created_at ASC"
        );
        $stmt->execute();
        sendJson($stmt->fetchAll());
        return;
    }

 // GET /admin/appointments - all appointments with optional filters
    if ($method === 'GET' && $id === null) {
        $status   = $_GET['status']        ?? null;
        $techId   = $_GET['technician_id'] ?? null;
        $fromDate = $_GET['from']          ?? null;
        $toDate   = $_GET['to']            ?? null;

        $where  = ['1=1'];
        $params = [];
        if ($status) { $where[] = 'a.status = ?';        $params[] = $status; }
        if ($techId) { $where[] = 'a.technician_id = ?'; $params[] = $techId; }

 // Date range: for completed jobs match on completed_at (or updated_at fallback), else confirmed_date
        if ($fromDate || $toDate) {
            $effectiveDate = "CASE
                WHEN a.status = 'completed' AND a.completed_at IS NOT NULL
                    THEN DATE(a.completed_at)
                WHEN a.status = 'completed'
                    THEN DATE(a.updated_at)
                ELSE a.confirmed_date
            END";
            if ($fromDate) { $where[] = "($effectiveDate) >= ?"; $params[] = $fromDate; }
            if ($toDate)   { $where[] = "($effectiveDate) <= ?"; $params[] = $toDate; }
        }

        $stmt = $db->prepare(
            "SELECT
                a.appointment_id,
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        c.company_name,
                c.phone,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                st.name           AS service_type,
                a.requested_date,
                a.requested_window,
                a.confirmed_date,
                a.confirmed_time,
                a.status,
                a.completed_at,
                a.updated_at,
                CONCAT(tu.first_name, ' ', tu.last_name) AS technician_name,
                a.customer_notes,
                a.office_notes,
                a.booking_source,
                a.created_at
             FROM appointments a
             JOIN customers c    ON a.customer_id    = c.customer_id
             JOIN service_types st ON a.service_type_id = st.type_id
             LEFT JOIN users tu  ON a.technician_id  = tu.user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(a.confirmed_date, a.requested_date) ASC"
        );
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
        return;
    }

 // GET /admin/appointments/{id}
    if ($method === 'GET' && $id !== null) {
        $appt = appointmentDetail($db, (int)$id);
        if (!$appt) sendError(404, 'Appointment not found');
        sendJson($appt);
        return;
    }

 // POST /admin/appointments - create manually
    if ($method === 'POST' && $id === null) {
        $body          = json_decode(file_get_contents('php://input'), true) ?? [];
        $customerId    = (int)($body['customer_id']     ?? 0);
        $serviceTypeId = (int)($body['service_type_id'] ?? 0);
        $confirmedDate = trim($body['confirmed_date']   ?? '');
        $confirmedTime = trim($body['confirmed_time']   ?? '');
        $technicianId  = !empty($body['technician_id']) ? (int)$body['technician_id'] : null;
        $window        = $body['requested_window']      ?? 'Either';
        $officeNotes   = trim($body['office_notes']     ?? '');
        $equipmentIds  = array_map('intval', $body['equipment_ids'] ?? []);

        if (!$customerId)    sendError(400, 'customer_id is required');
        if (!$serviceTypeId) sendError(400, 'service_type_id is required');
        if (!$confirmedDate) sendError(400, 'confirmed_date is required');

        $timeError = validateConfirmedTime($confirmedTime ?: null);
        if ($timeError) sendError(400, $timeError);

        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) sendError(404, 'Customer not found');

        $db->prepare(
            "INSERT INTO appointments
             (customer_id, service_type_id, requested_date, requested_window,
              confirmed_date, confirmed_time, technician_id, assigned_by,
              booking_source, status, office_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'phone', 'confirmed', ?)"
        )->execute([
            $customerId, $serviceTypeId, $confirmedDate, $window,
            $confirmedDate, $confirmedTime ?: null,
            $technicianId, $technicianId ? $adminUserId : null,
            $officeNotes ?: null,
        ]);

        $appointmentId = (int)$db->lastInsertId();

        if (!empty($equipmentIds)) {
            saveAppointmentEquipment($db, $appointmentId, $equipmentIds, $customerId);
        }

 // Sync multi-tech assignments
        $technicianIds = array_map('intval', $body['technician_ids'] ?? []);
        if ($technicianId && empty($technicianIds)) $technicianIds = [$technicianId];
        if (!empty($technicianIds)) syncAppointmentTechnicians($db, $appointmentId, $technicianIds);

 // Auto-create draft invoice (admin-created appointments are always confirmed)
        try {
            autoCreateInvoiceForAppointment($db, $appointmentId, $adminUserId);
        } catch (\Throwable $e) { /* non-fatal */ }

        sendJson([
            'message'        => 'Appointment created',
            'appointment_id' => $appointmentId,
        ], 201);
        return;
    }

 // PUT /admin/appointments/{id} - confirm, assign, reschedule, update status
    if ($method === 'PUT' && $id !== null) {
        $stmt = $db->prepare("SELECT * FROM appointments WHERE appointment_id = ?");
        $stmt->execute([$id]);
        $appt = $stmt->fetch();
        if (!$appt) sendError(404, 'Appointment not found');

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $allowed = ['confirmed_date','confirmed_time','technician_id',
                    'requested_window','status','office_notes','service_type_id'];
        $fields  = [];
        $values  = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "$f = ?";
                $values[] = $body[$f] === '' ? null : $body[$f];
            }
        }

 // Validate time if being set
        if (isset($body['confirmed_time']) && $body['confirmed_time']) {
            $timeError = validateConfirmedTime($body['confirmed_time']);
            if ($timeError) sendError(400, $timeError);
        }

 // When confirming a pending appointment set assigned_by
        if (isset($body['status']) && $body['status'] === 'confirmed') {
            $fields[] = 'assigned_by = ?';
            $values[] = $adminUserId;
        }

 // Set completed_at when marking complete
        if (isset($body['status']) && $body['status'] === 'completed') {
            $fields[] = 'completed_at = NOW()';
        }

        if (!empty($fields)) {
            $values[] = $id;
            $db->prepare(
                "UPDATE appointments SET " . implode(', ', $fields) . " WHERE appointment_id = ?"
            )->execute($values);
        }

 // Send confirmation email if status just changed to confirmed
        if (isset($body['status']) && $body['status'] === 'confirmed') {
            try {
                require_once __DIR__ . '/autoEmail.php';
                require_once __DIR__ . '/settings.php';
                sendConfirmationEmail($db, (int)$id);
            } catch (\Throwable $e) { /* non-fatal */ }

 // Push notification to customer's devices
            try {
                require_once __DIR__ . '/push.php';
                $confDate = !empty($body['confirmed_date']) ? $body['confirmed_date'] : ($appt['confirmed_date'] ?? '');
                $when = $confDate ? date('D, M j', strtotime($confDate)) : 'soon';
                sendPushToCustomer(
                    $db,
                    (int)$appt['customer_id'],
                    'Appointment confirmed',
                    "Your service visit is set for $when. We'll see you then!",
                    '/?page=appointments',
                    'appointment_confirmed',
                    ['appointment_id' => (int)$id]
                );
            } catch (\Throwable $e) { /* non-fatal */ }

 // Auto-create invoice
            try {
                autoCreateInvoiceForAppointment($db, (int)$id, $adminUserId);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

 // Sync additional technicians if provided
        if (isset($body['technician_ids']) && is_array($body['technician_ids'])) {
            syncAppointmentTechnicians($db, (int)$id, $body['technician_ids']);
        } elseif (isset($body['technician_id'])) {
            $techId = $body['technician_id'] ? [(int)$body['technician_id']] : [];
            syncAppointmentTechnicians($db, (int)$id, $techId);
        }

 // Auto-send invoice email when job is marked completed
        if (isset($body['status']) && $body['status'] === 'completed') {
            try {
                require_once __DIR__ . '/autoEmail.php';
                require_once __DIR__ . '/settings.php';
                $invStmt = $db->prepare(
                    "SELECT invoice_id FROM invoices WHERE appointment_id = ? AND status != 'void' ORDER BY created_at DESC LIMIT 1"
                );
                $invStmt->execute([$id]);
                $invRow = $invStmt->fetch();
                if ($invRow) {
                    sendCompletionInvoiceEmail($db, (int)$invRow['invoice_id'], (int)$id);
                }
            } catch (\Throwable $e) { /* non-fatal */ }

 // Push notification: service complete
            try {
                require_once __DIR__ . '/push.php';
                sendPushToCustomer(
                    $db,
                    (int)$appt['customer_id'],
                    'Service complete',
                    "Thanks for choosing Acme Water Service! Your invoice is ready to view.",
                    '/?page=invoices',
                    'service_complete',
                    ['appointment_id' => (int)$id]
                );
            } catch (\Throwable $e) { /* non-fatal */ }
        }

 // Update equipment list if provided
        if (isset($body['equipment_ids'])) {
            saveAppointmentEquipment($db, (int)$id,
                array_map('intval', $body['equipment_ids']), (int)$appt['customer_id']);
        }

        sendJson(appointmentDetail($db, (int)$id));
        return;
    }

 // DELETE /admin/appointments/{id} - cancel
    if ($method === 'DELETE' && $id !== null) {
        $stmt = $db->prepare("SELECT appointment_id FROM appointments WHERE appointment_id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) sendError(404, 'Appointment not found');

        $db->prepare(
            "UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?"
        )->execute([$id]);

        sendJson(['message' => 'Appointment cancelled']);
        return;
    }

    sendError(405, 'Method not allowed');
}
