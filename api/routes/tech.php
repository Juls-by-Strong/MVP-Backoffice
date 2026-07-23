<?php
// Technician endpoints (field app or web)
//
// GET /api/tech/schedule - upcoming / overdue service jobs
// POST /api/tech/service - log a service visit
// GET /api/tech/service/{id} - view a service record
// GET /api/tech/customers/{id}/notes - view notes for a customer
// POST /api/tech/customers/{id}/notes - add note for a customer
// PUT /api/tech/notes/{id} - edit own note

function routeTech(string $method, string $resource, ?string $id, ?string $sub = null, ?string $subsub = null): void {

    $payload      = requireRole('technician', 'admin');
    $technicianId = (int)$payload['sub'];
    $db           = getDB();

    switch ($resource) {

        case 'catalog':
            handleCatalog($db, $method, $id, $sub, 'technician');
            break;

        case 'invoices':
            handleInvoices($db, $method, $id, $sub, $subsub, $technicianId, 'technician');
            break;

        case 'estimates':
            require_once __DIR__ . '/estimates.php';
            handleEstimates($db, $method, $id, $sub, $subsub, $technicianId, 'technician');
            break;

        case 'contracts':
            require_once __DIR__ . '/contracts.php';
            handleContracts($db, $method, $id, $sub, $subsub, $technicianId, 'technician');
            break;

        case 'appointments':
 // POST /tech/appointments/{id}/on-my-way
            if ($sub === 'on-my-way' && $method === 'POST' && $id !== null) {
                $appointmentId = (int)$id;
                $stmt = $db->prepare(
                    "SELECT a.appointment_id, a.customer_id,
                            CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                            c.user_id AS customer_user_id,
                            CONCAT(t.first_name,' ',t.last_name) AS tech_name,
                            a.confirmed_time
                     FROM appointments a
                     JOIN customers c ON a.customer_id = c.customer_id
                     JOIN users t ON a.technician_id = t.user_id
                     WHERE a.appointment_id = ? AND a.technician_id = ?"
                );
                $stmt->execute([$appointmentId, $technicianId]);
                $appt = $stmt->fetch();
                if (!$appt) sendError(404, 'Appointment not found or not assigned to you');

 // Get customer FCM token
                $stmt2 = $db->prepare("SELECT device_token FROM users WHERE user_id = ?");
                $stmt2->execute([$appt['customer_user_id']]);
                $customerToken = $stmt2->fetchColumn();

 // Log the event
                try {
                    $db->prepare(
                        "INSERT INTO on_my_way_log (appointment_id, technician_id, sent_at) VALUES (?, ?, NOW())
                         ON DUPLICATE KEY UPDATE sent_at = NOW()"
                    )->execute([$appointmentId, $technicianId]);
                } catch (\Throwable $e) { /* table may not exist yet - migration needed */ }

                $result = ['message' => 'On my way notification sent', 'push_sent' => false];

                if ($customerToken) {
                    $fcmKey = getCompanySettingValue($db, 'fcm_server_key');
                    if ($fcmKey) {
                        $pushed = sendFcmPush(
                            $fcmKey,
                            $customerToken,
                            'Your technician is on the way! ',
                            $appt['tech_name'] . ' is heading to your location now.'
                                . ($appt['confirmed_time'] ? ' Scheduled time: ' . substr($appt['confirmed_time'], 0, 5) : ''),
                            ['type' => 'on_my_way', 'appointment_id' => (string)$appointmentId]
                        );
                        $result['push_sent'] = $pushed;
                    }
                }
                sendJson($result);
            }
 // All other appointment operations
            handleTechAppointments($db, $method, $id, $sub, $technicianId);
            break;

        case 'customers':
            if ($sub === 'notes') {
                handleTechNotes($db, $method, $id, 'notes', $technicianId);
                return;
            }

 // GET /tech/customers/{id}/service-history
            if ($method === 'GET' && $id !== null && $sub === 'service-history') {
                $stmt = $db->prepare(
                    "SELECT sr.record_id, sr.service_date, sr.service_type, sr.notes,
                            sr.next_service_due,
                            et.type_name, e.model,
                            CONCAT(u.first_name,' ',u.last_name) AS technician_name
                     FROM service_records sr
                     JOIN equipment e  ON sr.equipment_id = e.equipment_id
                     JOIN equipment_types et ON e.type_id = et.type_id
                     LEFT JOIN users u ON sr.technician_id = u.user_id
                     WHERE e.customer_id = ?
                     ORDER BY sr.service_date DESC
                     LIMIT 100"
                );
                $stmt->execute([$id]);
                sendJson($stmt->fetchAll());
            }

 // GET /tech/customers/{id}/water-tests
            if ($method === 'GET' && $id !== null && $sub === 'water-tests') {
                $stmt = $db->prepare(
                    "SELECT test_id, label, test_date, is_current, created_at
                     FROM water_tests WHERE customer_id = ?
                     ORDER BY test_date DESC"
                );
                $stmt->execute([$id]);
                $tests = $stmt->fetchAll();
 // Build PDF URLs (uses same water_test.php viewer, tech JWT is accepted)
                $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                         . '://' . $_SERVER['HTTP_HOST']
                         . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
                         . '/water_test.php';
                foreach ($tests as &$t) {
                    $t['pdf_url'] = $baseUrl . '?test_id=' . $t['test_id'];
                }
                unset($t);
                sendJson($tests);
            }
            if ($method === 'GET' && $id === null) {
                $q    = trim($_GET['q'] ?? '');
                $where = ['1=1'];
                $params = [];
                if ($q !== '') {
                    $like = '%' . $q . '%';
                    $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ?
                                 OR CONCAT(c.first_name,' ',c.last_name) LIKE ? OR c.company_name LIKE ?)";
                    $params = [$like, $like, $like, $like, $like];
                }
                $stmt = $db->prepare(
                    "SELECT c.customer_id, c.first_name, c.last_name, c.company_name, c.phone, c.email,
                            c.service_address, c.service_city, c.service_state, c.service_zip,
                            COUNT(DISTINCT e.equipment_id) AS equipment_count,
                            MAX(e.next_service_due) AS next_due
                     FROM customers c
                     LEFT JOIN equipment e ON e.customer_id = c.customer_id AND e.is_active = 1
                     WHERE " . implode(' AND ', $where) . "
                     GROUP BY c.customer_id
                     ORDER BY c.last_name, c.first_name
                     LIMIT 200"
                );
                $stmt->execute($params);
                sendJson($stmt->fetchAll());
            }

 // GET /tech/customers/{id} - customer detail with equipment (for invoice builder)
            if ($method === 'GET' && $id !== null) {
                $stmt = $db->prepare(
                    "SELECT customer_id, first_name, last_name, company_name, phone, email,
                            service_address, service_city, service_state, service_zip
                     FROM customers WHERE customer_id = ?"
                );
                $stmt->execute([$id]);
                $cust = $stmt->fetch();
                if (!$cust) sendError(404, 'Customer not found');

                $eq = $db->prepare(
                    "SELECT e.equipment_id, et.type_name, et.category, e.model,
                            e.last_service_date, e.next_service_due
                     FROM equipment e
                     JOIN equipment_types et ON e.type_id = et.type_id
                     WHERE e.customer_id = ? AND e.is_active = 1
                     ORDER BY et.type_name"
                );
                $eq->execute([$id]);
                $cust['equipment'] = $eq->fetchAll();
                sendJson($cust);
            }
 // GET /tech/customers/{id}/images
            if ($method === 'GET' && $id !== null && $sub === 'images') {
                $apptFilter = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : null;
                if ($apptFilter) {
                    $stmt = $db->prepare(
                        "SELECT ci.image_id, ci.customer_id, ci.appointment_id,
                                ci.filename, ci.caption, ci.created_at,
                                CONCAT(u.first_name,' ',u.last_name) AS uploaded_by_name
                         FROM customer_images ci
                         LEFT JOIN users u ON ci.uploaded_by = u.user_id
                         WHERE ci.customer_id = ? AND ci.appointment_id = ?
                         ORDER BY ci.created_at DESC"
                    );
                    $stmt->execute([$id, $apptFilter]);
                } else {
                    $stmt = $db->prepare(
                        "SELECT ci.image_id, ci.customer_id, ci.appointment_id,
                                ci.filename, ci.caption, ci.created_at,
                                CONCAT(u.first_name,' ',u.last_name) AS uploaded_by_name
                         FROM customer_images ci
                         LEFT JOIN users u ON ci.uploaded_by = u.user_id
                         WHERE ci.customer_id = ?
                         ORDER BY ci.created_at DESC"
                    );
                    $stmt->execute([$id]);
                }
                $rows = $stmt->fetchAll();
                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
                foreach ($rows as &$r) {
                    $r['url'] = $proto . '://' . $host . '/customer_images/' . $r['filename'];
                }
                unset($r);
                sendJson($rows);
            }

 // POST /tech/customers - create customer + user account (field-added customer)
            if ($method === 'POST' && $id === null) {
                $body      = json_decode(file_get_contents('php://input'), true) ?? [];
                $firstName = trim($body['first_name'] ?? '');
                $lastName  = trim($body['last_name']  ?? '');
                $email     = trim($body['email']      ?? '');
                $password  = $body['password'] ?? 'Water2026*';   // default password
                $phone     = trim($body['phone']      ?? '');

                if (!$firstName || !$lastName) sendError(400, 'first_name and last_name are required');
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) sendError(400, 'Invalid email address');
                if (strlen($password) < 8) sendError(400, 'Password must be at least 8 characters');

                $db->beginTransaction();
                try {
                    $loginEmail = $email ?: ('noemail_' . strtolower($lastName) . '_' . strtolower($firstName) . '_' . uniqid() . '@noemail.local');
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'customer')")
                       ->execute([$loginEmail, $hash]);
                    $userId = (int)$db->lastInsertId();

                    $db->prepare(
                        "INSERT INTO customers
                         (user_id, first_name, last_name, company_name, phone, phone2, email, email2,
                          service_address, service_city, service_state, service_zip,
                          billing_address, billing_city, billing_state, billing_zip, has_separate_billing,
                          notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $userId,
                        $firstName,
                        $lastName,
                        trim($body['company_name'] ?? '') ?: null,
                        $phone,
                        trim($body['phone2'] ?? '') ?: null,
                        $email ?: null,
                        trim($body['email2'] ?? '') ?: null,
                        $body['service_address'] ?? null,
                        $body['service_city']    ?? null,
                        $body['service_state']   ?? null,
                        $body['service_zip']     ?? null,
                        trim($body['billing_address'] ?? '') ?: null,
                        trim($body['billing_city']    ?? '') ?: null,
                        trim($body['billing_state']   ?? '') ?: null,
                        trim($body['billing_zip']     ?? '') ?: null,
                        empty($body['has_separate_billing']) ? 0 : 1,
                        $body['notes']           ?? null,
                    ]);
                    $customerId = (int)$db->lastInsertId();

                    $db->commit();
                    sendJson([
                        'message'     => 'Customer created',
                        'customer_id' => $customerId,
                        'user_id'     => $userId,
                        'has_email'   => !empty($email),
                    ], 201);
                } catch (PDOException $e) {
                    $db->rollBack();
                    if ($email) {
                        sendError(409, 'A customer with that email already exists');
                    }
                    sendError(500, 'Could not create customer: ' . $e->getMessage());
                }
            }

            sendError(404, 'Not found');
            break;

        case 'images':
            if ($method !== 'DELETE') sendError(405, 'Method not allowed');
            if (!$id) sendError(400, 'image_id required');

            $stmt = $db->prepare("SELECT filename, customer_id, uploaded_by FROM customer_images WHERE image_id = ?");
            $stmt->execute([$id]);
            $img = $stmt->fetch();
            if (!$img) sendError(404, 'Image not found');

            $filePath = __DIR__ . '/../../customer_images/' . basename($img['filename']);
            if (file_exists($filePath)) @unlink($filePath);

            $db->prepare("DELETE FROM customer_images WHERE image_id = ?")->execute([$id]);
            sendJson(['message' => 'Image deleted']);
            break;

 // ---- Equipment add / edit (no delete - office only) --
        case 'equipment':
            if ($method === 'POST') {
                $body       = json_decode(file_get_contents('php://input'), true) ?? [];
                $customerId = (int)($body['customer_id'] ?? 0);
                $typeId     = (int)($body['type_id']     ?? 0);

                if (!$customerId || !$typeId) sendError(400, 'customer_id and type_id are required');

                $intervalDays = !empty($body['service_interval_days'])
                    ? (int)$body['service_interval_days']
                    : null;

                $installDate     = $body['install_date'] ?? null;
                $lastServiceDate = $body['last_service_date'] ?? null;
                $partId          = !empty($body['part_id']) ? (int)$body['part_id'] : null;

                $nextDue = null;
                if ($lastServiceDate) {
                    if ($intervalDays) {
                        $nextDue = date('Y-m-d', strtotime($lastServiceDate . " + $intervalDays days"));
                    } else {
                        $ts = $db->prepare("SELECT default_interval_days FROM equipment_types WHERE type_id = ?");
                        $ts->execute([$typeId]);
                        $defaultInterval = (int)$ts->fetchColumn();
                        $nextDue = date('Y-m-d', strtotime($lastServiceDate . " + $defaultInterval days"));
                    }
                }

                $db->prepare(
                    "INSERT INTO equipment
                     (customer_id, type_id, model, install_date, service_interval_days,
                      last_service_date, next_service_due, notes, part_id, assigned_technician)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $customerId,
                    $typeId,
                    $body['model']            ?? null,
                    $installDate,
                    $intervalDays,
                    $lastServiceDate,
                    $nextDue,
                    $body['notes']            ?? null,
                    $partId,
                    $technicianId,
                ]);

                sendJson([
                    'message'      => 'Equipment added',
                    'equipment_id' => (int)$db->lastInsertId(),
                ], 201);
            }

            if ($method === 'PUT' && $id !== null) {
                $body    = json_decode(file_get_contents('php://input'), true) ?? [];
                $allowed = ['type_id','model','install_date','service_interval_days',
                            'last_service_date','next_service_due','notes','part_id'];
                $fields  = [];
                $values  = [];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $body)) {
                        $fields[] = "$f = ?";
                        $values[] = ($f === 'part_id' && empty($body[$f])) ? null : $body[$f];
                    }
                }
                if (empty($fields)) sendError(400, 'Nothing to update');
                $values[] = $id;
                $db->prepare("UPDATE equipment SET " . implode(', ', $fields) . " WHERE equipment_id = ?")
                   ->execute($values);
                sendJson(['message' => 'Equipment updated']);
            }

            sendError(405, 'Method not allowed');
            break;

        case 'notes':
            handleTechNotes($db, $method, $id, null, $technicianId);
            break;

        case 'technicians':
            if ($method !== 'GET') sendError(405, 'GET only');
            $stmt = $db->query(
                "SELECT user_id, first_name, last_name
                 FROM users
                 WHERE role IN ('technician','admin') AND is_active = 1
                 ORDER BY first_name, last_name"
            );
            sendJson($stmt->fetchAll());
            break;

        case 'schedule':
            if ($method !== 'GET') sendError(405, 'Method not allowed');

 // Optional query param ?days=30 (default 30)
            $days = min((int)($_GET['days'] ?? 30), 365);

            $stmt = $db->prepare(
                "SELECT e.equipment_id,
                        CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        c.company_name,
                        c.phone,
                        c.service_address, c.service_city, c.service_state, c.service_zip,
                        et.type_name, et.category,
                        e.model,
                        e.last_service_date,
                        e.next_service_due,
                        DATEDIFF(e.next_service_due, CURDATE()) AS days_until_due
                 FROM equipment e
                 JOIN customers c ON e.customer_id = c.customer_id
                 JOIN equipment_types et ON e.type_id = et.type_id
                 WHERE e.is_active = 1
                   AND e.next_service_due <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                 ORDER BY e.next_service_due ASC"
            );
            $stmt->execute([$days]);
            sendJson($stmt->fetchAll());
            break;

        case 'service':

 // View a specific record
            if ($method === 'GET' && $id !== null) {
                $stmt = $db->prepare(
                    "SELECT sr.*, u.email AS technician_email,
                            et.type_name, c.first_name, c.last_name
                     FROM service_records sr
                     JOIN users u ON sr.technician_id = u.user_id
                     JOIN equipment e ON sr.equipment_id = e.equipment_id
                     JOIN equipment_types et ON e.type_id = et.type_id
                     JOIN customers c ON e.customer_id = c.customer_id
                     WHERE sr.record_id = ?"
                );
                $stmt->execute([$id]);
                $record = $stmt->fetch();
                if (!$record) sendError(404, 'Service record not found');
                sendJson($record);
            }

            if ($method !== 'POST') sendError(405, 'Method not allowed');

            $body        = json_decode(file_get_contents('php://input'), true) ?? [];
            $equipmentId = (int)($body['equipment_id'] ?? 0);
            $serviceDate = $body['service_date'] ?? date('Y-m-d');
            $serviceType = trim($body['service_type'] ?? 'Routine Service');
            $notes       = trim($body['notes'] ?? '');

            if (!$equipmentId) sendError(400, 'equipment_id is required');

 // Verify equipment exists and is active
            $stmt = $db->prepare(
                "SELECT e.equipment_id,
                        COALESCE(e.service_interval_days, et.default_interval_days) AS interval_days
                 FROM equipment e
                 JOIN equipment_types et ON e.type_id = et.type_id
                 WHERE e.equipment_id = ? AND e.is_active = 1"
            );
            $stmt->execute([$equipmentId]);
            $equip = $stmt->fetch();
            if (!$equip) sendError(404, 'Equipment not found');

 // Calculate next service due date
 // Allow override in request body, otherwise calculate from interval
            if (!empty($body['next_service_due'])) {
                $nextDue = $body['next_service_due'];
            } else {
                $nextDue = date('Y-m-d', strtotime($serviceDate . ' + ' . $equip['interval_days'] . ' days'));
            }

 // Insert service record
            $db->prepare(
                "INSERT INTO service_records
                 (equipment_id, technician_id, service_date, service_type, next_service_due, notes)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$equipmentId, $technicianId, $serviceDate, $serviceType, $nextDue, $notes]);

            $recordId = $db->lastInsertId();

 // Update equipment row with latest service info
            $db->prepare(
                "UPDATE equipment
                 SET last_service_date = ?,
                     next_service_due  = ?,
                     assigned_technician = ?
                 WHERE equipment_id = ?"
            )->execute([$serviceDate, $nextDue, $technicianId, $equipmentId]);

            sendJson([
                'message'        => 'Service logged successfully',
                'record_id'      => $recordId,
                'next_service_due' => $nextDue,
            ], 201);
            break;

 // GET /tech/service-types
        case 'service-types':
            if ($method !== 'GET') sendError(405, 'Method not allowed');
            $stmt = $db->query(
                "SELECT type_id, name, min_days_out, default_price, price_overridable
                 FROM service_types WHERE is_active = 1 ORDER BY name"
            );
            sendJson($stmt->fetchAll());
            break;

 // POST /tech/device-token - register FCM push token
        case 'device-token':
            if ($method !== 'POST') sendError(405, 'Method not allowed');
            $body  = json_decode(file_get_contents('php://input'), true) ?? [];
            $token = trim($body['token'] ?? '');
            if (!$token) sendError(400, 'token is required');
            $db->prepare("UPDATE users SET device_token = ? WHERE user_id = ?")
               ->execute([$token, $technicianId]);
            sendJson(['message' => 'Device token registered']);
            break;

        case 'customer-search':
            if ($method !== 'GET') sendError(405, 'GET only');
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) sendJson([]);
            $like = '%' . $q . '%';
            $stmt = $db->prepare(
                "SELECT customer_id, first_name, last_name, company_name, phone, email,
                        service_address, service_city, service_state
                 FROM customers
                 WHERE first_name  LIKE ? OR last_name LIKE ?
                    OR phone       LIKE ? OR email     LIKE ?
                    OR CONCAT(first_name,' ',last_name) LIKE ?
                    OR company_name LIKE ?
                 ORDER BY last_name, first_name
                 LIMIT 20"
            );
            $stmt->execute([$like, $like, $like, $like, $like, $like]);
            sendJson($stmt->fetchAll());
            break;

 // CLOCK TIME
 //
 // GET /tech/clock-time - current status (open entry or null)
 // POST /tech/clock-time - clock in (auto-closes any stale open entry)
 // PUT /tech/clock-time/{id} - clock out (close an open entry)
 // DELETE /tech/clock-time/open - force-close stale open entry
        case 'clock-time':
            handleTechClockTime($db, $method, $id, $technicianId);
            break;

        default:
            sendError(404, 'Not found');
    }
}

function getCompanySettingValue(PDO $db, string $key): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (\Throwable $e) { return ''; }
}

function handleTechClockTime(PDO $db, string $method, ?string $id, int $technicianId): void {
 // Lock all date/time math in this handler to Central Time, regardless of
 // the server's or MySQL session's configured default timezone.
    date_default_timezone_set('America/Chicago');

    $getOpen = function() use ($db, $technicianId): ?array {
        $stmt = $db->prepare(
            "SELECT entry_id, technician_id, clocked_in_at, clocked_out_at, notes, force_closed
             FROM tech_clock_time
             WHERE technician_id = ? AND clocked_out_at IS NULL AND force_closed = 0
             ORDER BY clocked_in_at DESC
             LIMIT 1"
        );
        $stmt->execute([$technicianId]);
        return $stmt->fetch() ?: null;
    };

 // Day-by-day breakdown for a given Mon-Sun week: hours logged each day
 // plus the customers worked that day (from this tech's appointments).
 // Defaults to the current week if week_start is omitted or invalid.
 // Response: { week_start, week_end, days: [{date, day_name, hours, customers: [name,...]}], week_total_hours }
    if ($method === 'GET' && $id === 'history') {
        $weekStartParam = $_GET['week_start'] ?? '';
        $ts = strtotime($weekStartParam ?: 'now');
        if ($ts === false) $ts = time();
 // Normalize to the Monday of that week
        $dow = (int)date('N', $ts); // 1 (Mon) .. 7 (Sun)
        $monday = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
        $sunday = date('Y-m-d', strtotime('+6 days', strtotime($monday)));

 // Hours per day (completed entries only)
        $stmtHours = $db->prepare(
            "SELECT DATE(clocked_in_at) AS day,
                    ROUND(SUM(TIMESTAMPDIFF(SECOND, clocked_in_at, clocked_out_at)) / 3600.0, 4) AS hours
             FROM tech_clock_time
             WHERE technician_id = ?
               AND clocked_out_at IS NOT NULL
               AND DATE(clocked_in_at) BETWEEN ? AND ?
             GROUP BY DATE(clocked_in_at)"
        );
        $stmtHours->execute([$technicianId, $monday, $sunday]);
        $hoursByDay = [];
        foreach ($stmtHours->fetchAll() as $row) {
            $hoursByDay[$row['day']] = (float)$row['hours'];
        }

 // Individual clock in/out entries per day (for showing actual times,
 // not just the summed total)
        $stmtEntries = $db->prepare(
            "SELECT DATE(clocked_in_at) AS day, entry_id, clocked_in_at, clocked_out_at
             FROM tech_clock_time
             WHERE technician_id = ?
               AND clocked_out_at IS NOT NULL
               AND DATE(clocked_in_at) BETWEEN ? AND ?
             ORDER BY clocked_in_at ASC"
        );
        $stmtEntries->execute([$technicianId, $monday, $sunday]);
        $entriesByDay = [];
        foreach ($stmtEntries->fetchAll() as $row) {
            $entriesByDay[$row['day']][] = [
                'entry_id'       => (int)$row['entry_id'],
                'clocked_in_at'  => $row['clocked_in_at'],
                'clocked_out_at' => $row['clocked_out_at'],
            ];
        }

 // Customers worked each day - appointments assigned to this tech,
 // matched on confirmed_date (or completion date for completed jobs)
        $stmtAppt = $db->prepare(
            "SELECT
                COALESCE(DATE(a.completed_at), a.confirmed_date) AS day,
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name
             FROM appointments a
             JOIN customers c ON a.customer_id = c.customer_id
             WHERE a.technician_id = ?
               AND a.status != 'cancelled'
               AND COALESCE(DATE(a.completed_at), a.confirmed_date) BETWEEN ? AND ?
             ORDER BY day ASC, customer_name ASC"
        );
        $stmtAppt->execute([$technicianId, $monday, $sunday]);
        $customersByDay = [];
        foreach ($stmtAppt->fetchAll() as $row) {
            $customersByDay[$row['day']][] = $row['customer_name'];
        }

        $days = [];
        $weekTotal = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $day = date('Y-m-d', strtotime("+$i days", strtotime($monday)));
            $hrs = $hoursByDay[$day] ?? 0.0;
            $weekTotal += $hrs;
            $days[] = [
                'date'      => $day,
                'day_name'  => date('D, M j', strtotime($day)),
                'hours'     => $hrs,
                'entries'   => $entriesByDay[$day] ?? [],
                'customers' => $customersByDay[$day] ?? [],
            ];
        }

        sendJson([
            'week_start'       => $monday,
            'week_end'         => $sunday,
            'days'             => $days,
            'week_total_hours' => round($weekTotal, 4),
        ]);
        return;
    }

 // Returns current status, today's completed entries, weekly_hours
 // (Mon-Sun ISO week, completed entries only), and server_time.
 //
 // Response shape:
 // clocked_in bool - true if an open entry exists
 // open_entry obj|null - the open entry, or null
 // today array - completed entries for today (DATE(clocked_in_at) = CURDATE())
 // weekly_hours float - sum of completed-entry durations this ISO week (Mon-Sun), hours
 // server_time string - current server timestamp (Y-m-d H:i:s)
 //
 // Notes:
 // • weekly_hours covers only CLOSED entries (clocked_out_at IS NOT NULL).
 // The iOS app tracks the live in-progress duration locally and adds it on top.
 // • today likewise contains only completed entries; the app appends open_entry
 // to its local list via its existing dedup logic.
    if ($method === 'GET' && $id === null) {
        $open = $getOpen();

        $todayStr = date('Y-m-d');               // Chicago-local "today"
        $dow      = (int)date('N');               // 1 (Mon) .. 7 (Sun), Chicago-local
        $mondayStr = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
        $nextMondayStr = date('Y-m-d', strtotime($mondayStr . ' +7 days'));

 // Today's completed entries (clocked_out_at IS NOT NULL, date matches today)
        $stmtToday = $db->prepare(
            "SELECT entry_id, technician_id, clocked_in_at, clocked_out_at,
                    ROUND(TIMESTAMPDIFF(SECOND, clocked_in_at, clocked_out_at) / 3600.0, 4) AS duration_hours,
                    notes, force_closed
             FROM tech_clock_time
             WHERE technician_id = ?
               AND clocked_out_at IS NOT NULL
               AND DATE(clocked_in_at) = ?
             ORDER BY clocked_in_at ASC"
        );
        $stmtToday->execute([$technicianId, $todayStr]);
        $today = $stmtToday->fetchAll();

 // Weekly hours: completed entries whose clocked_in_at falls within the
 // current Mon-Sun week, bounds computed in Chicago-local time above
 // (avoids relying on MySQL's session timezone for CURDATE()/DAYOFWEEK()).
        $stmtWeek = $db->prepare(
            "SELECT COALESCE(
                SUM(TIMESTAMPDIFF(SECOND, clocked_in_at, clocked_out_at)) / 3600.0,
                0
             ) AS weekly_hours
             FROM tech_clock_time
             WHERE technician_id = ?
               AND clocked_out_at IS NOT NULL
               AND clocked_in_at >= ?
               AND clocked_in_at <  ?"
        );
        $stmtWeek->execute([$technicianId, $mondayStr, $nextMondayStr]);
        $weeklyHours = round((float)$stmtWeek->fetchColumn(), 2);

        sendJson([
            'clocked_in'   => $open !== null,
            'open_entry'   => $open,
            'today'        => $today,
            'weekly_hours' => $weeklyHours,
            'server_time'  => date('Y-m-d H:i:s'),
        ]);
        return;
    }

 // Auto-closes any stale open entry (back-to-back: its clock-out
 // time is set to this new clock-in time).
    if ($method === 'POST' && $id === null) {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $notes = trim($body['notes'] ?? '') ?: null;
        $now   = date('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
 // Auto-close any stale open entry
            $stale = $getOpen();
            if ($stale) {
                $db->prepare(
                    "UPDATE tech_clock_time
                     SET clocked_out_at = ?, force_closed = 0, notes = CONCAT(IFNULL(notes,''), ' [auto-closed on new clock-in]')
                     WHERE entry_id = ?"
                )->execute([$now, $stale['entry_id']]);
            }

 // Create the new entry
            $db->prepare(
                "INSERT INTO tech_clock_time (technician_id, clocked_in_at, notes) VALUES (?, ?, ?)"
            )->execute([$technicianId, $now, $notes]);
            $entryId = (int)$db->lastInsertId();

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            sendError(500, 'Clock-in failed: ' . $e->getMessage());
        }

        $stmt = $db->prepare("SELECT * FROM tech_clock_time WHERE entry_id = ?");
        $stmt->execute([$entryId]);
        sendJson($stmt->fetch());
        return;
    }

    if ($method === 'PUT' && $id !== null && $id !== 'open') {
        $entryId = (int)$id;
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $notes   = trim($body['notes'] ?? '') ?: null;
        $now     = date('Y-m-d H:i:s');

 // Verify entry belongs to this tech and is still open
        $stmt = $db->prepare(
            "SELECT entry_id FROM tech_clock_time
             WHERE entry_id = ? AND technician_id = ? AND clocked_out_at IS NULL"
        );
        $stmt->execute([$entryId, $technicianId]);
        if (!$stmt->fetch()) {
            sendError(404, 'Open clock entry not found');
        }

        $db->prepare(
            "UPDATE tech_clock_time
             SET clocked_out_at = ?, notes = ?, force_closed = 0
             WHERE entry_id = ?"
        )->execute([$now, $notes, $entryId]);

        $stmt = $db->prepare("SELECT * FROM tech_clock_time WHERE entry_id = ?");
        $stmt->execute([$entryId]);
        sendJson($stmt->fetch());
        return;
    }

 // DELETE /tech/clock-time/open force-close stale entry
    if ($method === 'DELETE' && $id === 'open') {
        $open = $getOpen();
        if (!$open) {
            sendError(404, 'No open clock entry found');
        }
        $now = date('Y-m-d H:i:s');
        $db->prepare(
            "UPDATE tech_clock_time
             SET clocked_out_at = ?, force_closed = 1
             WHERE entry_id = ?"
        )->execute([$now, $open['entry_id']]);

        $stmt = $db->prepare("SELECT * FROM tech_clock_time WHERE entry_id = ?");
        $stmt->execute([$open['entry_id']]);
        sendJson($stmt->fetch());
        return;
    }

    sendError(405, 'Method not allowed');
}

function sendFcmPush(string $serverKey, string $token, string $title, string $body, array $data = []): bool {
    $payload = json_encode([
        'to'           => $token,
        'notification' => ['title' => $title, 'body' => $body, 'sound' => 'default'],
        'data'         => $data,
        'priority'     => 'high',
    ]);
    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: key=' . $serverKey,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && $response) {
        $resp = json_decode($response, true);
        return isset($resp['success']) && $resp['success'] > 0;
    }
    return false;
}
