<?php
// arvan-upload-proxy.php
session_start(); // Start the session to store progress

header('Content-Type: application/json; charset=utf-8');

$config = parse_ini_file(__DIR__ . '/config.ini', true);
$apiKey = $config['arvan']['api_key'];
$arvanApiBaseUrl = $config['arvan']['api_base_url'];

$uploadId = $_POST['uploadId'] ?? null;

// Function to update progress in session
function update_upload_progress($uid, $status, $progress = null, $message = null, $uploadedBytes = null, $totalBytes = null) {
    if (!$uid) return;

    // اطمینان از فعال بودن سشن قبل از نوشتن (اگرچه session_start() در ابتدای اسکریپت وجود دارد)
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['upload_progress'][$uid] = [
        'status' => $status,
        'progress' => $progress,
        'message' => $message,
        'uploaded' => $uploadedBytes,
        'total' => $totalBytes,
        'timestamp' => time()
    ];

    session_write_close(); // داده‌های سشن را فوراً ذخیره و قفل سشن را آزاد می‌کند

    // سشن را مجدداً برای ادامه عملیات در همین اسکریپت (مانند فراخوانی بعدی به همین تابع یا کدهای پس از cURL) باز می‌کند
    // این برای callback پراگرس cURL که چندین بار فراخوانی می‌شود، حیاتی است.
    session_start();
}

// cURL progress callback function
function curl_progress_callback($resource, $download_size, $downloaded_count, $upload_size, $uploaded_count) {
    $uploadId = null;
    if (is_resource($resource)) {
        // بازیابی uploadId که از طریق CURLOPT_PRIVATE به cURL پاس داده شده است
        $uploadId = curl_getinfo($resource, CURLINFO_PRIVATE);
    }

    if ($uploadId && $upload_size > 0) {
        $progress = round(($uploaded_count / $upload_size) * 100);
        update_upload_progress($uploadId, 'uploading_to_arvan', $progress, 'در حال آپلود فایل به آروان...', $uploaded_count, $upload_size);
    }
}

if (!$uploadId) {
    // اگر $uploadId از ابتدا موجود نباشد، نمی‌توانیم وضعیت را در سشن ذخیره کنیم.
    // اما چون در سمت کلاینت $uploadId تولید و ارسال می‌شود، باید اینجا موجود باشد.
    echo json_encode(['success' => false, 'message' => 'Upload ID is missing.']);
    exit;
}

try {
    if (!isset($_POST['channelId'], $_POST['filename'], $_POST['filePath'])) {
        update_upload_progress($uploadId, 'error', 0, 'اطلاعات ناقص است.');
        throw new Exception('اطلاعات ناقص است.');
    }

    $channelId = $_POST['channelId'];
    $filename = $_POST['filename'];
    $desc = $_POST['desc'] ?? '';
    $originalFilePath = $_POST['filePath'];
    $fullFilePath = realpath(__DIR__ . '/' . $originalFilePath);


    if (!$fullFilePath || !file_exists($fullFilePath)) {
        update_upload_progress($uploadId, 'error', 0, 'فایل یافت نشد: ' . htmlspecialchars($originalFilePath));
        throw new Exception('فایل یافت نشد: ' . htmlspecialchars($originalFilePath));
    }

    $fileSize = filesize($fullFilePath);
    $fileType = mime_content_type($fullFilePath);

    update_upload_progress($uploadId, 'initializing_tus', 0, 'در حال آماده‌سازی آپلود TUS...');

    // مرحله ۱: ایجاد فایل TUS در آروان
    $encode = fn($str) => base64_encode((string)$str);
    $uploadMetadata = 'filename ' . $encode($filename) . ',filetype ' . $encode($fileType);
    if ($desc) {
        $uploadMetadata .= ',description ' . $encode($desc);
    }

    $initHeaders = [
        'Tus-Resumable: 1.0.0',
        'Upload-Length: ' . $fileSize,
        'Upload-Metadata: ' . $uploadMetadata,
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ];

    $ch_init = curl_init();
    curl_setopt($ch_init, CURLOPT_URL, $arvanApiBaseUrl . "/channels/$channelId/files");
    curl_setopt($ch_init, CURLOPT_POST, true);
    curl_setopt($ch_init, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_init, CURLOPT_HTTPHEADER, $initHeaders);
    curl_setopt($ch_init, CURLOPT_HEADER, true);
    curl_setopt($ch_init, CURLOPT_POSTFIELDS, "");
    
    $initResponse = curl_exec($ch_init);
    $initHttpCode = curl_getinfo($ch_init, CURLINFO_HTTP_CODE);
    $initHeaderSize = curl_getinfo($ch_init, CURLINFO_HEADER_SIZE);
    $initHeadersStr = substr($initResponse, 0, $initHeaderSize);
    $initBodyStr = substr($initResponse, $initHeaderSize);
    curl_close($ch_init);

    if ($initHttpCode !== 201 && $initHttpCode !== 200) {
        $errorMsg = 'خطا در مرحله اول TUS (ایجاد فایل): ' . $initHttpCode . ' - ' . $initBodyStr;
        update_upload_progress($uploadId, 'error', 0, $errorMsg);
        throw new Exception($errorMsg);
    }

    if (!preg_match('/Location:\s*(.*)/i', $initHeadersStr, $matches)) {
        $errorMsg = 'خطا در مرحله اول TUS: Location header یافت نشد.';
        update_upload_progress($uploadId, 'error', 0, $errorMsg);
        throw new Exception($errorMsg);
    }
    $tusLocation = trim($matches[1]);
    $parsedTusLocation = parse_url($tusLocation);
    $fileId = basename($parsedTusLocation['path']);

    update_upload_progress($uploadId, 'tus_initialized', 5, 'TUS آماده شد. شناسه فایل: ' . htmlspecialchars($fileId));

    // مرحله ۲: آپلود فایل با PATCH به آروان
    $patchHeaders = [
        'Tus-Resumable: 1.0.0',
        'Upload-Offset: 0',
        'Content-Type: application/offset+octet-stream',
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ];

    $fileHandle = fopen($fullFilePath, 'r');
    if (!$fileHandle) {
        $errorMsg = 'امکان باز کردن فایل برای خواندن وجود ندارد: ' . htmlspecialchars($fullFilePath);
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
    curl_setopt($ch_patch, CURLOPT_HEADER, false);

    // تنظیمات مربوط به نمایش پیشرفت آپلود
    curl_setopt($ch_patch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch_patch, CURLOPT_PROGRESSFUNCTION, 'curl_progress_callback');
    curl_setopt($ch_patch, CURLOPT_PRIVATE, $uploadId); // پاس دادن $uploadId به تابع callback

    // اولین به‌روزرسانی قبل از شروع واقعی آپلود PATCH
    // $progressBeforePatch = isset($_SESSION['upload_progress'][$uploadId]['progress']) ? $_SESSION['upload_progress'][$uploadId]['progress'] : 5;
    // update_upload_progress($uploadId, 'uploading_to_arvan', $progressBeforePatch, 'شروع آپلود داده‌های فایل به آروان...', 0, $fileSize);


    $patchResponse = curl_exec($ch_patch);
    $patchHttpCode = curl_getinfo($ch_patch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch_patch);
    curl_close($ch_patch);
    fclose($fileHandle);

    if ($patchHttpCode !== 204) {
        $currentProgress = $_SESSION['upload_progress'][$uploadId]['progress'] ?? 5;
        $errorMsg = 'خطا در آپلود فایل (PATCH): ' . $patchHttpCode . ' - ' . $patchResponse . ($curlError ? ' cURL Error: ' . $curlError : '');
        update_upload_progress($uploadId, 'error', $currentProgress, $errorMsg);
        throw new Exception($errorMsg);
    }
    
    // اطمینان از اینکه پس از موفقیت PATCH، درصد ۱۰۰ برای آپلود داده ثبت شده است (قبل از مرحله ثبت ویدیو)
    // این کار توسط آخرین فراخوانی curl_progress_callback انجام می‌شود، اما برای اطمینان اینجا هم می‌توانیم ست کنیم.
    // update_upload_progress($uploadId, 'patch_complete_registering', 95, 'آپلود داده‌های فایل تکمیل شد. در حال ثبت ویدیو...');


    // مرحله ۳: ثبت ویدیو
    update_upload_progress($uploadId, 'patch_complete_registering', $_SESSION['upload_progress'][$uploadId]['progress'] ?? 95, 'آپلود داده‌های فایل تکمیل شد. در حال ثبت ویدیو...');
    $videoData = [
        'title' => $filename,
        'description' => $desc,
        'file_id' => $fileId,
        'convert_mode' => 'auto'
    ];

    $ch_video = curl_init();
    curl_setopt($ch_video, CURLOPT_URL, $arvanApiBaseUrl . "/channels/$channelId/videos");
    curl_setopt($ch_video, CURLOPT_POST, true);
    curl_setopt($ch_video, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_video, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch_video, CURLOPT_POSTFIELDS, json_encode($videoData, JSON_UNESCAPED_UNICODE));
    
    $regResp = curl_exec($ch_video);
    $regCode = curl_getinfo($ch_video, CURLINFO_HTTP_CODE);
    curl_close($ch_video);

    if ($regCode !== 201 && $regCode !== 200) {
        $errorMsg = 'خطا در مرحله سوم (ثبت ویدیو): ' . $regCode . ' - ' . $regResp;
        update_upload_progress($uploadId, 'error', $_SESSION['upload_progress'][$uploadId]['progress'] ?? 98, $errorMsg);
        throw new Exception($errorMsg);
    }

    update_upload_progress($uploadId, 'completed', 100, 'ارسال با موفقیت انجام شد!');
    echo json_encode(['success' => true, 'message' => 'ارسال با موفقیت انجام شد!', 'arvan_response' => json_decode($regResp)]);

} catch (Exception $e) {
    $currentProgress = ($uploadId && isset($_SESSION['upload_progress'][$uploadId])) ? $_SESSION['upload_progress'][$uploadId]['progress'] : 0;
    update_upload_progress($uploadId, 'error', $currentProgress, $e->getMessage());
    
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>