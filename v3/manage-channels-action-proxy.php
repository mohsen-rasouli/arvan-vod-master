<?php
// v3/manage-channels-action-proxy.php
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
$channelId = $_GET['channel_id'] ?? null; // For DELETE and PATCH via URL param

// Get JSON data from the request body for POST and PATCH
$requestData = [];
if ($requestMethod === 'POST' || $requestMethod === 'PATCH') {
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
    $url = '';
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey,
            'Accept: application/json',
            'Content-Type: application/json' // For POST/PATCH
        ],
    ];

    switch ($requestMethod) {
        case 'POST': // Create Channel
            $url = $arvanApiBaseUrl . "/channels";
            $options[CURLOPT_POST] = true;
            // Only include title and description as per user request for creation
            $payload = [];
            if (isset($requestData['title'])) {
                $payload['title'] = $requestData['title'];
            } else {
                 http_response_code(400);
                 echo json_encode(['success' => false, 'message' => 'عنوان کانال برای ایجاد الزامی است.']);
                 exit;
            }
            if (isset($requestData['description'])) {
                $payload['description'] = $requestData['description'];
            }
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            break;

        case 'PATCH': // Update Channel
            if (!$channelId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'شناسه کانال برای بروزرسانی ارسال نشده است.']);
                exit;
            }
            $url = $arvanApiBaseUrl . "/channels/" . rawurlencode($channelId);
            $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            // Only include title and description for update, as per focus
            $payload = [];
            if (isset($requestData['title'])) { // Title can be empty string to clear it, but Arvan might not allow empty title
                $payload['title'] = $requestData['title'];
            }
            if (array_key_exists('description', $requestData)) { // Allow setting description to empty
                $payload['description'] = $requestData['description'];
            }
            if (empty($payload)) {
                 http_response_code(400);
                 echo json_encode(['success' => false, 'message' => 'هیچ داده‌ای برای بروزرسانی ارسال نشده است.']);
                 exit;
            }
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            break;

        case 'DELETE': // Delete Channel
            if (!$channelId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'شناسه کانال برای حذف ارسال نشده است.']);
                exit;
            }
            $url = $arvanApiBaseUrl . "/channels/" . rawurlencode($channelId);
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            unset($options[CURLOPT_HTTPHEADER][2]); // Remove Content-Type for DELETE
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

    // Arvan typically returns 200, 201 (created), or 204 (no content, e.g. for delete) on success
    if ($httpCode >= 200 && $httpCode < 300) {
        $responseData = json_decode($response, true);
        if ($httpCode === 204) { // No content, e.g., successful DELETE
            echo json_encode(['success' => true, 'message' => 'عملیات با موفقیت انجام شد.', 'data' => null]);
        } else {
            echo json_encode(['success' => true, 'message' => 'عملیات با موفقیت انجام شد.', 'data' => $responseData['data'] ?? $responseData]);
        }
    } else {
        $errorDetails = json_decode($response, true);
        $errorMessage = 'خطا در ارتباط با API آروان. کد: ' . $httpCode;
        if (isset($errorDetails['message'])) {
            $errorMessage .= ' - ' . $errorDetails['message'];
        } elseif (isset($errorDetails['errors'])) {
            // Flatten Arvan's validation errors
            $validationMessages = [];
            foreach($errorDetails['errors'] as $field => $messages) {
                $validationMessages[] = $field . ': ' . implode(', ', $messages);
            }
            $errorMessage .= ' - جزئیات خطا: ' . implode('; ', $validationMessages);
        } elseif ($response) {
             $errorMessage .= ' - ' . $response;
        }
        throw new Exception($errorMessage);
    }

} catch (Exception $e) {
    // Ensure a consistent error structure
    $statusCode = $e->getCode() ?: 400; // Default to 400 if no specific code
    if ($statusCode < 400 || $statusCode > 599) $statusCode = 500; // Ensure it's a client/server error code
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>