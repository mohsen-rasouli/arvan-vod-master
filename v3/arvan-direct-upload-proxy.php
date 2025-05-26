<?php
// v3/arvan-direct-upload-proxy.php
session_start(); // Start the session to store progress

header('Content-Type: application/json; charset=utf-8');

// Ensure error reporting is on for debugging, but turn off display for production
error_reporting(E_ALL);
ini_set('display_errors', 0); // 0 for production, 1 for debugging
ini_set('log_errors', 1); // Log errors to server error log

$config = parse_ini_file(__DIR__ . '/config.ini', true);
if (!$config || !isset($config['arvan']['api_key'], $config['arvan']['api_base_url'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا: فایل تنظیمات (config.ini) نامعتبر یا ناقص است.']);
    exit;
}
$apiKey = $config['arvan']['api_key'];
$arvanApiBaseUrl = $config['arvan']['api_base_url'];

$uploadId = $_POST['uploadId'] ?? null;

// Function to update progress in session (identical to the one in arvan-upload-proxy.php)
function update_upload_progress($uid, $status, $progress = null, $message = null, $uploadedBytes = null, $totalBytes = null) {
    if (!$uid) return;
    if (session_status() == PHP_SESSION_NONE) { session_start(); }
    $_SESSION['upload_progress'][$uid] = [
        'status' => $status,
        'progress' => $progress,
        'message' => $message,
        'uploaded' => $uploadedBytes,
        'total' => $totalBytes,
        'timestamp' => time()
    ];
    session_write_close();
    if (session_status() == PHP_SESSION_NONE) { session_start(); } // Re-open for current script
}

// cURL progress callback function (identical to the one in arvan-upload-proxy.php)
function curl_progress_callback($resource, $download_size, $downloaded_count, $upload_size, $uploaded_count) {
    $uploadId = null;
    if (is_resource($resource)) {
        $uploadId = curl_getinfo($resource, CURLINFO_PRIVATE);
    }
    if ($uploadId && $upload_size > 0) {
        $progress = round(($uploaded_count / $upload_size) * 100);
        update_upload_progress($uploadId, 'uploading_to_arvan', $progress, 'در حال آپلود فایل به آروان...', $uploaded_count, $upload_size);
    }
}

if (!$uploadId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Upload ID (uploadId) ارسال نشده است.']);
    exit;
}

try {
    if (!isset($_POST['channelId'], $_POST['title'], $_FILES['videoFile'])) {
        update_upload_progress($uploadId, 'error', 0, 'اطلاعات ناقص است: کانال، عنوان یا فایل ارسال نشده.');
        throw new Exception('اطلاعات ناقص است: کانال، عنوان یا فایل ارسال نشده.');
    }

    $channelId = $_POST['channelId'];
    $arvanFilename = $_POST['title']; // Use the title from form as filename for Arvan
    $arvanDesc = $_POST['description'] ?? '';
    
    if ($_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        $phpFileUploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'فایل آپلود شده از محدودیت upload_max_filesize در php.ini تجاوز می‌کند.',
            UPLOAD_ERR_FORM_SIZE  => 'فایل آپلود شده از محدودیت MAX_FILE_SIZE مشخص شده در فرم HTML تجاوز می‌کند.',
            UPLOAD_ERR_PARTIAL    => 'فایل فقط بصورت ناقص آپلود شده است.',
            UPLOAD_ERR_NO_FILE    => 'هیچ فایلی آپلود نشده است.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت یافت نشد.',
            UPLOAD_ERR_CANT_WRITE => 'شکست در نوشتن فایل روی دیسک.',
            UPLOAD_ERR_EXTENSION  => 'یک افزونه PHP آپلود فایل را متوقف کرد.',
        ];
        $errorMessage = $phpFileUploadErrors[$_FILES['videoFile']['error']] ?? 'خطای ناشناخته در آپلود فایل به سرور.';
        update_upload_progress($uploadId, 'error', 0, 'خطا در آپلود فایل به سرور شما: ' . $errorMessage);
        throw new Exception('خطا در آپلود فایل به سرور شما: ' . $errorMessage);
    }

    $tmpFilePath = $_FILES['videoFile']['tmp_name'];
    $originalFilename = $_FILES['videoFile']['name'];
    $fileSize = $_FILES['videoFile']['size'];
    // $fileType = $_FILES['videoFile']['type']; // Can be unreliable, use mime_content_type if needed, but Arvan usually figures it out
    $fileType = mime_content_type($tmpFilePath);


    if ($fileSize == 0) {
        update_upload_progress($uploadId, 'error', 0, 'فایل ارسالی خالی است.');
        throw new Exception('فایل ارسالی خالی است.');
    }

    update_upload_progress($uploadId, 'initializing_tus', 0, 'فایل با موفقیت به سرور شما رسید. در حال آماده‌سازی آپلود TUS به آروان...');

    // مرحله ۱: ایجاد فایل TUS در آروان
    $encode = fn($str) => base64_encode((string)$str);
    // Use $arvanFilename (from POST title) for Upload-Metadata filename
    $uploadMetadata = 'filename ' . $encode($arvanFilename) . ',filetype ' . $encode($fileType);
    if (!empty($arvanDesc)) {
        $uploadMetadata .= ',description ' . $encode($arvanDesc);
    }
    // You could also add original_filename if desired:
    // $uploadMetadata .= ',original_filename ' . $encode($originalFilename);


    $initHeaders = [
        'Tus-Resumable: 1.0.0',
        'Upload-Length: ' . $fileSize,
        'Upload-Metadata: ' . $uploadMetadata,
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ];

    $ch_init = curl_init();
    curl_setopt($ch_init, CURLOPT_URL, $arvanApiBaseUrl . "/channels/" . $channelId . "/files");
    curl_setopt($ch_init, CURLOPT_POST, true);
    curl_setopt($ch_init, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_init, CURLOPT_HTTPHEADER, $initHeaders);
    curl_setopt($ch_init, CURLOPT_HEADER, true); // Need headers to get Location
    curl_setopt($ch_init, CURLOPT_POSTFIELDS, ""); // POST with empty body for TUS creation
    
    $initResponseFull = curl_exec($ch_init);
    $initHttpCode = curl_getinfo($ch_init, CURLINFO_HTTP_CODE);
    $initHeaderSize = curl_getinfo($ch_init, CURLINFO_HEADER_SIZE);
    $initHeadersStr = substr($initResponseFull, 0, $initHeaderSize);
    $initBodyStr = substr($initResponseFull, $initHeaderSize);
    $initCurlError = curl_error($ch_init);
    curl_close($ch_init);

    if ($initHttpCode !== 201) { // TUS creation expects 201 Created
        $errorMsg = 'خطا در مرحله اول TUS (ایجاد فایل در آروان): ' . $initHttpCode;
        $errorMsg .= ' پاسخ: ' . $initBodyStr;
        if($initCurlError) $errorMsg .= ' خطای cURL: ' . $initCurlError;
        update_upload_progress($uploadId, 'error', 0, $errorMsg);
        throw new Exception($errorMsg);
    }

    if (!preg_match('/Location:\s*(.*)/i', $initHeadersStr, $matches)) {
        $errorMsg = 'خطا در مرحله اول TUS: هدر Location از آروان دریافت نشد.';
        update_upload_progress($uploadId, 'error', 0, $errorMsg);
        throw new Exception($errorMsg);
    }
    $tusLocation = trim($matches[1]);
    // Extract file_id from TUS Location URL (e.g., https://napi.arvancloud.ir/vod/2.0/files/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)
    $parsedTusLocation = parse_url($tusLocation);
    $fileId = basename($parsedTusLocation['path']);


    update_upload_progress($uploadId, 'tus_initialized', 5, 'TUS با موفقیت در آروان آماده شد. File ID: ' . htmlspecialchars($fileId) . '. شروع آپلود...');

    // مرحله ۲: آپلود فایل با PATCH به آروان
    $patchHeaders = [
        'Tus-Resumable: 1.0.0',
        'Upload-Offset: 0',
        'Content-Type: application/offset+octet-stream',
        'Authorization: ' . $apiKey,
        'Accept: application/json', // Though for PATCH, Arvan might not return a JSON body on success
    ];

    $fileHandle = fopen($tmpFilePath, 'r');
    if (!$fileHandle) {
        $errorMsg = 'امکان باز کردن فایل موقت آپلود شده برای خواندن وجود ندارد: ' . htmlspecialchars($tmpFilePath);
        update_upload_progress($uploadId, 'error', 5, $errorMsg);
        throw new Exception($errorMsg);
    }

    $ch_patch = curl_init();
    curl_setopt($ch_patch, CURLOPT_URL, $tusLocation);
    curl_setopt($ch_patch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch_patch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_patch, CURLOPT_HTTPHEADER, $patchHeaders);
    curl_setopt($ch_patch, CURLOPT_UPLOAD, true);
    curl_setopt($ch_patch, CURLOPT_INFILE, $fileHandle);
    curl_setopt($ch_patch, CURLOPT_INFILESIZE, $fileSize);
    // curl_setopt($ch_patch, CURLOPT_TIMEOUT, 3600); // Example: 1 hour timeout for upload
    // curl_setopt($ch_patch, CURLOPT_LOW_SPEED_LIMIT, 1024 * 50); // 50KB/s
    // curl_setopt($ch_patch, CURLOPT_LOW_SPEED_TIME, 60); // if speed is below 50KB/s for 60s, timeout


    // Progress callback setup
    curl_setopt($ch_patch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch_patch, CURLOPT_PROGRESSFUNCTION, 'curl_progress_callback');
    curl_setopt($ch_patch, CURLOPT_PRIVATE, $uploadId); // Pass uploadId to callback

    $patchResponse = curl_exec($ch_patch);
    $patchHttpCode = curl_getinfo($ch_patch, CURLINFO_HTTP_CODE);
    $patchCurlError = curl_error($ch_patch);
    curl_close($ch_patch);
    fclose($fileHandle);

    // After PATCH, tmp file is no longer needed by us, PHP will clean it up.

    if ($patchHttpCode !== 204) { // TUS PATCH expects 204 No Content on success
        $currentProgress = $_SESSION['upload_progress'][$uploadId]['progress'] ?? 5;
        $errorMsg = 'خطا در آپلود فایل به آروان (PATCH): ' . $patchHttpCode;
        $errorMsg .= ' پاسخ: ' . $patchResponse;
        if ($patchCurlError) $errorMsg .= ' خطای cURL: ' . $patchCurlError;
        update_upload_progress($uploadId, 'error', $currentProgress, $errorMsg);
        throw new Exception($errorMsg);
    }
    
    // Ensure progress is marked as nearly complete for this stage before registration
    update_upload_progress($uploadId, 'patch_complete_registering', 95, 'آپلود داده‌های فایل به آروان تکمیل شد. در حال ثبت ویدیو در کانال...');


    // مرحله ۳: ثبت ویدیو (POST to /channels/{channel_id}/videos)
    $videoData = [
        'title' => $arvanFilename, // Use the title from form
        'description' => $arvanDesc,
        'file_id' => $fileId, // The file_id obtained from TUS Location header
        'convert_mode' => 'auto', // Or 'none', 'abr', specific profiles
        // 'allowed_countries' => ["IR"], // Example
        // 'watermark_id' => 'your_watermark_id', // Example
        // 'thumbnail_time' => 10, // Example: thumbnail from 10th second
    ];

    $ch_video = curl_init();
    curl_setopt($ch_video, CURLOPT_URL, $arvanApiBaseUrl . "/channels/" . $channelId . "/videos");
    curl_setopt($ch_video, CURLOPT_POST, true);
    curl_setopt($ch_video, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_video, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch_video, CURLOPT_POSTFIELDS, json_encode($videoData, JSON_UNESCAPED_UNICODE));
    
    $regRespStr = curl_exec($ch_video);
    $regHttpCode = curl_getinfo($ch_video, CURLINFO_HTTP_CODE);
    $regCurlError = curl_error($ch_video);
    curl_close($ch_video);

    if ($regHttpCode !== 201 && $regHttpCode !== 200) { // Video creation usually returns 201, sometimes 200
        $errorMsg = 'خطا در مرحله سوم (ثبت ویدیو در آروان): ' . $regHttpCode;
        $errorMsg .= ' پاسخ: ' . $regRespStr;
        if($regCurlError) $errorMsg .= ' خطای cURL: ' . $regCurlError;
        update_upload_progress($uploadId, 'error', $_SESSION['upload_progress'][$uploadId]['progress'] ?? 98, $errorMsg);
        throw new Exception($errorMsg);
    }

    $arvanFinalResponse = json_decode($regRespStr, true);
    update_upload_progress($uploadId, 'completed', 100, 'ویدیو با موفقیت در آروان آپلود و ثبت شد.');
    echo json_encode([
        'success' => true,
        'message' => 'ویدیو با موفقیت در آروان آپلود و ثبت شد.',
        'arvan_response' => $arvanFinalResponse // Send Arvan's response back to client
    ]);

} catch (Exception $e) {
    // Log the detailed error to server logs
    error_log("Direct Arvan Upload Error for ID $uploadId: " . $e->getMessage() . "\nPOST Data: " . print_r($_POST, true) . "\nFILES Data: " . print_r($_FILES, true));

    $currentProgress = ($uploadId && isset($_SESSION['upload_progress'][$uploadId])) ? $_SESSION['upload_progress'][$uploadId]['progress'] : 0;
    // Ensure session is updated with the error for polling
    if ($uploadId && (!isset($_SESSION['upload_progress'][$uploadId]) || $_SESSION['upload_progress'][$uploadId]['status'] !== 'error')) {
         update_upload_progress($uploadId, 'error', $currentProgress, $e->getMessage());
    }
    
    http_response_code(400); // Or a more appropriate error code like 500 if it's a server-side issue not client's fault
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>