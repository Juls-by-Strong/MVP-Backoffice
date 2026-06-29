<?php
// Technician clock-in / clock-out
//
// POST /api/tech/time-tracking/clock-in
// POST /api/tech/time-tracking/clock-out
// GET /api/tech/time-tracking/status - current state for this tech
// GET /api/admin/time-tracking?from=&to= - payroll report (admin only)
// GET /api/admin/time-tracking/{user_id}?from=&to= - single tech report

function parseEventTimestamp(string $raw): ?string {
 // Accept ISO-8601 with or without timezone, and plain "YYYY-MM-DD HH:MM:SS"
    $raw = trim($raw);
    if (empty($raw)) return null;

 // Try parsing - DateTime handles ISO-8601 natively
    try {
        $dt = new DateTime($raw);
        return $dt->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {
        return null;
    }
}

function handleClockIn(PDO $db, int $userId): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $rawTs     = $body['timestamp']  ?? date('Y-m-d H:i:s');
    $latitude  = isset($body['latitude'])  && is_numeric($body['latitude'])
                 ? (float)$body['latitude']  : null;
    $longitude = isset($body['longitude']) && is_numeric($body['longitude'])
                 ? (float)$body['longitude'] : null;

    $eventTs = parseEventTimestamp((string)$rawTs);
    if (!$eventTs) sendError(400, 'Invalid timestamp format. Use ISO-8601, e.g. 2024-01-15T08:30:00');

 // Check whether the tech is already clocked in (no subsequent CLOCK_OUT)
    $stmt = $db->prepare(
        "SELECT id, event_type, event_timestamp
         FROM time_logs
         WHERE user_id = ?
         ORDER BY event_timestamp DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $last = $stmt->fetch();

    if ($last && $last['event_type'] === 'CLOCK_IN') {
 // Already clocked in - return 409 so the app can decide what to show.
 // We include the existing clock-in time so the app can display it.
        sendError(409, 'Already clocked in since ' . $last['event_timestamp']
            . '. Clock out first, or use force=true to start a new session.');
    }

 // Insert the new CLOCK_IN row
    $db->prepare(
        "INSERT INTO time_logs (user_id, event_type, event_timestamp, server_timestamp, latitude, longitude)
         VALUES (?, 'CLOCK_IN', ?, NOW(), ?, ?)"
    )->execute([$userId, $eventTs, $latitude, $longitude]);

    $logId = (int)$db->lastInsertId();

    sendJson([
        'message'         => 'Clocked in successfully',
        'log_id'          => $logId,
        'event_type'      => 'CLOCK_IN',
        'event_timestamp' => $eventTs,
    ], 201);
}

function handleClockOut(PDO $db, int $userId): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $rawTs     = $body['timestamp']  ?? date('Y-m-d H:i:s');
    $latitude  = isset($body['latitude'])  && is_numeric($body['latitude'])
                 ? (float)$body['latitude']  : null;
    $longitude = isset($body['longitude']) && is_numeric($body['longitude'])
                 ? (float)$body['longitude'] : null;

    $eventTs = parseEventTimestamp((string)$rawTs);
    if (!$eventTs) sendError(400, 'Invalid timestamp format. Use ISO-8601, e.g. 2024-01-15T17:00:00');

 // Find the most recent CLOCK_IN that hasn't been paired yet
    $stmt = $db->prepare(
        "SELECT id, event_type, event_timestamp, session_id
         FROM time_logs
         WHERE user_id = ?
         ORDER BY event_timestamp DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $last = $stmt->fetch();

    if (!$last || $last['event_type'] !== 'CLOCK_IN') {
        sendError(409, 'Not currently clocked in. Cannot clock out.');
    }

    $clockInId = (int)$last['id'];

 // Use the clock-in row's id as the session_id so both rows share it
    $sessionId = $clockInId;

 // Insert the CLOCK_OUT row
    $db->prepare(
        "INSERT INTO time_logs (user_id, event_type, event_timestamp, server_timestamp, latitude, longitude, session_id)
         VALUES (?, 'CLOCK_OUT', ?, NOW(), ?, ?, ?)"
    )->execute([$userId, $eventTs, $latitude, $longitude, $sessionId]);

    $logId = (int)$db->lastInsertId();

 // Back-fill session_id on the CLOCK_IN row so the pair is linked both ways
    $db->prepare("UPDATE time_logs SET session_id = ? WHERE id = ?")
       ->execute([$sessionId, $clockInId]);

 // Calculate shift duration in minutes
    $durationMinutes = null;
    try {
        $dtIn  = new DateTime($last['event_timestamp']);
        $dtOut = new DateTime($eventTs);
        $diff  = $dtIn->diff($dtOut);
        $durationMinutes = ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
    } catch (\Throwable $e) { /* non-fatal */ }

    sendJson([
        'message'          => 'Clocked out successfully',
        'log_id'           => $logId,
        'session_id'       => $sessionId,
        'event_type'       => 'CLOCK_OUT',
        'event_timestamp'  => $eventTs,
        'clock_in_time'    => $last['event_timestamp'],
        'duration_minutes' => $durationMinutes,
    ], 201);
}

function handleTimeStatus(PDO $db, int $userId): void {
    $stmt = $db->prepare(
        "SELECT id, event_type, event_timestamp, session_id
         FROM time_logs
         WHERE user_id = ?
         ORDER BY event_timestamp DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $last = $stmt->fetch();

    $isClockedIn = $last && $last['event_type'] === 'CLOCK_IN';

    sendJson([
        'is_clocked_in'    => $isClockedIn,
        'last_event_type'  => $last['event_type']      ?? null,
        'last_event_time'  => $last['event_timestamp'] ?? null,
        'last_log_id'      => $last ? (int)$last['id'] : null,
    ]);
}

// Optional: ?from=YYYY-MM-DD&to=YYYY-MM-DD&user_id=X
// Returns paired sessions with duration. Unpaired CLOCK_INs
// (tech still clocked in) are included with null clock_out / duration.
function handleAdminTimeReport(PDO $db, ?string $filterUserId): void {
    $from   = $_GET['from']    ?? date('Y-m-01');
    $to     = $_GET['to']      ?? date('Y-m-d');
    $userId = $filterUserId    ?? ($_GET['user_id'] ?? null);

 // Build a paired view: join each CLOCK_OUT back to its CLOCK_IN via session_id,
 // and also include unmatched CLOCK_INs (still clocked in).
    $where  = ["ci.event_type = 'CLOCK_IN'",
               "DATE(ci.event_timestamp) >= ?",
               "DATE(ci.event_timestamp) <= ?"];
    $params = [$from, $to];

    if ($userId) {
        $where[] = "ci.user_id = ?";
        $params[] = (int)$userId;
    }

    $sql = "
        SELECT
            ci.id                           AS clock_in_id,
            ci.user_id,
            CONCAT(u.first_name, ' ', u.last_name) AS technician_name,
            ci.event_timestamp              AS clock_in_time,
            ci.latitude                     AS clock_in_lat,
            ci.longitude                    AS clock_in_lng,
            co.id                           AS clock_out_id,
            co.event_timestamp              AS clock_out_time,
            co.latitude                     AS clock_out_lat,
            co.longitude                    AS clock_out_lng,
            ci.session_id,
            CASE
                WHEN co.event_timestamp IS NOT NULL
                THEN ROUND(TIMESTAMPDIFF(MINUTE, ci.event_timestamp, co.event_timestamp) / 60.0, 2)
                ELSE NULL
            END                             AS duration_hours
        FROM time_logs ci
        JOIN users u ON ci.user_id = u.user_id
        LEFT JOIN time_logs co
               ON co.session_id = ci.id
              AND co.event_type = 'CLOCK_OUT'
              AND co.user_id    = ci.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ci.user_id ASC, ci.event_timestamp ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

 // Compute summary totals per technician
    $totals = [];
    foreach ($rows as $r) {
        $uid = $r['user_id'];
        if (!isset($totals[$uid])) {
            $totals[$uid] = [
                'user_id'          => (int)$uid,
                'technician_name'  => $r['technician_name'],
                'total_hours'      => 0.0,
                'shift_count'      => 0,
                'incomplete_shifts' => 0,
            ];
        }
        $totals[$uid]['shift_count']++;
        if ($r['duration_hours'] !== null) {
            $totals[$uid]['total_hours'] += (float)$r['duration_hours'];
        } else {
            $totals[$uid]['incomplete_shifts']++;
        }
    }

    sendJson([
        'from'    => $from,
        'to'      => $to,
        'shifts'  => $rows,
        'summary' => array_values($totals),
    ]);
}
