<?php
// Admin / office staff endpoints
//
// Users
// GET /api/admin/users - list all users
// POST /api/admin/users - create user (any role)
// PUT /api/admin/users/{id} - update user
// DELETE /api/admin/users/{id} - deactivate user
//
// Customers
// GET /api/admin/customers - list all customers
// GET /api/admin/customers/{id} - single customer + equipment
// POST /api/admin/customers - create customer (also creates user account)
// PUT /api/admin/customers/{id} - update customer
//
// Customer Notes
// GET /api/admin/customers/{id}/notes - all notes for a customer
// POST /api/admin/customers/{id}/notes - add note
// PUT /api/admin/notes/{id} - edit any note
// DELETE /api/admin/notes/{id} - delete any note
//
// Equipment
// POST /api/admin/equipment - add equipment to customer
// PUT /api/admin/equipment/{id} - update equipment
// DELETE /api/admin/equipment/{id} - deactivate equipment
//
// Equipment types
// GET /api/admin/equipment-types - list all types
// POST /api/admin/equipment-types - add a new type
// PUT /api/admin/equipment-types/{id} - update a type
//
// Service due report
// GET /api/admin/due - overdue + upcoming (default 30 days)

// Accepts either an HTML <input type="datetime-local"> value (Y-m-d\TH:i)
// or a MySQL-formatted datetime (Y-m-d H:i:s) and returns a DateTime, or null
// if neither format parses. Used by the tech-hours endpoints below.
function techHoursParseDt(string $s): ?\DateTime {
    $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $s);
    if ($dt) return $dt;
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $s);
    if ($dt) return $dt;
    $dt = \DateTime::createFromFormat('Y-m-d H:i', $s);
    return $dt ?: null;
}

function routeAdmin(string $method, string $resource, ?string $id, ?string $sub = null, ?string $subsub = null): void {

    $payload     = requireRole('admin');
    $adminUserId = (int)$payload['sub'];
    $db          = getDB();

    switch ($resource) {

 // USERS (staff management - technicians & office)
        case 'users':

 // GET list - filter to staff only (not customers) unless ?all=1
            if ($method === 'GET' && $id === null) {
                $roleFilter = $_GET['role'] ?? '';
                $showAll    = !empty($_GET['all']);
                $where  = $showAll ? '1=1' : "role IN ('technician','admin')";
                $params = [];
                if ($roleFilter && in_array($roleFilter, ['customer','technician','admin'])) {
                    $where  = 'role = ?';
                    $params = [$roleFilter];
                }
                $stmt = $db->prepare(
                    "SELECT user_id, email, role, is_field_tech, first_name, last_name,
                            phone, is_active, created_at, last_login
                     FROM users
                     WHERE $where
                     ORDER BY role, last_name, first_name, email"
                );
                $stmt->execute($params);
                sendJson($stmt->fetchAll());
            }

 // GET single user
            if ($method === 'GET' && $id !== null) {
                $stmt = $db->prepare(
                    "SELECT user_id, email, role, is_field_tech, first_name, last_name,
                            phone, is_active, created_at, last_login
                     FROM users WHERE user_id = ?"
                );
                $stmt->execute([$id]);
                $user = $stmt->fetch();
                if (!$user) sendError(404, 'User not found');
                sendJson($user);
            }

 // POST - create staff member
            if ($method === 'POST') {
                $body      = json_decode(file_get_contents('php://input'), true) ?? [];
                $email     = trim($body['email'] ?? '');
                $password  = $body['password'] ?? '';
                $role      = $body['role'] ?? 'technician';
                $firstName = trim($body['first_name'] ?? '');
                $lastName  = trim($body['last_name']  ?? '');
                $phone     = trim($body['phone']      ?? '');

                if (!$email || !$password) sendError(400, 'email and password are required');
                if (!in_array($role, ['technician', 'admin'])) sendError(400, 'Role must be technician or admin');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sendError(400, 'Invalid email address');
                if (strlen($password) < 8) sendError(400, 'Password must be at least 8 characters');

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $isFieldTech = isset($body['is_field_tech']) ? (int)(bool)$body['is_field_tech'] : ($role === 'technician' ? 1 : 0);
                try {
                    $db->prepare(
                        "INSERT INTO users (email, password_hash, role, is_field_tech, first_name, last_name, phone, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
                    )->execute([
                        $email, $hash, $role, $isFieldTech,
                        $firstName ?: null,
                        $lastName  ?: null,
                        $phone     ?: null,
                    ]);
                } catch (PDOException $e) {
                    sendError(409, 'That email address is already in use');
                }
                $newId = (int)$db->lastInsertId();
                $stmt  = $db->prepare(
                    "SELECT user_id, email, role, is_field_tech, first_name, last_name, phone, is_active FROM users WHERE user_id = ?"
                );
                $stmt->execute([$newId]);
                sendJson($stmt->fetch(), 201);
            }

 // PUT - update staff member
            if ($method === 'PUT' && $id !== null) {
 // Prevent admin from deactivating themselves
                if ((int)$id === $adminUserId && isset($body['is_active']) && !$body['is_active']) {
                    sendError(403, 'You cannot deactivate your own account');
                }

                $body   = json_decode(file_get_contents('php://input'), true) ?? [];
                $fields = [];
                $values = [];

                $allowed = ['first_name','last_name','phone','role','is_field_tech'];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $body)) {
                        if ($f === 'role' && !in_array($body[$f], ['technician','admin'])) {
                            sendError(400, 'Role must be technician or admin');
                        }
                        $fields[] = "$f = ?";
                        $values[] = $body[$f] === '' ? null : $body[$f];
                    }
                }
                if (!empty($body['email'])) {
                    if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) sendError(400, 'Invalid email');
                    $fields[] = 'email = ?';
                    $values[] = $body['email'];
                }
                if (!empty($body['password'])) {
                    if (strlen($body['password']) < 8) sendError(400, 'Password must be at least 8 characters');
                    $fields[] = 'password_hash = ?';
                    $values[] = password_hash($body['password'], PASSWORD_BCRYPT);
                }
                if (isset($body['is_active'])) {
                    $fields[] = 'is_active = ?';
                    $values[] = (int)(bool)$body['is_active'];
                }
                if (empty($fields)) sendError(400, 'Nothing to update');

                try {
                    $values[] = $id;
                    $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = ?")
                       ->execute($values);
                } catch (PDOException $e) {
                    sendError(409, 'That email address is already in use');
                }

                $stmt = $db->prepare(
                    "SELECT user_id, email, role, is_field_tech, first_name, last_name, phone, is_active FROM users WHERE user_id = ?"
                );
                $stmt->execute([$id]);
                sendJson($stmt->fetch());
            }

 // DELETE - deactivate (soft delete)
            if ($method === 'DELETE' && $id !== null) {
                if ((int)$id === $adminUserId) sendError(403, 'You cannot delete your own account');
                $db->prepare("UPDATE users SET is_active = 0 WHERE user_id = ?")->execute([$id]);
                sendJson(['message' => 'Staff member deactivated']);
            }

            sendError(405, 'Method not allowed');
            break;

 // CUSTOMERS
        case 'customers':

            if ($method === 'GET' && $id === null) {
                $search = trim($_GET['search'] ?? '');
                if ($search) {
                    $like = '%' . $search . '%';
                    $stmt = $db->prepare(
                        "SELECT c.customer_id, c.first_name, c.last_name, c.company_name, c.phone, c.phone2,
                                c.email, c.email2, c.service_address, c.service_city, c.service_state,
                                c.service_zip,
                                (SELECT COUNT(*) FROM equipment e WHERE e.customer_id = c.customer_id AND e.is_active=1) AS equipment_count
                         FROM customers c
                         WHERE c.first_name LIKE ?
                            OR c.last_name  LIKE ?
                            OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
                            OR c.company_name LIKE ?
                            OR c.phone      LIKE ?
                            OR c.email      LIKE ?
                         ORDER BY c.last_name, c.first_name
                         LIMIT 20"
                    );
                    $stmt->execute([$like, $like, $like, $like, $like, $like]);
                } else {
                    $stmt = $db->query(
                        "SELECT c.customer_id, c.first_name, c.last_name, c.company_name, c.phone, c.phone2,
                                c.email, c.email2, c.service_address, c.service_city, c.service_state,
                                c.service_zip,
                                (SELECT COUNT(*) FROM equipment e WHERE e.customer_id = c.customer_id AND e.is_active=1) AS equipment_count
                         FROM customers c
                         ORDER BY c.last_name, c.first_name"
                    );
                }
                sendJson($stmt->fetchAll());
            }

 // Sub-resource: /admin/customers/{id}/notes - intercept ALL methods
            if ($id !== null && $sub === 'notes') {
                require_once __DIR__ . '/notes.php';
                handleAdminNotes($db, $method, $id, 'notes', $adminUserId);
                exit;
            }

            if ($method === 'GET' && $id !== null) {
 // Sub-resource: /admin/customers/{id}/water-tests
                if ($sub === 'water-tests') {
 // GET list
                    $stmt = $db->prepare(
                        "SELECT test_id, label, test_date, is_current, created_at
                         FROM water_tests WHERE customer_id = ?
                         ORDER BY test_date DESC"
                    );
                    $stmt->execute([$id]);
                    sendJson($stmt->fetchAll());
                }

 // Sub-resource: /admin/customers/{id}/images
                if ($sub === 'images' && $method === 'GET') {
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
                    sendJson($rows);
                }

 // Sub-resource: /admin/customers/{id}/service-history
                if ($sub === 'service-history') {
 // Equipment service records
                    $stmt = $db->prepare(
                        "SELECT 'service_record' AS record_type,
                                sr.record_id, sr.service_date AS date, sr.service_type, sr.notes,
                                sr.next_service_due,
                                et.type_name,
                                sr.equipment_id,
                                CONCAT(u.first_name,' ',u.last_name) AS technician_name,
                                NULL AS appointment_id, NULL AS status, NULL AS service_type_name
                         FROM service_records sr
                         JOIN equipment e ON sr.equipment_id = e.equipment_id
                         JOIN equipment_types et ON e.type_id = et.type_id
                         LEFT JOIN users u ON sr.technician_id = u.user_id
                         WHERE e.customer_id = ?
                         ORDER BY sr.service_date DESC
                         LIMIT 100"
                    );
                    $stmt->execute([$id]);
                    $serviceRecords = $stmt->fetchAll();

 // Appointments
                    $stmt = $db->prepare(
                        "SELECT 'appointment' AS record_type,
                                a.appointment_id AS record_id,
                                COALESCE(a.confirmed_date, a.requested_date) AS date,
                                NULL AS service_type, a.office_notes AS notes,
                                NULL AS next_service_due,
                                st.name AS type_name,
                                CONCAT(u.first_name,' ',u.last_name) AS technician_name,
                                a.appointment_id, a.status, st.name AS service_type_name
                         FROM appointments a
                         JOIN service_types st ON a.service_type_id = st.type_id
                         LEFT JOIN users u ON a.technician_id = u.user_id
                         WHERE a.customer_id = ? AND a.status NOT IN ('cancelled')
                         ORDER BY COALESCE(a.confirmed_date, a.requested_date) DESC
                         LIMIT 100"
                    );
                    $stmt->execute([$id]);
                    $appointments = $stmt->fetchAll();

 // Merge and sort by date descending
                    $combined = array_merge($serviceRecords, $appointments);
                    usort($combined, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
                    sendJson($combined);
                    return;
                }

                $stmt = $db->prepare(
                    "SELECT customer_id, user_id, first_name, last_name, company_name, phone, phone2, email, email2,
                            service_address, service_city, service_state, service_zip,
                            billing_address, billing_city, billing_state, billing_zip, has_separate_billing,
                            qbo_override_customer_id,
                            notes, do_not_service, auto_service_reminder,
                            override_enabled, override_pin, created_at
                     FROM customers WHERE customer_id = ?"
                );
                $stmt->execute([$id]);
                $customer = $stmt->fetch();
                if (!$customer) sendError(404, 'Customer not found');

 // Attach equipment list
                $stmt = $db->prepare(
                    "SELECT e.equipment_id, e.type_id, et.type_name, et.category, e.model,
                            e.install_date,
                            COALESCE(e.service_interval_days, et.default_interval_days) AS service_interval_days,
                            e.last_service_date, e.next_service_due,
                            DATEDIFF(e.next_service_due, CURDATE()) AS days_until_due,
                            e.notes, e.is_active, e.self_service,
                            COALESCE(et.no_service_schedule, 0) AS no_service_schedule,
                            e.part_id, et.default_part_id,
                            COALESCE(e.part_id, et.default_part_id) AS effective_part_id,
                            ep.name AS part_name, ep.sell_price AS part_sell_price
                     FROM equipment e
                     JOIN equipment_types et ON e.type_id = et.type_id
                     LEFT JOIN parts_catalog ep ON ep.part_id = COALESCE(e.part_id, et.default_part_id)
                     WHERE e.customer_id = ?
                     ORDER BY e.next_service_due ASC"
                );
                $stmt->execute([$id]);
                $customer['equipment'] = $stmt->fetchAll();
                sendJson($customer);
            }

 // DELETE /admin/customers/{id}/water-tests/{testId}
            if ($method === 'DELETE' && $sub === 'water-tests' && $subsub !== null) {
                $db->prepare("DELETE FROM water_tests WHERE test_id = ? AND customer_id = ?")
                   ->execute([(int)$subsub, (int)$id]);
                sendJson(['message' => 'Water test deleted']);
            }

 // Create customer + user account in one call
            if ($method === 'POST') {
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
 // users.email is NOT NULL UNIQUE - use placeholder when no email given
                    $loginEmail = $email ?: ('noemail_' . strtolower($lastName) . '_' . strtolower($firstName) . '_' . uniqid() . '@noemail.local');
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'customer')")
                       ->execute([$loginEmail, $hash]);
                    $userId = (int)$db->lastInsertId();

 // Create customer profile - store real email (or null)
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

            if ($method === 'PUT' && $id !== null) {
                $body    = json_decode(file_get_contents('php://input'), true) ?? [];
                $allowed = ['first_name','last_name','company_name','phone','phone2','email','email2',
                            'service_address','service_city','service_state','service_zip',
                            'billing_address','billing_city','billing_state','billing_zip','has_separate_billing',
                            'qbo_override_customer_id',
                            'notes','do_not_service','auto_service_reminder',
                            'override_pin','override_enabled'];
                $fields  = [];
                $values  = [];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $body)) {
                        $fields[] = "$f = ?";
                        $values[] = $body[$f];
                    }
                }
                if (empty($fields)) sendError(400, 'Nothing to update');
                $values[] = $id;
                $db->prepare("UPDATE customers SET " . implode(', ', $fields) . " WHERE customer_id = ?")
                   ->execute($values);
                sendJson(['message' => 'Customer updated']);
            }

            sendError(405, 'Method not allowed');
            break;

 // EQUIPMENT
        case 'equipment':

            if ($method === 'POST') {
                $body       = json_decode(file_get_contents('php://input'), true) ?? [];
                $customerId = (int)($body['customer_id'] ?? 0);
                $typeId     = (int)($body['type_id']     ?? 0);

                if (!$customerId || !$typeId) sendError(400, 'customer_id and type_id are required');

 // Get default interval if not overriding
                $intervalDays = !empty($body['service_interval_days'])
                    ? (int)$body['service_interval_days']
                    : null;

                $installDate     = $body['install_date'] ?? null;
                $lastServiceDate = $body['last_service_date'] ?? null;
                $partId          = !empty($body['part_id']) ? (int)$body['part_id'] : null;

 // Calculate initial next_service_due
                $nextDue = null;
                if ($lastServiceDate) {
                    if ($intervalDays) {
                        $nextDue = date('Y-m-d', strtotime($lastServiceDate . " + $intervalDays days"));
                    } else {
 // Fetch default
                        $ts = $db->prepare("SELECT default_interval_days FROM equipment_types WHERE type_id = ?");
                        $ts->execute([$typeId]);
                        $defaultInterval = (int)$ts->fetchColumn();
                        $nextDue = date('Y-m-d', strtotime($lastServiceDate . " + $defaultInterval days"));
                    }
                }

                $db->prepare(
                    "INSERT INTO equipment
                     (customer_id, type_id, model, install_date, service_interval_days,
                      last_service_date, next_service_due, notes, part_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
                ]);

                sendJson([
                    'message'      => 'Equipment added',
                    'equipment_id' => (int)$db->lastInsertId(),
                ], 201);
            }

            if ($method === 'PUT' && $id !== null) {
                $body    = json_decode(file_get_contents('php://input'), true) ?? [];
                $allowed = ['type_id','model','install_date','service_interval_days',
                            'last_service_date','next_service_due','assigned_technician',
                            'notes','self_service','part_id'];
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

            if ($method === 'DELETE' && $id !== null) {
 // Hard delete: remove appointment links, then equipment
                $db->prepare("DELETE FROM appointment_equipment WHERE equipment_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM equipment WHERE equipment_id = ?")->execute([$id]);
                sendJson(['message' => 'Equipment deleted']);
            }

            sendError(405, 'Method not allowed');
            break;

 // EQUIPMENT TYPES
        case 'equipmenttypes':

            if ($method === 'GET') {
                $stmt = $db->query("SELECT * FROM equipment_types WHERE is_active = 1 ORDER BY COALESCE(is_tracked,1) DESC, COALESCE(no_service_schedule,0) ASC, category, type_name");
                sendJson($stmt->fetchAll());
            }

            if ($method === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                if (empty($body['type_name'])) sendError(400, 'type_name is required');
                $validCategories = ['water','air','water_treatment','air_quality'];
                if (!in_array($body['category'] ?? '', $validCategories)) sendError(400, 'Invalid category');
                $isTracked         = isset($body['is_tracked'])          ? (int)(bool)$body['is_tracked']          : 1;
                $noSvcSchedule     = isset($body['no_service_schedule']) ? (int)(bool)$body['no_service_schedule'] : 0;
 // No-schedule types don't need an interval
                $intervalDays  = ($isTracked && !$noSvcSchedule) ? (int)($body['default_interval_days'] ?? 365) : null;
                $defaultPartId = !empty($body['default_part_id']) ? (int)$body['default_part_id'] : null;
                $db->prepare(
                    "INSERT INTO equipment_types (type_name, default_interval_days, category, notes, is_tracked, show_to_customer, no_service_schedule, default_part_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $body['type_name'],
                    $intervalDays,
                    $body['category'],
                    $body['notes'] ?? null,
                    $isTracked,
                    isset($body['show_to_customer']) ? (int)(bool)$body['show_to_customer'] : $isTracked,
                    $noSvcSchedule,
                    $defaultPartId,
                ]);
                sendJson(['message' => 'Equipment type added', 'type_id' => (int)$db->lastInsertId()], 201);
            }

            if ($method === 'PUT' && $id !== null) {
                $body    = json_decode(file_get_contents('php://input'), true) ?? [];
                $allowed = ['type_name','default_interval_days','category','notes','is_active','is_tracked','show_to_customer','no_service_schedule','default_part_id'];
                $fields  = [];
                $values  = [];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $body)) {
                        $fields[] = "$f = ?";
                        if (in_array($f, ['is_active','is_tracked','show_to_customer','no_service_schedule'])) {
                            $values[] = (int)(bool)$body[$f];
                        } elseif ($f === 'default_part_id') {
                            $values[] = !empty($body[$f]) ? (int)$body[$f] : null;
                        } else {
                            $values[] = $body[$f];
                        }
                    }
                }
                if (empty($fields)) sendError(400, 'Nothing to update');
                $values[] = $id;
                $db->prepare("UPDATE equipment_types SET " . implode(', ', $fields) . " WHERE type_id = ?")
                   ->execute($values);
                sendJson(['message' => 'Equipment type updated']);
            }

            sendError(405, 'Method not allowed');
            break;

 // CATALOG (parts price list + service call types)
        case 'catalog':
            handleCatalog($db, $method, $id, $sub, 'admin');
            break;

 // INVOICES
        case 'invoices':
            handleInvoices($db, $method, $id, $sub, $subsub, $adminUserId, 'admin');
            break;

 // ESTIMATES
        case 'estimates':
            require_once __DIR__ . '/estimates.php';
            handleEstimates($db, $method, $id, $sub, $subsub, $adminUserId, 'admin');
            break;

 // SERVICE CONTRACTS
        case 'contracts':
            require_once __DIR__ . '/contracts.php';
            handleContracts($db, $method, $id, $sub, $subsub, $adminUserId, 'admin');
            break;

 // TAX RATES
        case 'taxrates':
            handleTaxRates($db, $method, $id);
            break;

 // APPOINTMENTS
        case 'appointments':
            handleAdminAppointments($db, $method, $id, $sub, $adminUserId);
            break;

 // CUSTOMER NOTES
        case 'notes':
            require_once __DIR__ . '/notes.php';
            handleAdminNotes($db, $method, $id, null, $adminUserId);
            exit;

 // SERVICE DUE REPORT
        case 'due':
            if ($method !== 'GET') sendError(405, 'Method not allowed');
            $days = min((int)($_GET['days'] ?? 30), 365);

            $stmt = $db->prepare(
                "SELECT e.equipment_id,
                        c.customer_id,
                        CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        c.company_name,
                        c.phone,
                        c.email,
                        c.service_address, c.service_city, c.service_state, c.service_zip,
                        et.type_name, et.category,
                        e.model,
                        e.last_service_date,
                        e.next_service_due,
                        COALESCE(e.next_service_due, DATE_ADD(e.last_service_date, INTERVAL 365 DAY)) AS computed_next_due,
                        DATEDIFF(COALESCE(e.next_service_due, DATE_ADD(e.last_service_date, INTERVAL 365 DAY)), CURDATE()) AS days_until_due
                 FROM equipment e
                 JOIN customers c ON e.customer_id = c.customer_id
                 JOIN equipment_types et ON e.type_id = et.type_id
                 WHERE e.is_active = 1
                   AND COALESCE(e.self_service, 0) = 0
                   AND c.do_not_service = 0
                   AND COALESCE(et.no_service_schedule, 0) = 0
                   AND e.last_service_date IS NOT NULL
                   AND COALESCE(e.next_service_due, DATE_ADD(e.last_service_date, INTERVAL 365 DAY)) <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                 ORDER BY computed_next_due ASC"
            );
            $stmt->execute([$days]);
            sendJson($stmt->fetchAll());
            break;

 // COMPANY SETTINGS
        case 'settings':
            require_once __DIR__ . '/settings.php';
            handleSettings($db, $method);
            break;

 // EMAIL - send invoice or receipt
 // POST /admin/invoices/{id}/send-email
 // POST /admin/invoices/{id}/send-receipt
 // (routed here via sub-resource check in index.php)
        case 'send-email':
        case 'send-receipt':
            require_once __DIR__ . '/email.php';
 // $id is the invoice_id, passed from index.php
            handleEmailSend($db, $method, (int)($id ?? 0), $resource === 'send-receipt' ? 'receipt' : 'invoice');
            break;

 // TECHNICIAN APPOINTMENT HISTORY
 // GET /admin/tech-history?tech_id=X&from=Y&to=Z
        case 'tech-history':
            if ($method !== 'GET') sendError(405, 'Method not allowed');
            $techId   = $_GET['tech_id'] ?? null;
            $from     = $_GET['from']    ?? date('Y-m-01');
            $to       = $_GET['to']      ?? date('Y-m-d');
            $where    = ["a.status IN ('completed','in_progress','confirmed')"];
            $params   = [];
            if ($techId) {
                $where[] = '(a.technician_id = ? OR EXISTS (SELECT 1 FROM appointment_technicians at2 WHERE at2.appointment_id = a.appointment_id AND at2.technician_id = ?))';
                $params[] = (int)$techId;
                $params[] = (int)$techId;
            }
 // Effective date: for completed jobs use completed_at (or updated_at fallback), else use confirmed_date
            $effectiveDate = "CASE
                WHEN a.status = 'completed' AND a.completed_at IS NOT NULL
                    THEN DATE(a.completed_at)
                WHEN a.status = 'completed'
                    THEN DATE(a.updated_at)
                ELSE a.confirmed_date
            END";
            $where[] = "($effectiveDate) >= ?"; $params[] = $from;
            $where[] = "($effectiveDate) <= ?"; $params[] = $to;
            $stmt = $db->prepare(
                "SELECT a.appointment_id, a.confirmed_date, a.confirmed_time, a.status,
                        a.completed_at, a.updated_at,
                        st.name AS service_type,
                        CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.company_name,
                        c.phone AS customer_phone,
                        c.service_address, c.service_city,
                        GROUP_CONCAT(DISTINCT CONCAT(u2.first_name,' ',u2.last_name) ORDER BY at2.role SEPARATOR ', ') AS technician_name,
                        CONCAT(u.first_name,' ',u.last_name) AS lead_technician_name,
                        u.user_id AS technician_id,
                        i.invoice_id, i.invoice_number, i.status AS invoice_status, i.total AS invoice_total
                 FROM appointments a
                 JOIN service_types st ON a.service_type_id = st.type_id
                 JOIN customers c ON a.customer_id = c.customer_id
                 LEFT JOIN users u ON a.technician_id = u.user_id
                 LEFT JOIN appointment_technicians at2 ON at2.appointment_id = a.appointment_id
                 LEFT JOIN users u2 ON at2.technician_id = u2.user_id
                 LEFT JOIN invoices i ON i.appointment_id = a.appointment_id AND i.status != 'void'
                 WHERE " . implode(' AND ', $where) . "
                 GROUP BY a.appointment_id
                 ORDER BY a.confirmed_date DESC, a.confirmed_time DESC"
            );
            $stmt->execute($params);
            sendJson($stmt->fetchAll());
            break;

 // TECHNICIAN HOURS (admin view/edit/add/delete clock-time)
 // GET /admin/tech-hours?week_start=YYYY-MM-DD&tech_id=X
 // POST /admin/tech-hours - manual entry
 // PUT /admin/tech-hours/{entry_id} - edit entry
 // DELETE /admin/tech-hours/{entry_id} - delete entry
 // GET /admin/tech-hours-audit?tech_id=X&entry_id=Y&limit=N
        case 'tech-hours':
 // GET list for a week (all techs or one tech)
            if ($method === 'GET' && $id === null) {
                $weekStart = $_GET['week_start'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
                    sendError(400, 'week_start (YYYY-MM-DD) is required');
                    return;
                }
                $weekEnd = (new \DateTime($weekStart))->modify('+6 days')->format('Y-m-d');
                $techId  = $_GET['tech_id'] ?? '';

                $where  = ['DATE(ct.clocked_in_at) BETWEEN ? AND ?'];
                $params = [$weekStart, $weekEnd];
                if ($techId !== '') {
                    $where[]  = 'ct.technician_id = ?';
                    $params[] = (int)$techId;
                }

                $stmt = $db->prepare(
                    "SELECT ct.entry_id, ct.technician_id, ct.clocked_in_at, ct.clocked_out_at,
                            ct.notes, ct.force_closed,
                            CONCAT(u.first_name,' ',u.last_name) AS technician_name,
                            CASE WHEN ct.clocked_out_at IS NOT NULL
                                 THEN ROUND(TIMESTAMPDIFF(SECOND, ct.clocked_in_at, ct.clocked_out_at) / 3600.0, 4)
                                 ELSE NULL END AS duration_hours
                     FROM tech_clock_time ct
                     JOIN users u ON u.user_id = ct.technician_id
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY u.last_name, u.first_name, ct.clocked_in_at ASC"
                );
                $stmt->execute($params);
                sendJson($stmt->fetchAll());
                return;
            }

 // POST - manual new entry
            if ($method === 'POST') {
                $body     = json_decode(file_get_contents('php://input'), true) ?? [];
                $techId   = (int)($body['technician_id'] ?? 0);
                $clockIn  = trim($body['clocked_in_at']  ?? '');
                $clockOut = trim($body['clocked_out_at'] ?? '');
                $notes    = trim($body['notes'] ?? '') ?: null;

                if (!$techId)  { sendError(400, 'technician_id is required'); return; }
                if (!$clockIn) { sendError(400, 'clocked_in_at is required'); return; }

                $chk = $db->prepare("SELECT user_id FROM users WHERE user_id = ? AND (role = 'technician' OR is_field_tech = 1)");
                $chk->execute([$techId]);
                if (!$chk->fetch()) { sendError(404, 'Technician not found'); return; }

                $inDt = techHoursParseDt($clockIn);
                if (!$inDt) { sendError(400, 'Invalid clocked_in_at'); return; }
                $outDt = null;
                if ($clockOut !== '') {
                    $outDt = techHoursParseDt($clockOut);
                    if (!$outDt) { sendError(400, 'Invalid clocked_out_at'); return; }
                    if ($outDt <= $inDt) { sendError(400, 'Clock-out must be after clock-in'); return; }
                }
                $inStr  = $inDt->format('Y-m-d H:i:s');
                $outStr = $outDt ? $outDt->format('Y-m-d H:i:s') : null;

                $ins = $db->prepare(
                    "INSERT INTO tech_clock_time (technician_id, clocked_in_at, clocked_out_at, notes, force_closed)
                     VALUES (?, ?, ?, ?, 0)"
                );
                $ins->execute([$techId, $inStr, $outStr, $notes]);
                $newEntryId = (int)$db->lastInsertId();

                $aud = $db->prepare(
                    "INSERT INTO tech_clock_time_audit
                        (entry_id, technician_id, action, changed_by, new_clocked_in_at, new_clocked_out_at, new_notes)
                     VALUES (?, ?, 'create', ?, ?, ?, ?)"
                );
                $aud->execute([$newEntryId, $techId, $adminUserId, $inStr, $outStr, $notes]);

                sendJson(['entry_id' => $newEntryId, 'success' => true]);
                return;
            }

 // PUT /admin/tech-hours/{id} - edit an entry
            if ($method === 'PUT' && $id !== null) {
                $entryId = (int)$id;
                $oldStmt = $db->prepare("SELECT * FROM tech_clock_time WHERE entry_id = ?");
                $oldStmt->execute([$entryId]);
                $oldRow = $oldStmt->fetch();
                if (!$oldRow) { sendError(404, 'Entry not found'); return; }

                $body     = json_decode(file_get_contents('php://input'), true) ?? [];
                $clockIn  = trim($body['clocked_in_at']  ?? '');
                $clockOut = trim($body['clocked_out_at'] ?? '');
                $notes    = array_key_exists('notes', $body) ? (trim((string)$body['notes']) ?: null) : $oldRow['notes'];

                if (!$clockIn) { sendError(400, 'clocked_in_at is required'); return; }

                $inDt = techHoursParseDt($clockIn);
                if (!$inDt) { sendError(400, 'Invalid clocked_in_at'); return; }
                $outDt = null;
                if ($clockOut !== '') {
                    $outDt = techHoursParseDt($clockOut);
                    if (!$outDt) { sendError(400, 'Invalid clocked_out_at'); return; }
                    if ($outDt <= $inDt) { sendError(400, 'Clock-out must be after clock-in'); return; }
                }
                $inStr  = $inDt->format('Y-m-d H:i:s');
                $outStr = $outDt ? $outDt->format('Y-m-d H:i:s') : null;

                $upd = $db->prepare(
                    "UPDATE tech_clock_time
                     SET clocked_in_at = ?, clocked_out_at = ?, notes = ?, force_closed = 0
                     WHERE entry_id = ?"
                );
                $upd->execute([$inStr, $outStr, $notes, $entryId]);

                $aud = $db->prepare(
                    "INSERT INTO tech_clock_time_audit
                        (entry_id, technician_id, action, changed_by,
                         old_clocked_in_at, old_clocked_out_at, old_notes,
                         new_clocked_in_at, new_clocked_out_at, new_notes)
                     VALUES (?, ?, 'update', ?, ?, ?, ?, ?, ?, ?)"
                );
                $aud->execute([
                    $entryId, $oldRow['technician_id'], $adminUserId,
                    $oldRow['clocked_in_at'], $oldRow['clocked_out_at'], $oldRow['notes'],
                    $inStr, $outStr, $notes,
                ]);

                sendJson(['success' => true]);
                return;
            }

 // DELETE /admin/tech-hours/{id}
            if ($method === 'DELETE' && $id !== null) {
                $entryId = (int)$id;
                $oldStmt = $db->prepare("SELECT * FROM tech_clock_time WHERE entry_id = ?");
                $oldStmt->execute([$entryId]);
                $oldRow = $oldStmt->fetch();
                if (!$oldRow) { sendError(404, 'Entry not found'); return; }

                $aud = $db->prepare(
                    "INSERT INTO tech_clock_time_audit
                        (entry_id, technician_id, action, changed_by,
                         old_clocked_in_at, old_clocked_out_at, old_notes)
                     VALUES (?, ?, 'delete', ?, ?, ?, ?)"
                );
                $aud->execute([
                    $entryId, $oldRow['technician_id'], $adminUserId,
                    $oldRow['clocked_in_at'], $oldRow['clocked_out_at'], $oldRow['notes'],
                ]);

                $del = $db->prepare("DELETE FROM tech_clock_time WHERE entry_id = ?");
                $del->execute([$entryId]);

                sendJson(['success' => true]);
                return;
            }

            sendError(405, 'Method not allowed');
            break;

 // GET /admin/tech-hours-audit?tech_id=X&entry_id=Y&limit=N
        case 'tech-hours-audit':
            if ($method !== 'GET') { sendError(405, 'Method not allowed'); return; }

            $techId  = $_GET['tech_id']  ?? '';
            $entryId = $_GET['entry_id'] ?? '';
            $limit   = min((int)($_GET['limit'] ?? 25), 200);

            $where  = ['1=1'];
            $params = [];
            if ($techId !== '')  { $where[] = 'a.technician_id = ?'; $params[] = (int)$techId; }
            if ($entryId !== '') { $where[] = 'a.entry_id = ?';      $params[] = (int)$entryId; }

            $stmt = $db->prepare(
                "SELECT a.audit_id, a.entry_id, a.technician_id, a.action,
                        a.old_clocked_in_at, a.old_clocked_out_at, a.old_notes,
                        a.new_clocked_in_at, a.new_clocked_out_at, a.new_notes,
                        a.changed_at,
                        CONCAT(u.first_name,' ',u.last_name) AS technician_name,
                        CONCAT(cu.first_name,' ',cu.last_name) AS changed_by_name
                 FROM tech_clock_time_audit a
                 JOIN users u  ON u.user_id  = a.technician_id
                 JOIN users cu ON cu.user_id = a.changed_by
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY a.changed_at DESC
                 LIMIT $limit"
            );
            $stmt->execute($params);
            sendJson($stmt->fetchAll());
            return;

 // SERVICE TYPES (for appointment dropdown)
 // GET /admin/service-types
        case 'service-types':
            if ($method === 'GET') {
                $stmt = $db->query(
                    "SELECT type_id, name, min_days_out, default_price, price_overridable,
                            COALESCE(is_salt_delivery, 0) AS is_salt_delivery,
                            COALESCE(min_bags_required, 0) AS min_bags_required
                     FROM service_types WHERE is_active = 1 ORDER BY name"
                );
                sendJson($stmt->fetchAll());
            }
            sendError(405, 'Method not allowed');
            break;

        case 'qbo':
 // Handled in index.php before routeAdmin is called - should never reach here
 // but add break to prevent 404
            break;

 // WEEKLY SERVICE REPORT
 // GET /admin/weekly-report?from=YYYY-MM-DD&to=YYYY-MM-DD
 // POST /admin/weekly-report/note { appointment_id, note }
        case 'weekly-report':

 // Save office note for a single appointment
            if ($method === 'POST' && $sub === 'note') {
                $body   = json_decode(file_get_contents('php://input'), true) ?? [];
                $apptId = (int)($body['appointment_id'] ?? 0);
                $note   = trim($body['note'] ?? '');
                if (!$apptId) sendError(400, 'appointment_id required');
                $db->prepare("UPDATE appointments SET office_notes = ? WHERE appointment_id = ?")
                   ->execute([$note ?: null, $apptId]);
                sendJson(['message' => 'Note saved']);
                return;
            }

            if ($method !== 'GET') sendError(405, 'Method not allowed');

 // Default to current Mon-Sun
            $today  = new \DateTime('today');
            $dow    = (int)$today->format('N');
            $monday = (clone $today)->modify('-' . ($dow - 1) . ' days');
            $sunday = (clone $monday)->modify('+6 days');

            $from = $_GET['from'] ?? $monday->format('Y-m-d');
            $to   = $_GET['to']   ?? $sunday->format('Y-m-d');

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
                    c.company_name,
                    c.phone AS customer_phone,
                    c.service_address, c.service_city,
                    COALESCE(
                        GROUP_CONCAT(DISTINCT CONCAT(u2.first_name,' ',u2.last_name)
                                     ORDER BY at2.role SEPARATOR ', '),
                        CONCAT(u.first_name,' ',u.last_name)
                    ) AS technician_name,
                    i.invoice_id,
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
            $appointments = $apptStmt->fetchAll();

 // Money collected this period
            $collStmt = $db->prepare(
                "SELECT COALESCE(SUM(p.amount), 0) AS total_collected,
                        COUNT(DISTINCT p.payment_id) AS payment_count
                 FROM payments p
                 WHERE p.payment_date BETWEEN ? AND ?"
            );
            $collStmt->execute([$from, $to]);
            $collected = $collStmt->fetch();

 // Outstanding invoices (period + up to 8 weeks back)
            $overdueFrom = (new \DateTime($from))->modify('-56 days')->format('Y-m-d');
            $ovStmt = $db->prepare(
                "SELECT
                    i.invoice_id, i.invoice_number, i.status,
                    i.total, i.issue_date,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.company_name,
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
            $overdue = $ovStmt->fetchAll();

            sendJson([
                'from'         => $from,
                'to'           => $to,
                'appointments' => $appointments,
                'collected'    => $collected,
                'overdue'      => $overdue,
            ]);
            return;

  // PUSH - send test notification to a customer
  // POST /admin/push/send { customer_id, title, body }
        case 'push':
            if ($method !== 'POST' || $id !== 'send') {
                sendError(405, 'Method not allowed');
            }

            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $customerId = (int)($body['customer_id'] ?? 0);
            $title      = trim($body['title'] ?? '');
            $bodyText   = trim($body['body'] ?? '');

            if (!$customerId || !$title || !$bodyText) {
                sendError(400, 'customer_id, title, and body are required');
            }

            $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            if (!$stmt->fetch()) {
                sendError(404, 'Customer not found');
            }

            require_once __DIR__ . '/push.php';
            $notificationId = sendPendingNotification($db, $customerId, $title, $bodyText);

  // Also attempt a live web push if the customer has subscriptions
            $pushResult = sendPushToCustomer($db, $customerId, $title, $bodyText, '/', 'admin_test');

            sendJson([
                'message'             => 'Test notification sent',
                'pending_id'          => $notificationId,
                'push_attempted'      => $pushResult['attempted'],
                'push_success'        => $pushResult['success'],
                'push_failures'       => $pushResult['failures'],
                'push_subscriptions_pruned' => $pushResult['pruned'],
            ], 201);
            break;

  // IMAGES
  // DELETE /admin/images/{id} - delete a customer image
        case 'images':
            if ($method !== 'DELETE') sendError(405, 'Method not allowed');
            if (!$id) sendError(400, 'image_id required');

            $stmt = $db->prepare("SELECT filename, customer_id FROM customer_images WHERE image_id = ?");
            $stmt->execute([$id]);
            $img = $stmt->fetch();
            if (!$img) sendError(404, 'Image not found');

 // Delete physical file
            $filePath = __DIR__ . '/../../customer_images/' . basename($img['filename']);
            if (file_exists($filePath)) @unlink($filePath);

            $db->prepare("DELETE FROM customer_images WHERE image_id = ?")->execute([$id]);
            sendJson(['message' => 'Image deleted']);
            break;

        default:
            sendError(404, 'Not found');
    }
}
