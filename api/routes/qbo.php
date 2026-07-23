<?php
// routes/qbo.php - QuickBooks Online OAuth 2.0 + Invoice/Payment sync

if (!defined('QBO_SANDBOX_BASE')) {
    define('QBO_SANDBOX_BASE',    'https://sandbox-quickbooks.api.intuit.com/v3/company/');
    define('QBO_PRODUCTION_BASE', 'https://quickbooks.api.intuit.com/v3/company/');
    define('QBO_AUTH_URL',        'https://appcenter.intuit.com/connect/oauth2');
    define('QBO_TOKEN_URL',       'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer');
    define('QBO_REVOKE_URL',      'https://developer.api.intuit.com/v2/oauth2/tokens/revoke');
}

function handleQbo(string $method, ?string $sub, ?string $subId): void {
    error_reporting(0);
    $db  = getDB();

    if ($sub === 'callback' && $method === 'GET') {
        handleQboCallback($db);
        return;
    }

    requireRole('admin');

    $cfg = getQboConfig($db);

    try {
        switch ($sub) {
        case 'auth-url':
            if ($method !== 'GET') sendError(405, 'GET only');
            $clientId = $cfg['qbo_client_id'] ?? '';
            if (empty($clientId)) {
                sendError(400, 'QBO Client ID not saved. Enter your Client ID and click Save QBO Settings first. Config keys found: ' . implode(',', array_keys($cfg)));
            }
            $redirect = getCallbackUrl();
            $url = QBO_AUTH_URL . '?' . http_build_query([
                'client_id'     => $clientId,
                'response_type' => 'code',
                'scope'         => 'com.intuit.quickbooks.accounting',
                'redirect_uri'  => $redirect,
                'state'         => bin2hex(random_bytes(16)),
            ]);
            sendJson(['url' => $url, 'redirect_uri' => $redirect]);
            break;

        case 'status':
            if ($method !== 'GET') sendError(405, 'GET only');
            $connected = !empty($cfg['qbo_access_token']) && !empty($cfg['qbo_realm_id']);
            $expired   = $connected && (int)$cfg['qbo_token_expires'] < time();
            if ($expired && !empty($cfg['qbo_refresh_token'])) {
 // Try to refresh
                $refreshed = refreshQboToken($db, $cfg);
                if ($refreshed) {
                    $cfg = getQboConfig($db);
                    $expired = false;
                }
            }
            sendJson([
                'connected'   => $connected && !$expired,
                'realm_id'    => $cfg['qbo_realm_id'] ?: null,
                'environment' => $cfg['qbo_environment'],
                'enabled'     => $cfg['qbo_enabled'] === '1',
                'expires_at'  => $cfg['qbo_token_expires'] ? date('Y-m-d H:i:s', (int)$cfg['qbo_token_expires']) : null,
            ]);
            break;

        case 'disconnect':
            if ($method !== 'POST') sendError(405, 'POST only');
            if (!empty($cfg['qbo_access_token'])) {
                revokeQboToken($cfg['qbo_access_token'], $cfg['qbo_client_id'], $cfg['qbo_client_secret']);
            }
            foreach (['qbo_access_token','qbo_refresh_token','qbo_realm_id','qbo_token_expires'] as $k) {
                updateSetting($db, $k, '');
            }
            updateSetting($db, 'qbo_enabled', '0');
            sendJson(['message' => 'Disconnected from QuickBooks']);
            break;

        case 'sync-invoice':
            if ($method !== 'POST' || !$subId) sendError(400, 'Invoice ID required');
            $result = syncInvoiceToQbo($db, $cfg, (int)$subId);
            sendJson($result);
            break;

        case 'force-sync-invoice':
            if ($method !== 'POST' || !$subId) sendError(400, 'Invoice ID required');
            $result = forceResyncInvoiceToQbo($db, $cfg, (int)$subId);
            sendJson($result);
            return;

        case 'sync-payment':
            if ($method !== 'POST' || !$subId) sendError(400, 'Payment ID required');
            $result = syncPaymentToQbo($db, $cfg, (int)$subId);
            sendJson($result);
            break;

        case 'bank-accounts':
            if ($method !== 'GET') sendError(405, 'GET only');
            $res      = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode("select Id,Name,AccountType,AccountSubType from Account where AccountType='Bank' and AccountSubType='Checking' MAXRESULTS 100"));
            $accounts = $res['QueryResponse']['Account'] ?? [];
            sendJson(array_map(fn($a) => ['id' => $a['Id'], 'name' => $a['Name']], $accounts));
            return;

        case 'pull-payment':
            if ($method !== 'POST' || !$subId) sendError(400, 'Invoice ID required');
            $result = pullPaymentFromQbo($db, $cfg, (int)$subId);
            sendJson($result);
            return;

        case 'bulk-force-resync':
            if ($method !== 'POST') sendError(405, 'POST only');
            set_time_limit(300); // 5 minutes for bulk operation
            $result = bulkForceResyncToQbo($db, $cfg);
            sendJson($result);
            return;

        case 'bulk-pull-payments':
            if ($method !== 'POST') sendError(405, 'POST only');
            set_time_limit(300); // 5 minutes for bulk operation
            $result = bulkPullPaymentsFromQbo($db, $cfg);
            sendJson($result);
            return;

        case 'sync-all':
            if ($method !== 'POST') sendError(405, 'POST only');
            $results = syncAllUnsynced($db, $cfg);
            sendJson($results);
            break;

        case 'customers':
            if ($method !== 'GET') sendError(405, 'GET only');
            $customers = qboApiRequest($db, $cfg, 'GET', "query?query=select * from Customer MAXRESULTS 100");
            $list = $customers['QueryResponse']['Customer'] ?? [];
            sendJson($list);
            break;

 // GET /admin/qbo/import-preview - fetch QBO customers not yet in CRM (preview before import)
 // POST /admin/qbo/import-customers - actually import selected or all
        case 'import-preview':
            if ($method !== 'GET') sendError(405, 'GET only');
            $qboList = fetchAllQboCustomers($db, $cfg);
 // Find which QBO IDs are already mapped
            $mapped = [];
            $rows = $db->query("SELECT qbo_customer_id FROM qbo_customers")->fetchAll();
            foreach ($rows as $r) $mapped[$r['qbo_customer_id']] = true;
            $preview = [];
            foreach ($qboList as $qc) {
                $qid = $qc['Id'] ?? null;
                if (!$qid) continue;
                $preview[] = [
                    'qbo_id'       => $qid,
                    'display_name' => $qc['DisplayName'] ?? '',
                    'first_name'   => $qc['GivenName']   ?? '',
                    'last_name'    => $qc['FamilyName']   ?? '',
                    'email'        => $qc['PrimaryEmailAddr']['Address'] ?? '',
                    'phone'        => $qc['PrimaryPhone']['FreeFormNumber'] ?? '',
                    'address'      => $qc['BillAddr']['Line1']   ?? '',
                    'city'         => $qc['BillAddr']['City']    ?? '',
                    'state'        => $qc['BillAddr']['CountrySubDivisionCode'] ?? '',
                    'zip'          => $qc['BillAddr']['PostalCode'] ?? '',
                    'already_imported' => isset($mapped[$qid]),
                    'active'       => ($qc['Active'] ?? true) === true || ($qc['Active'] ?? 'true') === 'true',
                ];
            }
            sendJson($preview);
            break;

        case 'import-customers':
            if ($method !== 'POST') sendError(405, 'POST only');
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $qboIds  = $body['qbo_ids'] ?? null;   // null = import all new; array = import specific
            $qboList = fetchAllQboCustomers($db, $cfg);
  // Build set of already-mapped QBO IDs
            $mapped  = [];
            $rows    = $db->query("SELECT qbo_customer_id FROM qbo_customers")->fetchAll();
            foreach ($rows as $r) $mapped[$r['qbo_customer_id']] = true;
            $imported = 0;
            $skipped  = 0;
            $errors   = [];
            foreach ($qboList as $qc) {
                $qid = $qc['Id'] ?? null;
                if (!$qid) continue;
  // Skip already imported
                if (isset($mapped[$qid])) { $skipped++; continue; }
  // If specific IDs requested, skip others
                if ($qboIds !== null && !in_array($qid, $qboIds)) { $skipped++; continue; }
  // Skip inactive QBO customers
                $isActive = ($qc['Active'] ?? true);
                if ($isActive === false || $isActive === 'false') { $skipped++; continue; }
                $firstName = trim($qc['GivenName']  ?? '');
                $lastName  = trim($qc['FamilyName'] ?? '');
                $display   = trim($qc['DisplayName'] ?? '');
  // Fall back: split DisplayName if no given/family name
                if (!$firstName && !$lastName && $display) {
  // DisplayName may be "LASTNAME, Firstname" or "First Last"
                    if (strpos($display, ',') !== false) {
                        [$ln, $fn] = array_map('trim', explode(',', $display, 2));
                        $lastName  = $ln;
                        $firstName = $fn;
                    } else {
                        $parts     = explode(' ', $display, 2);
                        $firstName = $parts[0];
                        $lastName  = $parts[1] ?? '';
                    }
                }
                if (!$firstName && !$lastName) { $skipped++; continue; }
                $email   = trim($qc['PrimaryEmailAddr']['Address'] ?? '');
                $phone   = trim($qc['PrimaryPhone']['FreeFormNumber'] ?? '');
                $addr    = trim($qc['BillAddr']['Line1']   ?? '');
                $city    = trim($qc['BillAddr']['City']    ?? '');
                $state   = trim($qc['BillAddr']['CountrySubDivisionCode'] ?? '');
                $zip     = trim($qc['BillAddr']['PostalCode'] ?? '');
                try {
                    $db->beginTransaction();
  // Create user account (placeholder email if none)
                    $loginEmail = $email ?: ('noemail_' . strtolower($lastName) . '_' . strtolower($firstName) . '_' . uniqid() . '@noemail.local');
                    $hash       = password_hash('Water2026*', PASSWORD_BCRYPT);
                    $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'customer')")
                       ->execute([$loginEmail, $hash]);
                    $userId = (int)$db->lastInsertId();
  // Create customer profile
                    $db->prepare(
                        "INSERT INTO customers (user_id, first_name, last_name, phone, email,
                                                service_address, service_city, service_state, service_zip)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([$userId, $firstName, $lastName, $phone ?: null,
                                $email ?: null, $addr ?: null, $city ?: null, $state ?: null, $zip ?: null]);
                    $customerId = (int)$db->lastInsertId();
  // Save QBO mapping so we never double-import
                    $db->prepare(
                        "INSERT INTO qbo_customers (customer_id, qbo_customer_id, qbo_display_name)
                         VALUES (?, ?, ?)"
                    )->execute([$customerId, $qid, $display]);
                    $db->commit();
                    $imported++;
                } catch (\Throwable $e) {
                    $db->rollBack();
                    $errors[] = "QBO #{$qid} ({$display}): " . $e->getMessage();
                    $skipped++;
                }
            }
            sendJson(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
            break;

        case 'pull-customer-invoices':
            if ($method !== 'GET' || !$subId) sendError(400, 'Customer ID required');
            $result = pullCustomerInvoicesFromQbo($db, $cfg, (int)$subId);
            sendJson($result);
            break;

        default:
            sendError(404, 'QBO endpoint not found: ' . ($sub ?? 'null'));
        }
    } catch (\Throwable $e) {
        sendError(500, 'QBO error: ' . $e->getMessage());
    }
}

function handleQboCallback(PDO $db): void {
    $code    = $_GET['code']    ?? '';
    $realmId = $_GET['realmId'] ?? '';
    $error   = $_GET['error']   ?? '';

 // Always respond with HTML that closes the popup and messages the parent
    header('Content-Type: text/html');

    $sendPopupResult = function(string $status, string $message) {
        $safeMsg = htmlspecialchars($message, ENT_QUOTES);
        echo "<!DOCTYPE html><html><body><script>
            try {
                window.opener && window.opener.postMessage({qbo: '{$status}', message: '{$safeMsg}'}, '*');
            } catch(e) {}
            window.close();
        </script><p>{$safeMsg}</p></body></html>";
        exit;
    };

    if ($error) {
        $sendPopupResult('error', 'QuickBooks error: ' . $error);
    }

    if (!$code || !$realmId) {
        $sendPopupResult('error', 'Missing authorization code or company ID');
    }

    $cfg      = getQboConfig($db);
    $redirect = getCallbackUrl();

 // Exchange code for tokens
    $response = httpPost(QBO_TOKEN_URL, [
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => $redirect,
    ], [
        'Authorization: Basic ' . base64_encode($cfg['qbo_client_id'] . ':' . $cfg['qbo_client_secret']),
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ]);

    if (!isset($response['access_token'])) {
        $detail = isset($response['error']) ? $response['error'] : json_encode($response);
        $sendPopupResult('error', 'Token exchange failed: ' . $detail);
    }

    $expires = time() + (int)($response['expires_in'] ?? 3600) - 60;
    updateSetting($db, 'qbo_access_token',  $response['access_token']);
    updateSetting($db, 'qbo_refresh_token', $response['refresh_token'] ?? '');
    updateSetting($db, 'qbo_realm_id',      $realmId);
    updateSetting($db, 'qbo_token_expires', (string)$expires);
    updateSetting($db, 'qbo_enabled',       '1');

    $sendPopupResult('connected', 'Connected successfully! This window will close.');
}

function refreshQboToken(PDO $db, array $cfg): bool {
    if (empty($cfg['qbo_refresh_token'])) return false;
    $response = httpPost(QBO_TOKEN_URL, [
        'grant_type'    => 'refresh_token',
        'refresh_token' => $cfg['qbo_refresh_token'],
    ], [
        'Authorization: Basic ' . base64_encode($cfg['qbo_client_id'] . ':' . $cfg['qbo_client_secret']),
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ]);
    if (!isset($response['access_token'])) return false;
    $expires = time() + (int)($response['expires_in'] ?? 3600) - 60;
    updateSetting($db, 'qbo_access_token',  $response['access_token']);
    if (!empty($response['refresh_token'])) {
        updateSetting($db, 'qbo_refresh_token', $response['refresh_token']);
    }
    updateSetting($db, 'qbo_token_expires', (string)$expires);
    return true;
}

function revokeQboToken(string $token, string $clientId, string $clientSecret): void {
    httpPost(QBO_REVOKE_URL, ['token' => $token], [
        'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ]);
}

function syncInvoiceToQbo(PDO $db, array $cfg, int $invoiceId): array {
    ensureQboReady($db, $cfg);

 // Load invoice with lines
    $stmt = $db->prepare(
        "SELECT i.*, c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                c.email AS customer_email, c.phone AS customer_phone,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.customer_id
         FROM invoices i
         JOIN customers c ON i.customer_id = c.customer_id
         WHERE i.invoice_id = ?"
    );
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv) return ['error' => 'Invoice not found'];

    if ($inv['qbo_id']) {
        return ['message' => 'Already synced', 'qbo_id' => $inv['qbo_id']];
    }

 // Load line items
    $stmt2 = $db->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order, line_id");
    $stmt2->execute([$invoiceId]);
    $lines = $stmt2->fetchAll();

    if (empty($lines)) {
        return ['error' => 'Invoice has no line items - add at least one line before syncing'];
    }

 // Get or create QBO customer
    $qboCustomerId = getOrCreateQboCustomer($db, $cfg, $inv['customer_id'], $inv);

 // Get a valid QBO item ID (looks up once, caches in qbo_items table)
    $defaultItemId = getDefaultQboItemId($db, $cfg);

 // Build line items
    $lineItems = [];
    $sortOrder = 1;
    foreach ($lines as $line) {
        $amt = (float)$line['line_total'];
        if ($amt == 0) continue;
 // Use line_name as the primary QBO description; fall back to description for legacy lines
        $qboDesc = !empty($line['line_name']) ? $line['line_name'] : $line['description'];
        if (!empty($line['description']) && $line['description'] !== $line['line_name']) {
            $qboDesc .= ' - ' . $line['description'];
        }
        $taxCodeRef = !empty($line['is_taxable']) ? ['value' => 'TAX'] : ['value' => 'NON'];
        if ($defaultItemId) {
            $lineItems[] = [
                'Id'          => (string)$sortOrder++,
                'DetailType'  => 'SalesItemLineDetail',
                'Amount'      => $amt,
                'Description' => $qboDesc,
                'TaxCodeRef'  => $taxCodeRef,
                'SalesItemLineDetail' => [
                    'ItemRef'  => ['value' => $defaultItemId],
                    'Qty'      => (float)$line['quantity'],
                    'UnitPrice'=> (float)$line['unit_price'],
                ],
            ];
        } else {
            $lineItems[] = [
                'Id'          => (string)$sortOrder++,
                'DetailType'  => 'DescriptionOnly',
                'Amount'      => $amt,
                'Description' => $qboDesc,
                'TaxCodeRef'  => $taxCodeRef,
            ];
        }
    }

 // Card fee line (always taxable)
    if ((float)($inv['card_fee_amount'] ?? 0) > 0) {
        $amt = (float)$inv['card_fee_amount'];
        if ($defaultItemId) {
            $lineItems[] = [
                'Id'          => (string)$sortOrder++,
                'DetailType'  => 'SalesItemLineDetail',
                'Amount'      => $amt,
                'Description' => 'Credit/Debit Service Fee (3.5%)',
                'TaxCodeRef'  => ['value' => 'TAX'],
                'SalesItemLineDetail' => [
                    'ItemRef'  => ['value' => $defaultItemId],
                    'Qty'      => 1,
                    'UnitPrice'=> $amt,
                ],
            ];
        } else {
            $lineItems[] = [
                'Id'          => (string)$sortOrder++,
                'DetailType'  => 'DescriptionOnly',
                'Amount'      => $amt,
                'Description' => 'Credit/Debit Service Fee (3.5%)',
                'TaxCodeRef'  => ['value' => 'TAX'],
            ];
        }
    }

    $txnDate = $inv['issue_date'] ?: date('Y-m-d');
    $dueDate = !empty($inv['due_date']) ? $inv['due_date'] : $txnDate;

    $payload = [
        'Line'                  => $lineItems,
        'CustomerRef'           => ['value' => $qboCustomerId],
        'DocNumber'             => $inv['invoice_number'],
        'TxnDate'               => $txnDate,
        'DueDate'               => $dueDate,
        'GlobalTaxCalculation'  => 'TaxExcluded',
    ];

 // Pass our pre-calculated tax as an override so QBO does not recalculate
    $taxAmount = (float)($inv['tax_amount'] ?? 0);
    $taxRateId = getDefaultQboTaxRateId($db, $cfg);
    if ($taxAmount > 0 && $taxRateId !== null) {
        $taxRate = round((float)($inv['tax_rate'] ?? 0) * 100, 4); // stored as decimal, e.g. 0.1025
        $payload['TxnTaxDetail'] = [
            'TotalTax' => $taxAmount,
            'TaxLine'  => [[
                'DetailType'   => 'TaxLineDetail',
                'Amount'       => $taxAmount,
                'TaxLineDetail' => [
                    'TaxRateRef'        => ['value' => $taxRateId],
                    'PercentBased'      => true,
                    'TaxPercent'        => $taxRate,
                    'NetAmountTaxable'  => (float)($inv['taxable_amount'] ?? 0),
                ],
            ]],
        ];
    }

    if (!empty($inv['notes'])) {
        $payload['CustomerMemo'] = ['value' => $inv['notes']];
    }

    $result = qboApiRequest($db, $cfg, 'POST', 'invoice', $payload);

    if (isset($result['Invoice']['Id'])) {
        $qboId = $result['Invoice']['Id'];
        $db->prepare("UPDATE invoices SET qbo_id=?, qbo_sync_status='synced', qbo_synced_at=NOW(), qbo_sync_error=NULL WHERE invoice_id=?")
           ->execute([$qboId, $invoiceId]);
        return ['success' => true, 'qbo_id' => $qboId, 'message' => 'Invoice synced to QuickBooks'];
    }

    $errMsg = json_encode(['payload_sent' => $payload, 'qbo_response' => $result]);
    $db->prepare("UPDATE invoices SET qbo_sync_status='error', qbo_sync_error=? WHERE invoice_id=?")
       ->execute([substr($errMsg, 0, 2000), $invoiceId]);
    return ['error' => 'QBO sync failed', 'detail' => $errMsg];
}

function syncPaymentToQbo(PDO $db, array $cfg, int $paymentId): array {
    ensureQboReady($db, $cfg);

    $stmt = $db->prepare(
        "SELECT p.*, i.qbo_id AS invoice_qbo_id, i.total AS invoice_total,
                i.customer_id, i.invoice_number,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name
         FROM payments p
         JOIN invoices i ON p.invoice_id = i.invoice_id
         JOIN customers c ON i.customer_id = c.customer_id
         WHERE p.payment_id = ?"
    );
    $stmt->execute([$paymentId]);
    $pay = $stmt->fetch();
    if (!$pay) return ['error' => 'Payment not found'];
    if ($pay['qbo_payment_id']) return ['message' => 'Already synced', 'qbo_payment_id' => $pay['qbo_payment_id']];

 // Invoice must be synced first
    if (!$pay['invoice_qbo_id']) {
        $syncResult = syncInvoiceToQbo($db, $cfg, $pay['invoice_id']);
        if (!isset($syncResult['qbo_id'])) return ['error' => 'Invoice must sync first', 'detail' => $syncResult];
        $pay['invoice_qbo_id'] = $syncResult['qbo_id'];
    }

    $qboCustomerId = getQboCustomerIdForCustomer($db, $pay['customer_id']);

 // Map internal payment method QBO Payment Method name
    $methodNameMap = [
        'cash'          => 'Cash',
        'check'         => 'Check',
        'card_in_field' => 'Credit Card',
        'card_field'    => 'Credit Card',
        'card_on_file'  => 'Credit Card',
        'card_office'   => 'Credit Card',
        'bank_transfer' => 'Check',   // closest QBO equivalent
        'other'         => null,
    ];
    $qboMethodName = $methodNameMap[$pay['payment_method']] ?? null;
    $qboMethodId   = null;
    if ($qboMethodName) {
        $qboMethodId = resolveQboPaymentMethodId($db, $cfg, $qboMethodName);
    }

 // Deposit account: prefer explicit deposit_account_id stored on payment, fall back to card-field config
    $isCardField    = in_array($pay['payment_method'], ['card_field', 'card_in_field', 'card_office']);
    $depositAccount = null;
    if (!empty($pay['deposit_account_id'])) {
 // Explicit account chosen at payment time
        $accountId = resolveQboAccountId($db, $cfg, $pay['deposit_account_id']);
        if ($accountId) $depositAccount = ['value' => $accountId];
    } elseif ($isCardField && !empty($cfg['qbo_card_account'])) {
        $accountId = resolveQboAccountId($db, $cfg, $cfg['qbo_card_account']);
        if ($accountId) $depositAccount = ['value' => $accountId];
    }

    $payload = [
        'CustomerRef'   => ['value' => $qboCustomerId],
        'TotalAmt'      => (float)$pay['amount'],
        'TxnDate'       => $pay['payment_date'],
        'PaymentRefNum' => !empty($pay['check_number'])
            ? substr($pay['check_number'], 0, 21)
            : ($pay['payment_notes'] ? substr($pay['payment_notes'], 0, 21) : null),
        'Line' => [[
            'Amount'     => (float)$pay['amount'],
            'LinkedTxn'  => [[
                'TxnId'   => $pay['invoice_qbo_id'],
                'TxnType' => 'Invoice',
            ]],
        ]],
    ];
    if ($qboMethodId) {
        $payload['PaymentMethodRef'] = ['value' => $qboMethodId];
    }
    if ($depositAccount) {
        $payload['DepositToAccountRef'] = $depositAccount;
    }

    $result = null;
    try {
        $result = qboApiRequest($db, $cfg, 'POST', 'payment', $payload);
    } catch (\RuntimeException $e) {
 // Error 620: TxnID Cannot Be Linked - invoice is in a bad state in QBO.
 // Auto-force-resync the invoice then retry the payment once.
        if (strpos($e->getMessage(), 'Code: 620') !== false) {
            $resync = forceResyncInvoiceToQbo($db, $cfg, (int)$pay['invoice_id']);
            if (!isset($resync['success'])) {
                $db->prepare("UPDATE payments SET qbo_sync_status='error' WHERE payment_id=?")
                   ->execute([$paymentId]);
                return ['error' => 'Payment failed (620) and invoice resync also failed', 'resync_detail' => $resync];
            }
 // Retry payment after successful resync
            try {
                $result = qboApiRequest($db, $cfg, 'POST', 'payment', $payload);
                if (isset($result['Payment']['Id'])) {
                    $result['_auto_resynced_invoice'] = true;
                }
            } catch (\RuntimeException $e2) {
                $db->prepare("UPDATE payments SET qbo_sync_status='error' WHERE payment_id=?")
                   ->execute([$paymentId]);
                return ['error' => 'Payment failed after invoice resync: ' . $e2->getMessage()];
            }
        } else {
            $db->prepare("UPDATE payments SET qbo_sync_status='error' WHERE payment_id=?")
               ->execute([$paymentId]);
            return ['error' => 'Payment sync failed: ' . $e->getMessage()];
        }
    }

    if (isset($result['Payment']['Id'])) {
        $qboPayId = $result['Payment']['Id'];
        $db->prepare("UPDATE payments SET qbo_payment_id=?, qbo_sync_status='synced', qbo_synced_at=NOW() WHERE payment_id=?")
           ->execute([$qboPayId, $paymentId]);

 // Add note about card-in-field
        if ($isCardField) {
            $note = "Card payment recorded in field. Deposited to Undeposited Funds in QBO - process through terminal and mark deposited.";
            $db->prepare("UPDATE payments SET payment_notes = CONCAT(IFNULL(payment_notes,''), ?) WHERE payment_id=?")
               ->execute([' ['.$note.']', $paymentId]);
        }
        return ['success' => true, 'qbo_payment_id' => $qboPayId, 'card_field' => $isCardField];
    }

    $errMsg = json_encode($result);
    $db->prepare("UPDATE payments SET qbo_sync_status='error' WHERE payment_id=?")
       ->execute([$paymentId]);
    return ['error' => 'Payment sync failed', 'detail' => $errMsg];
}

function syncAllUnsynced(PDO $db, array $cfg): array {
    $results = ['invoices' => [], 'payments' => [], 'errors' => []];

 // Unsynced invoices (paid or sent)
    $stmt = $db->query("SELECT invoice_id FROM invoices WHERE qbo_id IS NULL AND status IN ('paid','sent') LIMIT 50");
    foreach ($stmt->fetchAll() as $row) {
        $r = syncInvoiceToQbo($db, $cfg, (int)$row['invoice_id']);
        if (isset($r['qbo_id'])) $results['invoices'][] = $row['invoice_id'];
        else $results['errors'][] = 'Invoice '.$row['invoice_id'].': '.($r['error']??'unknown');
    }

 // Unsynced payments
    $stmt2 = $db->query("SELECT payment_id FROM payments WHERE qbo_payment_id IS NULL LIMIT 50");
    foreach ($stmt2->fetchAll() as $row) {
        $r = syncPaymentToQbo($db, $cfg, (int)$row['payment_id']);
        if (isset($r['qbo_payment_id'])) $results['payments'][] = $row['payment_id'];
        else $results['errors'][] = 'Payment '.$row['payment_id'].': '.($r['error']??'unknown');
    }

    $results['summary'] = sprintf(
        'Synced %d invoice(s) and %d payment(s). %d error(s).',
        count($results['invoices']), count($results['payments']), count($results['errors'])
    );
    return $results;
}

// Fetches SyncToken from QBO then POSTs a full update in place.
function forceResyncInvoiceToQbo(PDO $db, array $cfg, int $invoiceId): array {
    ensureQboReady($db, $cfg);

 // Load invoice
    $stmt = $db->prepare(
        "SELECT i.*, c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                c.email AS customer_email, c.phone AS customer_phone,
                c.service_address, c.service_city, c.service_state, c.service_zip,
                c.customer_id
         FROM invoices i
         JOIN customers c ON i.customer_id = c.customer_id
         WHERE i.invoice_id = ?"
    );
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv) return ['error' => 'Invoice not found'];

    if (!$inv['qbo_id']) {
 // Not yet synced - fall through to normal sync
        return syncInvoiceToQbo($db, $cfg, $invoiceId);
    }

 // Fetch current QBO invoice to get SyncToken (required for updates)
    $qboInvoice = null;
    try {
        $fetched    = qboApiRequest($db, $cfg, 'GET', 'invoice/' . $inv['qbo_id']);
        $qboInvoice = $fetched['Invoice'] ?? null;
    } catch (\RuntimeException $e) {
        return ['error' => 'Could not fetch invoice from QBO: ' . $e->getMessage()];
    }
    if (!$qboInvoice || !isset($qboInvoice['SyncToken'])) {
        return ['error' => 'QBO returned no SyncToken for invoice ' . $inv['qbo_id'] . ' - it may have been deleted in QBO'];
    }
    $syncToken = $qboInvoice['SyncToken'];

 // Load line items
    $stmt2 = $db->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order, line_id");
    $stmt2->execute([$invoiceId]);
    $lines = $stmt2->fetchAll();
    if (empty($lines)) {
        return ['error' => 'Invoice has no line items'];
    }

    $qboCustomerId = getOrCreateQboCustomer($db, $cfg, $inv['customer_id'], $inv);
    $defaultItemId = getDefaultQboItemId($db, $cfg);

    $lineItems = [];
    $sortOrder  = 1;
    foreach ($lines as $line) {
        $amt = (float)$line['line_total'];
        if ($amt == 0) continue;
        $qboDesc = !empty($line['line_name']) ? $line['line_name'] : $line['description'];
        if (!empty($line['description']) && $line['description'] !== $line['line_name']) {
            $qboDesc .= ' - ' . $line['description'];
        }
        $taxCodeRef = !empty($line['is_taxable']) ? ['value' => 'TAX'] : ['value' => 'NON'];
        if ($defaultItemId) {
            $lineItems[] = [
                'Id'          => (string)$sortOrder++,
                'DetailType'  => 'SalesItemLineDetail',
                'Amount'      => $amt,
                'Description' => $qboDesc,
                'TaxCodeRef'  => $taxCodeRef,
                'SalesItemLineDetail' => [
                    'ItemRef'  => ['value' => $defaultItemId],
                    'Qty'      => (float)$line['quantity'],
                    'UnitPrice'=> (float)$line['unit_price'],
                ],
            ];
        } else {
            $lineItems[] = [
                'Id'          => (string)$sortOrder++,
                'DetailType'  => 'DescriptionOnly',
                'Amount'      => $amt,
                'Description' => $qboDesc,
                'TaxCodeRef'  => $taxCodeRef,
            ];
        }
    }
    if ((float)($inv['card_fee_amount'] ?? 0) > 0) {
        $amt = (float)$inv['card_fee_amount'];
        if ($defaultItemId) {
            $lineItems[] = [
                'Id'          => (string)$sortOrder++,
                'DetailType'  => 'SalesItemLineDetail',
                'Amount'      => $amt,
                'Description' => 'Credit/Debit Service Fee (3.5%)',
                'TaxCodeRef'  => ['value' => 'TAX'],
                'SalesItemLineDetail' => [
                    'ItemRef'  => ['value' => $defaultItemId],
                    'Qty'      => 1,
                    'UnitPrice'=> $amt,
                ],
            ];
        } else {
            $lineItems[] = [
                'Id'          => (string)$sortOrder++,
                'DetailType'  => 'DescriptionOnly',
                'Amount'      => $amt,
                'Description' => 'Credit/Debit Service Fee (3.5%)',
                'TaxCodeRef'  => ['value' => 'TAX'],
            ];
        }
    }

    $txnDate = $inv['issue_date'] ?: date('Y-m-d');
    $dueDate = !empty($inv['due_date']) ? $inv['due_date'] : $txnDate;

 // QBO full update: must include Id + SyncToken
    $payload = [
        'Id'                   => $inv['qbo_id'],
        'SyncToken'            => $syncToken,
        'sparse'               => true,
        'Line'                 => $lineItems,
        'CustomerRef'          => ['value' => $qboCustomerId],
        'DocNumber'            => $inv['invoice_number'],
        'TxnDate'              => $txnDate,
        'DueDate'              => $dueDate,
        'GlobalTaxCalculation' => 'TaxExcluded',
    ];

 // Pass our pre-calculated tax as an override so QBO does not recalculate
    $taxAmount = (float)($inv['tax_amount'] ?? 0);
    $taxRateId = getDefaultQboTaxRateId($db, $cfg);
    if ($taxAmount > 0 && $taxRateId !== null) {
        $taxRate = round((float)($inv['tax_rate'] ?? 0) * 100, 4); // stored as decimal, e.g. 0.1025
        $payload['TxnTaxDetail'] = [
            'TotalTax' => $taxAmount,
            'TaxLine'  => [[
                'DetailType'    => 'TaxLineDetail',
                'Amount'        => $taxAmount,
                'TaxLineDetail' => [
                    'TaxRateRef'       => ['value' => $taxRateId],
                    'PercentBased'     => true,
                    'TaxPercent'       => $taxRate,
                    'NetAmountTaxable' => (float)($inv['taxable_amount'] ?? 0),
                ],
            ]],
        ];
    }

    if (!empty($inv['notes'])) {
        $payload['CustomerMemo'] = ['value' => $inv['notes']];
    }

    try {
        $result = qboApiRequest($db, $cfg, 'POST', 'invoice', $payload);
    } catch (\RuntimeException $e) {
        $db->prepare("UPDATE invoices SET qbo_sync_status='error', qbo_sync_error=? WHERE invoice_id=?")
           ->execute([substr($e->getMessage(), 0, 2000), $invoiceId]);
        return ['error' => $e->getMessage()];
    }

    if (isset($result['Invoice']['Id'])) {
        $db->prepare("UPDATE invoices SET qbo_sync_status='synced', qbo_synced_at=NOW(), qbo_sync_error=NULL WHERE invoice_id=?")
           ->execute([$invoiceId]);

 // Auto-sync any local payments that haven't been pushed to QBO yet
        $payStmt = $db->prepare("SELECT payment_id FROM payments WHERE invoice_id = ? AND (qbo_payment_id IS NULL OR qbo_payment_id = '')");
        $payStmt->execute([$invoiceId]);
        $unpushedPayments = $payStmt->fetchAll();
        $paymentResults = [];
        foreach ($unpushedPayments as $p) {
            $pr = syncPaymentToQbo($db, $cfg, (int)$p['payment_id']);
            $paymentResults[] = $pr;
        }

        return [
            'success'          => true,
            'qbo_id'           => $inv['qbo_id'],
            'message'          => 'Invoice updated in QuickBooks',
            'payments_synced'  => count(array_filter($paymentResults, fn($r) => !empty($r['success']))),
            'payment_errors'   => count(array_filter($paymentResults, fn($r) => !empty($r['error']))),
        ];
    }

    $errMsg = json_encode($result);
    $db->prepare("UPDATE invoices SET qbo_sync_status='error', qbo_sync_error=? WHERE invoice_id=?")
       ->execute([substr($errMsg, 0, 2000), $invoiceId]);
    return ['error' => 'QBO update failed', 'detail' => $errMsg];
}

// QBO IDS does not support filtering on Line.LinkedTxn.TxnId, so we
// query payments by CustomerRef and filter locally for the matching invoice.
function pullPaymentFromQbo(PDO $db, array $cfg, int $invoiceId): array {
    ensureQboReady($db, $cfg);

    $stmt = $db->prepare(
        "SELECT i.qbo_id, i.customer_id, p.payment_id, p.qbo_payment_id,
                qc.qbo_customer_id
         FROM invoices i
         LEFT JOIN payments p ON p.invoice_id = i.invoice_id
         LEFT JOIN qbo_customers qc ON qc.customer_id = i.customer_id
         WHERE i.invoice_id = ?"
    );
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch();
    if (!$row)           return ['error' => 'Invoice not found'];
    if (!$row['qbo_id']) return ['error' => 'Invoice has not been synced to QBO yet'];

    $qboInvoiceId  = $row['qbo_id'];
    $qboCustomerId = $row['qbo_customer_id'] ?? null;

 // Query payments by customer (LinkedTxn.TxnId is not filterable in IDS)
    if ($qboCustomerId) {
        $query = "select * from Payment where CustomerRef = '" . str_replace("'", "''", $qboCustomerId) . "' MAXRESULTS 20";
    } else {
 // Fallback: fetch recent payments and scan - slower but works without customer mapping
        $query = "select * from Payment ORDERBY TxnDate DESC MAXRESULTS 50";
    }

    try {
        $result = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode($query));
    } catch (\RuntimeException $e) {
        return ['error' => 'QBO query failed: ' . $e->getMessage()];
    }

    $allPayments = $result['QueryResponse']['Payment'] ?? [];

 // Filter locally for a payment whose lines reference our invoice
    $matched = null;
    foreach ($allPayments as $qboPay) {
        foreach ($qboPay['Line'] ?? [] as $line) {
            foreach ($line['LinkedTxn'] ?? [] as $link) {
                if (($link['TxnType'] ?? '') === 'Invoice' && (string)($link['TxnId'] ?? '') === (string)$qboInvoiceId) {
                    $matched = $qboPay;
                    break 3;
                }
            }
        }
    }

    if (!$matched) {
        return ['message' => 'No payment found in QBO linked to this invoice', 'qbo_invoice_id' => $qboInvoiceId];
    }

    $qboPayId = $matched['Id'];

    if (!$row['payment_id']) {
        return ['message' => 'QBO payment found but no local payment record exists - record a payment in MVP first', 'qbo_payment_id' => $qboPayId];
    }

    $db->prepare("UPDATE payments SET qbo_payment_id=?, qbo_sync_status='synced', qbo_synced_at=NOW() WHERE payment_id=?")
       ->execute([$qboPayId, $row['payment_id']]);

    return ['success' => true, 'qbo_payment_id' => $qboPayId, 'qbo_total' => $matched['TotalAmt'] ?? null];
}

function bulkForceResyncToQbo(PDO $db, array $cfg): array {
    ensureQboReady($db, $cfg);

    $stmt = $db->query("SELECT invoice_id FROM invoices WHERE qbo_id IS NOT NULL LIMIT 50");
    $rows = $stmt->fetchAll();

    $resynced = [];
    $errors   = [];
    foreach ($rows as $row) {
 // Reload cfg each iteration to pick up any token refresh that occurred
        $cfg = getQboConfig($db);
        $r = forceResyncInvoiceToQbo($db, $cfg, (int)$row['invoice_id']);
        if (!empty($r['success'])) {
            $resynced[] = $row['invoice_id'];
        } else {
            $errors[] = 'Invoice ' . $row['invoice_id'] . ': ' . ($r['error'] ?? 'unknown');
        }
    }

    return [
        'resynced' => $resynced,
        'errors'   => $errors,
        'summary'  => sprintf('Resynced %d invoice(s) to QBO. %d error(s).', count($resynced), count($errors)),
    ];
}

function bulkPullPaymentsFromQbo(PDO $db, array $cfg): array {
    ensureQboReady($db, $cfg);

 // Invoices synced to QBO that have a local payment without a qbo_payment_id
    $stmt = $db->query(
        "SELECT i.invoice_id, i.qbo_id, p.payment_id
         FROM invoices i
         JOIN payments p ON p.invoice_id = i.invoice_id
         WHERE i.qbo_id IS NOT NULL
           AND (p.qbo_payment_id IS NULL OR p.qbo_payment_id = '')
         LIMIT 50"
    );
    $rows = $stmt->fetchAll();

    $pulled = [];
    $errors = [];
    foreach ($rows as $row) {
        $cfg = getQboConfig($db); // reload to pick up any token refresh
        $r = pullPaymentFromQbo($db, $cfg, (int)$row['invoice_id']);
        if (!empty($r['success'])) {
            $pulled[] = $row['invoice_id'];
        } elseif (!empty($r['error'])) {
            $errors[] = 'Invoice ' . $row['invoice_id'] . ': ' . $r['error'];
        }
 // 'message' (no payment in QBO) is silently skipped
    }

    return [
        'pulled'  => $pulled,
        'errors'  => $errors,
        'summary' => sprintf('Pulled %d payment(s) from QBO. %d error(s).', count($pulled), count($errors)),
    ];
}

function pullCustomerInvoicesFromQbo(PDO $db, array $cfg, int $customerId): array {
    ensureQboReady($db, $cfg);

    // Get QBO customer ID for this MVP customer
    $stmt = $db->prepare("SELECT qbo_customer_id FROM qbo_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $qboCustId = $stmt->fetchColumn();

    if (!$qboCustId) {
        // Try to find by name match in QBO
        $custStmt = $db->prepare(
            "SELECT CONCAT(c.first_name,' ',c.last_name) AS name, c.email
             FROM customers c WHERE c.customer_id = ?"
        );
        $custStmt->execute([$customerId]);
        $cust = $custStmt->fetch();
        if (!$cust) return ['error' => 'Customer not found'];

        // Search QBO for matching customer
        $escapedName = str_replace("'", "''", $cust['name']);
        $searchResult = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode("select * from Customer where DisplayName like '%{$escapedName}%' MAXRESULTS 5"));
        $qboCustomers = $searchResult['QueryResponse']['Customer'] ?? [];
        if (empty($qboCustomers)) {
            return ['error' => 'No matching QBO customer found. Sync this customer to QBO first.'];
        }
        // Use first match
        $qboCustId = $qboCustomers[0]['Id'];
    }

    // Query QBO for all invoices for this customer
    $qboInvoices = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode("select * from Invoice where CustomerRef = '$qboCustId' MAXRESULTS 100"));
    $invoices = $qboInvoices['QueryResponse']['Invoice'] ?? [];

    // Compare with existing MVP invoices
    $stmt = $db->prepare("SELECT qbo_id FROM invoices WHERE qbo_id IS NOT NULL");
    $stmt->execute();
    $existingQboIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $existingQboIds[$row['qbo_id']] = true;
    }

    $newInvoices = [];
    $existingInvoices = [];

    foreach ($invoices as $inv) {
        $qboId = $inv['Id'] ?? null;
        if (!$qboId) continue;

        $lineItems = [];
        foreach ($inv['Line'] ?? [] as $line) {
            if ($line['DetailType'] === 'SalesItemLineDetail') {
                $lineItems[] = [
                    'description' => $line['Description'] ?? '',
                    'amount'      => $line['Amount'] ?? 0,
                    'qty'         => $line['SalesItemLineDetail']['Qty'] ?? 1,
                    'item'        => $line['SalesItemLineDetail']['ItemRef']['name'] ?? '',
                ];
            }
        }

        $invoiceData = [
            'qbo_id'        => $qboId,
            'doc_number'    => $inv['DocNumber'] ?? '',
            'txn_date'      => $inv['TxnDate'] ?? '',
            'due_date'      => $inv['DueDate'] ?? '',
            'total'         => $inv['TotalAmt'] ?? 0,
            'balance'       => $inv['Balance'] ?? 0,
            'status'        => $inv['Balance'] > 0 ? 'unpaid' : 'paid',
            'line_items'    => $lineItems,
        ];

        if (isset($existingQboIds[$qboId])) {
            $existingInvoices[] = $invoiceData;
        } else {
            $newInvoices[] = $invoiceData;
        }
    }

    return [
        'qbo_customer_id'   => $qboCustId,
        'total_found'       => count($invoices),
        'new_invoices'      => $newInvoices,
        'existing_invoices' => $existingInvoices,
    ];
}

function getDefaultQboItemId(PDO $db, array $cfg): string {
 // Check cache first
    $cached = $db->prepare("SELECT qbo_item_id FROM qbo_items WHERE item_key = '__default__'")->execute([]);
    $row = $db->query("SELECT qbo_item_id FROM qbo_items WHERE item_key = '__default__'")->fetch();
    if ($row) return $row['qbo_item_id'];

 // Query QBO for any Service item
    try {
        $result = qboApiRequest($db, $cfg, 'GET', 'query?query=select Id, Name from Item where Type=\'Service\' MAXRESULTS 1');
        $items  = $result['QueryResponse']['Item'] ?? [];
        if (!empty($items)) {
            $itemId = $items[0]['Id'];
            $db->prepare("INSERT INTO qbo_items (item_key, qbo_item_id, item_name, synced_at) VALUES ('__default__', ?, ?, NOW()) ON DUPLICATE KEY UPDATE qbo_item_id=?, synced_at=NOW()")
               ->execute([$itemId, $items[0]['Name'], $itemId]);
            return $itemId;
        }
    } catch (\Throwable $e) { /* fall through */ }

 // Last resort: return empty string and use DescriptionOnly
    return '';
}

// Returns a numeric QBO TaxRate ID. Checks the config override first (qbo_tax_rate_id),
// then the cache, then queries QBO for the first active non-zero rate and caches it.
// Returns null if nothing can be resolved - caller skips TxnTaxDetail.
function getDefaultQboTaxRateId(PDO $db, array $cfg): ?string {
 // Config override wins
    if (!empty($cfg['qbo_tax_rate_id']) && ctype_digit(trim($cfg['qbo_tax_rate_id']))) {
        return trim($cfg['qbo_tax_rate_id']);
    }

 // Cache
    try {
        $row = $db->query("SELECT qbo_item_id FROM qbo_items WHERE item_key = '__taxrate__'")->fetch();
        if ($row) return $row['qbo_item_id'];
    } catch (\Throwable $e) {}

 // Query QBO
    try {
        $result = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode("select Id, Name, RateValue from TaxRate where Active=true MAXRESULTS 20"));
        $rates  = $result['QueryResponse']['TaxRate'] ?? [];
 // Prefer a rate with a non-zero RateValue
        $chosen = null;
        foreach ($rates as $r) {
            if ((float)($r['RateValue'] ?? 0) > 0) { $chosen = $r; break; }
        }
        if (!$chosen && !empty($rates)) $chosen = $rates[0];
        if ($chosen) {
            $id = $chosen['Id'];
            try {
                $db->prepare(
                    "INSERT INTO qbo_items (item_key, qbo_item_id, item_name, synced_at)
                     VALUES ('__taxrate__', ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE qbo_item_id=VALUES(qbo_item_id), synced_at=NOW()"
                )->execute([$id, $chosen['Name'] ?? 'Tax']);
            } catch (\Throwable $e) {}
            return $id;
        }
    } catch (\Throwable $e) {}

    return null;
}

function fetchAllQboCustomers(PDO $db, array $cfg): array {
    $all      = [];
    $pageSize = 1000;
    $start    = 1;
    do {
        $query  = urlencode("select * from Customer STARTPOSITION {$start} MAXRESULTS {$pageSize}");
        $result = qboApiRequest($db, $cfg, 'GET', "query?query={$query}");
        $batch  = $result['QueryResponse']['Customer'] ?? [];
        $all    = array_merge($all, $batch);
        $start += $pageSize;
    } while (count($batch) === $pageSize);
    return $all;
}

function getOrCreateQboCustomer(PDO $db, array $cfg, int $customerId, array $inv): string {
 // Check for manual override QBO customer ID first
    $overrideStmt = $db->prepare("SELECT qbo_override_customer_id FROM customers WHERE customer_id = ?");
    $overrideStmt->execute([$customerId]);
    $override = $overrideStmt->fetchColumn();
    if ($override) return (string)$override;

 // Check mapping table
    $existing = getQboCustomerIdForCustomer($db, $customerId);
    if ($existing) return $existing;

 // Use separate first/last name fields - never split a concatenated string
 // because multi-word first names (Mary Jo) would break the split.
    $firstName   = trim($inv['customer_first_name'] ?? '');
    $lastName    = trim($inv['customer_last_name']  ?? '');

 // Fall back to splitting customer_name only if separate fields are absent
    if (!$firstName && !$lastName && !empty($inv['customer_name'])) {
        $parts     = explode(' ', trim($inv['customer_name']), 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? '';
    }

 // QBO display name format: "Lastname, Firstname" - preserve original casing so it
 // matches whatever the customer was created with in QuickBooks.
    $displayName = $lastName
        ? trim($lastName) . ', ' . trim($firstName)
        : trim($firstName);

 // Search QBO for matching customer - escape single quotes per IDS rules, then urlencode
    $escapedName = str_replace("'", "''", $displayName);
    $search      = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode("select * from Customer where DisplayName='{$escapedName}' MAXRESULTS 1"));
    $customers   = $search['QueryResponse']['Customer'] ?? [];
    if (!empty($customers)) {
        $qboId = $customers[0]['Id'];
        saveQboCustomerMapping($db, $customerId, $qboId, $displayName);
        return $qboId;
    }

 // Create customer in QBO
    $payload = [
        'DisplayName' => $displayName,
        'GivenName'   => $firstName,
        'FamilyName'  => $lastName,
        'PrimaryPhone' => ['FreeFormNumber' => $inv['customer_phone'] ?? ''],
        'BillAddr'    => [
            'Line1'                  => $inv['service_address'] ?? '',
            'City'                   => $inv['service_city']    ?? '',
            'CountrySubDivisionCode' => $inv['service_state']   ?? 'IL',
            'PostalCode'             => $inv['service_zip']     ?? '',
            'Country'                => 'US',
        ],
    ];
 // Only include email if present - QBO rejects empty PrimaryEmailAddr
    if (!empty($inv['customer_email'])) {
        $payload['PrimaryEmailAddr'] = ['Address' => $inv['customer_email']];
    }
    $result = qboApiRequest($db, $cfg, 'POST', 'customer', $payload);
    $qboId  = $result['Customer']['Id'] ?? null;
    if (!$qboId) throw new \RuntimeException('Failed to create QBO customer: ' . json_encode($result));
    saveQboCustomerMapping($db, $customerId, $qboId, $displayName);
    return $qboId;
}

function getQboCustomerIdForCustomer(PDO $db, int $customerId): ?string {
    $stmt = $db->prepare("SELECT qbo_customer_id FROM qbo_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    return $stmt->fetchColumn() ?: null;
}

function saveQboCustomerMapping(PDO $db, int $customerId, string $qboId, string $name): void {
    $db->prepare("INSERT INTO qbo_customers (customer_id, qbo_customer_id, qbo_display_name)
                  VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qbo_customer_id=VALUES(qbo_customer_id), qbo_display_name=VALUES(qbo_display_name)")
       ->execute([$customerId, $qboId, $name]);
}

function getOrCreateQboItem(PDO $db, array $cfg, string $itemKey, string $itemName, string $incomeAccount): string {
    $stmt = $db->prepare("SELECT qbo_item_id FROM qbo_items WHERE item_key = ?");
    $stmt->execute([$itemKey]);
    $existing = $stmt->fetchColumn();
    if ($existing) return $existing;

 // Search QBO for item - escape single quotes per IDS rules, then urlencode
    $escapedName = str_replace("'", "''", $itemName);
    $search = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode("select * from Item where Name='{$escapedName}' MAXRESULTS 1"));
    $items = $search['QueryResponse']['Item'] ?? [];
    if (!empty($items)) {
        $qboItemId = $items[0]['Id'];
        $db->prepare("INSERT INTO qbo_items (item_key, qbo_item_id, item_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qbo_item_id=VALUES(qbo_item_id)")
           ->execute([$itemKey, $qboItemId, $itemName]);
        return $qboItemId;
    }

 // Create service item in QBO
    $payload = [
        'Name'       => $itemName,
        'Type'       => 'Service',
        'IncomeAccountRef' => ['name' => $incomeAccount],
        'Active'     => true,
    ];
    $result    = qboApiRequest($db, $cfg, 'POST', 'item', $payload);
    $qboItemId = $result['Item']['Id'] ?? null;
    if (!$qboItemId) {
 // Fallback: use a default item if creation fails
        return '1';
    }
    $db->prepare("INSERT INTO qbo_items (item_key, qbo_item_id, item_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qbo_item_id=VALUES(qbo_item_id)")
       ->execute([$itemKey, $qboItemId, $itemName]);
    return $qboItemId;
}

function qboApiRequest(PDO $db, array $cfg, string $method, string $endpoint, ?array $body = null): array {
 // Refresh token if needed
    if ((int)$cfg['qbo_token_expires'] < time() + 60) {
        if (!refreshQboToken($db, $cfg)) {
            throw new \RuntimeException('QBO token expired and refresh failed. Please reconnect in Settings.');
        }
        $cfg = getQboConfig($db);
    }

    $base = $cfg['qbo_environment'] === 'production' ? QBO_PRODUCTION_BASE : QBO_SANDBOX_BASE;

 // For GET requests with a query string, encode it properly
    if ($method === 'GET' && strpos($endpoint, '?') !== false) {
        [$path, $queryStr] = explode('?', $endpoint, 2);
        parse_str($queryStr, $queryParams);
        $url = $base . $cfg['qbo_realm_id'] . '/' . $path . '?' . http_build_query($queryParams);
    } else {
        $url = $base . $cfg['qbo_realm_id'] . '/' . $endpoint;
    }
    $headers = [
        'Authorization: Bearer ' . $cfg['qbo_access_token'],
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        throw new \RuntimeException("curl failed (#{$curlErrNo}): {$curlErr} - URL: {$url}");
    }
    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $fault   = $data['Fault']['Error'][0] ?? [];
        $msg     = $fault['Message'] ?? 'Unknown error';
        $detail  = $fault['Detail'] ?? '';
        $code    = $fault['code'] ?? '';
        throw new \RuntimeException("QBO API error {$httpCode}: {$msg} | Detail: {$detail} | Code: {$code} | Raw: " . substr(json_encode($data), 0, 500));
    }
    return $data ?? [];
}

function httpPost(string $url, array $params, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp ?: '{}', true) ?? [];
}

function getQboConfig(PDO $db): array {
    $stmt = $db->query("SELECT setting_key, setting_value FROM company_settings WHERE setting_key LIKE 'qbo_%'");
    $cfg  = [];
    foreach ($stmt->fetchAll() as $row) $cfg[$row['setting_key']] = $row['setting_value'];
    return $cfg;
}

if (!function_exists('updateSetting')) {
function updateSetting(PDO $db, string $key, string $value): void {
    $db->prepare("INSERT INTO company_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
       ->execute([$key, $value, $value]);
}
}

function ensureQboReady(PDO $db, array $cfg): void {
    if (empty($cfg['qbo_client_id']) || empty($cfg['qbo_client_secret'])) {
        sendError(400, 'QuickBooks not configured. Add Client ID and Secret in Settings  QuickBooks.');
    }
    if (empty($cfg['qbo_access_token']) || empty($cfg['qbo_realm_id'])) {
        sendError(400, 'QuickBooks not connected. Click Connect in Settings  QuickBooks.');
    }
    if ($cfg['qbo_enabled'] !== '1') {
        sendError(400, 'QuickBooks integration is disabled in Settings.');
    }
}

// Resolve a QBO Payment Method ID by name (Cash, Check, Credit Card, etc.)
// Creates the method in QBO if it doesn't exist, caches result.
function resolveQboPaymentMethodId(PDO $db, array $cfg, string $methodName): ?string {
    $cacheKey = 'pmeth__' . md5(strtolower($methodName));
    try {
        $row = $db->query(
            "SELECT qbo_item_id FROM qbo_items WHERE item_key = " . $db->quote($cacheKey)
        )->fetch();
        if ($row) return $row['qbo_item_id'];
    } catch (\Throwable $e) {}

    try {
        $escaped = str_replace("'", "''", $methodName);
        $result  = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode("select Id,Name from PaymentMethod where Name='{$escaped}' MAXRESULTS 1"));
        $methods = $result['QueryResponse']['PaymentMethod'] ?? [];
        if (!empty($methods)) {
            $id = $methods[0]['Id'];
            try {
                $db->prepare(
                    "INSERT INTO qbo_items (item_key, qbo_item_id, item_name, synced_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE qbo_item_id = VALUES(qbo_item_id), synced_at = NOW()"
                )->execute([$cacheKey, $id, $methods[0]['Name']]);
            } catch (\Throwable $e) {}
            return $id;
        }

 // Payment method doesn't exist - create it
        $createResult = qboApiRequest($db, $cfg, 'POST', 'paymentmethod', [
            'Name'   => $methodName,
            'Active' => true,
            'Type'   => in_array(strtolower($methodName), ['credit card', 'visa', 'mastercard', 'amex']) ? 'CREDIT_CARD' : 'NON_CREDIT_CARD',
        ]);
        $id = $createResult['PaymentMethod']['Id'] ?? null;
        if ($id) {
            try {
                $db->prepare(
                    "INSERT INTO qbo_items (item_key, qbo_item_id, item_name, synced_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE qbo_item_id = VALUES(qbo_item_id), synced_at = NOW()"
                )->execute([$cacheKey, $id, $methodName]);
            } catch (\Throwable $e) {}
            return $id;
        }
    } catch (\Throwable $e) {}

    return null; // Non-fatal - payment syncs without a method ref
}

// Resolve a QBO account reference - accepts a numeric ID or an account name.
// Looks up by name if needed and caches the result. Returns null if unresolvable.
function resolveQboAccountId(PDO $db, array $cfg, string $accountRef): ?string {
    $accountRef = trim($accountRef);
    if ($accountRef === '') return null;

 // Already a numeric ID - use as-is
    if (ctype_digit($accountRef)) return $accountRef;

 // Check cache
    $cacheKey = 'acct__' . md5(strtolower($accountRef));
    try {
        $row = $db->query(
            "SELECT qbo_item_id FROM qbo_items WHERE item_key = " . $db->quote($cacheKey)
        )->fetch();
        if ($row) return $row['qbo_item_id'];
    } catch (\Throwable $e) { /* cache table may not exist */ }

 // Look up by name in QBO
    try {
        $escaped  = str_replace("'", "''", $accountRef);
        $result   = qboApiRequest($db, $cfg, 'GET', 'query?query=' . urlencode("select Id,Name from Account where Name='{$escaped}' MAXRESULTS 1"));
        $accounts = $result['QueryResponse']['Account'] ?? [];
        if (!empty($accounts)) {
            $id = $accounts[0]['Id'];
            try {
                $db->prepare(
                    "INSERT INTO qbo_items (item_key, qbo_item_id, item_name, synced_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE qbo_item_id = VALUES(qbo_item_id), synced_at = NOW()"
                )->execute([$cacheKey, $id, $accounts[0]['Name']]);
            } catch (\Throwable $e) { /* non-fatal */ }
            return $id;
        }
    } catch (\Throwable $e) { /* fall through */ }

    return null;
}

function getCallbackUrl(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
 // Callback goes to the API public endpoint, not admin.html
    return $proto . '://' . $host . '/api/public/qbo/callback';
}
