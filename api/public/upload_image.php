<?php
// Handles image uploads for customer profiles and appointments
//
// POST /api/public/upload_image.php
// multipart/form-data fields:
// customer_id int (required)
// appointment_id int (optional - links photo to an appointment)
// caption str (optional)
// image file (required - jpg/png/gif/webp, max 10MB)

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

$payload = requireRole('admin', 'technician');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed');
}

$customerId    = (int)($_POST['customer_id']    ?? 0);
$appointmentId = (int)($_POST['appointment_id'] ?? 0) ?: null;
$caption       = trim($_POST['caption']         ?? '');

if (!$customerId) sendError(400, 'customer_id is required');

// Validate file
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp directory',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension',
    ];
    $code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    sendError(400, $uploadErrors[$code] ?? 'Image upload failed (error ' . $code . ')');
}

$file = $_FILES['image'];

// Check MIME type
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mimeType, $allowedMimes)) {
    sendError(400, 'File must be an image (JPEG, PNG, GIF, or WebP)');
}

$mimeToExt = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$ext = $mimeToExt[$mimeType];

// Max 10MB
if ($file['size'] > 10 * 1024 * 1024) {
    sendError(400, 'File size must be under 10MB');
}

// Create upload directory
$uploadDir = __DIR__ . '/../../customer_images/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Unique filename - prefix with customer id so files are loosely grouped
$filename = 'img_' . $customerId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    sendError(500, 'Failed to save image file');
}

// Record in database
$db = getDB();

// Verify customer exists
$cs = $db->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
$cs->execute([$customerId]);
if (!$cs->fetchColumn()) {
    unlink($destPath);
    sendError(404, 'Customer not found');
}

// If appointment_id provided, verify it belongs to this customer
if ($appointmentId) {
    $as = $db->prepare("SELECT appointment_id FROM appointments WHERE appointment_id = ? AND customer_id = ?");
    $as->execute([$appointmentId, $customerId]);
    if (!$as->fetchColumn()) {
        unlink($destPath);
        sendError(404, 'Appointment not found or does not belong to this customer');
    }
}

$db->prepare(
    "INSERT INTO customer_images (customer_id, appointment_id, filename, caption, uploaded_by)
     VALUES (?, ?, ?, ?, ?)"
)->execute([$customerId, $appointmentId, $filename, $caption ?: null, $payload['sub']]);

$imageId = (int)$db->lastInsertId();

// Build public URL for the image
$proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$imgUrl  = $proto . '://' . $host . '/customer_images/' . $filename;

sendJson([
    'message'        => 'Image uploaded successfully',
    'image_id'       => $imageId,
    'filename'       => $filename,
    'url'            => $imgUrl,
    'customer_id'    => $customerId,
    'appointment_id' => $appointmentId,
], 201);
