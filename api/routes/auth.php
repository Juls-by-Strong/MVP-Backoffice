<?php
// POST /api/auth/login
// POST /api/auth/refresh
// POST /api/auth/logout

function routeAuth(string $method, string $resource): void {

    if ($method !== 'POST') {
        sendError(405, 'Method not allowed');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    switch ($resource) {

        case 'login':
            $email    = trim($body['email'] ?? '');
            $password = $body['password'] ?? '';

            if (!$email || !$password) {
                sendError(400, 'Email and password are required');
            }

            $db   = getDB();
            $stmt = $db->prepare(
                "SELECT user_id, password_hash, role, is_active
                 FROM users WHERE email = ? LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                sendError(401, 'Invalid email or password');
            }

            if (!$user['is_active']) {
                sendError(403, 'Account is disabled');
            }

 // Capture platform sent by the app ('ios', 'android', 'ubuntu_touch', etc.)
 // Falls back to sniffing the User-Agent when not explicitly provided
            $platform = strtolower(trim($body['platform'] ?? ''));
            if (!in_array($platform, ['ios', 'android', 'ubuntu_touch'])) {
                $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
                if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) {
                    $platform = 'ios';
                } elseif (str_contains($ua, 'android')) {
                    $platform = 'android';
                } elseif (str_contains($ua, 'ubuntu') || str_contains($ua, 'ubports')) {
                    $platform = 'ubuntu_touch';
                } else {
                    $platform = null;   // web / unknown - don't overwrite stored value
                }
            }

 // Update last login (and platform for customer accounts)
            $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")
               ->execute([$user['user_id']]);

            $accessToken  = createAccessToken($user['user_id'], $user['role']);
            $refreshToken = createRefreshToken($user['user_id']);

 // If customer, attach customer_id for convenience and store last platform
            $customerId = null;
            if ($user['role'] === 'customer') {
                $cs = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
                $cs->execute([$user['user_id']]);
                $customerId = $cs->fetchColumn();

                if ($customerId && $platform !== null) {
                    $db->prepare(
                        "UPDATE customers SET last_login_platform = ? WHERE customer_id = ?"
                    )->execute([$platform, $customerId]);
                }
            }

            sendJson([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'role'          => $user['role'],
                'user_id'       => $user['user_id'],
                'customer_id'   => $customerId,
                'expires_in'    => JWT_ACCESS_EXPIRY,
            ]);
            break;

        case 'refresh':
            $refreshToken = $body['refresh_token'] ?? '';

            if (!$refreshToken) {
                sendError(400, 'refresh_token is required');
            }

            $user = validateRefreshToken($refreshToken);
            if (!$user) {
                sendError(401, 'Invalid or expired refresh token');
            }

 // Rotate: delete old, issue new pair
            $newAccess  = createAccessToken($user['user_id'], $user['role']);
            $newRefresh = createRefreshToken($user['user_id']);

            sendJson([
                'access_token'  => $newAccess,
                'refresh_token' => $newRefresh,
                'expires_in'    => JWT_ACCESS_EXPIRY,
            ]);
            break;

        case 'logout':
            $payload = requireAuth();
            $db      = getDB();
            $db->prepare("DELETE FROM refresh_tokens WHERE user_id = ?")
               ->execute([$payload['sub']]);
            sendJson(['message' => 'Logged out successfully']);
            break;

        default:
            sendError(404, 'Not found');
    }
}
