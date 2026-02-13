<?php
// webhook.php

// Pi Developer Portal の App Secret
const PI_APP_SECRET = 'YOUR_PI_APP_SECRET';

// 共通レスポンス
header('Content-Type: application/json; charset=utf-8');

// 生の JSON を取得
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$action = $_GET['action'] ?? '';

if ($action === 'approve') {
    approvePayment($data);
} elseif ($action === 'complete') {
    completePayment($data);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'invalid action']);
    exit;
}

/**
 * 支払い承認
 * フロントから paymentId を受け取り、Pi サーバーに approve を投げる
 */
function approvePayment(array $data)
{
    if (empty($data['paymentId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'paymentId required']);
        return;
    }

    $paymentId = $data['paymentId'];

    // Pi API: approve
    $url = "https://api.minepi.com/v2/payments/{$paymentId}/approve";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Key ' . PI_APP_SECRET, // 公式ドキュメントに合わせる
        ],
        CURLOPT_POSTFIELDS => json_encode([]),
    ]);

    $resBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        // ここで自前DBに「承認済み」として保存してもよい
        echo $resBody;
    } else {
        http_response_code($httpCode);
        echo json_encode([
            'error' => 'approve failed',
            'status' => $httpCode,
            'body'   => $resBody,
        ]);
    }
}

/**
 * 支払い完了
 * フロントから paymentId, txid を受け取り、Pi サーバーに complete を投げる
 */
function completePayment(array $data)
{
    if (empty($data['paymentId']) || empty($data['txid'])) {
        http_response_code(400);
        echo json_encode(['error' => 'paymentId and txid required']);
        return;
    }

    $paymentId = $data['paymentId'];
    $txid      = $data['txid'];

    // Pi API: complete
    $url = "https://api.minepi.com/v2/payments/{$paymentId}/complete";

    $payload = [
        'txid' => $txid,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Key ' . PI_APP_SECRET,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $resBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        // ここで自前DBに「完了済み」として保存してもよい
        echo $resBody;
    } else {
        http_response_code($httpCode);
        echo json_encode([
            'error' => 'complete failed',
            'status' => $httpCode,
            'body'   => $resBody,
        ]);
    }
}
