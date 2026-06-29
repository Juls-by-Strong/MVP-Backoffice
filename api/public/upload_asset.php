<?php
// POST /api/public/upload_asset.php
// Standalone multipart handler for logo / QR PNG uploads.
// Mirrors upload_water_test.php pattern to avoid index.php
// output buffer conflicts with multipart/form-data requests.

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

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed');
}

$type = trim($_POST['type'] ?? '');
if (!in_array($type, ['logo', 'qr_google', 'qr_facebook'], true)) {
    sendError(400, 'Invalid type: ' . $type);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    sendError(400, 'No file received (error code: ' . ($_FILES['image']['error'] ?? 'none') . ')');
}

$file = $_FILES['image'];

if ($file['size'] > 10 * 1024 * 1024) {
    sendError(400, 'File must be under 10 MB');
}

// Verify PNG via magic bytes
$handle = fopen($file['tmp_name'], 'rb');
$magic  = fread($handle, 8);
fclose($handle);
if (substr($magic, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    sendError(400, 'File is not a valid PNG');
}

// This file lives in public/; assets/ is one level up alongside public/
$assetsDir = dirname(__DIR__) . '/assets';

if (!is_dir($assetsDir) && !mkdir($assetsDir, 0755, true)) {
    sendError(500, 'Cannot create assets dir: ' . $assetsDir);
}

if (!is_writable($assetsDir)) {
    sendError(500, 'Assets dir not writable: ' . $assetsDir);
}

$names = [
    'logo'        => 'logo.png',
    'qr_google'   => 'qr_google.png',
    'qr_facebook' => 'qr_facebook.png',
];
$dest = $assetsDir . '/' . $names[$type];

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    sendError(500, 'move_uploaded_file failed to: ' . $dest);
}

$realDest = realpath($dest) ?: $dest;

sendJson([
    'message'  => $type . ' uploaded successfully',
    'path'     => $realDest,
    'filename' => $names[$type],
    'type'     => $type,
]);
