<?php
// GET /admin/settings - get all settings
// PUT /admin/settings - update one or many settings

if (!function_exists('updateSetting')) {
    function updateSetting(PDO $db, string $key, string $value): void {
        $db->prepare(
            "INSERT INTO company_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?"
        )->execute([$key, $value, $value]);
    }
}

if (!function_exists('getCompanySettingValue')) {
    function getCompanySettingValue(PDO $db, string $key): string {
        $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: '';
    }
}

function getCompanySettings(PDO $db): array {
    $stmt = $db->query("SELECT setting_key, setting_value FROM company_settings");
    $rows = $stmt->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    return $out;
}

function handleSettings(PDO $db, string $method): void {
    if ($method === 'GET') {
        sendJson(getCompanySettings($db));
    }

    if ($method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        foreach ($body as $key => $value) {
            updateSetting($db, (string)$key, (string)$value);
        }
        sendJson(['message' => 'Settings saved']);
    }

    sendError(405, 'Method not allowed');
}
