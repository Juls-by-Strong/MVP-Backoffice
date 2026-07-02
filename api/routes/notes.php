<?php
// Customer notes - shared logic for admin, tech, and customer
//
// Admin:
// GET /admin/customers/{id}/notes - all notes for a customer
// POST /admin/customers/{id}/notes - add note (general or equipment-specific)
// PUT /admin/notes/{id} - edit note (text, visibility)
// DELETE /admin/notes/{id} - delete any note
//
// Tech:
// GET /tech/customers/{id}/notes - all notes for a customer
// POST /tech/customers/{id}/notes - add note
// PUT /tech/notes/{id} - edit own notes only
//
// Customer:
// GET /customer/notes - notes marked visible to this customer

function getNotesForCustomer(PDO $db, int $customerId, string $viewerRole): array {

 // Customers only see notes marked visible
    $visibilityClause = $viewerRole === 'customer'
        ? 'AND n.is_visible_to_customer = 1'
        : '';

    $stmt = $db->prepare(
        "SELECT
            n.note_id,
            n.customer_id,
            n.equipment_id,
            et.type_name     AS equipment_name,
            n.note_text,
            n.is_visible_to_customer,
            n.is_pinned,
            n.pinned_at,
            n.created_at,
            n.updated_at,
            -- Customers see role label, staff see full name or email
            CASE
                WHEN ? IN ('admin','technician')
                    THEN COALESCE(
                        CONCAT(u.first_name, ' ', u.last_name),
                        u.email
                    )
                ELSE
                    CASE u.role
                        WHEN 'admin'      THEN 'Office'
                        WHEN 'technician' THEN 'Technician'
                        ELSE 'Staff'
                    END
            END AS author_display,
            u.role AS author_role,
            n.author_id
         FROM customer_notes n
         JOIN users u ON n.author_id = u.user_id
         LEFT JOIN equipment e  ON n.equipment_id = e.equipment_id
         LEFT JOIN equipment_types et ON e.type_id = et.type_id
         WHERE n.customer_id = ?
         $visibilityClause
         ORDER BY n.is_pinned DESC, COALESCE(n.pinned_at, n.created_at) DESC, n.created_at DESC"
    );
    $stmt->execute([$viewerRole, $customerId]);
    return $stmt->fetchAll();
}

function handleAdminNotes(PDO $db, string $method, ?string $id, ?string $sub, int $adminUserId): void {

 // /admin/customers/{id}/notes
    if ($sub === 'notes' && $id !== null) {
        $customerId = (int)$id;

 // Verify customer exists
        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) sendError(404, 'Customer not found');

        if ($method === 'GET') {
            sendJson(getNotesForCustomer($db, $customerId, 'admin'));
        }

        if ($method === 'POST') {
            $body        = json_decode(file_get_contents('php://input'), true) ?? [];
            $noteText    = trim($body['note_text']             ?? '');
            $equipmentId   = isset($body['equipment_id'])   ? (int)$body['equipment_id']   : null;
            $appointmentId = isset($body['appointment_id']) ? (int)$body['appointment_id'] : null;
            $visible     = isset($body['is_visible_to_customer']) ? (int)(bool)$body['is_visible_to_customer'] : 0;

            if (!$noteText) sendError(400, 'note_text is required');

 // Verify equipment belongs to this customer if provided
            if ($equipmentId) {
                $stmt = $db->prepare(
                    "SELECT equipment_id FROM equipment WHERE equipment_id = ? AND customer_id = ?"
                );
                $stmt->execute([$equipmentId, $customerId]);
                if (!$stmt->fetch()) sendError(404, 'Equipment not found for this customer');
            }

            $db->prepare(
                "INSERT INTO customer_notes
                 (customer_id, equipment_id, appointment_id, author_id, note_text, is_visible_to_customer)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$customerId, $equipmentId, $appointmentId, $adminUserId, $noteText, $visible]);

            $noteId = (int)$db->lastInsertId();
            sendJson(['message' => 'Note added', 'note_id' => $noteId], 201);
        }

        sendError(405, 'Method not allowed');
    }

 // /admin/notes/{id} - edit or delete any note
    if ($id !== null && $sub === null) {
        $noteId = (int)$id;
        $stmt   = $db->prepare("SELECT * FROM customer_notes WHERE note_id = ?");
        $stmt->execute([$noteId]);
        $note   = $stmt->fetch();
        if (!$note) sendError(404, 'Note not found');

        if ($method === 'PUT') {
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = ['note_text', 'is_visible_to_customer', 'equipment_id'];
            $fields  = [];
            $values  = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    $values[] = $f === 'is_visible_to_customer'
                        ? (int)(bool)$body[$f]
                        : $body[$f];
                }
            }
            if (array_key_exists('is_pinned', $body)) {
                $pinned = (int)(bool)$body['is_pinned'];
                $fields[] = 'is_pinned = ?';
                $values[] = $pinned;
                $fields[] = 'pinned_at = ?';
                $values[] = $pinned ? date('Y-m-d H:i:s') : null;
            }
            if (empty($fields)) sendError(400, 'Nothing to update');
            $values[] = $noteId;
            $db->prepare("UPDATE customer_notes SET " . implode(', ', $fields) . " WHERE note_id = ?")
               ->execute($values);
            sendJson(['message' => 'Note updated']);
        }

        if ($method === 'DELETE') {
            $db->prepare("DELETE FROM customer_notes WHERE note_id = ?")->execute([$noteId]);
            sendJson(['message' => 'Note deleted']);
        }

        sendError(405, 'Method not allowed');
    }

    sendError(400, 'Invalid notes request');
}

function handleTechNotes(PDO $db, string $method, ?string $id, ?string $sub, int $techUserId): void {

 // /tech/customers/{id}/notes
    if ($sub === 'notes' && $id !== null) {
        $customerId = (int)$id;

        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) sendError(404, 'Customer not found');

        if ($method === 'GET') {
            sendJson(getNotesForCustomer($db, $customerId, 'technician'));
        }

        if ($method === 'POST') {
            $body        = json_decode(file_get_contents('php://input'), true) ?? [];
            $noteText    = trim($body['note_text']             ?? '');
            $equipmentId   = isset($body['equipment_id'])   ? (int)$body['equipment_id']   : null;
            $appointmentId = isset($body['appointment_id']) ? (int)$body['appointment_id'] : null;
            $visible     = isset($body['is_visible_to_customer']) ? (int)(bool)$body['is_visible_to_customer'] : 0;

            if (!$noteText) sendError(400, 'note_text is required');

            if ($equipmentId) {
                $stmt = $db->prepare(
                    "SELECT equipment_id FROM equipment WHERE equipment_id = ? AND customer_id = ?"
                );
                $stmt->execute([$equipmentId, $customerId]);
                if (!$stmt->fetch()) sendError(404, 'Equipment not found for this customer');
            }

            $db->prepare(
                "INSERT INTO customer_notes
                 (customer_id, equipment_id, appointment_id, author_id, note_text, is_visible_to_customer)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$customerId, $equipmentId, $appointmentId, $techUserId, $noteText, $visible]);

            $noteId = (int)$db->lastInsertId();
            sendJson(['message' => 'Note added', 'note_id' => $noteId], 201);
        }

        sendError(405, 'Method not allowed');
    }

 // /tech/notes/{id} - tech can edit their OWN notes only, cannot delete
 // Pin/unpin is exempt from the ownership check so either role can surface
 // important context regardless of who wrote the note.
    if ($id !== null && $sub === null) {
        $noteId = (int)$id;
        $stmt   = $db->prepare("SELECT * FROM customer_notes WHERE note_id = ?");
        $stmt->execute([$noteId]);
        $note   = $stmt->fetch();
        if (!$note) sendError(404, 'Note not found');

 // Verify ownership unless the request is a pin-only toggle
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $pinOnly = array_key_exists('is_pinned', $body) && count($body) === 1;
        if (!$pinOnly && (int)$note['author_id'] !== $techUserId) {
            sendError(403, 'You can only edit your own notes');
        }

        if ($method === 'PUT') {
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
 // Techs can edit text and visibility, but not equipment_id
            $allowed = ['note_text', 'is_visible_to_customer'];
            $fields  = [];
            $values  = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    $values[] = $f === 'is_visible_to_customer'
                        ? (int)(bool)$body[$f]
                        : $body[$f];
                }
            }
            if (array_key_exists('is_pinned', $body)) {
                $pinned = (int)(bool)$body['is_pinned'];
                $fields[] = 'is_pinned = ?';
                $values[] = $pinned;
                $fields[] = 'pinned_at = ?';
                $values[] = $pinned ? date('Y-m-d H:i:s') : null;
            }
            if (empty($fields)) sendError(400, 'Nothing to update');
            $values[] = $noteId;
            $db->prepare("UPDATE customer_notes SET " . implode(', ', $fields) . " WHERE note_id = ?")
               ->execute($values);
            sendJson(['message' => 'Note updated']);
        }

        if ($method === 'DELETE') {
            sendError(403, 'Technicians cannot delete notes - contact an administrator');
        }

        sendError(405, 'Method not allowed');
    }

    sendError(400, 'Invalid notes request');
}

function handleCustomerNotes(PDO $db, int $customerId): void {
    sendJson(getNotesForCustomer($db, $customerId, 'customer'));
}
