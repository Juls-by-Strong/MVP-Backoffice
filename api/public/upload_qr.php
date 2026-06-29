<?php
// Uploads a pre-generated QR code PNG for Google or Facebook reviews.
// Saves the file and stores its path in company_settings.
//
// POST /upload_qr.php
// multipart/form-data:
// qr file (required - PNG only, max 2MB)
// platform string (required - "google" or "facebook")

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

$payload = requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed');
}

$platform = trim($_POST['platform'] ?? '');
if (!in_array($platform, ['google', 'facebook'], true)) {
    sendError(400, 'platform must be "google" or "facebook"');
}

if (!isset($_FILES['qr']) || $_FILES['qr']['error'] !== UPLOAD_ERR_OK) {
    $errs = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp directory',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
    ];
    $code = $_FILES['qr']['error'] ?? UPLOAD_ERR_NO_FILE;
    sendError(400, $errs[$code] ?? 'Upload error ' . $code);
}

$file = $_FILES['qr'];

if ($file['size'] > 2 * 1024 * 1024) {
    sendError(400, 'QR image must be under 2 MB');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($mime !== 'image/png') {
    sendError(400, 'QR code image must be a PNG file');
}

// Save to assets/ folder alongside this file (same level as upload_image.php)
$assetsDir = __DIR__ . '/../assets/';
if (!is_dir($assetsDir)) {
    if (!mkdir($assetsDir, 0755, true)) {
        sendError(500, 'Could not create assets directory');
    }
}

$filename = 'qr_' . $platform . '.png';
$destPath = $assetsDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    sendError(500, 'Failed to save QR image');
}

// Store the path in company_settings
try {
    $db  = getDB();
    $key = $platform . '_qr_path';
    $db->prepare(
        "INSERT INTO company_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?"
    )->execute([$key, $destPath, $destPath]);
} catch (\Throwable $e) {
 // File was saved - non-fatal DB error
    error_log('[WAY upload_qr] DB error: ' . $e->getMessage());
}

sendJson(['message' => 'QR code uploaded', 'platform' => $platform, 'path' => $destPath]);
