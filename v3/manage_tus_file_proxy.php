<?php
// v3/manage_tus_file_proxy.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0); // Turn off error reporting to client for this proxy

$dbFile = __DIR__ . '/data.db';
$config = parse_ini_file(__DIR__ . '/config.ini', true); //

if (!$config || !isset($config['arvan']['api_key'], $config['arvan']['api_base_url'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا: فایل تنظیمات (config.ini) نامعتبر یا ناقص است.']);
    exit;
}
$apiKey = $config['arvan']['api_key']; //
$arvanApiBaseUrl = $config['arvan']['api_base_url']; //

$requestMethod = $_SERVER['REQUEST_METHOD'];
$arvanFileId = $_GET['arvan_file_id'] ?? null;
$clientUploadId = $_GET['client_upload_id'] ?? null; // برای حذف از دیتابیس محلی و فایل موقت

if ($requestMethod !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'متد HTTP نامعتبر است. فقط DELETE پشتیبانی می‌شود.']);
    exit;
}

if (!$arvanFileId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'شناسه فایل آروان (arvan_file_id) ارسال نشده است.']);
    exit;
}
if (!$clientUploadId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'شناسه آپلود کلاینت (client_upload_id) برای پاکسازی محلی ارسال نشده است.']);
    exit;
}


$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- 1. Delete from ArvanCloud ---
    $ch = curl_init();
    // Endpoint بر اساس مستندات شما: DELETE /files/{file}
    $url = $arvanApiBaseUrl . "/files/" . rawurlencode($arvanFileId);
    
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
    ];

    curl_setopt_array($ch, $options);
    curl_setopt($ch, CURLOPT_URL, $url);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('خطای cURL در حذف فایل از آروان: ' . $curlError);
    }

    // طبق مستندات شما، 200 یعنی موفقیت آمیز بودن حذف
    if ($httpCode !== 200) {
        $errorDetails = json_decode($response, true);
        $errorMessage = 'خطا در حذف فایل از آروان. کد: ' . $httpCode;
        if (isset($errorDetails['message'])) $errorMessage .= ' - ' . $errorDetails['message'];
        elseif ($response) $errorMessage .= ' - ' . $response;
        
        if ($httpCode === 404) { // اگر فایل در آروان پیدا نشد، همچنان از دیتابیس محلی حذف می‌کنیم
            error_log("TUS file {$arvanFileId} not found on Arvan (404), proceeding with local cleanup for {$clientUploadId}.");
            // ادامه می‌دهیم تا از دیتابیس محلی حذف شود
        } else {
            throw new Exception($errorMessage);
        }
    }

    // --- 2. Delete from local database and persistent temp file ---
    $stmt = $pdo->prepare("SELECT persistent_temp_filepath FROM upload_attempts WHERE client_upload_id = :client_upload_id AND arvan_file_id = :arvan_file_id");
    $stmt->execute([':client_upload_id' => $clientUploadId, ':arvan_file_id' => $arvanFileId]);
    $uploadAttempt = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($uploadAttempt && !empty($uploadAttempt['persistent_temp_filepath']) && file_exists($uploadAttempt['persistent_temp_filepath'])) {
        if (!@unlink($uploadAttempt['persistent_temp_filepath'])) {
            error_log("Failed to delete persistent temp file: " . $uploadAttempt['persistent_temp_filepath'] . " for client_upload_id: " . $clientUploadId);
            // ادامه می‌دهیم حتی اگر حذف فایل موقت با خطا مواجه شود، اما لاگ می‌کنیم
        }
    }

    $stmtDelete = $pdo->prepare("DELETE FROM upload_attempts WHERE client_upload_id = :client_upload_id AND arvan_file_id = :arvan_file_id");
    $deletedRows = $stmtDelete->execute([':client_upload_id' => $clientUploadId, ':arvan_file_id' => $arvanFileId]);
    
    if ($deletedRows > 0) {
        echo json_encode(['success' => true, 'message' => 'آپلود ناتمام با موفقیت از آروان (در صورت وجود) و لیست محلی حذف شد.']);
    } else if ($httpCode === 200) { // از آروان حذف شد ولی در دیتابیس محلی با این ترکیب client_id/arvan_file_id نبود
         echo json_encode(['success' => true, 'message' => 'فایل از آروان حذف شد، اما رکورد متناظر در لیست محلی یافت نشد (یا قبلا حذف شده بود).']);
    }
     else { // از آروان حذف نشد (مثلا 404 بود) و در دیتابیس هم نبود
        echo json_encode(['success' => false, 'message' => 'فایل در آروان یافت نشد و رکورد متناظر در لیست محلی نیز وجود نداشت.']);
    }


} catch (PDOException $e) {
    error_log("Database Error in manage_tus_file_proxy.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطای دیتابیس در حذف آپلود ناتمام.']);
} catch (Exception $e) {
    error_log("General Error in manage_tus_file_proxy.php: " . $e->getMessage());
    http_response_code(400); // Or 500 depending on error source
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>