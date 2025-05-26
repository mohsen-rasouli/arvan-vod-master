<?php
// v3/index.php
?>
<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <title>ویدیوها - مدیریت جامع آروان</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 text-center">
                <h1 class="mb-4">مدیریت جامع آروان VOD</h1>
                
                <div class="card shadow mb-4">
                    <div class="card-header">
                       عملیات مرتبط با کانال‌های آروان
                    </div>
                    <div class="card-body">
                        <a href="manage-channels.php" class="btn btn-warning btn-lg mb-3 w-100">مدیریت کانال‌ها (ایجاد، ویرایش، حذف)</a>
                        <a href="arvan-channel-videos.php" class="btn btn-info btn-lg w-100">مشاهده ویدیوهای کانال‌های آروان</a>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header">
                       عملیات مرتبط با ویدیوهای آروان
                    </div>
                    <div class="card-body">
                        <a href="direct-arvan-uploader.php" class="btn btn-success btn-lg w-100">آپلود مستقیم ویدیو به آروان</a>
                    </div>
                </div>

                <div class="card shadow">
                    <div class="card-header">
                        آپلود دومرحله‌ای (منسوخ شده - استفاده از آپلود مستقیم توصیه می‌شود)
                    </div>
                    <div class="card-body">
                        <a href="video-uploader.php" class="btn btn-outline-primary btn-lg mb-3 w-100 disabled">۱. ارسال ویدیو به این سرور</a>
                        <a href="video-list.php" class="btn btn-outline-secondary btn-lg w-100 disabled">۲. لیست ویدیوهای این سرور (و ارسال به آروان)</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>