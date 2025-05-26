<?php
// v3/direct-arvan-uploader.php
// This page is for direct uploads to ArvanCloud via the proxy.
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>آپلود مستقیم ویدیو به آروان</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .progress { height: 30px; }
        .progress-bar { font-size: 1rem; line-height: 30px; }
        #arvanUploadStatus .alert { margin-top: 10px; }
        #arvanUploadStatus h5, #arvanUploadStatus h6 { margin-top: 15px; margin-bottom: 5px;}
        #arvanUploadStatus ul { padding-right: 20px; }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-body">
                        <h2 class="mb-4 text-center">آپلود مستقیم ویدیو به سرور آروان</h2>
                        <form id="directArvanUploadForm" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="videoFile" class="form-label">انتخاب ویدیو:</label>
                                <input type="file" id="videoFile" name="videoFile" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label for="arvanChannel" class="form-label">انتخاب کانال آروان:</label>
                                <select id="arvanChannel" name="channelId" class="form-select" required>
                                    <option value="">در حال بارگذاری کانال‌ها...</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="arvanTitle" class="form-label">عنوان ویدیو (در آروان):</label>
                                <input type="text" id="arvanTitle" name="title" class="form-control" required placeholder="مثلا: ویدیوی معرفی محصول">
                            </div>
                            <div class="mb-3">
                                <label for="arvanDesc" class="form-label">توضیحات ویدیو (در آروان):</label>
                                <textarea id="arvanDesc" name="description" class="form-control" rows="3" placeholder="توضیحات بیشتر درباره ویدیو"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">شروع آپلود به آروان</button>
                        </form>

                        <div class="progress mt-4" style="display:none;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="arvanProgressBar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                        </div>
                        <div id="arvanUploadStatus" class="mt-3">
                            </div>
                        <a href="index.php" class="btn btn-link w-100 mt-3">بازگشت به صفحه اصلی</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let currentUploadId = null;
    let pollingIntervalId = null;
    let videoStatusInterval = null; // For Arvan video processing status

    const statusTranslations = { // Copied from video-list.php for consistency
        'secure_upload_create': 'در حال ایجاد آپلود امن',
        'secure_upload_pending': 'در انتظار آپلود امن',
        'secure_upload_failed': 'خطا در آپلود امن',
        'getsize': 'در حال دریافت اندازه',
        'downloading': 'در حال دانلود از مبدا',
        'queue_download': 'در صف دانلود از مبدا',
        'generating_thumbnail': 'در حال ساخت تصویر بندانگشتی',
        'converting': 'در حال تبدیل',
        'queue_convert': 'در صف تبدیل',
        'watermarking': 'در حال افزودن واترمارک',
        'complete': 'تکمیل شده',
        'failed': 'خطا در پردازش',
        'source_failed': 'خطا در فایل منبع',
        'canceled': 'لغو شده'
    };

    function getVideoStatusText(statusCode) {
        return statusTranslations[statusCode] || statusCode;
    }

    // Function to display Arvan video details (similar to video-list.php)
    function displayVideoDetails(arvanVideoData, targetElement) {
        if (!targetElement) {
            console.error('Target element for displayVideoDetails is not defined.');
            return;
        }
        if (!arvanVideoData || !arvanVideoData.data) {
            targetElement.innerHTML = '<div class="alert alert-warning mt-3">اطلاعات ویدیوی آروان یافت نشد یا فرمت پاسخ نامعتبر است.</div>';
            return;
        }

        const videoInfo = arvanVideoData.data;
        let statusHTML = `<div class="mt-3 p-3 border rounded bg-light">`;
        statusHTML += `<h5>جزئیات ویدیو در آروان (ID: ${videoInfo.id})</h5>`;
        statusHTML += `<p class="mb-1"><strong>عنوان:</strong> ${videoInfo.title || '-'}</p>`;
        statusHTML += `<p class="mb-1"><strong>توضیحات:</strong> ${videoInfo.description || '-'}</p>`;
        statusHTML += `<p class="mb-1"><strong>تاریخ آپلود:</strong> ${new Date(videoInfo.created_at).toLocaleString('fa-IR')}</p>`;
        statusHTML += `<p class="mb-1"><strong>تاریخ تکمیل:</strong> ${videoInfo.completed_at ? new Date(videoInfo.completed_at).toLocaleString('fa-IR') : '-'}</p>`;
        statusHTML += `<p><strong>وضعیت:</strong> <span class="fw-bold ${videoInfo.status === 'complete' ? 'text-success' : (videoInfo.status === 'failed' || videoInfo.status === 'canceled' ? 'text-danger' : 'text-primary')}">${getVideoStatusText(videoInfo.status)}</span></p>`;

        if (videoInfo.status === 'complete') {
            statusHTML += '<h6 class="mt-3">لینک‌های پخش آنلاین:</h6><ul class="list-unstyled mb-3">';
            if (videoInfo.player_url) {
                statusHTML += `<li class="mb-2"><strong>پخش‌کننده آروان:</strong> <a href="${videoInfo.player_url}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">مشاهده در پلیر آروان</a></li>`;
            }
            if (videoInfo.hls_playlist) {
                statusHTML += `<li class="mb-2"><strong>HLS Playlist:</strong> <a href="${videoInfo.hls_playlist}" target="_blank" rel="noopener noreferrer" class="text-break">${videoInfo.hls_playlist}</a></li>`;
            }
            statusHTML += '</ul>';
             if (videoInfo.mp4_videos && videoInfo.mp4_videos.length > 0) {
                statusHTML += '<h6>لینک‌های دانلود MP4:</h6><div class="list-group mb-3">';
                const convertInfo = videoInfo.converted_info || videoInfo.convert_info || [];
                videoInfo.mp4_videos.forEach((mp4Url, index) => {
                    const quality = convertInfo[index] || {};
                    const resolution = quality.resolution || 'نامشخص';
                    statusHTML += `
                    <a href="${mp4Url}" target="_blank" rel="noopener noreferrer"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-download"></i> کیفیت ${resolution}</span>
                        <span class="badge bg-primary rounded-pill">دانلود</span>
                    </a>`;
                });
                statusHTML += '</div>';
            }
            if (videoInfo.thumbnail_url) {
                 statusHTML += `<div class="mb-2"><strong>تصویر بندانگشتی:</strong> <a href="${videoInfo.thumbnail_url}" target="_blank">مشاهده</a></div>`;
            }
            if (videoStatusInterval) {
                clearInterval(videoStatusInterval);
                videoStatusInterval = null;
            }
        } else if (videoInfo.status === 'failed' || videoInfo.status === 'canceled' || videoInfo.status === 'source_failed') {
            statusHTML += `<p class="text-danger">پردازش ویدیو با خطا مواجه شده است.</p>`;
            if(videoInfo.fail_reason) {
                statusHTML += `<p class="text-danger small">علت خطا: ${videoInfo.fail_reason}</p>`;
            }
            if (videoStatusInterval) {
                clearInterval(videoStatusInterval);
                videoStatusInterval = null;
            }
        } else {
            statusHTML += `<div class="d-flex align-items-center mt-2">
                            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                            <span>در حال بررسی خودکار وضعیت پردازش ویدیو...</span>
                         </div>`;
            if (!videoStatusInterval) {
                videoStatusInterval = setInterval(() => {
                    checkArvanVideoProcessingStatus(videoInfo.id, targetElement);
                }, 3000);
            }
             statusHTML += `<button class="btn btn-sm btn-outline-secondary mt-2" onclick="clearInterval(videoStatusInterval); videoStatusInterval = null; this.style.display='none';">توقف بررسی خودکار وضعیت پردازش</button>`;
        }
        statusHTML += `</div>`;
        targetElement.innerHTML = statusHTML;
    }
    
    // Function to check Arvan video processing status
    function checkArvanVideoProcessingStatus(videoId, targetElement) {
        if (!videoId) return;
        fetch(`arvan-video-status.php?video_id=${videoId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success === false) { // Error from our status script
                     targetElement.innerHTML = `<div class="alert alert-danger mt-3">خطا در دریافت وضعیت پردازش: ${data.message}</div>`;
                     if (videoStatusInterval) clearInterval(videoStatusInterval);
                } else { // Data directly from Arvan
                    displayVideoDetails(data, targetElement);
                }
            })
            .catch(error => {
                console.error('Error fetching video processing status:', error);
                if (targetElement) targetElement.innerHTML = `<div class="alert alert-danger mt-3">خطا در ارتباط برای بررسی وضعیت پردازش ویدیو.</div>`;
                if (videoStatusInterval) clearInterval(videoStatusInterval);
            });
    }


    // Function to poll upload progress from server to Arvan
    function pollProgress(uploadId) {
        if (currentUploadId !== uploadId) return; // Stop polling if new upload started

        fetch(`arvan-progress.php?uploadId=${uploadId}`)
            .then(response => response.json())
            .then(data => {
                if (currentUploadId !== uploadId) return;

                const progressBar = document.getElementById('arvanProgressBar');
                const statusDiv = document.getElementById('arvanUploadStatus');
                let uploadMessageSpan = statusDiv.querySelector('#uploadMessageSpan');
                if (!uploadMessageSpan) {
                    uploadMessageSpan = document.createElement('div');
                    uploadMessageSpan.id = 'uploadMessageSpan';
                    statusDiv.prepend(uploadMessageSpan);
                }

                progressBar.style.width = (data.progress || 0) + '%';
                progressBar.textContent = (data.progress || 0) + '%';
                progressBar.setAttribute('aria-valuenow', data.progress || 0);
                
                let progressMessage = data.message || data.status || 'در حال بررسی وضعیت آپلود به آروان...';
                if (data.uploaded !== undefined && data.total !== undefined && data.total > 0 && data.status === 'uploading_to_arvan') {
                    const uploadedMB = (data.uploaded / (1024*1024)).toFixed(2);
                    const totalMB = (data.total / (1024*1024)).toFixed(2);
                    progressMessage = `در حال آپلود به آروان: ${data.progress || 0}% (${uploadedMB}MB / ${totalMB}MB)`;
                }
                uploadMessageSpan.innerHTML = `<p class="mb-1">${progressMessage}</p>`;

                if (data.status === 'completed') {
                    progressBar.classList.remove('progress-bar-animated', 'bg-primary');
                    progressBar.classList.add('bg-success');
                    uploadMessageSpan.innerHTML = `<p class="text-success mb-1">${data.message || 'آپلود فایل به سرور آروان از طریق سرور شما تکمیل شد.'}</p>`;
                    clearTimeout(pollingIntervalId);
                    pollingIntervalId = null;
                    // The main AJAX request's 'load' event will handle displaying video details from Arvan.
                } else if (data.status === 'error') {
                    progressBar.classList.remove('progress-bar-animated', 'bg-primary', 'bg-success');
                    progressBar.classList.add('bg-danger');
                    uploadMessageSpan.innerHTML = `<p class="text-danger mb-1">خطا در آپلود به آروان: ${data.message || 'خطای نامشخص'}</p>`;
                    clearTimeout(pollingIntervalId);
                    pollingIntervalId = null;
                    currentUploadId = null; // Reset to allow new uploads
                } else if (data.status === 'not_found' || data.status === 'expired') {
                    uploadMessageSpan.innerHTML = `<p class="mb-1">${data.message || 'در انتظار شروع آپلود از سرور شما به آروان...'}</p>`;
                    pollingIntervalId = setTimeout(() => pollProgress(uploadId), 2500);
                } else { // Active states like initializing_tus, tus_initialized, uploading_to_arvan
                    progressBar.classList.add('progress-bar-animated', 'bg-primary');
                    progressBar.classList.remove('bg-success', 'bg-danger');
                    pollingIntervalId = setTimeout(() => pollProgress(uploadId), 1500);
                }
            })
            .catch(error => {
                console.error('Error polling progress:', error);
                const uploadMessageSpan = document.getElementById('arvanUploadStatus').querySelector('#uploadMessageSpan');
                if(uploadMessageSpan) uploadMessageSpan.innerHTML = `<p class="text-danger mb-1">خطا در ارتباط برای بررسی پیشرفت آپلود.</p>`;
                if (currentUploadId === uploadId) { // Only retry if it's still the current upload
                   pollingIntervalId = setTimeout(() => pollProgress(uploadId), 5000); // Retry after a longer delay
                }
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const channelSelect = document.getElementById('arvanChannel');
        fetch('channels-proxy.php')
            .then(res => res.json())
            .then(data => {
                channelSelect.innerHTML = ''; // Clear "loading..."
                if (data.data && Array.isArray(data.data)) {
                    if (data.data.length === 0) {
                        channelSelect.innerHTML = '<option value="">هیچ کانالی یافت نشد. ابتدا یک کانال در پنل آروان بسازید.</option>';
                    } else {
                        data.data.forEach(ch => {
                            const opt = document.createElement('option');
                            opt.value = ch.id;
                            opt.textContent = ch.title;
                            channelSelect.appendChild(opt);
                        });
                    }
                } else if (data.message) {
                     channelSelect.innerHTML = `<option value="">خطا: ${data.message}</option>`;
                } else {
                    channelSelect.innerHTML = '<option value="">خطا در دریافت لیست کانال‌ها.</option>';
                }
            })
            .catch(error => {
                console.error("Error fetching channels:", error);
                channelSelect.innerHTML = '<option value="">خطا در ارتباط برای دریافت کانال‌ها.</option>';
            });

        document.getElementById('directArvanUploadForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (pollingIntervalId) { clearTimeout(pollingIntervalId); pollingIntervalId = null; }
            if (videoStatusInterval) { clearInterval(videoStatusInterval); videoStatusInterval = null; }
            
            currentUploadId = 'direct_upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

            const formData = new FormData(this);
            formData.append('uploadId', currentUploadId); // Send client-generated uploadId to proxy

            const videoFile = document.getElementById('videoFile').files[0];
            if (!videoFile) {
                alert('لطفا یک فایل ویدیویی انتخاب کنید.');
                return;
            }
            // Optionally add client-side file size/type validation here

            const progressBar = document.getElementById('arvanProgressBar');
            const progressDiv = progressBar.parentElement;
            const statusDiv = document.getElementById('arvanUploadStatus');
            
            statusDiv.innerHTML = ''; // Clear previous messages
            const uploadMessageSpan = document.createElement('div');
            uploadMessageSpan.id = 'uploadMessageSpan';
            statusDiv.appendChild(uploadMessageSpan);

            const videoDetailsContainer = document.createElement('div'); // For Arvan video details post-upload
            videoDetailsContainer.id = 'videoDetailsDivOnPage';
            statusDiv.appendChild(videoDetailsContainer);

            progressDiv.style.display = 'block';
            progressBar.style.width = '0%';
            progressBar.textContent = '0%';
            progressBar.setAttribute('aria-valuenow', '0');
            progressBar.classList.remove('bg-success', 'bg-danger');
            progressBar.classList.add('bg-primary', 'progress-bar-animated');
            uploadMessageSpan.innerHTML = '<p class="mb-1">در حال ارسال فایل به سرور شما و آماده سازی برای ارسال به آروان...</p>';
            
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> در حال آپلود...';


            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'arvan-direct-upload-proxy.php', true);

            // Optional: Track browser-to-server upload progress
            xhr.upload.onprogress = function(event) {
                if (event.lengthComputable) {
                    // This progress is for browser to your PHP server.
                    // You might not want to show this if it's too confusing with the server-to-Arvan progress.
                    // For now, we focus on server-to-Arvan progress via polling.
                    // const percentComplete = (event.loaded / event.total) * 100;
                    // console.log(`Browser to PHP Server Progress: ${percentComplete.toFixed(2)}%`);
                    // If pollProgress hasn't started showing messages yet, update initial message:
                     if (!pollingIntervalId && progressBar.style.width === '0%') {
                         uploadMessageSpan.innerHTML = '<p class="mb-1">فایل در حال ارسال به سرور شما است...</p>';
                     }
                }
            };

            xhr.onload = function() {
                submitButton.disabled = false;
                submitButton.innerHTML = 'شروع آپلود به آروان';
                
                // The polling should handle final 'completed' or 'error' messages for the upload itself.
                // This 'onload' primarily deals with the response from arvan-direct-upload-proxy.php.
                // which should confirm the video registration with Arvan.
                if (pollingIntervalId) { clearTimeout(pollingIntervalId); pollingIntervalId = null; } // Stop polling if it was somehow still active

                const currentUploadMsgSpan = document.getElementById('arvanUploadStatus').querySelector('#uploadMessageSpan');
                const currentVideoDetailsDiv = document.getElementById('videoDetailsDivOnPage');

                try {
                    const responseJson = JSON.parse(xhr.responseText);
                    if (xhr.status === 200 && responseJson.success) {
                        if(currentUploadMsgSpan) {
                             // Update based on proxy's final message (might be redundant if polling caught 'completed')
                            currentUploadMsgSpan.innerHTML = `<p class="text-success mb-1">${responseJson.message || 'عملیات آپلود و ثبت ویدیو در آروان با موفقیت انجام شد.'}</p>`;
                        }
                        progressBar.classList.remove('progress-bar-animated', 'bg-primary');
                        progressBar.classList.add('bg-success'); // Ensure progress bar is green
                        progressBar.style.width = '100%'; // Ensure it's full
                        progressBar.textContent = '100%';


                        if (responseJson.arvan_response && responseJson.arvan_response.data && responseJson.arvan_response.data.id) {
                            const arvanVideoId = responseJson.arvan_response.data.id;
                            if(currentVideoDetailsDiv) {
                                currentVideoDetailsDiv.innerHTML = `<div class="mt-3 p-3 border rounded bg-light">ویدیو با موفقیت در آروان ثبت شد. شناسه: <strong>${arvanVideoId}</strong>. در حال دریافت جزئیات و وضعیت پردازش...</div>`;
                                checkArvanVideoProcessingStatus(arvanVideoId, currentVideoDetailsDiv); // Start checking processing status
                            }
                        } else {
                           if(currentVideoDetailsDiv) currentVideoDetailsDiv.innerHTML = '<div class="alert alert-warning mt-3">آپلود به پروکسی و آروان موفق بود اما اطلاعات کامل ویدیوی آروان از پاسخ دریافت نشد. ممکن است نیاز به بررسی دستی در پنل آروان باشد.</div>';
                        }
                    } else { // Error from proxy script after upload attempt or during registration
                        if(currentUploadMsgSpan) {
                            currentUploadMsgSpan.innerHTML = `<p class="text-danger mb-1">خطا از سرور پروکسی: ${responseJson.message || xhr.statusText || 'خطای ناشناخته'}</p>`;
                        }
                        progressBar.classList.remove('bg-primary', 'bg-success', 'progress-bar-animated');
                        progressBar.classList.add('bg-danger');
                    }
                } catch (parseError) {
                    if(currentUploadMsgSpan) {
                        currentUploadMsgSpan.innerHTML = `<p class="text-danger mb-1">پاسخ غیرمنتظره از سرور پروکسی. جزئیات در کنسول موجود است.</p>`;
                    }
                    progressBar.classList.remove('bg-primary', 'bg-success', 'progress-bar-animated');
                    progressBar.classList.add('bg-danger');
                    console.error("Error parsing proxy response: ", parseError, xhr.responseText);
                }
                currentUploadId = null; // Reset for next potential upload
            };

            xhr.onerror = function() {
                submitButton.disabled = false;
                submitButton.innerHTML = 'شروع آپلود به آروان';
                if (pollingIntervalId) { clearTimeout(pollingIntervalId); pollingIntervalId = null; }
                
                const statusDiv = document.getElementById('arvanUploadStatus');
                statusDiv.innerHTML = '<div class="alert alert-danger mt-3">خطا در ارتباط با سرور پروکسی. لطفا اتصال اینترنت خود را بررسی کنید و دوباره تلاش کنید.</div>';
                progressBar.classList.remove('bg-primary', 'bg-success', 'progress-bar-animated');
                progressBar.classList.add('bg-danger');
                currentUploadId = null;
            };
            
            xhr.send(formData);
            // Start polling for server-to-Arvan progress shortly after sending the file to your server.
            // The PHP script will update the session as soon as it starts the TUS process.
            setTimeout(() => pollProgress(currentUploadId), 1000); 
        });
    });
    </script>
</body>
</html>