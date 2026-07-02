<?php
// Customer-facing endpoints (PWA)
//
if (!defined('MVP_VERSION')) require_once __DIR__ . '/../config/version.php';
// GET /api/customer/me - own profile
// PUT /api/customer/me - update own profile
// PUT /api/customer/password - change own password
// POST /api/customer/service - log service via override PIN
// GET /api/customer/equipment - all equipment + next service dates
// GET /api/customer/equipment/{id} - single equipment item
// GET /api/customer/equipment/{id}/history - service history for one item
// GET /api/customer/invoices - own invoices (non-void)
// GET /api/customer/invoices/{id} - single invoice detail
// POST /api/customer/salt-delivery - request a salt delivery (min 4 bags, 4-day advance)

function routeCustomer(string $method, string $resource, ?string $id, ?string $sub): void {

 // All customer routes require a valid customer token
    $payload    = requireRole('customer');
    $userId     = $payload['sub'];
    $db         = getDB();

 // Resolve customer_id from user_id
    $stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
    $stmt->execute([$userId]);
    $customerId = $stmt->fetchColumn();

    if (!$customerId) {
        sendError(404, 'Customer profile not found');
    }

    switch ($resource) {

        case 'me':
            if ($method === 'GET') {
                $stmt = $db->prepare(
                    "SELECT customer_id, first_name, last_name, phone, email,
                            service_address, service_city, service_state, service_zip,
                            notes, override_enabled
                     FROM customers WHERE customer_id = ?"
                );
                $stmt->execute([$customerId]);
                sendJson($stmt->fetch());
            }

            if ($method === 'PUT') {
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                $allowed = ['phone', 'email', 'service_address', 'service_city',
                            'service_state', 'service_zip', 'notes'];
                $fields = [];
                $values = [];
                foreach ($allowed as $field) {
                    if (array_key_exists($field, $body)) {
                        $fields[] = "$field = ?";
                        $values[] = $body[$field];
                    }
                }
                if (empty($fields)) sendError(400, 'No valid fields to update');
                $values[] = $customerId;
                $db->prepare("UPDATE customers SET " . implode(', ', $fields) . " WHERE customer_id = ?")
                   ->execute($values);
                sendJson(['message' => 'Profile updated']);
            }

            sendError(405, 'Method not allowed');
            break;

        case 'appointments':
            handleCustomerAppointments($db, $method, $id, $sub, $customerId);
            break;

        case 'invoices':
            require_once __DIR__ . '/invoices.php';
 // GET /customer/invoices - list own invoices (non-void)
            if ($method === 'GET' && $id === null) {
                $stmt = $db->prepare(
                    "SELECT i.invoice_id, i.invoice_number, i.status, i.issue_date,
                            i.subtotal, i.tax_amount, i.total, i.appointment_id,
                            i.created_at,
                            (SELECT MAX(p.payment_date) FROM payments p WHERE p.invoice_id = i.invoice_id) AS last_payment_date
                     FROM invoices i
                     WHERE i.customer_id = ? AND i.status != 'void'
                     ORDER BY i.created_at DESC"
                );
                $stmt->execute([$customerId]);
                sendJson($stmt->fetchAll());
                break;
            }

 // GET /customer/invoices/{id} - single invoice detail
            if ($method === 'GET' && $id !== null) {
                $inv = invoiceDetail($db, (int)$id);
                if (!$inv || (int)$inv['customer_id'] !== (int)$customerId) {
                    sendError(404, 'Invoice not found');
                }
                sendJson($inv);
                break;
            }

            sendError(405, 'Method not allowed');
            break;

        case 'notes':
            if ($method !== 'GET') sendError(405, 'Method not allowed');
            require_once __DIR__ . '/notes.php';
            handleCustomerNotes($db, $customerId);
            break;

        case 'password':
            if ($method !== 'PUT') sendError(405, 'Method not allowed');

            $body        = json_decode(file_get_contents('php://input'), true) ?? [];
            $currentPass = $body['current_password'] ?? '';
            $newPass     = $body['new_password']     ?? '';

            if (!$currentPass || !$newPass) {
                sendError(400, 'current_password and new_password are required');
            }
            if (strlen($newPass) < 8) {
                sendError(400, 'New password must be at least 8 characters');
            }

 // Verify current password
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPass, $user['password_hash'])) {
                sendError(401, 'Current password is incorrect');
            }

 // Update to new password
            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
               ->execute([$newHash, $userId]);

 // Invalidate all refresh tokens so other devices are logged out
            $db->prepare("DELETE FROM refresh_tokens WHERE user_id = ?")
               ->execute([$userId]);

            sendJson(['message' => 'Password changed successfully']);
            break;

        case 'service':
            if ($method !== 'POST') sendError(405, 'Method not allowed');

            $body        = json_decode(file_get_contents('php://input'), true) ?? [];
            $equipmentId = (int)($body['equipment_id'] ?? 0);
            $pin         = trim($body['override_pin']  ?? '');
            $serviceDate = $body['service_date']        ?? date('Y-m-d');
            $notes       = trim($body['notes']          ?? '');

            if (!$equipmentId) sendError(400, 'equipment_id is required');
            if (!$pin)         sendError(400, 'override_pin is required');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate)) {
                sendError(400, 'service_date must be in YYYY-MM-DD format');
            }
            if ($serviceDate > date('Y-m-d')) {
                sendError(400, 'service_date cannot be in the future');
            }

 // Verify override is enabled and PIN matches
            $stmt = $db->prepare(
                "SELECT override_pin, override_enabled FROM customers WHERE customer_id = ?"
            );
            $stmt->execute([$customerId]);
            $cust = $stmt->fetch();

            if (!$cust['override_enabled']) {
                sendError(403, 'Override mode is not enabled for your account');
            }
            if (empty($cust['override_pin']) || $cust['override_pin'] !== $pin) {
                sendError(401, 'Invalid override PIN');
            }

 // Verify equipment belongs to this customer
            $stmt = $db->prepare(
                "SELECT e.equipment_id,
                        COALESCE(e.service_interval_days, et.default_interval_days) AS interval_days
                 FROM equipment e
                 JOIN equipment_types et ON e.type_id = et.type_id
                 WHERE e.equipment_id = ? AND e.customer_id = ? AND e.is_active = 1"
            );
            $stmt->execute([$equipmentId, $customerId]);
            $equip = $stmt->fetch();
            if (!$equip) sendError(404, 'Equipment not found');

 // Calculate next service due from service interval
            $nextDue = date('Y-m-d', strtotime($serviceDate . ' + ' . $equip['interval_days'] . ' days'));

 // Insert record flagged as customer-logged
            $db->prepare(
                "INSERT INTO service_records
                 (equipment_id, technician_id, service_date, service_type,
                  next_service_due, notes, logged_by)
                 VALUES (?, ?, ?, 'Customer Self-Service', ?, ?, 'customer')"
            )->execute([$equipmentId, $userId, $serviceDate, $nextDue, $notes ?: null]);

            $recordId = $db->lastInsertId();

 // Update equipment row with new dates
            $db->prepare(
                "UPDATE equipment SET last_service_date = ?, next_service_due = ?
                 WHERE equipment_id = ?"
            )->execute([$serviceDate, $nextDue, $equipmentId]);

            sendJson([
                'message'          => 'Service logged successfully',
                'record_id'        => $recordId,
                'next_service_due' => $nextDue,
            ], 201);
            break;

        case 'water-test':
            if ($method !== 'GET') sendError(405, 'Method not allowed');

            $stmt = $db->prepare(
                "SELECT test_id, label, test_date, is_current, created_at
                 FROM water_tests
                 WHERE customer_id = ? AND is_current = 1
                 LIMIT 1"
            );
            $stmt->execute([$customerId]);
            $current = $stmt->fetch();

            $stmt = $db->prepare(
                "SELECT test_id, label, test_date, is_current, created_at
                 FROM water_tests
                 WHERE customer_id = ?
                 ORDER BY test_date DESC"
            );
            $stmt->execute([$customerId]);
            $all = $stmt->fetchAll();

            if (!$current) {
                sendJson(['current' => null, 'history' => []]);
            }

            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                     . '://' . $_SERVER['HTTP_HOST']
                     . dirname($_SERVER['SCRIPT_NAME'])
                     . '/water_test.php';

            $current['pdf_url'] = $baseUrl . '?customer_id=' . $customerId;
            $history = array_map(function($t) use ($baseUrl) {
                $t['pdf_url'] = $baseUrl . '?test_id=' . $t['test_id'];
                return $t;
            }, $all);

            sendJson(['current' => $current, 'history' => $history]);
            break;

        case 'equipment':

 // Single item or sub-resource
            if ($id !== null) {
 // Verify this equipment belongs to this customer
                $stmt = $db->prepare(
                    "SELECT e.equipment_id FROM equipment e
                     WHERE e.equipment_id = ? AND e.customer_id = ? AND e.is_active = 1"
                );
                $stmt->execute([$id, $customerId]);
                if (!$stmt->fetch()) sendError(404, 'Equipment not found');

 // Service history
                if ($sub === 'history' && $method === 'GET') {
                    $stmt = $db->prepare(
                        "SELECT sr.record_id, sr.service_date, sr.service_type,
                                sr.next_service_due, sr.notes, sr.logged_by,
                                u.email AS technician
                         FROM service_records sr
                         JOIN users u ON sr.technician_id = u.user_id
                         WHERE sr.equipment_id = ?
                         ORDER BY sr.service_date DESC"
                    );
                    $stmt->execute([$id]);
                    sendJson($stmt->fetchAll());
                }

 // Single equipment detail
                if ($method === 'GET') {
                    $stmt = $db->prepare(
                        "SELECT e.equipment_id, et.type_name, et.category, e.model,
                                e.install_date,
                                COALESCE(e.service_interval_days, et.default_interval_days) AS service_interval_days,
                                e.last_service_date, e.next_service_due,
                                DATEDIFF(e.next_service_due, CURDATE()) AS days_until_due,
                                e.notes
                         FROM equipment e
                         JOIN equipment_types et ON e.type_id = et.type_id
                         WHERE e.equipment_id = ?"
                    );
                    $stmt->execute([$id]);
                    sendJson($stmt->fetch());
                }

                sendError(405, 'Method not allowed');
            }

 // All equipment for this customer
            if ($method === 'GET') {
                $stmt = $db->prepare(
                    "SELECT e.equipment_id, et.type_name, et.category, e.model,
                            e.install_date,
                            COALESCE(e.service_interval_days, et.default_interval_days) AS service_interval_days,
                            e.last_service_date, e.next_service_due,
                            DATEDIFF(e.next_service_due, CURDATE()) AS days_until_due,
                            e.notes
                     FROM equipment e
                     JOIN equipment_types et ON e.type_id = et.type_id
                     WHERE e.customer_id = ? AND e.is_active = 1
                     ORDER BY e.next_service_due ASC"
                );
                $stmt->execute([$customerId]);
                sendJson($stmt->fetchAll());
            }

            sendError(405, 'Method not allowed');
            break;

        case 'poll':
            if ($method !== 'GET') sendError(405, 'Method not allowed');

            $stmt = $db->prepare(
                "SELECT a.appointment_id, a.requested_date, a.confirmed_date, a.confirmed_time,
                        a.status, a.service_type_id, st.name AS service_type
                 FROM appointments a
                 JOIN service_types st ON a.service_type_id = st.type_id
                 WHERE a.customer_id = ? AND a.status IN ('pending','confirmed','in_progress')
                 ORDER BY COALESCE(a.confirmed_date, a.requested_date) ASC"
            );
            $stmt->execute([$customerId]);
            $appointments = $stmt->fetchAll();

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM invoices
                 WHERE customer_id = ? AND status IN ('draft','sent')"
            );
            $stmt->execute([$customerId]);
            $unpaidCount = (int)$stmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT e.equipment_id, et.type_name, et.category, e.model,
                        e.last_service_date, e.next_service_due,
                        DATEDIFF(e.next_service_due, CURDATE()) AS days_until_due
                 FROM equipment e
                 JOIN equipment_types et ON e.type_id = et.type_id
                 WHERE e.customer_id = ? AND e.is_active = 1
                 ORDER BY e.next_service_due ASC"
            );
            $stmt->execute([$customerId]);
            $equipment = $stmt->fetchAll();

            $stmt = $db->prepare(
                "SELECT id, title, body, created_at
                 FROM pending_notifications
                 WHERE customer_id = ? AND read_at IS NULL
                 ORDER BY created_at DESC"
            );
            $stmt->execute([$customerId]);
            $notifications = $stmt->fetchAll();

            sendJson([
                'appointments'  => $appointments,
                'unpaid_count'  => $unpaidCount,
                'equipment'     => $equipment,
                'notifications' => $notifications,
            ]);
            break;

        case 'notifications':
            if ($method === 'POST' && $sub === 'ack' && $id !== null) {
                $stmt = $db->prepare(
                    "UPDATE pending_notifications SET read_at = NOW()
                     WHERE id = ? AND customer_id = ? AND read_at IS NULL"
                );
                $stmt->execute([$id, $customerId]);

                if ($stmt->rowCount() === 0) {
                    sendError(404, 'Notification not found or already acknowledged');
                }

                sendJson(['message' => 'Notification acknowledged']);
            }
            sendError(405, 'Method not allowed');
            break;

        case 'push':
            require_once __DIR__ . '/push.php';
            handleCustomerPush($db, $method, $sub, $customerId);
            break;

        case 'salt-delivery':
            if ($method !== 'POST') { sendError(405, 'Method not allowed'); exit; }

            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $bags            = (int)($body['bags']             ?? 0);
            $requestedDate   = trim($body['requested_date']    ?? '');
            $requestedWindow = trim($body['requested_window']  ?? 'Either');
            $notes           = trim($body['notes']             ?? '');

 // Validation
            if ($bags < 4) { sendError(400, 'Minimum 4 bags per delivery'); exit; }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
                sendError(400, 'requested_date must be YYYY-MM-DD'); exit;
            }
            $dateTs  = strtotime($requestedDate);
            $today   = mktime(0,0,0, date('n'), date('j'), date('Y'));
            $diffDays = (int)(($dateTs - $today) / 86400);
            if ($diffDays < 4) {
                sendError(400, 'Salt deliveries require at least 4 days advance notice'); exit;
            }
            $dow = (int)date('N', $dateTs); // 1=Mon … 7=Sun
            if ($dow >= 6) {
                sendError(400, 'Delivery date must be a weekday (Mon-Fri)'); exit;
            }

            $allowedWindows = ['Morning', 'Afternoon', 'Either'];
            if (!in_array($requestedWindow, $allowedWindows, true)) {
                $requestedWindow = 'Either';
            }

 // Fetch customer info for the email / appointment
            $stmt = $db->prepare(
                "SELECT c.first_name, c.last_name, c.phone, c.email,
                        c.service_address, c.service_city, c.service_state, c.service_zip
                 FROM customers c WHERE c.customer_id = ?"
            );
            $stmt->execute([$customerId]);
            $cust = $stmt->fetch();

            $custName    = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? ''));
            $custAddr    = implode(', ', array_filter([
                $cust['service_address'] ?? '',
                $cust['service_city']    ?? '',
                $cust['service_state']   ?? '',
                $cust['service_zip']     ?? '',
            ]));
            $totalAmount = $bags * 10;

 // Resolve or create the "Salt Delivery" service type
            $stmt = $db->prepare("SELECT type_id FROM service_types WHERE name = 'Salt Delivery' LIMIT 1");
            $stmt->execute();
            $saltTypeId = $stmt->fetchColumn();
            if (!$saltTypeId) {
                $db->prepare(
                    "INSERT INTO service_types (name, min_days_out, is_active) VALUES ('Salt Delivery', 4, 1)"
                )->execute();
                $saltTypeId = (int)$db->lastInsertId();
            }

 // Create unassigned pending appointment
            $db->prepare(
                "INSERT INTO appointments
                 (customer_id, service_type_id, requested_date, requested_window,
                  customer_notes, booking_source, status)
                 VALUES (?, ?, ?, ?, ?, 'customer_app', 'pending')"
            )->execute([
                $customerId,
                $saltTypeId,
                $requestedDate,
                $requestedWindow,
                trim("[{$bags} bags / \${$totalAmount}]" . ($notes ? " - {$notes}" : '')),
            ]);
            $appointmentId = (int)$db->lastInsertId();

 // Send notification email to office
            require_once __DIR__ . '/gmail_oauth.php';
            require_once __DIR__ . '/email.php';
            require_once __DIR__ . '/autoEmail.php';
            require_once __DIR__ . '/settings.php';
            $settings = getCompanySettings($db);
            $dateFormatted   = date('D, M j, Y', $dateTs);
            $subject         = "Salt Delivery Request - {$custName} on {$dateFormatted}";
            $bodyHtml = "
<p>A salt delivery has been requested through the customer portal.</p>
<table cellpadding='6' style='border-collapse:collapse;font-family:sans-serif;font-size:14px'>
  <tr><td><strong>Customer</strong></td><td>{$custName}</td></tr>
  <tr><td><strong>Address</strong></td><td>{$custAddr}</td></tr>
  <tr><td><strong>Phone</strong></td><td>" . ($cust['phone'] ?? '-') . "</td></tr>
  <tr><td><strong>Email</strong></td><td>" . ($cust['email'] ?? '-') . "</td></tr>
  <tr><td><strong>Date Requested</strong></td><td>{$dateFormatted}</td></tr>
  <tr><td><strong>Time Preference</strong></td><td>{$requestedWindow}</td></tr>
  <tr><td><strong>Bags (50 lb)</strong></td><td>{$bags}</td></tr>
  <tr><td><strong>Est. Amount</strong></td><td>\${$totalAmount}</td></tr>" .
  ($notes ? "<tr><td><strong>Notes</strong></td><td>" . htmlspecialchars($notes) . "</td></tr>" : '') . "
  <tr><td><strong>Appointment #</strong></td><td>{$appointmentId}</td></tr>
</table>
<p style='margin-top:16px;color:#666;font-size:12px'>Generated from MVP Backoffice " . MVP_VERSION . "</p>";

            dispatchEmail($db, $settings, 'info@example.com', 'Acme Water Service',
                          $subject, $bodyHtml, 'salt_delivery', null, $appointmentId);

            sendJson([
                'message'        => 'Salt delivery request submitted successfully',
                'appointment_id' => $appointmentId,
                'bags'           => $bags,
                'requested_date' => $requestedDate,
            ], 201);
            exit;

    }
}
