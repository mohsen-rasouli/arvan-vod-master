<?php
// channels-proxy.php
header('Content-Type: application/json; charset=utf-8');

$config = parse_ini_file(__DIR__ . '/config.ini', true);
$apiKey = $config['arvan']['api_key'];
$arvanApiBaseUrl = $config['arvan']['api_base_url'];

try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $arvanApiBaseUrl . "/channels");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        throw new Exception('خطا در دریافت لیست کانال‌ها: ' . $response);
    }
    echo $response;
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 