<?php
// upload-proxy.php
// این فایل باید در کنار video-uploader.php قرار بگیرد
// API KEY فقط اینجا قرار می‌گیرد و سمت کلاینت ارسال نمی‌شود

header('Content-Type: application/json; charset=utf-8');

// تنظیمات
$config = parse_ini_file(__DIR__ . '/config.ini', true);
$apiKey = $config['arvan']['api_key'];
$arvanApiBaseUrl = $config['arvan']['api_base_url'];

try {
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('خطا در آپلود فایل.');
    }
    $file = $_FILES['videoFile'];
    $filePath = $file['tmp_name'];
    $fileName = $file['name'];
    $fileSize = $file['size'];
    $fileType = mime_content_type($filePath);
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $channelId = $_POST['channelId'] ?? '';
    if (!$title || !$channelId) {
        throw new Exception('عنوان و کانال الزامی است.');
    }

    // مرحله ۱: ایجاد فایل TUS در آروان
    $encode = fn($str) => base64_encode((string)$str);
    $uploadMetadata = 'filename ' . $encode($fileName) . ',filetype ' . $encode($fileType);
    $initHeaders = [
        'Tus-Resumable: 1.0.0',
        'Upload-Length: ' . $fileSize,
        'Upload-Metadata: ' . $uploadMetadata,
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $arvanApiBaseUrl . "/channels/$channelId/files");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $initHeaders);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "");
    $initResponse = curl_exec($ch);
    $initHeaderSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $initHeadersStr = substr($initResponse, 0, $initHeaderSize);
    curl_close($ch);
    if (!preg_match('/Location:\s*(.*)/i', $initHeadersStr, $matches)) {
        throw new Exception('خطا در مرحله اول TUS (ایجاد فایل): Location header یافت نشد.');
    }
    $tusLocation = trim($matches[1]);
    $fileId = basename(parse_url($tusLocation, PHP_URL_PATH));

    // مرحله ۲: آپلود فایل با PATCH به آروان
    $patchHeaders = [
        'Tus-Resumable: 1.0.0',
        'Upload-Offset: 0',
        'Content-Type: application/offset+octet-stream',
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ];
    $fileHandle = fopen($filePath, 'r');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $arvanApiBaseUrl . "/channels/$channelId/files/$fileId");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $patchHeaders);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILE, $fileHandle);
    curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $patchResponse = curl_exec($ch);
    $patchCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fileHandle);
    if ($patchCode !== 204) {
        throw new Exception('خطا در مرحله دوم TUS (آپلود فایل با PATCH): ' . $patchResponse);
    }

    // مرحله ۳: ثبت ویدیو
    $videoData = [
        'title' => $title,
        'description' => $description,
        'file_id' => $fileId,
        'convert_mode' => 'auto'
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $arvanApiBaseUrl . "/channels/$channelId/videos");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($videoData));
    $registerResponse = curl_exec($ch);
    $registerCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $registerResult = json_decode($registerResponse, true);
    if ($registerCode !== 201 || !isset($registerResult['data']['id'])) {
        throw new Exception('خطا در مرحله سوم (ثبت ویدیو): ' . ($registerResult['message'] ?? ('HTTP ' . $registerCode)));
    }
    $videoId = $registerResult['data']['id'];
    echo json_encode([
        'success' => true,
        'video_id' => $videoId,
        'data' => $registerResult['data'],
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 