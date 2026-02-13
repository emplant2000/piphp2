<?php
// webhook.php
  - PiSDK Webhook Handler (Database-less Version)

// ====================
// 設定
// ====================
$config = [
    'app_id' => 'testnet_dummy_app_001',
    'api_key' => 'testnet_dummy_key',
    'environment' => 'sandbox', // sandbox or production
    'log_file' => 'pi_payments.log',
    'max_log_size' => 10 * 1024 * 1024, // 10MB
];

// ====================
// ヘッダー設定
// ====================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// ====================
// CORSプリフライトリクエスト処理
// ====================
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ====================
// ログ関数
// ====================
function logMessage($message, $data = [], $level = 'INFO') {
    global $config;
    
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => $level,
        'message' => $message,
        'data' => $data,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    $logLine = json_encode($logEntry) . PHP_EOL;
    
    // ログファイルに書き込み
    if (file_exists($config['log_file'])) {
        $fileSize = filesize($config['log_file']);
        if ($fileSize > $config['max_log_size']) {
            // ログローテーション
            rename($config['log_file'], $config['log_file'] . '.' . date('YmdHis'));
        }
    }
    
    file_put_contents($config['log_file'], $logLine, FILE_APPEND);
    
    // コンソールにも出力（Herokuログ用）
    error_log("[$level] $message");
}

// ====================
// 決済検証関数
// ====================
function validatePiPayment($paymentId, $config) {
    logMessage('決済検証開始', ['payment_id' => $paymentId]);
    
    $apiUrl = $config['environment'] === 'sandbox' 
        ? 'https://api.testnet.minepi.com/v2/payments/' 
        : 'https://api.minepi.com/v2/payments/';
    
    $url = $apiUrl . $paymentId;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Key ' . $config['api_key'],
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logMessage('CURLエラー', ['error' => $error], 'ERROR');
        return [
            'valid' => false,
            'error' => 'Network error: ' . $error
        ];
    }
    
    if ($httpCode !== 200) {
        logMessage('APIレスポンスエラー', [
            'http_code' => $httpCode,
            'response' => $response
        ], 'ERROR');
        
        return [
            'valid' => false,
            'error' => "API returned HTTP $httpCode"
        ];
    }
    
    $paymentData = json_decode($response, true);
    
    if (!$paymentData || !isset($paymentData['identifier'])) {
        logMessage('無効なレスポンスデータ', ['response' => $response], 'ERROR');
        return [
            'valid' => false,
            'error' => 'Invalid response data'
        ];
    }
    
    logMessage('決済検証成功', [
        'payment_id' => $paymentData['identifier'],
        'amount' => $paymentData['amount'] ?? 'unknown',
        'status' => $paymentData['status'] ?? 'unknown'
    ]);
    
    return [
        'valid' => true,
        'data' => $paymentData
    ];
}

// ====================
// 決済完了処理
// ====================
function completePayment($paymentData, $requestData) {
    logMessage('決済完了処理開始', [
        'payment_id' => $paymentData['identifier'],
        'amount' => $paymentData['amount']
    ]);
    
    // 決済状態の確認
    if ($paymentData['status'] !== 'completed') {
        logMessage('決済が完了していません', [
            'status' => $paymentData['status']
        ], 'WARNING');
        
        return [
            'success' => false,
            'error' => 'Payment is not completed yet'
        ];
    }
    
    // メタデータの抽出
    $metadata = $paymentData['metadata'] ?? [];
    $productId = $metadata['productId'] ?? 'unknown';
    $userId = $paymentData['user_uid'] ?? ($requestData['user']['uid'] ?? 'unknown');
    
    // 商品提供処理
    $deliveryResult = deliverProduct($productId, $userId, $paymentData);
    
    if (!$deliveryResult['success']) {
        logMessage('商品提供失敗', $deliveryResult, 'ERROR');
        return $deliveryResult;
    }
    
    // 成功レスポンス
    $result = [
        'success' => true,
        'message' => 'Payment processed successfully',
        'payment_id' => $paymentData['identifier'],
        'amount' => $paymentData['amount'],
        'status' => $paymentData['status'],
        'product_id' => $productId,
        'user_id' => $userId,
        'timestamp' => date('Y-m-d H:i:s'),
        'transaction_id' => 'TXN_' . strtoupper(uniqid()),
        'delivery_status' => $deliveryResult['status']
    ];
    
    logMessage('決済完了処理成功', $result);
    
    return $result;
}

// ====================
// 商品提供関数
// ====================
function deliverProduct($productId, $userId, $paymentData) {
    logMessage('商品提供開始', [
        'product_id' => $productId,
        'user_id' => $userId
    ]);
    
    // 商品タイプに応じた処理
    $productTypes = [
        'DIGITAL_001' => 'デジタル商品パック',
        'PREMIUM_001' => 'プレミアム機能',
        'CUSTOM' => 'カスタム商品'
    ];
    
    $productName = $productTypes[$productId] ?? '不明な商品';
    
    // 仮のライセンスキー生成
    $licenseKey = 'LIC-' . strtoupper(substr(md5($userId . $productId . time()), 0, 16));
    
    // アクティベーション日時
    $activationDate = date('Y-m-d H:i:s');
    $expiryDate = date('Y-m-d H:i:s', strtotime('+1 year'));
    
    // 提供結果
    $result = [
        'success' => true,
        'status' => 'delivered',
        'product_name' => $productName,
        'license_key' => $licenseKey,
        'activation_date' => $activationDate,
        'expiry_date' => $expiryDate,
        'delivery_method' => 'instant_digital',
        'notes' => 'This is a testnet delivery. No actual product is delivered.'
    ];
    
    // 仮の配信ログ作成
    $deliveryLog = [
        'timestamp' => $activationDate,
        'product_id' => $productId,
        'user_id' => $userId,
        'payment_id' => $paymentData['identifier'],
        'license_key' => $licenseKey,
        'amount' => $paymentData['amount']
    ];
    
    // 配信ログをファイルに保存
    $deliveryFile = 'deliveries.log';
    file_put_contents($deliveryFile, json_encode($deliveryLog) . PHP_EOL, FILE_APPEND);
    
    logMessage('商品提供完了', $result);
    
    return $result;
}

// ====================
// 決済履歴取得
// ====================
function getPaymentHistory($limit = 10) {
    global $config;
    
    if (!file_exists($config['log_file'])) {
        return ['payments' => []];
    }
    
    $logContent = file_get_contents($config['log_file']);
    $lines = explode(PHP_EOL, $logContent);
    
    $payments = [];
    foreach ($lines as $line) {
        if (empty($line)) continue;
        
        $logEntry = json_decode($line, true);
        if ($logEntry && 
            isset($logEntry['message']) && 
            strpos($logEntry['message'], '決済完了処理成功') !== false &&
            isset($logEntry['data'])) {
            
            $payments[] = $logEntry['data'];
            
            if (count($payments) >= $limit) {
                break;
            }
        }
    }
    
    return [
        'success' => true,
        'count' => count($payments),
        'payments' => array_reverse($payments) // 最新順
    ];
}


// ====================
// ヘルスチェック
// ====================
function healthCheck() {
    $health = [
        'status' => 'healthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'service' => 'Pi Payment Webhook',
        'version' => '1.0.0',
        'environment' => 'testnet',
        'components' => [
            'api' => 'operational',
            'logging' => file_exists('pi_payments.log') ? 'operational' : 'warning',
            'storage' => is_writable('.') ? 'operational' : 'error'
        ]
    ];
    
    logMessage('ヘルスチェック実行', $health);
    
    return $health;
}

// ====================
// メインリクエスト処理
// ====================
function handleRequest() {
    global $config;
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    logMessage('リクエスト受信', [
        'method' => $method,
        'path' => $path,
        'ip' => $_SERVER['REMOTE_ADDR']
    ]);
    
    // ルーティング
    if ($path === '/webhook.php' || $path === '/') {
        if ($method === 'GET') {
            // ヘルスチェック
            $result = healthCheck();
            http_response_code(200);
            return $result;
            
        } elseif ($method === 'POST') {
            // 決済処理
            return handlePaymentWebhook();
            
        } else {
            http_response_code(405);
            return ['error' => 'Method not allowed'];
        }
    }
    
    // 決済履歴API
    if ($path === '/history' && $method === 'GET') {
        $limit = $_GET['limit'] ?? 10;
        $result = getPaymentHistory($limit);
        http_response_code(200);
        return $result;
    }
    
    // ログ表示API（開発用）
    if ($path === '/logs' && $method === 'GET') {
        $lines = $_GET['lines'] ?? 50;
        return getRecentLogs($lines);
    }
    
    // 404
    http_response_code(404);
    return ['error' => 'Endpoint not found'];
}

// ====================
// 決済Webhook処理
// ====================
function handlePaymentWebhook() {
    global $config;
    
    // リクエストボディの取得
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        logMessage('無効なJSONデータ', ['input' => $input], 'ERROR');
        http_response_code(400);
        return ['success' => false, 'error' => 'Invalid JSON data'];
    }
    
    logMessage('Webhookデータ受信', $data);
    
    // 必須パラメータチェック
    if (!isset($data['paymentId'])) {
        logMessage('必須パラメータ不足', $data, 'ERROR');
        http_response_code(400);
        return ['success' => false, 'error' => 'Missing paymentId parameter'];
    }
    
    $paymentId = $data['paymentId'];
    $action = $data['action'] ?? 'complete';
    
    // 決済検証
    $validation = validatePiPayment($paymentId, $config);
    
    if (!$validation['valid']) {
        http_response_code(400);
        return [
            'success' => false,
            'error' => 'Payment validation failed',
            'details' => $validation['error']
        ];
    }
    
    $paymentData = $validation['data'];
    
    // アクションに応じた処理
    switch ($action) {
        case 'complete':
            $result = completePayment($paymentData, $data);
            break;
            
        case 'verify':
            $result = [
                'success' => true,
                'message' => 'Payment verified',
                'payment_id' => $paymentData['identifier'],
                'amount' => $paymentData['amount'],
                'status' => $paymentData['status'],
                'verified' => true,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'refund':
            $result = [
                'success' => true,
                'message' => 'Refund simulated (testnet only)',
                'payment_id' => $paymentData['identifier'],
                'refund_id' => 'REF_' . strtoupper(uniqid()),
                'status' => 'refunded',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            logMessage('返金処理シミュレーション', $result);
            break;
            
        default:
            http_response_code(400);
            return ['success' => false, 'error' => 'Unknown action'];
    }
    
    // レスポンスコード設定
    if ($result['success']) {
        http_response_code(200);
    } else {
        http_response_code(400);
    }
    
    return $result;
}

// ====================
// 最近のログ取得
// ====================
function getRecentLogs($lines = 50) {
    global $config;
    
    if (!file_exists($config['log_file'])) {
        return ['logs' => [], 'count' => 0];
    }
    
    $file = file($config['log_file']);
    $recentLogs = array_slice($file, -$lines);
    
    $parsedLogs = [];
    foreach ($recentLogs as $logLine) {
        if (trim($logLine)) {
            $parsedLogs[] = json_decode($logLine, true);
        }
    }
    
    return [
        'success' => true,
        'count' => count($parsedLogs),
        'logs' => array_reverse($parsedLogs) // 最新順
    ];
}

// ====================
// エラーハンドリング
// ====================
function handleError($errno, $errstr, $errfile, $errline) {
    logMessage('PHPエラー', [
        'error' => $errstr,
        'file' => $errfile,
        'line' => $errline,
        'level' => $errno
    ], 'ERROR');
    
    // 本番環境では詳細エラーを表示しない
    if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
            'reference' => 'ERR_' . time()
        ]);
        exit();
    }
}

// ====================
// シャットダウン処理
// ====================
function shutdownHandler() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logMessage('致命的エラー', $error, 'CRITICAL');
    }
}

// ====================
// セキュリティヘッダー
// ====================
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    // Content Security Policy
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ====================
// メイン実行
// ====================
try {
    // エラーハンドラ設定
    set_error_handler('handleError');
    register_shutdown_function('shutdownHandler');
    
    // セキュリティヘッダー設定
    setSecurityHeaders();
    
    // メイン処理実行
    $response = handleRequest();
    
    // レスポンス出力
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logMessage('未処理例外', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'CRITICAL');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'reference' => 'EXCEPTION_' . time()
    ]);
}

// ====================
// 簡易管理画面（オプション）
// ====================
if (isset($_GET['admin']) && $_GET['admin'] === 'view') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Pi Payment Admin</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .log-entry { border: 1px solid #ddd; padding: 10px; margin: 5px 0; }
            .info { background: #d1ecf1; }
            .success { background: #d4edda; }
            .error { background: #f8d7da; }
            .warning { background: #fff3cd; }
        </style>
    </head>
    <body>
        <h1>Pi Payment Webhook Admin</h1>
        <p>App ID: <?php echo htmlspecialchars($config['app_id']); ?></p>
        <p>Environment: <?php echo htmlspecialchars($config['environment']); ?></p>
        
        <h2>Recent Logs</h2>
        <?php
        $logs = getRecentLogs(20);
        foreach ($logs['logs'] as $log): 
            $levelClass = strtolower($log['level'] ?? 'info');
        ?>
            <div class="log-entry <?php echo $levelClass; ?>">
                <strong><?php echo htmlspecialchars($log['timestamp']); ?></strong>
                [<?php echo htmlspecialchars($log['level']); ?>]
                <?php echo htmlspecialchars($log['message']); ?>
                <?php if (!empty($log['data'])): ?>
                    <pre><?php echo htmlspecialchars(json_encode($log['data'], JSON_PRETTY_PRINT)); ?></pre>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <h2>Quick Actions</h2>
        <button onclick="location.href='?admin=view&refresh=1'">Refresh Logs</button>
        <button onclick="location.href='?admin=view&clearlog=1'">Clear Logs</button>
        
        <?php
        if (isset($_GET['clearlog'])) {
            file_put_contents($config['log_file'], '');
            echo '<p>Logs cleared</p>';
        }
        ?>
    </body>
    </html>
    <?php
    exit();
}
?>
