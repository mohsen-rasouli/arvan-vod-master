<?php
// v3/get-incomplete-uploads-proxy.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0); // Turn off error reporting to client for this proxy

$dbFile = __DIR__ . '/data.db';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // انتخاب آپلودهایی که کامل نشده‌اند یا در وضعیت خطا هستند
    // شما می‌توانید وضعیت‌های بیشتری را برای "ناتمام" در نظر بگیرید
    $stmt = $pdo->prepare("
        SELECT client_upload_id, original_filename, total_filesize, status, 
               target_channel_id, video_title, video_description, 
               current_offset_on_arvan, last_error_message, updated_at
        FROM upload_attempts 
        WHERE status NOT IN ('completed', 'archived_completed') 
        ORDER BY updated_at DESC
    ");
    $stmt->execute();
    $incompleteUploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $incompleteUploads]);

} catch (PDOException $e) {
    error_log("Database Error in get-incomplete-uploads-proxy.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا در ارتباط با دیتابیس.']);
} catch (Exception $e) {
    error_log("General Error in get-incomplete-uploads-proxy.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطای عمومی در سرور.']);
}
?>