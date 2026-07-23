<?php
// IoT Sensor Telemetry and Device Management
//
// Public (device auth via API key in X-Device-Key header or body):
// POST /api/iot/telemetry - upload readings from ESP32
// POST /api/iot/register  - device self-registration
//
// Admin:
// GET  /admin/iot/devices              - list all
// POST /admin/iot/devices              - provision
// GET  /admin/iot/devices/{id}          - detail + alerts
// PUT  /admin/iot/devices/{id}          - update
// DELETE /admin/iot/devices/{id}        - remove
// GET  /admin/iot/devices/{id}/readings - history
// GET  /admin/iot/devices/{id}/readings/latest - latest per metric
// GET  /admin/iot/devices/{id}/alerts   - alert log
// POST /admin/iot/devices/{id}/alerts/acknowledge - ack alerts
// POST /admin/iot/devices/{id}/alert-rules - add rule
// PUT  /admin/iot/devices/{id}/alert-rules/{ruleId} - update rule
// DELETE /admin/iot/devices/{id}/alert-rules/{ruleId} - delete rule
// GET  /admin/iot/device-types         - catalog
// GET  /admin/iot/dashboard            - overview
//
// Tech:
// GET  /tech/iot/devices               - assigned customers' devices
// GET  /tech/iot/devices/{id}          - detail (read-only)
// GET  /tech/iot/devices/{id}/readings - history
//
// Customer:
// GET  /customer/iot/devices           - own devices
// GET  /customer/iot/devices/{id}      - detail
// GET  /customer/iot/devices/{id}/readings - readings
// POST /customer/iot/devices/{id}/alerts/acknowledge - ack alerts

require_once __DIR__ . '/push.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/settings.php';

function generateDeviceKey(): string {
    return bin2hex(random_bytes(32));
}

function authenticateDevice(PDO $db, string $deviceKey): ?array {
    $stmt = $db->prepare(
        "SELECT d.*, dt.type_slug, dt.type_name, dt.metrics AS device_type_metrics
         FROM iot_devices d
         JOIN iot_device_types dt ON d.type_id = dt.type_id
         WHERE d.device_key = ? AND d.status = 'active'"
    );
    $stmt->execute([$deviceKey]);
    $device = $stmt->fetch();
    return $device ?: null;
}

function evaluateAlertRules(PDO $db, array $device, string $metricName, float $value): void {
    $stmt = $db->prepare(
        "SELECT * FROM iot_alert_rules
         WHERE device_id = ? AND metric_name = ? AND enabled = 1"
    );
    $stmt->execute([$device['device_id'], $metricName]);
    $rules = $stmt->fetchAll();

    foreach ($rules as $rule) {
        $triggered = false;
        $thresholdVal = (float)$rule['threshold_value'];

        switch ($rule['condition']) {
            case 'below':           $triggered = $value < $thresholdVal; break;
            case 'above':           $triggered = $value > $thresholdVal; break;
            case 'equals':          $triggered = $value == $thresholdVal; break;
            case 'below_or_equals': $triggered = $value <= $thresholdVal; break;
            case 'above_or_equals': $triggered = $value >= $thresholdVal; break;
        }

        if (!$triggered) continue;

        $cooldown = (int)($rule['cooldown_minutes'] ?? 60);
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) FROM iot_alert_log
             WHERE rule_id = ? AND triggered_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt2->execute([$rule['rule_id'], $cooldown]);
        if ((int)$stmt2->fetchColumn() > 0) continue;

        $message = sprintf(
            '%s: %s is %s %s (threshold: %s)',
            $device['device_name'],
            $metricName,
            $rule['condition'],
            $value,
            $thresholdVal
        );

        $db->prepare(
            "INSERT INTO iot_alert_log
             (device_id, rule_id, customer_id, metric_name, triggered_value, threshold_value, condition, message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $device['device_id'], $rule['rule_id'], $device['customer_id'],
            $metricName, $value, $thresholdVal, $rule['condition'], $message
        ]);

        $alertId = (int)$db->lastInsertId();

        $channels = json_decode($rule['notification_channels'] ?? '["push"]', true);
        if (in_array('push', $channels)) {
            try {
                sendPushToCustomer(
                    $db,
                    (int)$device['customer_id'],
                    'Sensor Alert: ' . $device['device_name'],
                    $message,
                    '/?page=iot',
                    'iot_alert',
                    ['device_id' => $device['device_id'], 'alert_id' => $alertId]
                );
                $db->prepare("UPDATE iot_alert_log SET notification_sent = 1 WHERE alert_id = ?")
                   ->execute([$alertId]);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        if (in_array('email', $channels)) {
            try {
                $settings = getCompanySettings($db);
                $custStmt = $db->prepare("SELECT first_name, last_name, email FROM customers WHERE customer_id = ?");
                $custStmt->execute([$device['customer_id']]);
                $cust = $custStmt->fetch();
                if ($cust && $cust['email']) {
                    $html = "<p>{$message}</p><p>Device: {$device['device_name']}<br>Customer: {$cust['first_name']} {$cust['last_name']}</p>";
                    dispatchEmail($db, $settings, $cust['email'], "{$cust['first_name']} {$cust['last_name']}",
                        "Sensor Alert: {$device['device_name']}", $html, 'iot_alert', null, null);
                    $db->prepare("UPDATE iot_alert_log SET notification_sent = 1 WHERE alert_id = ?")
                       ->execute([$alertId]);
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
    }
}

function updateDeviceStatus(PDO $db, int $deviceId, array $latestReadings): void {
    $summary = [];
    foreach ($latestReadings as $r) {
        $summary[$r['metric_name']] = [
            'value' => (float)$r['value'],
            'unit' => $r['unit'],
            'recorded_at' => $r['recorded_at']
        ];
    }

    $db->prepare(
        "UPDATE iot_devices
         SET last_seen = NOW(), last_reading_summary = ?, status = 'active'
         WHERE device_id = ?"
    )->execute([json_encode($summary), $deviceId]);
}

// PUBLIC TELEMETRY
function handleIotTelemetry(PDO $db, string $method): void {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceKey = $body['device_key'] ?? ($_SERVER['HTTP_X_DEVICE_KEY'] ?? '');

    if (!$deviceKey) {
        http_response_code(401);
        echo json_encode(['error' => 'device_key is required']);
        return;
    }

    $device = authenticateDevice($db, $deviceKey);
    if (!$device) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or inactive device key']);
        return;
    }

    $readings = $body['readings'] ?? [];
    if (!is_array($readings) || empty($readings)) {
        http_response_code(400);
        echo json_encode(['error' => 'readings array is required']);
        return;
    }

    $accepted = 0;
    $rejected = 0;
    $latestReadings = [];

    foreach ($readings as $r) {
        $metricName = trim($r['metric'] ?? $r['metric_name'] ?? '');
        $value = isset($r['value']) ? (float)$r['value'] : null;
        $unit = trim($r['unit'] ?? '');
        $recordedAt = $r['recorded_at'] ?? $r['timestamp'] ?? date('Y-m-d H:i:s');

        if (!$metricName || $value === null) {
            $rejected++;
            continue;
        }

        if (is_numeric($recordedAt)) {
            $recordedAt = date('Y-m-d H:i:s', (int)$recordedAt);
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $recordedAt)) {
            $parsed = strtotime($recordedAt);
            $recordedAt = $parsed ? date('Y-m-d H:i:s', $parsed) : date('Y-m-d H:i:s');
        }

        $metadata = !empty($r['metadata']) ? json_encode($r['metadata']) : null;

        $db->prepare(
            "INSERT INTO iot_readings
             (device_id, customer_id, metric_name, value, unit, recorded_at, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $device['device_id'], $device['customer_id'],
            $metricName, $value, $unit, $recordedAt, $metadata
        ]);

        $accepted++;
        $latestReadings[] = [
            'metric_name' => $metricName,
            'value' => $value,
            'unit' => $unit,
            'recorded_at' => $recordedAt
        ];

        evaluateAlertRules($db, $device, $metricName, $value);
    }

    if (!empty($latestReadings)) {
        updateDeviceStatus($db, $device['device_id'], $latestReadings);
    }

    if (!empty($body['firmware_version'])) {
        $db->prepare("UPDATE iot_devices SET firmware_version = ? WHERE device_id = ?")
           ->execute([$body['firmware_version'], $device['device_id']]);
    }

    echo json_encode([
        'accepted' => $accepted,
        'rejected' => $rejected,
        'total'    => count($readings),
    ]);
}

// DEVICE REGISTRATION
function handleIotRegister(PDO $db, string $method): void {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $typeSlug = trim($body['type_slug'] ?? '');
    $deviceName = trim($body['device_name'] ?? '');
    $macAddress = trim($body['mac_address'] ?? '');

    if (!$typeSlug) {
        http_response_code(400);
        echo json_encode(['error' => 'type_slug is required']);
        return;
    }

    $stmt = $db->prepare("SELECT * FROM iot_device_types WHERE type_slug = ? AND is_active = 1");
    $stmt->execute([$typeSlug]);
    $deviceType = $stmt->fetch();
    if (!$deviceType) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown device type']);
        return;
    }

    $deviceKey = generateDeviceKey();

    $db->prepare(
        "INSERT INTO iot_devices
         (customer_id, equipment_id, type_id, device_name, device_key, mac_address, status)
         VALUES (NULL, ?, ?, ?, ?, ?, 'inactive')"
    )->execute([
        !empty($body['equipment_id']) ? (int)$body['equipment_id'] : null,
        $deviceType['type_id'],
        $deviceName ?: $deviceType['type_name'] . ' (unregistered)',
        $deviceKey,
        $macAddress ?: null
    ]);

    $deviceId = (int)$db->lastInsertId();

    echo json_encode([
        'message'    => 'Device registered. Link to a customer via admin panel.',
        'device_id'  => $deviceId,
        'device_key' => $deviceKey,
    ], 201);
}

// ADMIN/TECH HANDLER
function handleAdminIotDevices(
    PDO $db, string $method, ?string $id, ?string $sub, ?string $subsub,
    int $userId, string $role
): void {

    if ($id === 'device-types' && $method === 'GET') {
        $stmt = $db->query("SELECT * FROM iot_device_types WHERE is_active = 1 ORDER BY type_name");
        sendJson($stmt->fetchAll());
        return;
    }

    if ($id === 'dashboard' && $method === 'GET') {
        $stmt = $db->query(
            "SELECT status, COUNT(*) as count FROM iot_devices GROUP BY status"
        );
        $statusCounts = [];
        foreach ($stmt->fetchAll() as $row) {
            $statusCounts[$row['status']] = (int)$row['count'];
        }

        $stmt = $db->query(
            "SELECT d.device_id, d.device_name, d.last_seen,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.phone, dt.type_name
             FROM iot_devices d
             JOIN customers c ON d.customer_id = c.customer_id
             JOIN iot_device_types dt ON d.type_id = dt.type_id
             WHERE d.status = 'active'
               AND (d.last_seen IS NULL OR d.last_seen < DATE_SUB(NOW(), INTERVAL 24 HOUR))
             ORDER BY d.last_seen ASC"
        );
        $offlineDevices = $stmt->fetchAll();

        $stmt = $db->query(
            "SELECT al.*, d.device_name, d.device_key,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name
             FROM iot_alert_log al
             JOIN iot_devices d ON al.device_id = d.device_id
             JOIN customers c ON d.customer_id = c.customer_id
             WHERE al.acknowledged_at IS NULL
               AND al.triggered_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY al.triggered_at DESC
             LIMIT 20"
        );
        $recentAlerts = $stmt->fetchAll();

        $stmt = $db->query(
            "SELECT d.device_id, d.device_name, d.last_reading_summary,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    dt.type_name
             FROM iot_devices d
             JOIN customers c ON d.customer_id = c.customer_id
             JOIN iot_device_types dt ON d.type_id = dt.type_id
             WHERE d.status = 'active'
               AND d.last_reading_summary IS NOT NULL
             ORDER BY d.last_seen DESC
             LIMIT 50"
        );
        $devicesWithReadings = $stmt->fetchAll();

        sendJson([
            'status_counts'    => $statusCounts,
            'offline_devices'  => $offlineDevices,
            'recent_alerts'    => $recentAlerts,
            'devices_readings' => $devicesWithReadings,
        ]);
        return;
    }

    if ($id === null && $method === 'GET') {
        $customerId = $_GET['customer_id'] ?? null;
        $status     = $_GET['status'] ?? null;
        $typeId     = $_GET['type_id'] ?? null;

        $where  = ['1=1'];
        $params = [];
        if ($customerId) { $where[] = 'd.customer_id = ?'; $params[] = $customerId; }
        if ($status)     { $where[] = 'd.status = ?';      $params[] = $status; }
        if ($typeId)     { $where[] = 'd.type_id = ?';     $params[] = $typeId; }

        $stmt = $db->prepare(
            "SELECT d.device_id, d.device_name, d.status, d.last_seen,
                    d.mac_address, d.firmware_version, d.created_at,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.company_name, c.phone,
                    dt.type_slug, dt.type_name,
                    e.model AS equipment_model, et.type_name AS equipment_type_name
             FROM iot_devices d
             JOIN customers c ON d.customer_id = c.customer_id
             JOIN iot_device_types dt ON d.type_id = dt.type_id
             LEFT JOIN equipment e ON d.equipment_id = e.equipment_id
             LEFT JOIN equipment_types et ON e.type_id = et.type_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY d.last_seen DESC"
        );
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
    }

    if ($id === null && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $customerId   = (int)($body['customer_id'] ?? 0);
        $equipmentId  = !empty($body['equipment_id']) ? (int)$body['equipment_id'] : null;
        $typeId       = (int)($body['type_id'] ?? 0);
        $deviceName   = trim($body['device_name'] ?? '');
        $macAddress   = trim($body['mac_address'] ?? '');
        $notes        = trim($body['notes'] ?? '');

        if (!$customerId) sendError(400, 'customer_id is required');
        if (!$typeId)     sendError(400, 'type_id is required');

        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) sendError(404, 'Customer not found');

        $stmt = $db->prepare("SELECT type_id FROM iot_device_types WHERE type_id = ? AND is_active = 1");
        $stmt->execute([$typeId]);
        if (!$stmt->fetch()) sendError(404, 'Device type not found');

        $deviceKey = generateDeviceKey();

        $db->prepare(
            "INSERT INTO iot_devices
             (customer_id, equipment_id, type_id, device_name, device_key, mac_address, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
        )->execute([
            $customerId, $equipmentId, $typeId,
            $deviceName ?: 'IoT Device',
            $deviceKey, $macAddress ?: null, $notes ?: null
        ]);

        $deviceId = (int)$db->lastInsertId();

        sendJson([
            'message'    => 'Device provisioned',
            'device_id'  => $deviceId,
            'device_key' => $deviceKey,
        ], 201);
    }

    if ($id !== null) {
        $deviceId = (int)$id;

        if ($method === 'GET' && $sub === null) {
            $stmt = $db->prepare(
                "SELECT d.*,
                        CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                        c.company_name, c.phone, c.email,
                        c.service_address, c.service_city, c.service_state, c.service_zip,
                        dt.type_slug, dt.type_name, dt.metrics AS device_type_metrics,
                        e.model AS equipment_model, et.type_name AS equipment_type_name
                 FROM iot_devices d
                 JOIN customers c ON d.customer_id = c.customer_id
                 JOIN iot_device_types dt ON d.type_id = dt.type_id
                 LEFT JOIN equipment e ON d.equipment_id = e.equipment_id
                 LEFT JOIN equipment_types et ON e.type_id = et.type_id
                 WHERE d.device_id = ?"
            );
            $stmt->execute([$deviceId]);
            $device = $stmt->fetch();
            if (!$device) sendError(404, 'Device not found');

            $stmt2 = $db->prepare(
                "SELECT * FROM iot_alert_rules WHERE device_id = ? ORDER BY metric_name, rule_name"
            );
            $stmt2->execute([$deviceId]);
            $device['alert_rules'] = $stmt2->fetchAll();

            $stmt3 = $db->prepare(
                "SELECT * FROM iot_alert_log WHERE device_id = ? AND acknowledged_at IS NULL ORDER BY triggered_at DESC LIMIT 10"
            );
            $stmt3->execute([$deviceId]);
            $device['open_alerts'] = $stmt3->fetchAll();

            sendJson($device);
        }

        if ($method === 'PUT' && $sub === null) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = ['customer_id','equipment_id','device_name','mac_address','status','notes'];
            $fields = [];
            $values = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    $values[] = $body[$f] === '' ? null : $body[$f];
                }
            }
            if (!empty($fields)) {
                $values[] = $deviceId;
                $db->prepare("UPDATE iot_devices SET " . implode(', ', $fields) . " WHERE device_id = ?")
                   ->execute($values);
            }

            $stmt = $db->prepare("SELECT * FROM iot_devices WHERE device_id = ?");
            $stmt->execute([$deviceId]);
            sendJson($stmt->fetch());
        }

        if ($method === 'DELETE' && $sub === null) {
            $db->prepare("DELETE FROM iot_alert_rules WHERE device_id = ?")->execute([$deviceId]);
            $db->prepare("DELETE FROM iot_alert_log WHERE device_id = ?")->execute([$deviceId]);
            $db->prepare("DELETE FROM iot_readings WHERE device_id = ?")->execute([$deviceId]);
            $db->prepare("DELETE FROM iot_devices WHERE device_id = ?")->execute([$deviceId]);
            sendJson(['message' => 'Device and all associated data deleted']);
        }

        if ($sub === 'readings' && $method === 'GET') {
            $metricName = $_GET['metric'] ?? null;
            $fromDate   = $_GET['from'] ?? null;
            $toDate     = $_GET['to'] ?? null;
            $limit      = min((int)($_GET['limit'] ?? 100), 1000);

            $where  = ['r.device_id = ?'];
            $params = [$deviceId];
            if ($metricName) { $where[] = 'r.metric_name = ?'; $params[] = $metricName; }
            if ($fromDate)   { $where[] = 'r.recorded_at >= ?'; $params[] = $fromDate; }
            if ($toDate)     { $where[] = 'r.recorded_at <= ?'; $params[] = $toDate; }

            $stmt = $db->prepare(
                "SELECT r.reading_id, r.metric_name, r.value, r.unit,
                        r.recorded_at, r.server_received_at, r.metadata
                 FROM iot_readings r
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY r.recorded_at DESC
                 LIMIT $limit"
            );
            $stmt->execute($params);
            sendJson($stmt->fetchAll());
        }

        if ($sub === 'readings' && $subsub === 'latest' && $method === 'GET') {
            $stmt = $db->prepare(
                "SELECT r1.metric_name, r1.value, r1.unit, r1.recorded_at, r1.metadata
                 FROM iot_readings r1
                 INNER JOIN (
                     SELECT metric_name, MAX(recorded_at) AS max_time
                     FROM iot_readings
                     WHERE device_id = ?
                     GROUP BY metric_name
                 ) r2 ON r1.metric_name = r2.metric_name AND r1.recorded_at = r2.max_time
                 WHERE r1.device_id = ?
                 ORDER BY r1.metric_name"
            );
            $stmt->execute([$deviceId, $deviceId]);
            sendJson($stmt->fetchAll());
        }

        if ($sub === 'alerts' && $method === 'GET') {
            $limit = min((int)($_GET['limit'] ?? 50), 200);
            $showAll = !empty($_GET['all']);

            $where = ['al.device_id = ?'];
            $params = [$deviceId];
            if (!$showAll) {
                $where[] = 'al.acknowledged_at IS NULL';
            }

            $stmt = $db->prepare(
                "SELECT al.*, r.rule_name, r.condition AS rule_condition, r.threshold_value AS rule_threshold
                 FROM iot_alert_log al
                 LEFT JOIN iot_alert_rules r ON al.rule_id = r.rule_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY al.triggered_at DESC
                 LIMIT $limit"
            );
            $stmt->execute($params);
            sendJson($stmt->fetchAll());
        }

        if ($sub === 'alerts' && $subsub === 'acknowledge' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $alertId = !empty($body['alert_id']) ? (int)$body['alert_id'] : null;

            if ($alertId) {
                $db->prepare(
                    "UPDATE iot_alert_log SET acknowledged_at = NOW(), acknowledged_by = ?
                     WHERE alert_id = ? AND device_id = ?"
                )->execute([$userId, $alertId, $deviceId]);
            } else {
                $db->prepare(
                    "UPDATE iot_alert_log SET acknowledged_at = NOW(), acknowledged_by = ?
                     WHERE device_id = ? AND acknowledged_at IS NULL"
                )->execute([$userId, $deviceId]);
            }
            sendJson(['message' => 'Alert(s) acknowledged']);
        }

        if ($sub === 'ping' && $method === 'POST') {
            $db->prepare(
                "UPDATE iot_devices SET last_seen = NOW(), status = 'active'
                 WHERE device_id = ?"
            )->execute([$deviceId]);
            sendJson(['message' => 'Device ping recorded']);
        }

        if ($sub === 'alert-rules' && $method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $metricName = trim($body['metric_name'] ?? '');
            $ruleName   = trim($body['rule_name'] ?? '');
            $condition  = $body['condition'] ?? 'below';
            $threshold  = isset($body['threshold_value']) ? (float)$body['threshold_value'] : null;
            $enabled    = isset($body['enabled']) ? (int)(bool)$body['enabled'] : 1;
            $cooldown   = (int)($body['cooldown_minutes'] ?? 60);
            $channels   = $body['notification_channels'] ?? ['push'];

            if (!$metricName) sendError(400, 'metric_name is required');
            if (!$ruleName)   sendError(400, 'rule_name is required');
            if ($threshold === null) sendError(400, 'threshold_value is required');

            $validConditions = ['below','above','equals','below_or_equals','above_or_equals'];
            if (!in_array($condition, $validConditions)) sendError(400, 'Invalid condition');

            $db->prepare(
                "INSERT INTO iot_alert_rules
                 (device_id, metric_name, rule_name, condition, threshold_value, enabled, cooldown_minutes, notification_channels)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $deviceId, $metricName, $ruleName, $condition,
                $threshold, $enabled, $cooldown, json_encode($channels)
            ]);

            sendJson(['message' => 'Alert rule created', 'rule_id' => (int)$db->lastInsertId()], 201);
        }

        if ($sub === 'alert-rules' && $subsub !== null && $method === 'PUT') {
            $ruleId = (int)$subsub;
            $body = json_decode(file_get_contents('php://input'), true) ?? [];

            $stmt = $db->prepare(
                "SELECT rule_id FROM iot_alert_rules WHERE rule_id = ? AND device_id = ?"
            );
            $stmt->execute([$ruleId, $deviceId]);
            if (!$stmt->fetch()) sendError(404, 'Alert rule not found');

            $allowed = ['rule_name','condition','threshold_value','enabled','cooldown_minutes','notification_channels'];
            $fields = [];
            $values = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    if ($f === 'notification_channels') {
                        $values[] = json_encode($body[$f]);
                    } elseif (in_array($f, ['enabled', 'cooldown_minutes'])) {
                        $values[] = (int)$body[$f];
                    } elseif ($f === 'threshold_value') {
                        $values[] = (float)$body[$f];
                    } else {
                        $values[] = $body[$f];
                    }
                }
            }
            if (!empty($fields)) {
                $values[] = $ruleId;
                $db->prepare("UPDATE iot_alert_rules SET " . implode(', ', $fields) . " WHERE rule_id = ?")
                   ->execute($values);
            }
            sendJson(['message' => 'Alert rule updated']);
        }

        if ($sub === 'alert-rules' && $subsub !== null && $method === 'DELETE') {
            $ruleId = (int)$subsub;
            $db->prepare("DELETE FROM iot_alert_rules WHERE rule_id = ? AND device_id = ?")
               ->execute([$ruleId, $deviceId]);
            sendJson(['message' => 'Alert rule deleted']);
        }

        sendError(405, 'Method not allowed');
    }

    sendError(404, 'Not found');
}

// CUSTOMER HANDLER
function handleCustomerIotDevices(
    PDO $db, string $method, ?string $id, ?string $sub, ?string $subsub, int $customerId
): void {

    if ($method === 'GET' && $id === null) {
        $stmt = $db->prepare(
            "SELECT d.device_id, d.device_name, d.status, d.last_seen,
                    dt.type_slug, dt.type_name,
                    e.model AS equipment_model
             FROM iot_devices d
             JOIN iot_device_types dt ON d.type_id = dt.type_id
             LEFT JOIN equipment e ON d.equipment_id = e.equipment_id
             WHERE d.customer_id = ? AND d.status != 'inactive'
             ORDER BY d.last_seen DESC"
        );
        $stmt->execute([$customerId]);
        sendJson($stmt->fetchAll());
    }

    if ($method === 'GET' && $id !== null) {
        $stmt = $db->prepare(
            "SELECT d.*, dt.type_slug, dt.type_name, dt.metrics AS device_type_metrics,
                    e.model AS equipment_model, et.type_name AS equipment_type_name
             FROM iot_devices d
             JOIN iot_device_types dt ON d.type_id = dt.type_id
             LEFT JOIN equipment e ON d.equipment_id = e.equipment_id
             LEFT JOIN equipment_types et ON e.type_id = et.type_id
             WHERE d.device_id = ? AND d.customer_id = ?"
        );
        $stmt->execute([(int)$id, $customerId]);
        $device = $stmt->fetch();
        if (!$device) sendError(404, 'Device not found');

        $stmt2 = $db->prepare(
            "SELECT * FROM iot_alert_log WHERE device_id = ? AND acknowledged_at IS NULL ORDER BY triggered_at DESC LIMIT 5"
        );
        $stmt2->execute([(int)$id]);
        $device['open_alerts'] = $stmt2->fetchAll();

        sendJson($device);
    }

    if ($method === 'GET' && $id !== null && $sub === 'readings') {
        $stmt = $db->prepare("SELECT device_id FROM iot_devices WHERE device_id = ? AND customer_id = ?");
        $stmt->execute([(int)$id, $customerId]);
        if (!$stmt->fetch()) sendError(404, 'Device not found');

        $metricName = $_GET['metric'] ?? null;
        $fromDate   = $_GET['from'] ?? null;
        $toDate     = $_GET['to'] ?? null;
        $limit      = min((int)($_GET['limit'] ?? 50), 500);

        $where  = ['r.device_id = ?'];
        $params = [(int)$id];
        if ($metricName) { $where[] = 'r.metric_name = ?'; $params[] = $metricName; }
        if ($fromDate)   { $where[] = 'r.recorded_at >= ?'; $params[] = $fromDate; }
        if ($toDate)     { $where[] = 'r.recorded_at <= ?'; $params[] = $toDate; }

        $stmt = $db->prepare(
            "SELECT r.metric_name, r.value, r.unit, r.recorded_at
             FROM iot_readings r
             WHERE " . implode(' AND ', $where) . "
             ORDER BY r.recorded_at DESC
             LIMIT $limit"
        );
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
    }

    if ($method === 'POST' && $id !== null && $sub === 'alerts' && $subsub === 'acknowledge') {
        $stmt = $db->prepare("SELECT device_id FROM iot_devices WHERE device_id = ? AND customer_id = ?");
        $stmt->execute([(int)$id, $customerId]);
        if (!$stmt->fetch()) sendError(404, 'Device not found');

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $alertId = !empty($body['alert_id']) ? (int)$body['alert_id'] : null;

        if ($alertId) {
            $db->prepare(
                "UPDATE iot_alert_log SET acknowledged_at = NOW()
                 WHERE alert_id = ? AND device_id = ? AND customer_id = ?"
            )->execute([$alertId, (int)$id, $customerId]);
        } else {
            $db->prepare(
                "UPDATE iot_alert_log SET acknowledged_at = NOW()
                 WHERE device_id = ? AND customer_id = ? AND acknowledged_at IS NULL"
            )->execute([(int)$id, $customerId]);
        }
        sendJson(['message' => 'Alert(s) acknowledged']);
    }

    sendError(405, 'Method not allowed');
}
