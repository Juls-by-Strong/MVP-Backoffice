<?php
// Web Push notification helper using VAPID (RFC 8292) + AES-128-GCM (RFC 8291).
// No Composer dependencies - pure PHP using openssl_*.
//
// Public functions:
// handleCustomerPush($db, $method, $sub, $customerId) - route handler
// sendPushToCustomer($db, $customerId, $title, $body, $url, $eventType, $contextIds)
// sendPushToSubscription($db, $sub, $payload, $vapid)
//
// Schema requirements:
// - push_subscriptions(customer_id, endpoint, p256dh_key, auth_key, user_agent, ...)
// - customers.push_notifications_enabled
// - company_settings: vapid_public_key, vapid_private_key, vapid_subject

// ROUTE HANDLER - POST /customer/push/subscribe, DELETE /customer/push/unsubscribe
function handleCustomerPush(PDO $db, string $method, ?string $sub, int $customerId): void {
 // POST /customer/push/subscribe - register a new subscription
    if ($method === 'POST' && $sub === 'subscribe') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $endpoint = trim($body['endpoint'] ?? '');
        $p256dh   = trim($body['p256dh']   ?? '');
        $auth     = trim($body['auth']     ?? '');
        $ua       = substr(trim($body['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

        if (!$endpoint || !$p256dh || !$auth) {
            sendError(400, 'endpoint, p256dh, and auth are all required');
        }
        if (strlen($endpoint) > 500) sendError(400, 'endpoint too long');

 // Upsert: same endpoint always replaces
        $db->prepare(
            "INSERT INTO push_subscriptions
             (customer_id, endpoint, p256dh_key, auth_key, user_agent, created_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 customer_id  = VALUES(customer_id),
                 p256dh_key   = VALUES(p256dh_key),
                 auth_key     = VALUES(auth_key),
                 user_agent   = VALUES(user_agent),
                 last_used_at = NOW()"
        )->execute([$customerId, $endpoint, $p256dh, $auth, $ua]);

        sendJson(['message' => 'Subscription saved'], 201);
    }

 // DELETE /customer/push/unsubscribe - pass endpoint as query or body
    if ($method === 'DELETE' && $sub === 'unsubscribe') {
        $endpoint = $_GET['endpoint'] ?? null;
        if (!$endpoint) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $endpoint = $body['endpoint'] ?? null;
        }
        if (!$endpoint) sendError(400, 'endpoint is required');

        $db->prepare(
            "DELETE FROM push_subscriptions WHERE customer_id = ? AND endpoint = ?"
        )->execute([$customerId, $endpoint]);

        sendJson(['message' => 'Subscription removed']);
    }

 // GET /customer/push/vapid-public-key - needed by the PWA client
    if ($method === 'GET' && $sub === 'vapid-public-key') {
        $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE setting_key = 'vapid_public_key' LIMIT 1");
        $stmt->execute();
        $key = $stmt->fetchColumn();
        sendJson(['key' => $key ?: '']);
    }

    sendError(404, 'Push endpoint not found');
}

// HIGH-LEVEL: send push to all of a customer's active subscriptions
function sendPushToCustomer(
    PDO $db,
    int $customerId,
    string $title,
    string $body,
    string $url = '/',
    string $eventType = 'generic',
    array $contextIds = []
): array {
    $result = ['attempted' => 0, 'success' => 0, 'failures' => 0, 'pruned' => 0];

 // Honor opt-out
    $stmt = $db->prepare("SELECT push_notifications_enabled FROM customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $optedIn = $stmt->fetchColumn();
    if ($optedIn !== false && (int)$optedIn === 0) {
        _logPush($db, $customerId, $eventType, $title, $body, 0, 0, $contextIds);
        return $result;
    }

    $vapid = _getVapidConfig($db);
    if (!$vapid) {
        error_log('[push] VAPID config missing - push send skipped');
        return $result;
    }

    $stmt = $db->prepare(
        "SELECT subscription_id, endpoint, p256dh_key, auth_key
         FROM push_subscriptions WHERE customer_id = ?"
    );
    $stmt->execute([$customerId]);
    $subs = $stmt->fetchAll();
    if (!$subs) {
        _logPush($db, $customerId, $eventType, $title, $body, 0, 0, $contextIds);
        return $result;
    }

    $payload = json_encode([
        'title' => $title,
        'body'  => $body,
        'url'   => $url,
        'tag'   => $eventType,
        'icon'  => '/customer-icon-192.png',
        'badge' => '/customer-icon-192.png',
    ]);

    foreach ($subs as $sub) {
        $result['attempted']++;
        $ok = sendPushToSubscription($db, $sub, $payload, $vapid);
        if ($ok === true) {
            $result['success']++;
        } elseif ($ok === 'gone') {
 // 404/410 endpoint dead, prune it
            $db->prepare("DELETE FROM push_subscriptions WHERE subscription_id = ?")
               ->execute([$sub['subscription_id']]);
            $result['pruned']++;
            $result['failures']++;
        } else {
            $result['failures']++;
        }
    }

    _logPush($db, $customerId, $eventType, $title, $body, $result['success'], $result['failures'], $contextIds);
    return $result;
}

// LOW-LEVEL: send a single push request
// Returns true on success, 'gone' on 404/410 (caller should prune),
// false on other failures.
function sendPushToSubscription(PDO $db, array $sub, string $payload, array $vapid) {
    $endpoint  = $sub['endpoint'];
    $p256dhB64 = $sub['p256dh_key'];
    $authB64   = $sub['auth_key'];

 // 1. Encrypt the payload using AES-128-GCM (RFC 8291 / aes128gcm content-encoding)
    $encrypted = _encryptPayload($payload, $p256dhB64, $authB64);
    if (!$encrypted) return false;

 // 2. Build VAPID Authorization header (JWT signed with VAPID private key)
    $vapidHeader = _buildVapidHeader($endpoint, $vapid);
    if (!$vapidHeader) return false;

 // 3. POST encrypted payload to the push service
    $headers = [
        'Authorization: ' . $vapidHeader,
        'Crypto-Key: p256ecdsa=' . $vapid['public_key'],
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'Content-Length: ' . strlen($encrypted),
        'TTL: 86400',
        'Urgency: normal',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $encrypted,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($code === 201 || $code === 200 || $code === 202) return true;
    if ($code === 404 || $code === 410) return 'gone';

    error_log(sprintf('[push] send failed: code=%d err=%s body=%s endpoint=%s',
        $code, $err, substr((string)$response, 0, 200), substr($endpoint, 0, 80)));
    return false;
}

// CRYPTO INTERNALS

// Read VAPID config from company_settings
function _getVapidConfig(PDO $db): ?array {
    $stmt = $db->query(
        "SELECT setting_key, setting_value FROM company_settings
         WHERE setting_key IN ('vapid_public_key','vapid_private_key','vapid_subject')"
    );
    $kv = [];
    foreach ($stmt->fetchAll() as $r) $kv[$r['setting_key']] = $r['setting_value'];

    if (empty($kv['vapid_public_key']) || empty($kv['vapid_private_key'])) return null;
    return [
        'public_key'  => $kv['vapid_public_key'],
        'private_key' => $kv['vapid_private_key'],
        'subject'     => $kv['vapid_subject'] ?? 'mailto:info@example.com',
    ];
}

// base64url encode/decode
function _b64url_encode($data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function _b64url_decode(string $data): string {
    $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);
    return base64_decode(strtr($padded, '-_', '+/'));
}

// Build VAPID JWT for the Authorization header
function _buildVapidHeader(string $endpoint, array $vapid): ?string {
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
    $aud = $parts['scheme'] . '://' . $parts['host'];

    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $claims = [
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => $vapid['subject'],
    ];

    $headerB64 = _b64url_encode(json_encode($header));
    $claimsB64 = _b64url_encode(json_encode($claims));
    $signingInput = $headerB64 . '.' . $claimsB64;

 // Convert raw 32-byte private key to a PEM EC key for openssl_sign
    $privRaw = _b64url_decode($vapid['private_key']);
    if (strlen($privRaw) !== 32) {
        error_log('[push] VAPID private key must decode to 32 bytes, got ' . strlen($privRaw));
        return null;
    }
    $pubRaw = _b64url_decode($vapid['public_key']); // 65 bytes uncompressed
    if (strlen($pubRaw) !== 65 || $pubRaw[0] !== "\x04") {
        error_log('[push] VAPID public key invalid');
        return null;
    }

    $pem = _ecKeyToPem($privRaw, $pubRaw);
    $pkey = openssl_pkey_get_private($pem);
    if (!$pkey) return null;

    $sig = '';
    if (!openssl_sign($signingInput, $sig, $pkey, OPENSSL_ALGO_SHA256)) return null;

 // openssl emits DER-encoded ECDSA signature; JWT needs raw r||s (64 bytes for P-256)
    $rs = _derToRawSig($sig, 32);
    if (!$rs) return null;

    return 'vapid t=' . $signingInput . '.' . _b64url_encode($rs)
         . ', k=' . $vapid['public_key'];
}

// Build a PEM-encoded P-256 EC private key from a 32-byte raw scalar + 65-byte raw pubkey
function _ecKeyToPem(string $priv32, string $pub65): string {
 // ECPrivateKey ASN.1 (RFC 5915)
 // SEQUENCE {
 // INTEGER 1 (version)
 // OCTET STRING <32-byte private>
 // [0] EXPLICIT { OID 1.2.840.10045.3.1.7 } (P-256 namedCurve)
 // [1] EXPLICIT { BIT STRING <65-byte public> }
 // }
    $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID 1.2.840.10045.3.1.7
    $params = "\xa0" . _derLen(strlen($oid)) . $oid;
    $bitString = "\x00" . $pub65;
    $bsTag = "\x03" . _derLen(strlen($bitString)) . $bitString;
    $publicKey = "\xa1" . _derLen(strlen($bsTag)) . $bsTag;

    $version  = "\x02\x01\x01";
    $privOct  = "\x04\x20" . $priv32;

    $body = $version . $privOct . $params . $publicKey;
    $ecPrivateKey = "\x30" . _derLen(strlen($body)) . $body;

    return "-----BEGIN EC PRIVATE KEY-----\n"
         . chunk_split(base64_encode($ecPrivateKey), 64, "\n")
         . "-----END EC PRIVATE KEY-----\n";
}

function _derLen(int $n): string {
    if ($n < 128) return chr($n);
    $bytes = '';
    while ($n > 0) { $bytes = chr($n & 0xff) . $bytes; $n >>= 8; }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

// Decode DER ECDSA signature to raw r||s
function _derToRawSig(string $der, int $size): ?string {
    if (strlen($der) < 2 || $der[0] !== "\x30") return null;
    $offset = 2;
    if (ord($der[1]) > 0x80) {
        $lenBytes = ord($der[1]) - 0x80;
        $offset = 2 + $lenBytes;
    }

    $readInt = function(&$pos) use ($der, $size) {
        if ($der[$pos] !== "\x02") return null;
        $len = ord($der[$pos + 1]);
        $pos += 2;
        $val = substr($der, $pos, $len);
        $pos += $len;
 // strip leading zero
        while (strlen($val) > $size && $val[0] === "\x00") $val = substr($val, 1);
 // left-pad to $size
        if (strlen($val) < $size) $val = str_repeat("\x00", $size - strlen($val)) . $val;
        return $val;
    };

    $r = $readInt($offset);
    $s = $readInt($offset);
    if ($r === null || $s === null) return null;
    return $r . $s;
}

// Encrypt the payload using aes128gcm content-encoding (RFC 8188 + 8291)
function _encryptPayload(string $payload, string $clientPubKeyB64, string $authSecretB64): ?string {
    $clientPub = _b64url_decode($clientPubKeyB64); // 65-byte uncompressed
    $authSecret = _b64url_decode($authSecretB64);   // 16-byte
    if (strlen($clientPub) !== 65 || strlen($authSecret) !== 16) return null;

 // Generate ephemeral ECDH P-256 keypair
    $kp = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$kp) return null;
    $details = openssl_pkey_get_details($kp);
    if (empty($details['ec']['x']) || empty($details['ec']['y'])) return null;
    $localPub = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                       . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

 // ECDH shared secret
    $clientPemKey = _publicKeyToPem($clientPub);
    $clientPubKey = openssl_pkey_get_public($clientPemKey);
    if (!$clientPubKey) return null;

    if (function_exists('openssl_pkey_derive')) {
        $sharedSecret = openssl_pkey_derive($clientPubKey, $kp, 32);
    } else {
 // Fallback: use ECDH via openssl (PHP < 7.3 won't have derive)
        return null;
    }
    if (!$sharedSecret) return null;

 // Salt: 16 random bytes
    $salt = random_bytes(16);

 // PRK_key = HKDF-Extract(authSecret, sharedSecret) with info "WebPush: info\x00<clientPub><serverPub>"
    $keyInfo = "WebPush: info\x00" . $clientPub . $localPub;
    $ikm = _hkdf($authSecret, $sharedSecret, $keyInfo, 32);

 // Content Encryption Key (16 bytes) and Nonce (12 bytes)
    $cek   = _hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = _hkdf($salt, $ikm, "Content-Encoding: nonce\x00",     12);

 // Append \x02 padding delimiter (no extra padding)
    $plaintext = $payload . "\x02";

 // AES-128-GCM
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) return null;

 // Build aes128gcm record:
 // salt (16) | record_size (4 BE) | server_pub_len (1) | server_pub | ciphertext+tag
    $recordSize = pack('N', 4096);
    $keyId      = $localPub;
    $keyIdLen   = chr(strlen($keyId));
    return $salt . $recordSize . $keyIdLen . $keyId . $ciphertext . $tag;
}

// Build a PEM SPKI key from a 65-byte uncompressed P-256 point
function _publicKeyToPem(string $rawPub): string {
 // SPKI ASN.1 wrapper for P-256 uncompressed point
    $oidEc      = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // ecPublicKey
    $oidP256    = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // prime256v1
    $algId      = "\x30" . _derLen(strlen($oidEc) + strlen($oidP256)) . $oidEc . $oidP256;
    $bs         = "\x00" . $rawPub;
    $bsTag      = "\x03" . _derLen(strlen($bs)) . $bs;
    $spki       = "\x30" . _derLen(strlen($algId) + strlen($bsTag)) . $algId . $bsTag;
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

// HKDF (RFC 5869). Output up to 1 hash size.
function _hkdf(string $salt, string $ikm, string $info, int $length): string {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
}

// Insert a pending notification for the customer's next poll to pick up
function sendPendingNotification(PDO $db, int $customerId, string $title, string $body): int {
    $db->prepare(
        "INSERT INTO pending_notifications (customer_id, title, body, created_at)
         VALUES (?, ?, ?, NOW())"
    )->execute([$customerId, $title, $body]);
    return (int)$db->lastInsertId();
}

// Log push attempts
function _logPush(PDO $db, int $customerId, string $eventType, string $title, string $body,
                  int $success, int $failures, array $contextIds): void {
    try {
        $db->prepare(
            "INSERT INTO push_log (customer_id, appointment_id, invoice_id, event_type, title, body, sent_at, success_count, failure_count)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)"
        )->execute([
            $customerId,
            $contextIds['appointment_id'] ?? null,
            $contextIds['invoice_id']     ?? null,
            $eventType,
            substr($title, 0, 255),
            substr($body,  0, 500),
            $success,
            $failures,
        ]);
    } catch (\Throwable $e) {
        error_log('[push] log insert failed: ' . $e->getMessage());
    }
}
