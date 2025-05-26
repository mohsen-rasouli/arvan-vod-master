<?php
// v3/manage-video-action-proxy.php
header('Content-Type: application/json; charset=utf-8');

$config = parse_ini_file(__DIR__ . '/config.ini', true); //
if (!$config || !isset($config['arvan']['api_key'], $config['arvan']['api_base_url'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا: فایل تنظیمات (config.ini) نامعتبر یا ناقص است.']);
    exit;
}
$apiKey = $config['arvan']['api_key']; //
$arvanApiBaseUrl = $config['arvan']['api_base_url']; //

$requestMethod = $_SERVER['REQUEST_METHOD'];
$videoId = $_GET['video_id'] ?? null; // Video ID from URL parameter

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'شناسه ویدیو (video_id) ارسال نشده است.']);
    exit;
}

// Get JSON data from the request body for PATCH
$requestData = [];
if ($requestMethod === 'PATCH') {
    $jsonInput = file_get_contents('php://input');
    $requestData = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'داده JSON ارسال شده نامعتبر است: ' . json_last_error_msg()]);
        exit;
    }
}

try {
    $ch = curl_init();
    $url = $arvanApiBaseUrl . "/videos/" . rawurlencode($videoId);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey,
            'Accept: application/json',
        ],
    ];

    switch ($requestMethod) {
        case 'PATCH': // Update Video
            $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            array_push($options[CURLOPT_HTTPHEADER], 'Content-Type: application/json');

            $payload = [];
            if (array_key_exists('title', $requestData)) {
                $payload['title'] = $requestData['title'];
            }
            if (array_key_exists('description', $requestData)) {
                $payload['description'] = $requestData['description'];
            }

            if (empty($payload)) {
                 http_response_code(400);
                 echo json_encode(['success' => false, 'message' => 'هیچ داده‌ای (عنوان یا توضیحات) برای بروزرسانی ارسال نشده است.']);
                 exit;
            }
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            break;

        case 'DELETE': // Delete Video
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            // No body needed for DELETE
            break;

        default:
            http_response_code(405); // Method Not Allowed
            echo json_encode(['success' => false, 'message' => 'متد HTTP نامعتبر است.']);
            exit;
    }

    curl_setopt_array($ch, $options);
    curl_setopt($ch, CURLOPT_URL, $url);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('خطای cURL: ' . $curlError);
    }

    // Arvan typically returns 200 (for PATCH) or 204 (no content, e.g. for DELETE) on success
    if (($requestMethod === 'PATCH' && $httpCode === 200) || ($requestMethod === 'DELETE' && $httpCode === 204)) {
        $responseData = json_decode($response, true);
        if ($httpCode === 204) { // No content, e.g., successful DELETE
            echo json_encode(['success' => true, 'message' => 'ویدیو با موفقیت حذف شد.', 'data' => null]);
        } else {
            echo json_encode(['success' => true, 'message' => 'ویدیو با موفقیت بروزرسانی شد.', 'data' => $responseData['data'] ?? $responseData]);
        }
    } else {
        $errorDetails = json_decode($response, true);
        $errorMessage = 'خطا در ارتباط با API آروان برای عملیات ویدیو. کد: ' . $httpCode;
         if (isset($errorDetails['message'])) {
            $errorMessage .= ' - ' . $errorDetails['message'];
        } elseif (isset($errorDetails['errors'])) {
            $validationMessages = [];
            foreach($errorDetails['errors'] as $field => $messages) {
                $validationMessages[] = $field . ': ' . implode(', ', $messages);
            }
            $errorMessage .= ' - جزئیات خطا: ' . implode('; ', $validationMessages);
        } elseif ($response) {
             $errorMessage .= ' - ' . $response;
        }
        // For 404 on DELETE/PATCH, it means video not found
        if ($httpCode === 404) {
            $errorMessage = 'ویدیو با شناسه مشخص شده یافت نشد (خطای 404 از سرور آروان).';
        }
        throw new Exception($errorMessage, $httpCode);
    }

} catch (Exception $e) {
    $statusCode = $e->getCode() ?: 400;
    if ($statusCode < 400 || $statusCode > 599) $statusCode = ($requestMethod === 'PATCH' ? 400 : 500); // More specific default
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>