<?php
// Handles PDF uploads for water tests
// Called separately from index.php because PHP file uploads
// use multipart/form-data, not JSON

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../middleware/auth.php';

// Admin only
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed');
}

$customerId = (int)($_POST['customer_id'] ?? 0);
$label      = trim($_POST['label']       ?? '');
$testDate   = trim($_POST['test_date']   ?? '');

if (!$customerId) sendError(400, 'customer_id is required');
if (!$label)      sendError(400, 'label is required');
if (!$testDate)   sendError(400, 'test_date is required');

// Validate file upload
if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    sendError(400, 'PDF file is required');
}

$file = $_FILES['pdf'];

// Verify it's actually a PDF
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($mimeType !== 'application/pdf') {
    sendError(400, 'File must be a PDF');
}

// Max 10MB
if ($file['size'] > 10 * 1024 * 1024) {
    sendError(400, 'File size must be under 10MB');
}

// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/../../water_tests/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate safe unique filename
$filename = 'wt_' . $customerId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    sendError(500, 'Failed to save file');
}

$db      = getDB();
$payload = requireAuth();

// Archive existing current test for this customer
$db->prepare(
    "UPDATE water_tests SET is_current = 0 WHERE customer_id = ? AND is_current = 1"
)->execute([$customerId]);

// Insert new test record
$db->prepare(
    "INSERT INTO water_tests (customer_id, label, test_date, filename, uploaded_by, is_current)
     VALUES (?, ?, ?, ?, ?, 1)"
)->execute([$customerId, $label, $testDate, $filename, $payload['sub']]);

$testId = (int)$db->lastInsertId();

sendJson([
    'message' => 'Water test uploaded successfully',
    'test_id' => $testId,
    'filename' => $filename,
], 201);
