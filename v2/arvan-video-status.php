<?php
// arvan-video-status.php
header('Content-Type: application/json; charset=utf-8');

// فایل config.ini برای خواندن کلید API و آدرس پایه استفاده می‌شود
$config = parse_ini_file(__DIR__ . '/config.ini', true);
$apiKey = $config['arvan']['api_key']; // کلید API آروان از فایل کانفیگ
$arvanApiBaseUrl = $config['arvan']['api_base_url']; // آدرس پایه API آروان از فایل کانفیگ

$videoId = $_GET['video_id'] ?? null;

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'شناسه ویدیو (video_id) ارسال نشده است.']);
    exit;
}

try {
    $ch = curl_init();
    // درخواست GET به API آروان برای دریافت اطلاعات ویدیو
    curl_setopt($ch, CURLOPT_URL, $arvanApiBaseUrl . "/videos/" . $videoId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiKey, // استفاده از کلید API
        'Accept: application/json',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = 'خطا در دریافت وضعیت ویدیو از آروان. کد: ' . $httpCode;
        if (isset($errorData['message'])) {
            $errorMessage .= ' - ' . $errorData['message'];
        } elseif ($response) {
            // گاهی اوقات آروان خطاهای غیر JSON برمی‌گرداند
            $decodedResponse = json_decode($response);
            if (json_last_error() === JSON_ERROR_NONE && isset($decodedResponse->message)) {
                 $errorMessage .= ' - ' . $decodedResponse->message;
            } else if (is_string($response) && strlen($response) < 200) { // نمایش متن خطای کوتاه
                 $errorMessage .= ' - ' . $response;
            }
        }
        throw new Exception($errorMessage);
    }

    // پاسخ آروان معمولا به صورت JSON است و اطلاعات ویدیو را در یک کلید "data" قرار می‌دهد.
    // برای سازگاری بیشتر با کلاینت، می‌توانیم مستقیما پاسخ آروان را ارسال کنیم.
    echo $response;

} catch (Exception $e) {
    http_response_code(400); // یا خطای مناسب دیگر مانند 500
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>