<?php
// Securely serves water test PDFs to authenticated customers
// Usage: /api/public/water_test.php?customer_id=3
// or /api/public/water_test.php?test_id=12 (for archived)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../middleware/auth.php';

// Validate JWT
$payload = requireAuth();
$role    = $payload['role'];
$userId  = $payload['sub'];
$db      = getDB();

$testId     = isset($_GET['test_id'])     ? (int)$_GET['test_id']     : null;
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;

// Build query based on params
if ($testId) {
    $stmt = $db->prepare("SELECT * FROM water_tests WHERE test_id = ?");
    $stmt->execute([$testId]);
} elseif ($customerId) {
    $stmt = $db->prepare(
        "SELECT * FROM water_tests WHERE customer_id = ? AND is_current = 1 LIMIT 1"
    );
    $stmt->execute([$customerId]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'test_id or customer_id required']);
    exit;
}

$test = $stmt->fetch();
if (!$test) {
    http_response_code(404);
    echo json_encode(['error' => 'No water test found']);
    exit;
}

// Access control:
// - Admins and technicians can access any test
// - Customers can only access their own test
if ($role === 'customer') {
    $cs = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
    $cs->execute([$userId]);
    $myCustomerId = (int)$cs->fetchColumn();
    if ($myCustomerId !== (int)$test['customer_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
}

$filePath = __DIR__ . '/../../water_tests/' . basename($test['filename']);
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found on server']);
    exit;
}

// Serve the PDF
$safeLabel = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $test['label']);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $safeLabel . '.pdf"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;
