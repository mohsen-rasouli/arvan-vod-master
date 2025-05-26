 <?php
// arvan-progress.php
session_start();
header('Content-Type: application/json; charset=utf-8');

$uploadId = $_GET['uploadId'] ?? null;

if (!$uploadId) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No uploadId provided.',
        'progress' => 0
    ]);
    exit;
}

if (isset($_SESSION['upload_progress'][$uploadId])) {
    $progressData = $_SESSION['upload_progress'][$uploadId];
    // Optional: Clean up old session data if completed or errored out long ago
    // if (($progressData['status'] === 'completed' || $progressData['status'] === 'error') && (time() - $progressData['timestamp'] > 3600)) {
    //     unset($_SESSION['upload_progress'][$uploadId]);
    //     echo json_encode(['status' => 'expired', 'message' => 'Upload session expired.', 'progress' => $progressData['progress']]);
    //     exit;
    // }
    echo json_encode($progressData);
} else {
    echo json_encode([
        'status' => 'not_found',
        'message' => 'Upload progress not found. It might be initializing or an error occurred.',
        'progress' => 0
    ]);
}
?>