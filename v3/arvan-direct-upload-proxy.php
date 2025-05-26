<?php
// v3/arvan-direct-upload-proxy.php (with resumability, DB, and increased execution time)

// افزایش حداکثر زمان اجرای اسکریپت به ۶۰۰ ثانیه (۱۰ دقیقه)
// این مقدار را بر اساس حداکثر زمان مورد نیاز برای آپلودهای خود تنظیم کنید.
// برای آپلودهای بسیار بزرگ یا اتصالات کندتر، ممکن است به زمان بیشتری نیاز داشته باشید.
// مقدار 0 به معنی عدم وجود محدودیت زمانی است (با احتیاط استفاده شود).
ini_set('max_execution_time', 600); 
ini_set('memory_limit', '256M'); // در صورت نیاز، محدودیت حافظه را نیز افزایش دهید

session_start();

header('Content-Type: application/json; charset=utf-utf-8');
error_reporting(E_ALL); // گزارش تمام خطاها برای اشکال‌زدایی
ini_set('display_errors', 0); // در محیط پروداکشن خاموش شود و از لاگ‌ها استفاده شود
ini_set('log_errors', 1); // فعال کردن لاگ خطاها

// --- Configuration and DB Connection ---
$dbFile = __DIR__ . '/data.db';
$persistentUploadsDir = __DIR__ . '/persistent_uploads/'; 

if (!is_dir($persistentUploadsDir) || !is_writable($persistentUploadsDir)) {
    http_response_code(500);
    $errorMessage = 'خطا: پوشه persistent_uploads (' . realpath($persistentUploadsDir) . ') وجود ندارد یا قابل نوشتن نیست.';
    echo json_encode(['success' => false, 'message' => $errorMessage]);
    error_log($errorMessage);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // $pdo->exec("PRAGMA journal_mode = WAL;"); // بهبود عملکرد همزمان برای SQLite، اختیاری
} catch (PDOException $e) {
    http_response_code(500);
    $errorMessage = 'خطا در اتصال به دیتابیس: ' . $e->getMessage();
    echo json_encode(['success' => false, 'message' => $errorMessage]);
    error_log($errorMessage);
    exit;
}

$config = parse_ini_file(__DIR__ . '/config.ini', true); //
if (!$config || !isset($config['arvan']['api_key'], $config['arvan']['api_base_url'])) {
    http_response_code(500);
    $errorMessage = 'خطا: فایل تنظیمات (config.ini) نامعتبر یا ناقص است.';
    echo json_encode(['success' => false, 'message' => $errorMessage]);
    error_log($errorMessage);
    exit;
}
$apiKey = $config['arvan']['api_key']; //
$arvanApiBaseUrl = $config['arvan']['api_base_url']; //

// --- Global Variables for Progress Callback ---
$clientUploadIdForProgress = null;
$baseOffsetForProgress = 0;
$totalFileSizeForProgress = 0;

// --- Helper Functions ---
function getCurrentTimestamp() {
    return date('Y-m-d H:i:s');
}

function update_upload_progress($uid, $status, $current_chunk_progress = null, $message = null, $uploaded_chunk_bytes = null, $total_chunk_bytes = null) {
    global $clientUploadIdForProgress, $baseOffsetForProgress, $totalFileSizeForProgress; // Removed $pdo as it's not directly used here
    if (!$uid) return;

    if (session_status() == PHP_SESSION_NONE) { session_start(); }

    $overallProgress = 0;
    $effectiveUploadedBytes = $baseOffsetForProgress; 

    if ($status === 'uploading_to_arvan' && $total_chunk_bytes > 0 && $current_chunk_progress !== null) {
        if ($totalFileSizeForProgress > 0) {
            $overallProgress = (($baseOffsetForProgress + $uploaded_chunk_bytes) / $totalFileSizeForProgress) * 100;
        } else {
            $overallProgress = ($total_chunk_bytes > 0 && $uploaded_chunk_bytes >= $total_chunk_bytes && $baseOffsetForProgress == 0) ? 100 : 0;
        }
        $effectiveUploadedBytes = $baseOffsetForProgress + $uploaded_chunk_bytes;
    } elseif ($status === 'patch_complete_registering' || $status === 'completed') {
        $overallProgress = 100;
        $effectiveUploadedBytes = $totalFileSizeForProgress > 0 ? $totalFileSizeForProgress : 0;
    } elseif ($status === 'initializing_tus' || $status === 'tus_initialized') {
        if ($totalFileSizeForProgress > 0) {
            $overallProgress = max(0, min(5, (($baseOffsetForProgress / $totalFileSizeForProgress) * 100) ) );
        } else {
            $overallProgress = 0;
        }
    } else if (isset($_SESSION['upload_progress'][$uid]['progress']) && $status !== 'error') { // Don't overwrite error progress with old value
        $overallProgress = $_SESSION['upload_progress'][$uid]['progress'];
    } else if ($status === 'error' && $current_chunk_progress !== null) { // If error, use provided progress
        $overallProgress = $current_chunk_progress;
    }


    $_SESSION['upload_progress'][$uid] = [
        'status' => $status,
        'progress' => round($overallProgress),
        'message' => $message,
        'uploaded' => $effectiveUploadedBytes, 
        'total' => $totalFileSizeForProgress,   
        'timestamp' => time()
    ];
    session_write_close();
    if (session_status() == PHP_SESSION_NONE) { session_start(); }
}

function curl_progress_callback($resource, $download_size, $downloaded_count, $upload_size, $uploaded_count) {
    global $clientUploadIdForProgress; 
    // baseOffsetForProgress and totalFileSizeForProgress are used by update_upload_progress directly
    $uploadIdFromCurl = null;
    if (is_resource($resource)) {
        $uploadIdFromCurl = curl_getinfo($resource, CURLINFO_PRIVATE); 
    }

    if ($uploadIdFromCurl && $upload_size > 0) { 
        $progress = round(($uploaded_count / $upload_size) * 100);
        update_upload_progress($uploadIdFromCurl, 'uploading_to_arvan', $progress, 'در حال آپلود فایل به آروان...', $uploaded_count, $upload_size);
    }
}

// --- Database operations functions ---
function getUploadAttempt($pdo, $client_upload_id) {
    $stmt = $pdo->prepare("SELECT * FROM upload_attempts WHERE client_upload_id = :client_upload_id");
    $stmt->execute([':client_upload_id' => $client_upload_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function createUploadAttempt($pdo, $data) {
    $sql = "INSERT INTO upload_attempts (client_upload_id, original_filename, persistent_temp_filepath, total_filesize, status, target_channel_id, video_title, video_description, created_at, updated_at)
            VALUES (:client_upload_id, :original_filename, :persistent_temp_filepath, :total_filesize, :status, :target_channel_id, :video_title, :video_description, :created_at, :updated_at)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    return $pdo->lastInsertId();
}

function updateUploadAttempt($pdo, $client_upload_id_for_where_clause, $fields_to_set) {
    $params_for_sql_set_clause = $fields_to_set;
    $params_for_sql_set_clause['updated_at'] = getCurrentTimestamp();

    $setClausesArr = [];
    $executeParameters = [];

    foreach ($params_for_sql_set_clause as $key => $value) {
        if ($key === 'client_upload_id') { continue; }
        $setClausesArr[] = "$key = :$key";
        $executeParameters[$key] = $value;
    }

    if (empty($setClausesArr)) {
        // This means $fields_to_set was empty or only contained 'client_upload_id'.
        // 'updated_at' should always be in $params_for_sql_set_clause, so $setClausesArr includes it.
        // This if-condition block might not be strictly necessary if 'updated_at' is always intended to be set.
        // For safety, if by some logic it was empty, avoid executing an empty update.
        error_log("Warning: updateUploadAttempt called for client_upload_id '{$client_upload_id_for_where_clause}' with no valid fields to update.");
        return true; 
    }
    
    $sql = "UPDATE upload_attempts SET " . implode(', ', $setClausesArr) . 
           " WHERE client_upload_id = :client_upload_id_for_where_clause_param";
    
    $executeParameters['client_upload_id_for_where_clause_param'] = $client_upload_id_for_where_clause;

    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($executeParameters);
    } catch (PDOException $e) {
        error_log("Error in updateUploadAttempt execution for client_upload_id '{$client_upload_id_for_where_clause}': " . $e->getMessage() . " SQL: " . $sql . " Params: " . json_encode($executeParameters));
        throw $e; 
    }
}

function saveArvanVideo($pdo, $videoDetailsData, $originalFilename, $filesize) { // Renamed $videoDetails to $videoDetailsData
    $now = getCurrentTimestamp();
    $mp4Links = [];
    if (isset($videoDetailsData['mp4_videos']) && isset($videoDetailsData['converted_info'])) { // Ensure 'data' key is not assumed if $videoDetailsData is already the data part
        foreach($videoDetailsData['mp4_videos'] as $index => $url) {
            $resolution = $videoDetailsData['converted_info'][$index]['resolution'] ?? "کیفیت " . ($index + 1);
            $mp4Links[] = ['url' => $url, 'resolution' => $resolution];
        }
    }

    $data = [
        ':arvan_video_id' => $videoDetailsData['id'], // Assuming $videoDetailsData is the 'data' object from Arvan
        ':arvan_channel_id' => $videoDetailsData['channel']['id'] ?? null,
        ':title' => $videoDetailsData['title'],
        ':description' => $videoDetailsData['description'],
        ':arvan_player_url' => $videoDetailsData['player_url'],
        ':arvan_hls_playlist_url' => $videoDetailsData['hls_playlist'],
        ':arvan_dash_playlist_url' => $videoDetailsData['dash_playlist'],
        ':arvan_thumbnail_url' => $videoDetailsData['thumbnail_url'],
        ':arvan_video_url_origin' => $videoDetailsData['video_url'],
        ':arvan_config_url' => $videoDetailsData['config_url'],
        ':mp4_links_json' => json_encode($mp4Links, JSON_UNESCAPED_UNICODE),
        ':original_filename_uploaded' => $originalFilename,
        ':filesize_bytes' => $filesize,
        ':duration_seconds' => $videoDetailsData['file_info']['general']['duration'] ?? null,
        ':arvan_status' => $videoDetailsData['status'],
        ':arvan_created_at' => $videoDetailsData['created_at'],
        ':arvan_completed_at' => $videoDetailsData['completed_at'],
        ':db_created_at' => $now,
        ':db_updated_at' => $now,
    ];
    $sql = "INSERT OR REPLACE INTO arvan_videos (arvan_video_id, arvan_channel_id, title, description, arvan_player_url, arvan_hls_playlist_url, arvan_dash_playlist_url, arvan_thumbnail_url, arvan_video_url_origin, arvan_config_url, mp4_links_json, original_filename_uploaded, filesize_bytes, duration_seconds, arvan_status, arvan_created_at, arvan_completed_at, db_created_at, db_updated_at)
            VALUES (:arvan_video_id, :arvan_channel_id, :title, :description, :arvan_player_url, :arvan_hls_playlist_url, :arvan_dash_playlist_url, :arvan_thumbnail_url, :arvan_video_url_origin, :arvan_config_url, :mp4_links_json, :original_filename_uploaded, :filesize_bytes, :duration_seconds, :arvan_status, :arvan_created_at, :arvan_completed_at, :db_created_at, :db_updated_at)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
}

// --- Main Logic ---
$clientUploadId = $_POST['uploadId'] ?? null; 

if (!$clientUploadId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Upload ID (client_upload_id) ارسال نشده است.']);
    exit;
}
$clientUploadIdForProgress = $clientUploadId; 

try {
    $uploadAttempt = getUploadAttempt($pdo, $clientUploadId);
    $now = getCurrentTimestamp();

    $channelId = $_POST['channelId'] ?? ($uploadAttempt['target_channel_id'] ?? null);
    $videoTitle = $_POST['title'] ?? ($uploadAttempt['video_title'] ?? null);
    $videoDescription = $_POST['description'] ?? ($uploadAttempt['video_description'] ?? '');

    if (empty($channelId) || $videoTitle === null) { // Title can be empty string, so check for null explicitly if it's truly optional but required if not set before
         update_upload_progress($clientUploadId, 'error', 0, 'اطلاعات ضروری (شناسه کانال یا عنوان ویدیو) ناقص است.');
         throw new Exception('اطلاعات ضروری (شناسه کانال یا عنوان ویدیو) ناقص است.');
    }

    if (!$uploadAttempt) { 
        if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
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
            update_upload_progress($clientUploadId, 'error', 0, 'خطا در آپلود فایل به سرور شما: ' . $errorMessage);
            throw new Exception('خطا در آپلود فایل به سرور شما: ' . $errorMessage);
        }

        $originalFilename = basename($_FILES['videoFile']['name']);
        $tempUploadedFilePath = $_FILES['videoFile']['tmp_name'];
        $fileSize = $_FILES['videoFile']['size'];
        $fileType = mime_content_type($tempUploadedFilePath);

        if ($fileSize == 0) { 
            update_upload_progress($clientUploadId, 'error', 0, 'فایل ارسالی خالی است.');
            throw new Exception('فایل ارسالی خالی است.');
        }
        
        $totalFileSizeForProgress = $fileSize; // Set global for progress
        $baseOffsetForProgress = 0;            // Set global for progress

        $sanitizedOriginalFilename = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $originalFilename);
        $persistentFilePath = rtrim($persistentUploadsDir, '/') . '/' . $clientUploadId . '_' . $sanitizedOriginalFilename;
        
        if (!move_uploaded_file($tempUploadedFilePath, $persistentFilePath)) {
            update_upload_progress($clientUploadId, 'error', 0, 'خطا در انتقال فایل به محل ذخیره‌سازی دائمی موقت.');
            throw new Exception('خطا در انتقال فایل به محل ذخیره‌سازی دائمی موقت.');
        }
        
        createUploadAttempt($pdo, [
            ':client_upload_id' => $clientUploadId, ':original_filename' => $originalFilename,
            ':persistent_temp_filepath' => $persistentFilePath, ':total_filesize' => $fileSize,
            ':status' => 'pending_tus_creation', ':target_channel_id' => $channelId,
            ':video_title' => $videoTitle, ':video_description' => $videoDescription,
            ':created_at' => $now, ':updated_at' => $now,
        ]);
        $uploadAttempt = getUploadAttempt($pdo, $clientUploadId); 
    } else {
        $persistentFilePath = $uploadAttempt['persistent_temp_filepath'];
        $fileSize = (int)$uploadAttempt['total_filesize'];
        $originalFilename = $uploadAttempt['original_filename']; 
        $fileType = file_exists($persistentFilePath) ? mime_content_type($persistentFilePath) : 'application/octet-stream';

        $totalFileSizeForProgress = $fileSize;
        $baseOffsetForProgress = (int)$uploadAttempt['current_offset_on_arvan'];

        if (!file_exists($persistentFilePath)) {
            $errMsg = 'فایل موقت دائمی یافت نشد. لطفاً دوباره آپلود کنید.';
            update_upload_progress($clientUploadId, 'error', 0, $errMsg);
            updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed', 'last_error_message' => 'Persistent temp file not found.']);
            throw new Exception($errMsg);
        }
        if ($uploadAttempt['status'] === 'completed') {
             update_upload_progress($clientUploadId, 'completed', 100, 'این آپلود قبلاً با موفقیت انجام شده است.');
             // Fetch video details from arvan_videos and return if needed
             $stmtVideo = $pdo->prepare("SELECT * FROM arvan_videos WHERE title = :title ORDER BY db_created_at DESC LIMIT 1"); // Simplified retrieval
             $stmtVideo->execute([':title' => $uploadAttempt['video_title']]);
             $videoData = $stmtVideo->fetch(PDO::FETCH_ASSOC);
             echo json_encode(['success' => true, 'message' => 'این آپلود قبلاً با موفقیت انجام شده است.', 'arvan_response' => ['data' => $videoData]]); // Mocking arvan_response structure
             exit;
        }
         if ($uploadAttempt['status'] !== 'patch_complete_pending_registration' && $uploadAttempt['status'] !== 'tus_created_pending_patch' && $uploadAttempt['status'] !== 'uploading_to_arvan') {
             // For 'failed', 'failed_registration', 'pending_creation', reset for a new TUS attempt with Arvan
             updateUploadAttempt($pdo, $clientUploadId, [
                 'status' => 'pending_tus_creation', 
                 'arvan_tus_url' => null, // Force re-creation
                 'arvan_file_id' => null, // Force re-creation
                 'current_offset_on_arvan' => 0, // Reset offset
                 'last_error_message' => null
                ]);
             $uploadAttempt = getUploadAttempt($pdo, $clientUploadId); // Refresh local copy
             $baseOffsetForProgress = 0;
         }
    }
    update_upload_progress($clientUploadId, 'initializing_tus', null, 'در حال آماده‌سازی آپلود...');

    if (empty($uploadAttempt['arvan_tus_url'])) {
        $encode = fn($str) => base64_encode((string)$str);
        $uploadMetadata = 'filename ' . $encode($videoTitle) . ',filetype ' . $encode($fileType);
        if (!empty($videoDescription)) { $uploadMetadata .= ',description ' . $encode($videoDescription); }

        $initHeaders = [ 
            'Tus-Resumable: 1.0.0', 'Upload-Length: ' . $fileSize, 'Upload-Metadata: ' . $uploadMetadata,
            'Authorization: ' . $apiKey, 'Accept: application/json',
        ];
        $ch_init = curl_init();
        curl_setopt($ch_init, CURLOPT_URL, $arvanApiBaseUrl . "/channels/" . $channelId . "/files");
        curl_setopt($ch_init, CURLOPT_POST, true); curl_setopt($ch_init, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_init, CURLOPT_HTTPHEADER, $initHeaders); curl_setopt($ch_init, CURLOPT_HEADER, true);
        curl_setopt($ch_init, CURLOPT_POSTFIELDS, "");
        // Add cURL timeouts
        curl_setopt($ch_init, CURLOPT_CONNECTTIMEOUT, 30); 
        curl_setopt($ch_init, CURLOPT_TIMEOUT, 60);    

        $initResponseFull = curl_exec($ch_init); $initHttpCode = curl_getinfo($ch_init, CURLINFO_HTTP_CODE);
        $initHeaderSize = curl_getinfo($ch_init, CURLINFO_HEADER_SIZE);
        $initHeadersStr = substr($initResponseFull, 0, $initHeaderSize); $initBodyStr = substr($initResponseFull, $initHeaderSize);
        $initCurlError = curl_error($ch_init); curl_close($ch_init);

        if ($initHttpCode !== 201) { 
            $errorMsg = 'خطا در TUS (ایجاد فایل در آروان): ' . $initHttpCode . ' - ' . $initBodyStr . ($initCurlError ? ' cURL Error: ' . $initCurlError : '');
            updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed', 'last_error_message' => $errorMsg]);
            update_upload_progress($clientUploadId, 'error', 0, $errorMsg); throw new Exception($errorMsg);
        }
        if (!preg_match('/Location:\s*(.*)/i', $initHeadersStr, $matches)) { 
            $errorMsg = 'هدر Location در پاسخ ایجاد فایل TUS یافت نشد.';
            updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed', 'last_error_message' => $errorMsg]);
            update_upload_progress($clientUploadId, 'error', 0, $errorMsg); throw new Exception($errorMsg);
        }
        $newArvanTusUrl = trim($matches[1]);
        $newArvanFileId = basename(parse_url($newArvanTusUrl)['path']);
        updateUploadAttempt($pdo, $clientUploadId, [
            'arvan_tus_url' => $newArvanTusUrl, 'arvan_file_id' => $newArvanFileId,
            'status' => 'tus_created_pending_patch', 'current_offset_on_arvan' => 0 // Reset offset with new TUS URL
        ]);
        $uploadAttempt = getUploadAttempt($pdo, $clientUploadId); // Refresh
        $baseOffsetForProgress = 0; // Offset is 0 for new TUS URL
    }
    update_upload_progress($clientUploadId, 'tus_initialized', null, 'TUS آماده شد. شناسه فایل آروان: ' . htmlspecialchars($uploadAttempt['arvan_file_id']));

    if ($uploadAttempt['status'] !== 'patch_complete_pending_registration' && $uploadAttempt['status'] !== 'completed') {
        $ch_head = curl_init();
        curl_setopt($ch_head, CURLOPT_URL, $uploadAttempt['arvan_tus_url']);
        curl_setopt($ch_head, CURLOPT_NOBODY, true); curl_setopt($ch_head, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_head, CURLOPT_HEADER, true);
        curl_setopt($ch_head, CURLOPT_HTTPHEADER, ['Tus-Resumable: 1.0.0', 'Authorization: ' . $apiKey]);
        curl_setopt($ch_head, CURLOPT_CONNECTTIMEOUT, 30); curl_setopt($ch_head, CURLOPT_TIMEOUT, 60);    
        $head_response_headers = curl_exec($ch_head); $head_http_code = curl_getinfo($ch_head, CURLINFO_HTTP_CODE);
        curl_close($ch_head);

        $currentArvanOffset = 0; // Default to 0 if HEAD fails or offset not found
        if ($head_http_code == 200 || $head_http_code == 204) {
            if (preg_match('/Upload-Offset:\s*(\d+)/i', $head_response_headers, $matches)) {
                $currentArvanOffset = (int)$matches[1];
            }
        } else {
            $errorMsg = "خطا در دریافت آفست آپلود از آروان: " . $head_http_code . ". ممکن است URL آپلود منقضی شده باشد. تلاش مجدد باعث ایجاد URL جدید خواهد شد.";
            updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed', 'last_error_message' => $errorMsg, 'arvan_tus_url' => null, 'arvan_file_id' => null]);
            update_upload_progress($clientUploadId, 'error', null, $errorMsg);
            throw new Exception($errorMsg);
        }
        updateUploadAttempt($pdo, $clientUploadId, ['current_offset_on_arvan' => $currentArvanOffset]);
        $uploadAttempt['current_offset_on_arvan'] = $currentArvanOffset;
        $baseOffsetForProgress = $currentArvanOffset; 
    }

    if ($uploadAttempt['status'] !== 'patch_complete_pending_registration' && $uploadAttempt['status'] !== 'completed') {
        $currentArvanOffset = (int)$uploadAttempt['current_offset_on_arvan'];
        $remainingSize = $fileSize - $currentArvanOffset;
        if ($remainingSize < 0) $remainingSize = 0;

        if ($remainingSize > 0) {
            $fileHandle = fopen($persistentFilePath, 'r');
            if (!$fileHandle) { 
                $errorMsg = 'امکان باز کردن فایل موقت دائمی برای خواندن وجود ندارد: ' . $persistentFilePath;
                updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed', 'last_error_message' => $errorMsg]);
                update_upload_progress($clientUploadId, 'error', 0, $errorMsg); throw new Exception($errorMsg);
            }
            if ($currentArvanOffset > 0) { fseek($fileHandle, $currentArvanOffset); }

            $patchHeaders = [ 
                'Tus-Resumable: 1.0.0', 'Upload-Offset: ' . $currentArvanOffset,
                'Content-Type: application/offset+octet-stream', 'Authorization: ' . $apiKey, 'Accept: application/json',
            ];
            $ch_patch = curl_init();
            curl_setopt($ch_patch, CURLOPT_URL, $uploadAttempt['arvan_tus_url']);
            curl_setopt($ch_patch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch_patch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_patch, CURLOPT_HTTPHEADER, $patchHeaders);
            curl_setopt($ch_patch, CURLOPT_UPLOAD, true); curl_setopt($ch_patch, CURLOPT_INFILE, $fileHandle);
            curl_setopt($ch_patch, CURLOPT_INFILESIZE, $remainingSize);
            curl_setopt($ch_patch, CURLOPT_NOPROGRESS, false); curl_setopt($ch_patch, CURLOPT_PROGRESSFUNCTION, 'curl_progress_callback');
            curl_setopt($ch_patch, CURLOPT_PRIVATE, $clientUploadId); 
            // Longer timeout for actual file upload
            curl_setopt($ch_patch, CURLOPT_CONNECTTIMEOUT, 60); 
            curl_setopt($ch_patch, CURLOPT_TIMEOUT, $totalFileSizeForProgress < (50 * 1024 * 1024) ? 300 : 1800 ); // 5 min for <50MB, 30 min for larger

            updateUploadAttempt($pdo, $clientUploadId, ['status' => 'uploading_to_arvan']);
            $initialProgressPercentChunk = 0; // For this specific PATCH call
            update_upload_progress($clientUploadId, 'uploading_to_arvan', $initialProgressPercentChunk, 'شروع آپلود داده‌های فایل به آروان...', 0, $remainingSize);

            $patchResponse = curl_exec($ch_patch); $patchHttpCode = curl_getinfo($ch_patch, CURLINFO_HTTP_CODE);
            $patchCurlError = curl_error($ch_patch); curl_close($ch_patch); fclose($fileHandle);

            if ($patchHttpCode !== 204) { 
                $errorMsg = 'خطا در آپلود فایل (PATCH): ' . $patchHttpCode . ' - ' . $patchResponse . ($patchCurlError ? ' cURL Error: ' . $patchCurlError : '');
                updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed', 'last_error_message' => $errorMsg]);
                $failedProgress = $totalFileSizeForProgress > 0 ? ($uploadAttempt['current_offset_on_arvan'] / $totalFileSizeForProgress) * 100 : 0;
                update_upload_progress($clientUploadId, 'error', round($failedProgress), $errorMsg);
                throw new Exception($errorMsg);
            }
            updateUploadAttempt($pdo, $clientUploadId, [
                'current_offset_on_arvan' => $fileSize, 
                'status' => 'patch_complete_pending_registration'
            ]);
            $uploadAttempt['status'] = 'patch_complete_pending_registration'; 
        } else { 
             updateUploadAttempt($pdo, $clientUploadId, [
                'current_offset_on_arvan' => $fileSize,
                'status' => 'patch_complete_pending_registration'
            ]);
            $uploadAttempt['status'] = 'patch_complete_pending_registration';
        }
    }
    update_upload_progress($clientUploadId, 'patch_complete_registering', 100, 'آپلود داده‌ها تکمیل شد. در حال ثبت ویدیو...');

    $arvanFileIdToRegister = $uploadAttempt['arvan_file_id'];
    if (empty($arvanFileIdToRegister)) {
        throw new Exception("خطای داخلی: شناسه فایل آروان برای ثبت یافت نشد.");
    }
    $videoData = ['title' => $videoTitle, 'description' => $videoDescription, 'file_id' => $arvanFileIdToRegister, 'convert_mode' => 'auto'];
    $ch_video = curl_init();
    curl_setopt($ch_video, CURLOPT_URL, $arvanApiBaseUrl . "/channels/" . $channelId . "/videos");
    curl_setopt($ch_video, CURLOPT_POST, true); curl_setopt($ch_video, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_video, CURLOPT_HTTPHEADER, ['Authorization: ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch_video, CURLOPT_POSTFIELDS, json_encode($videoData, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch_video, CURLOPT_CONNECTTIMEOUT, 30); curl_setopt($ch_video, CURLOPT_TIMEOUT, 120);    
    
    $regRespStr = curl_exec($ch_video); $regHttpCode = curl_getinfo($ch_video, CURLINFO_HTTP_CODE);
    $regCurlError = curl_error($ch_video); curl_close($ch_video);

    if ($regHttpCode !== 201 && $regHttpCode !== 200) { 
        $errorMsg = 'خطا در ثبت ویدیو: ' . $regHttpCode . ' - ' . $regRespStr . ($regCurlError ? ' cURL Error: ' . $regCurlError : '');
        updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed_registration', 'last_error_message' => $errorMsg]);
        update_upload_progress($clientUploadId, 'error', 99, $errorMsg); throw new Exception($errorMsg);
    }

    $arvanFinalResponse = json_decode($regRespStr, true);
    if (!isset($arvanFinalResponse['data']['id'])) {
        $errorMsg = 'پاسخ ثبت ویدیو از آروان معتبر نیست یا شناسه ویدیو دریافت نشد.';
        updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed_registration', 'last_error_message' => $errorMsg . $regRespStr]);
        update_upload_progress($clientUploadId, 'error', 99, $errorMsg); throw new Exception($errorMsg);
    }

    updateUploadAttempt($pdo, $clientUploadId, ['status' => 'completed', 'updated_at' => getCurrentTimestamp()]); // Ensure updated_at is set on final success
    saveArvanVideo($pdo, $arvanFinalResponse['data'], $originalFilename, $fileSize); 

    if (file_exists($persistentFilePath)) { 
        @unlink($persistentFilePath); // Suppress error if unlink fails, but log it
        if(file_exists($persistentFilePath)) error_log("Failed to delete persistent file: {$persistentFilePath}");
    }
    
    update_upload_progress($clientUploadId, 'completed', 100, 'ارسال با موفقیت انجام شد!');
    echo json_encode(['success' => true, 'message' => 'ارسال با موفقیت انجام شد!', 'arvan_response' => $arvanFinalResponse]);

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $currentAttemptForError = getUploadAttempt($pdo, $clientUploadId); // Re-fetch to get latest DB state
    if ($currentAttemptForError && $currentAttemptForError['status'] !== 'failed' && $currentAttemptForError['status'] !== 'failed_registration') {
        updateUploadAttempt($pdo, $clientUploadId, ['status' => 'failed', 'last_error_message' => $errorMessage]);
    }
    
    $errorProgress = 0;
    if($currentAttemptForError && isset($currentAttemptForError['total_filesize']) && $currentAttemptForError['total_filesize'] > 0){ // Check isset for total_filesize
        $errorProgress = ($currentAttemptForError['current_offset_on_arvan'] / $currentAttemptForError['total_filesize']) * 100;
    }
    update_upload_progress($clientUploadId, 'error', round($errorProgress), $errorMessage);
    
    error_log("Arvan Direct Upload Proxy Error for clientUploadId $clientUploadId: " . $errorMessage . "\nPOST Data: " . print_r($_POST, true) . "\nFILES Data: " . print_r($_FILES, true));
    http_response_code(400); 
    echo json_encode(['success' => false, 'message' => $errorMessage]);
}
?>