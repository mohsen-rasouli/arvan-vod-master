<?php
// v3/arvan-channel-videos-proxy.php
header('Content-Type: application/json; charset=utf-8');

// فایل config.ini برای خواندن کلید API و آدرس پایه استفاده می‌شود
$config = parse_ini_file(__DIR__ . '/config.ini', true);
if (!$config || !isset($config['arvan']['api_key'], $config['arvan']['api_base_url'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا: فایل تنظیمات (config.ini) نامعتبر یا ناقص است.']);
    exit;
}
$apiKey = $config['arvan']['api_key']; // کلید API آروان از فایل کانفیگ
$arvanApiBaseUrl = $config['arvan']['api_base_url']; // آدرس پایه API آروان از فایل کانفیگ

$channelId = $_GET['channel_id'] ?? null;
$page = $_GET['page'] ?? 1; // For pagination, default to page 1

if (!$channelId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'شناسه کانال (channel_id) ارسال نشده است.']);
    exit;
}

try {
    $ch = curl_init();
    // درخواست GET به API آروان برای دریافت لیست ویدیوهای یک کانال خاص
    $url = $arvanApiBaseUrl . "/channels/" . rawurlencode($channelId) . "/videos?page=" . rawurlencode($page) . "&per_page=10"; // Fetch 10 videos per page
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiKey, // استفاده از کلید API
        'Accept: application/json',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('خطای cURL در دریافت لیست ویدیوهای کانال: ' . $curlError);
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = 'خطا در دریافت لیست ویدیوهای کانال از آروان. کد: ' . $httpCode;
        if (isset($errorData['message'])) {
            $errorMessage .= ' - ' . $errorData['message'];
        } elseif ($response) {
            $decodedResponse = json_decode($response);
            if (json_last_error() === JSON_ERROR_NONE && isset($decodedResponse->message)) {
                 $errorMessage .= ' - ' . $decodedResponse->message;
            } else if (is_string($response) && strlen($response) < 200) {
                 $errorMessage .= ' - ' . $response;
            }
        }
        throw new Exception($errorMessage);
    }

    // پاسخ آروان مستقیما به کلاینت ارسال می‌شود.
    echo $response;

} catch (Exception $e) {
    http_response_code(400); // یا خطای مناسب دیگر مانند 500
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]); // Ensure data is an empty array on error
}
?>