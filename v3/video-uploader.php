<?php
// مسیر پوشه ویدیوها
$videoDir = __DIR__ . '/video/';
$dataFile = $videoDir . 'data.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        // نمایش خطا در همان صفحه (در آینده می‌توان پیام خطا را نمایش داد)
    } else {
        $file = $_FILES['video'];
        $filename = basename($file['name']);
        $targetPath = $videoDir . $filename;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $desc = isset($_POST['desc']) ? $_POST['desc'] : '';
            $mime = mime_content_type($targetPath);
            $data = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
            $data[] = [
                'name' => $filename,
                'desc' => $desc,
                'mime' => $mime,
                'path' => 'video/' . $filename
            ];
            file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            header('Location: video-list.php');
            exit;
        }
        // نمایش خطا در همان صفحه (در آینده می‌توان پیام خطا را نمایش داد)
    }
}
?>
<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <title>آپلود ویدیو</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
</head>
<body dir="rtl" class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-body">
                        <h2 class="mb-4 text-center">آپلود ویدیو</h2>
                        <form method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">انتخاب ویدیو</label>
                                <input type="file" name="video" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">توضیحات</label>
                                <input type="text" name="desc" class="form-control" placeholder="توضیحات">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">آپلود</button>
                        </form>
                        <a href="index.php" class="btn btn-link mt-3 w-100">بازگشت به صفحه اصلی</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 