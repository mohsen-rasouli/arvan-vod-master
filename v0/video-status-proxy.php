<?php
// video-status-proxy.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$config = parse_ini_file(__DIR__ . '/config.ini', true);
$apiKey = $config['arvan']['api_key'];
$arvanApiBaseUrl = $config['arvan']['api_base_url'];

file_put_contents('debug.txt', 'مرحله 1');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['video_id'])) {
        throw new Exception('video_id ارسال نشده است.');
    }
    $videoId = $input['video_id'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $arvanApiBaseUrl . "/videos/$videoId");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        throw new Exception('خطا در دریافت وضعیت ویدیو: ' . $response);
    }
    echo $response;
    file_put_contents('debug.txt', 'مرحله 2: ' . print_r($response, true), FILE_APPEND);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 