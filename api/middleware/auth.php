<?php
// JWT authentication and role enforcement middleware

require_once __DIR__ . '/../config/jwt.php';

function requireAuth(): array {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

 // Allow token as query param for PDF/binary downloads opened in new tab
    if (!$authHeader && !empty($_GET['token'])) {
        $authHeader = 'Bearer ' . $_GET['token'];
    }

    if (strpos($authHeader, 'Bearer ') !== 0) {
        sendError(401, 'Missing or malformed Authorization header');
    }

    $token   = substr($authHeader, 7);
    $payload = jwtDecode($token);

    if (!$payload || ($payload['type'] ?? '') !== 'access') {
        sendError(401, 'Invalid or expired token');
    }

    return $payload;
}

function requireRole(string ...$roles): array {
    $payload = requireAuth();

    if (!in_array($payload['role'], $roles, true)) {
        sendError(403, 'Insufficient permissions');
    }

    return $payload;
}

function sendError(int $code, string $message): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

function sendJson($data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
