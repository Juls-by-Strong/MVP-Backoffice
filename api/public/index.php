<?php
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Fatal: ' . $err['message'] . ' in ' . $err['file'] . ' line ' . $err['line']]);
    } else {
        ob_end_flush();
    }
});

set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' line ' . $e->getLine()]);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if ($errno & (E_ERROR | E_USER_ERROR)) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    return false;
}, E_ALL);

error_reporting(E_ALL);
header('Content-Type: application/json');

require_once __DIR__ . '/../config/version.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim(preg_replace('#^(/api/public|/api)#', '', $uri), '/');
$parts  = explode('/', ltrim($uri, '/'));

$group    = $parts[0] ?? '';
$resource = $parts[1] ?? '';
$id       = $parts[2] ?? null;
$sub      = $parts[3] ?? null;
$subsub   = $parts[4] ?? null;

switch ($group) {

    case 'version':
 // Public, no auth - returns the current MVP_VERSION string
        header('Content-Type: application/json');
        echo json_encode(['version' => MVP_VERSION]);
        exit;

    case 'book':
        require_once __DIR__ . '/../routes/book.php';
        handleBooking(getDB(), $method);
        break;

    case 'iot':
        require_once __DIR__ . '/../routes/iot.php';
        if ($resource === 'telemetry' && $method === 'POST') {
            handleIotTelemetry(getDB(), $method);
            exit;
        }
        if ($resource === 'register' && $method === 'POST') {
            handleIotRegister(getDB(), $method);
            exit;
        }
        break;

    case 'auth':
        require_once __DIR__ . '/../routes/auth.php';
        routeAuth($method, $resource);
        break;

    case 'cron':
 // GET /cron/reminders?secret=xxx - run reminder emails (call daily via cron)
        require_once __DIR__ . '/../routes/autoEmail.php';
        require_once __DIR__ . '/../routes/email.php';
        require_once __DIR__ . '/../routes/settings.php';
        if ($resource === 'reminders' && $method === 'GET') {
            runReminderCron(getDB());
        } else {
            http_response_code(404); echo json_encode(['error' => 'Not found']);
        }
        break;

    case 'customer':
        require_once __DIR__ . '/../routes/customer.php';
        require_once __DIR__ . '/../routes/notes.php';
        require_once __DIR__ . '/../routes/appointments.php';
        require_once __DIR__ . '/../routes/invoices.php';
        require_once __DIR__ . '/../routes/estimates.php';
        require_once __DIR__ . '/../routes/contracts.php';
        if ($resource === 'invoices' && $sub === 'pdf' && $method === 'GET') {
            require_once __DIR__ . '/../routes/invoice_pdf.php';
            $payload = requireRole('customer');
            handleInvoicePdf(getDB(), 'customer', (int)$id, (int)$payload['sub']);
        }
        if ($resource === 'estimates') {
            $payload = requireRole('customer');
            $customerIdStmt = getDB()->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
            $customerIdStmt->execute([$payload['sub']]);
            $custId = $customerIdStmt->fetchColumn();
            if (!$custId) { http_response_code(404); echo json_encode(['error' => 'Customer profile not found']); exit; }
            handleCustomerEstimates(getDB(), $method, $id, $sub, (int)$custId);
            exit;
        }
        if ($resource === 'contracts') {
            $payload = requireRole('customer');
            $customerIdStmt = getDB()->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
            $customerIdStmt->execute([$payload['sub']]);
            $custId = $customerIdStmt->fetchColumn();
            if (!$custId) { http_response_code(404); echo json_encode(['error' => 'Customer profile not found']); exit; }
            handleCustomerContracts(getDB(), $method, $id, $sub, (int)$custId);
            exit;
        }
        if ($resource === 'iot') {
            $payload = requireRole('customer');
            $customerIdStmt = getDB()->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
            $customerIdStmt->execute([$payload['sub']]);
            $custId = $customerIdStmt->fetchColumn();
            if (!$custId) { http_response_code(404); echo json_encode(['error' => 'Customer profile not found']); exit; }
            require_once __DIR__ . '/../routes/iot.php';
            handleCustomerIotDevices(getDB(), $method, $id, $sub, $subsub, (int)$custId);
            exit;
        }
        routeCustomer($method, $resource, $id, $sub);
        break;

    case 'tech':
        require_once __DIR__ . '/../routes/tech.php';
        require_once __DIR__ . '/../routes/notes.php';
        require_once __DIR__ . '/../routes/appointments.php';
        require_once __DIR__ . '/../routes/invoices.php';
        require_once __DIR__ . '/../routes/catalog.php';
        require_once __DIR__ . '/../routes/estimates.php';
        require_once __DIR__ . '/../routes/contracts.php';
        if ($resource === 'invoices' && $sub === 'pdf' && $method === 'GET') {
            require_once __DIR__ . '/../routes/invoice_pdf.php';
            $payload = requireRole('technician', 'admin');
            handleInvoicePdf(getDB(), 'technician', (int)$id, (int)$payload['sub']);
        }
        if ($resource === 'appointments' && $sub === 'report-pdf' && $method === 'GET') {
            require_once __DIR__ . '/../routes/invoice_pdf.php';
            require_once __DIR__ . '/../routes/appointment_report_pdf.php';
            $payload = requireRole('technician', 'admin');
            handleAppointmentReportPdf(getDB(), 'tech', (int)$id);
            exit;
        }
        if ($resource === 'invoices' && in_array($sub, ['send-email','send-receipt']) && $method === 'POST') {
            require_once __DIR__ . '/../routes/gmail_oauth.php';
            require_once __DIR__ . '/../routes/email.php';
            require_once __DIR__ . '/../routes/settings.php';
            $payload = requireRole('technician', 'admin');
            handleEmailSend(getDB(), $method, (int)$id, $sub === 'send-receipt' ? 'receipt' : 'invoice');
        }
        if ($resource === 'iot') {
            $payload = requireRole('technician', 'admin');
            require_once __DIR__ . '/../routes/iot.php';
            handleAdminIotDevices(getDB(), $method, $id, $sub, $subsub, (int)$payload['sub'], 'technician');
            exit;
        }
        routeTech($method, $resource, $id, $sub, $subsub);
        break;

    case 'admin':
        require_once __DIR__ . '/../routes/admin.php';
        require_once __DIR__ . '/../routes/notes.php';
        require_once __DIR__ . '/../routes/appointments.php';
        require_once __DIR__ . '/../routes/settings.php';
        require_once __DIR__ . '/../routes/invoices.php';
        require_once __DIR__ . '/../routes/catalog.php';
        require_once __DIR__ . '/../routes/estimates.php';
        require_once __DIR__ . '/../routes/contracts.php';
        if ($resource === 'invoices' && $sub === 'pdf' && $method === 'GET') {
            require_once __DIR__ . '/../routes/invoice_pdf.php';
            $payload = requireRole('admin');
            handleInvoicePdf(getDB(), 'admin', (int)$id, (int)$payload['sub']);
        }
        if ($resource === 'appointments' && $sub === 'report-pdf' && $method === 'GET') {
            require_once __DIR__ . '/../routes/invoice_pdf.php';
            require_once __DIR__ . '/../routes/appointment_report_pdf.php';
            $payload = requireRole('admin');
            handleAppointmentReportPdf(getDB(), 'admin', (int)$id);
            exit;
        }
        if ($resource === 'invoices' && in_array($sub, ['send-email','send-receipt']) && $method === 'POST') {
            require_once __DIR__ . '/../routes/gmail_oauth.php';
            require_once __DIR__ . '/../routes/email.php';
            require_once __DIR__ . '/../routes/settings.php';
            $payload = requireRole('admin');
            handleEmailSend(getDB(), $method, (int)$id, $sub === 'send-receipt' ? 'receipt' : 'invoice');
        }
        if ($resource === 'appointments' && $sub === 'send-review' && $method === 'POST') {
            require_once __DIR__ . '/../routes/gmail_oauth.php';
            require_once __DIR__ . '/../routes/email.php';
            require_once __DIR__ . '/../routes/settings.php';
            $payload = requireRole('admin');
            handleReviewRequest(getDB(), $method, (int)$id);
            exit;
        }
        if ($resource === 'gmail') {
            require_once __DIR__ . '/../routes/gmail_oauth.php';
            require_once __DIR__ . '/../routes/settings.php';
            $payload = requireRole('admin');
            handleGmailOauth($method, $id);
            exit;
        }
        if ($resource === 'settings') {
            require_once __DIR__ . '/../routes/settings.php';
            $payload = requireRole('admin');
            handleSettings(getDB(), $method);
            exit;
        }
        if ($resource === 'qbo') {
            require_once __DIR__ . '/../routes/qbo.php';
            require_once __DIR__ . '/../routes/settings.php';
            // URL: /admin/qbo/{sub}/{subId}
            // $id = sub-resource (auth-url, status, sync-invoice, etc.)
            // $sub = subId (e.g. invoice ID for sync-invoice/123)
            handleQbo($method, $id, $sub);
            exit;
        }
        if ($resource === 'iot') {
            $payload = requireRole('admin');
            require_once __DIR__ . '/../routes/iot.php';
            handleAdminIotDevices(getDB(), $method, $id, $sub, $subsub, (int)$payload['sub'], 'admin');
            exit;
        }
        routeAdmin($method, $resource, $id, $sub, $subsub);
        break;

    case 'gmail':
 // OAuth callback - no auth prefix needed, accessible as /gmail/callback
        require_once __DIR__ . '/../routes/gmail_oauth.php';
        require_once __DIR__ . '/../routes/settings.php';
        if ($resource === 'callback' && $method === 'GET') {
            handleGmailOauth('GET', 'callback');
        } else {
            http_response_code(404); echo json_encode(['error' => 'Not found']);
        }
        break;

    case 'qbo':
 // OAuth callback - no auth prefix needed, accessible as /qbo/callback
        require_once __DIR__ . '/../routes/qbo.php';
        require_once __DIR__ . '/../routes/settings.php';
        if ($resource === 'callback' && $method === 'GET') {
            handleQbo('GET', 'callback', null);
        } else {
            http_response_code(404); echo json_encode(['error' => 'Not found']);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        break;
}
