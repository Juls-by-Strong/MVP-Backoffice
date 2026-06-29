<?php
// JWT configuration and token helpers
// Uses HS256 signing - no external library required

// JWT secret is loaded from the JWT_SECRET environment variable.
// Generate one with: php -r "echo bin2hex(random_bytes(32));"
define('JWT_SECRET', getenv('JWT_SECRET') ?: '');

define('JWT_ACCESS_EXPIRY',  900);        // 15 minutes in seconds
define('JWT_REFRESH_EXPIRY', 2592000);    // 30 days in seconds

function jwtEncode(array $payload): string {
    $header  = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64UrlEncode(json_encode($payload));
    $sig     = base64UrlEncode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $sig] = $parts;

    $expectedSig = base64UrlEncode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expectedSig, $sig)) return null;

    $data = json_decode(base64UrlDecode($payload), true);
    if (!$data) return null;

 // Check expiry
    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

function createAccessToken(int $userId, string $role): string {
    return jwtEncode([
        'sub'  => $userId,
        'role' => $role,
        'iat'  => time(),
        'exp'  => time() + JWT_ACCESS_EXPIRY,
        'type' => 'access',
    ]);
}

function createRefreshToken(int $userId): string {
    $token     = bin2hex(random_bytes(40));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + JWT_REFRESH_EXPIRY);

    $db = getDB();

 // Remove old refresh tokens for this user
    $db->prepare("DELETE FROM refresh_tokens WHERE user_id = ?")->execute([$userId]);

    $stmt = $db->prepare(
        "INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)"
    );
    $stmt->execute([$userId, $tokenHash, $expiresAt]);

    return $token;
}

function validateRefreshToken(string $token): ?array {
    $tokenHash = hash('sha256', $token);
    $db        = getDB();

    $stmt = $db->prepare(
        "SELECT rt.user_id, u.role
         FROM refresh_tokens rt
         JOIN users u ON rt.user_id = u.user_id
         WHERE rt.token_hash = ?
           AND rt.expires_at > NOW()
           AND u.is_active = 1"
    );
    $stmt->execute([$tokenHash]);
    return $stmt->fetch() ?: null;
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}
