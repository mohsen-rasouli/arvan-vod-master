<?php
$videoDir = __DIR__ . '/video/'; // مسیر پوشه ویدیوها
$dataFile = $videoDir . 'data.json'; // مسیر فایل data.json
// خواندن اطلاعات ویدیوها از data.json
$videos = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
?><!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <title>لیست ویدیوها</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .modal-custom { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; }
        .modal-content-custom { background: #fff; padding: 20px; border-radius: 8px; max-width: 90vw; max-height: 90vh; overflow-y: auto; } /* Added overflow-y */
        .close { float: left; font-size: 24px; cursor: pointer; margin-bottom: 10px; } /* RTL: changed from float: right */
        .video-item-actions .btn { margin-left: 5px; } /* RTL: margin for buttons */
        .progress { height: 25px; }
        .progress-bar { font-size: 1rem; line-height: 25px; }
        #arvanUploadStatus .alert { margin-top: 10px; }
        #arvanUploadStatus h5, #arvanUploadStatus h6 { margin-top: 15px; margin-bottom: 5px;}
        #arvanUploadStatus ul { padding-right: 20px; } /* RTL: Changed padding-left to padding-right */
    </style>
</head>
<body dir="rtl" class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-10"> <!-- Increased col width for better layout -->
                <div class="card shadow">
                    <div class="card-body">
                        <h2 class="mb-4 text-center">لیست ویدیوها</h2>
                        <?php if (empty($videos)): ?>
                            <div class="alert alert-info text-center">هنوز ویدیویی آپلود نشده است. برای شروع، یک <a href="video-uploader.php">ویدیوی جدید آپلود کنید</a>.</div>
                        <?php else: ?>
                        <ul class="list-group mb-3">
                            <?php foreach ($videos as $video): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <a href="#" class="video-link fw-bold" data-src="<?php echo htmlspecialchars($video['path']); ?>" data-desc="<?php echo htmlspecialchars($video['desc']); ?>">
                                            <?php echo htmlspecialchars($video['name']); ?>
                                        </a>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($video['desc']); ?></small>
                                    </div>
                                    <div class="video-item-actions">
                                        <button class="btn btn-success btn-sm send-to-arvan-btn" 
                                            data-filename="<?php echo htmlspecialchars($video['name']); ?>"
                                            data-desc="<?php echo htmlspecialchars($video['desc']); ?>"
                                            data-path="<?php echo htmlspecialchars($video['path']); ?>">
                                            ارسال به سرور آروان
                                        </button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <a href="index.php" class="btn btn-link w-100 mt-3">بازگشت به صفحه اصلی</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Local Video Playback -->
    <div class="modal-custom" id="videoModal">
        <div class="modal-content-custom">
            <span class="close" id="closeModal">&times;</span>
            <div id="videoContainer"></div>
            <div id="videoDesc" class="mt-3"></div>
        </div>
    </div>

    <!-- Modal for Arvan Upload -->
    <div class="modal fade" id="arvanModal" tabindex="-1" aria-labelledby="arvanModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg"> <!-- Increased modal size -->
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="arvanModalLabel">ارسال ویدیو به سرور آروان</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
          </div>
          <div class="modal-body">
            <form id="arvanUploadForm">
              <div class="mb-3">
                <label for="arvanChannel" class="form-label">انتخاب کانال:</label>
                <select id="arvanChannel" name="channelId" class="form-select" required>
                  <option value="">در حال بارگذاری کانال‌ها...</option>
                </select>
              </div>
              <div class="mb-3">
                <label for="arvanFilename" class="form-label">نام فایل (در آروان):</label>
                <input type="text" id="arvanFilename" name="filename" class="form-control" required>
              </div>
              <div class="mb-3">
                <label for="arvanDesc" class="form-label">توضیحات (در آروان):</label>
                <input type="text" id="arvanDesc" name="desc" class="form-control">
              </div>
              <input type="hidden" id="arvanFilePath" name="filePath">
              <button type="submit" class="btn btn-primary w-100">شروع آپلود و ارسال</button>
            </form>
            <div class="progress mt-3" style="display:none; height: 30px;"> <!-- * Progress bar height -->
              <div class="progress-bar progress-bar-striped progress-bar-animated" id="arvanProgressBar" role="progressbar" style="width: 0%; font-size: 1rem;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
            </div>
            <div id="arvanUploadStatus" class="mt-3">
                <!-- Messages related to upload and video status will appear here -->
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Modal for local video playback
    const videoModal = document.getElementById('videoModal');
    const closeVideoModal = document.getElementById('closeModal');
    const videoModalContainer = document.getElementById('videoContainer');
    const videoModalDesc = document.getElementById('videoDesc');
    document.querySelectorAll('.video-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const src = this.getAttribute('data-src');
            const desc = this.getAttribute('data-desc');
            videoModalContainer.innerHTML = `<video src="${src}" controls style="max-width:80vw;max-height:70vh;"></video>`;
            videoModalDesc.textContent = desc;
            videoModal.style.display = 'flex';
        });
    });
    closeVideoModal.onclick = function() {
        videoModal.style.display = 'none';
        videoModalContainer.innerHTML = '';
    };
    window.onclick = function(event) {
        if (event.target == videoModal) {
            videoModal.style.display = 'none';
            videoModalContainer.innerHTML = '';
        }
    };

    // Arvan Upload and Status Logic
    let currentUploadId = null; 
    let pollingIntervalId = null;

    const statusTranslations = {
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

        // بررسی وضعیت ویدیو و نمایش محتوای مناسب
        if (videoInfo.status === 'complete') {
            statusHTML += '<h6 class="mt-3">لینک‌های پخش آنلاین:</h6><ul class="list-unstyled mb-3">';
            if (videoInfo.player_url) {
                statusHTML += `<li class="mb-2"><strong>پخش‌کننده آروان:</strong> <a href="${videoInfo.player_url}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">مشاهده در پلیر آروان</a></li>`;
            }
            if (videoInfo.hls_playlist) {
                statusHTML += `<li class="mb-2"><strong>HLS Playlist:</strong> <a href="${videoInfo.hls_playlist}" target="_blank" rel="noopener noreferrer" class="text-break">${videoInfo.hls_playlist}</a></li>`;
            }
            if (videoInfo.dash_playlist) {
                statusHTML += `<li class="mb-2"><strong>DASH Playlist:</strong> <a href="${videoInfo.dash_playlist}" target="_blank" rel="noopener noreferrer" class="text-break">${videoInfo.dash_playlist}</a></li>`;
            }
            statusHTML += '</ul>';

            // نمایش لینک اصلی ویدیو
            if (videoInfo.video_url) {
                statusHTML += `<h6>لینک اصلی ویدیو:</h6>
                <div class="mb-3">
                    <a href="${videoInfo.video_url}" target="_blank" rel="noopener noreferrer" class="text-break">${videoInfo.video_url}</a>
                </div>`;
            }

            // نمایش لینک‌های دانلود MP4
            if (videoInfo.mp4_videos && videoInfo.mp4_videos.length > 0) {
                statusHTML += '<h6>لینک‌های دانلود MP4:</h6><div class="list-group mb-3">';
                // اضافه کردن اطلاعات تبدیل برای نمایش کیفیت‌ها
                const convertInfo = videoInfo.converted_info || videoInfo.convert_info || [];
                videoInfo.mp4_videos.forEach((mp4Url, index) => {
                    const quality = convertInfo[index] || {};
                    const resolution = quality.resolution || 'نامشخص';
                    const videoBitrate = quality.video_bitrate ? `${quality.video_bitrate}k` : '';
                    const audioBitrate = quality.audio_bitrate ? `${quality.audio_bitrate}k` : '';
                    
                    statusHTML += `
                    <a href="${mp4Url}" target="_blank" rel="noopener noreferrer" 
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span>
                            <i class="bi bi-download"></i> 
                            کیفیت ${resolution}
                            ${videoBitrate ? ` (ویدیو: ${videoBitrate}` : ''}
                            ${audioBitrate ? `, صدا: ${audioBitrate})` : ''}
                        </span>
                        <span class="badge bg-primary rounded-pill">دانلود</span>
                    </a>`;
                });
                statusHTML += '</div>';
            }

            if (videoInfo.thumbnail_url) {
                 statusHTML += `<div class="mb-2"><strong>تصویر بندانگشتی:</strong> <a href="${videoInfo.thumbnail_url}" target="_blank" rel="noopener noreferrer">مشاهده تصویر</a></div>`;
            }
            if (videoInfo.preview_image) {
                 statusHTML += `<div class="mb-2"><strong>تصویر پیش‌نمایش:</strong> <a href="${videoInfo.preview_image}" target="_blank" rel="noopener noreferrer">مشاهده پیش‌نمایش</a></div>`;
            }
            if (window.videoStatusInterval) {
                clearInterval(window.videoStatusInterval);
                window.videoStatusInterval = null;
            }
        } else if (videoInfo.status === 'failed' || videoInfo.status === 'canceled' || videoInfo.status === 'source_failed' || videoInfo.status === 'secure_upload_failed') {
            statusHTML += `<p class="text-danger">پردازش ویدیو با خطا مواجه شده است.</p>`;
            if(videoInfo.fail_reason) {
                statusHTML += `<p class="text-danger small">علت خطا: ${videoInfo.fail_reason}</p>`;
            }
            if (window.videoStatusInterval) {
                clearInterval(window.videoStatusInterval);
                window.videoStatusInterval = null;
            }
        } else {
            // برای وضعیت‌های در حال پردازش، تنظیم بررسی خودکار هر 2 ثانیه
            statusHTML += `<div class="d-flex align-items-center mt-2">
                            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                            <span>در حال بررسی خودکار وضعیت...</span>
                         </div>`;
            
            if (!window.videoStatusInterval) {
                window.videoStatusInterval = setInterval(() => {
                    checkArvanVideoStatus(videoInfo.id, targetElement);
                }, 2000);
            }
            // اضافه کردن دکمه توقف بررسی خودکار
            statusHTML += `<button class="btn btn-sm btn-outline-secondary mt-2" onclick="clearInterval(window.videoStatusInterval); window.videoStatusInterval = null; this.style.display='none';">توقف بررسی خودکار</button>`;
        }
        statusHTML += `</div>`;
        targetElement.innerHTML = statusHTML;
    }

    window.checkArvanVideoStatus = function(videoId, targetElement) {
        if (!videoId) {
            targetElement.innerHTML = '<div class="alert alert-danger mt-3">شناسه ویدیو برای بررسی وضعیت موجود نیست.</div>';
            return;
        }
        
        const refreshButton = targetElement.querySelector(`button[onclick*="${videoId}"]`);
        const spinner = refreshButton ? refreshButton.querySelector('span.spinner-grow') : null;
        if(spinner) spinner.classList.remove('d-none');


        // Preserve existing content if it's just a refresh, otherwise set "checking status" message
        if (!targetElement.innerHTML.includes(videoId)) { // Initial check
             targetElement.innerHTML = `<div class="mt-3 p-3 border rounded bg-light">در حال بررسی وضعیت ویدیوی ${videoId}... <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></div>`;
        }


        fetch(`arvan-video-status.php?video_id=${videoId}`)
            .then(response => {
                if (!response.ok) {
                    return response.json().then(errData => {
                        throw new Error(errData.message || `خطای سرور: ${response.status}`);
                    }).catch(() => {
                        throw new Error(`خطای سرور: ${response.status}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if(spinner) spinner.classList.add('d-none');
                if (data.success === false) {
                    targetElement.innerHTML = `<div class="alert alert-danger mt-3">خطا در دریافت وضعیت: ${data.message} <button class="btn btn-sm btn-outline-secondary ms-2" onclick="checkArvanVideoStatus('${videoId}', document.getElementById('videoDetailsDivInModal'))">تلاش مجدد</button></div>`;
                } else {
                    displayVideoDetails(data, targetElement);
                }
            })
            .catch(error => {
                if(spinner) spinner.classList.add('d-none');
                console.error('Error fetching video status:', error);
                targetElement.innerHTML = `<div class="alert alert-danger mt-3">خطا در ارتباط برای بررسی وضعیت ویدیو: ${error.message} <button class="btn btn-sm btn-outline-secondary ms-2" onclick="checkArvanVideoStatus('${videoId}', document.getElementById('videoDetailsDivInModal'))">تلاش مجدد</button></div>`;
            });
    }

    function pollProgress(uploadId) {
        if (currentUploadId !== uploadId) return;

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
                    statusDiv.prepend(uploadMessageSpan); // Prepend to show above video details
                }
                
                progressBar.style.width = (data.progress || 0) + '%';
                progressBar.textContent = (data.progress || 0) + '%';
                progressBar.setAttribute('aria-valuenow', data.progress || 0);
                
                let progressMessage = data.message || data.status || 'در حال بررسی وضعیت آپلود...';
                if (data.uploaded !== undefined && data.total !== undefined && data.total > 0 && data.status === 'uploading_to_arvan') {
                    const uploadedMB = (data.uploaded / (1024*1024)).toFixed(2);
                    const totalMB = (data.total / (1024*1024)).toFixed(2);
                    progressMessage = `در حال آپلود به آروان: ${data.progress || 0}% (${uploadedMB}MB / ${totalMB}MB)`;
                }
                uploadMessageSpan.innerHTML = `<p class="mb-1">${progressMessage}</p>`;


                if (data.status === 'completed') {
                    progressBar.classList.remove('progress-bar-animated', 'bg-primary');
                    progressBar.classList.add('bg-success');
                    uploadMessageSpan.innerHTML = `<p class="text-success mb-1">${data.message || 'آپلود فایل به آروان از طریق سرور تکمیل شد.'}</p>`;
                    clearTimeout(pollingIntervalId);
                    pollingIntervalId = null; 
                } else if (data.status === 'error') {
                    progressBar.classList.remove('progress-bar-animated', 'bg-primary', 'bg-success');
                    progressBar.classList.add('bg-danger');
                    uploadMessageSpan.innerHTML = `<p class="text-danger mb-1">خطا در آپلود: ${data.message || 'خطای نامشخص در آپلود'}</p>`;
                    clearTimeout(pollingIntervalId);
                    pollingIntervalId = null;
                    currentUploadId = null;
                } else if (data.status === 'not_found' || data.status === 'expired') {
                    uploadMessageSpan.innerHTML = `<p class="mb-1">${data.message || 'در انتظار شروع آپلود سرور...'}</p>`;
                    pollingIntervalId = setTimeout(() => pollProgress(uploadId), 2500); // Slower poll if not found yet
                } else { // Active states
                    progressBar.classList.add('progress-bar-animated', 'bg-primary');
                    progressBar.classList.remove('bg-success', 'bg-danger');
                    pollingIntervalId = setTimeout(() => pollProgress(uploadId), 1500);
                }
            })
            .catch(error => {
                console.error('Error polling progress:', error);
                 const uploadMessageSpan = document.getElementById('arvanUploadStatus').querySelector('#uploadMessageSpan');
                 if(uploadMessageSpan) uploadMessageSpan.innerHTML = `<p class="text-danger mb-1">خطا در ارتباط برای بررسی پیشرفت آپلود.</p>`;
                if (currentUploadId === uploadId) {
                   pollingIntervalId = setTimeout(() => pollProgress(uploadId), 5000);
                }
            });
    }

    document.getElementById('arvanUploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (pollingIntervalId) { clearTimeout(pollingIntervalId); pollingIntervalId = null; }
        currentUploadId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

        const channelId = document.getElementById('arvanChannel').value;
        const filename = document.getElementById('arvanFilename').value;
        const desc = document.getElementById('arvanDesc').value;
        const filePath = document.getElementById('arvanFilePath').value;

        const progressBar = document.getElementById('arvanProgressBar');
        const progressDiv = progressBar.parentElement;
        const statusDiv = document.getElementById('arvanUploadStatus');
        
        statusDiv.innerHTML = ''; // Clear previous messages
        
        const uploadMessageSpan = document.createElement('div');
        uploadMessageSpan.id = 'uploadMessageSpan';
        statusDiv.appendChild(uploadMessageSpan);

        const videoDetailsContainer = document.createElement('div');
        videoDetailsContainer.id = 'videoDetailsDivInModal';
        statusDiv.appendChild(videoDetailsContainer);
        
        progressDiv.style.display = 'block';
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressBar.classList.remove('bg-success', 'bg-danger');
        progressBar.classList.add('bg-primary', 'progress-bar-animated');
        uploadMessageSpan.innerHTML = '<p class="mb-1">در حال آماده سازی برای ارسال...</p>';

        const formData = new FormData();
        formData.append('channelId', channelId);
        formData.append('filename', filename);
        formData.append('desc', desc);
        formData.append('filePath', filePath);
        formData.append('uploadId', currentUploadId);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'arvan-upload-proxy.php', true);
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                const currentUploadMsgSpan = document.getElementById('uploadMessageSpan');
                const currentVideoDetailsDiv = document.getElementById('videoDetailsDivInModal');

                if (pollingIntervalId) { clearTimeout(pollingIntervalId); pollingIntervalId = null; }
                
                let arvanVideoId = null;
                try {
                    const responseJson = JSON.parse(xhr.responseText);
                    if (xhr.status === 200 && responseJson.success) {
                        if(currentUploadMsgSpan) {
                            // Message already set by pollProgress's 'completed' state, or this confirms proxy success.
                            currentUploadMsgSpan.innerHTML = `<p class="text-success mb-1">${responseJson.message || 'عملیات پروکسی با موفقیت انجام شد.'}</p>`;
                        }
                        
                        if (responseJson.arvan_response && responseJson.arvan_response.data && responseJson.arvan_response.data.id) {
                            arvanVideoId = responseJson.arvan_response.data.id;
                            if(currentVideoDetailsDiv) {
                                // Initial message before fetching full details
                                currentVideoDetailsDiv.innerHTML = `<div class="mt-3 p-3 border rounded bg-light">شناسه ویدیو در آروان: <strong>${arvanVideoId}</strong>. در حال دریافت جزئیات...</div>`;
                                checkArvanVideoStatus(arvanVideoId, currentVideoDetailsDiv);
                            }
                        } else {
                           if(currentVideoDetailsDiv) currentVideoDetailsDiv.innerHTML = '<div class="alert alert-warning mt-3">آپلود به پروکسی موفق بود اما شناسه ویدیوی آروان از پاسخ دریافت نشد.</div>';
                        }
                    } else { // Error from proxy script
                        if(currentUploadMsgSpan) {
                            currentUploadMsgSpan.innerHTML = `<p class="text-danger mb-1">خطا در سرور پروکسی: ${responseJson.message || xhr.statusText || 'خطای ناشناخته'}</p>`;
                        }
                        progressBar.classList.remove('bg-primary', 'bg-success', 'progress-bar-animated');
                        progressBar.classList.add('bg-danger');
                    }
                } catch (parseError) {
                    if(currentUploadMsgSpan) {
                        currentUploadMsgSpan.innerHTML = '<p class="text-danger mb-1">پاسخ غیرمنتظره و غیر JSON از سرور پروکسی.</p>';
                    }
                    progressBar.classList.remove('bg-primary', 'bg-success', 'progress-bar-animated');
                    progressBar.classList.add('bg-danger');
                    console.error("Error parsing proxy response: ", parseError, xhr.responseText);
                }
                currentUploadId = null; 
            }
        };
        
        xhr.send(formData);
        setTimeout(() => pollProgress(currentUploadId), 500); // Start polling for upload progress
    });

    document.querySelectorAll('.send-to-arvan-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        document.getElementById('arvanFilename').value = this.getAttribute('data-filename');
        document.getElementById('arvanDesc').value = this.getAttribute('data-desc');
        document.getElementById('arvanFilePath').value = this.getAttribute('data-path');
        
        const progressBar = document.getElementById('arvanProgressBar');
        const progressDiv = progressBar.parentElement;
        const statusDiv = document.getElementById('arvanUploadStatus');
        
        statusDiv.innerHTML = ''; 
        progressDiv.style.display = 'none';
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressBar.classList.remove('bg-success', 'bg-danger', 'bg-primary', 'progress-bar-animated');

        const channelSelect = document.getElementById('arvanChannel');
        channelSelect.innerHTML = '<option value="">در حال بارگذاری کانال‌ها...</option>';
        fetch('channels-proxy.php') //
          .then(res => res.json())
          .then(data => {
            channelSelect.innerHTML = ''; 
            if (data.data && Array.isArray(data.data)) {
              if (data.data.length === 0) {
                channelSelect.innerHTML = '<option value=\"\">هیچ کانالی یافت نشد. ابتدا یک کانال در پنل آروان بسازید.</option>';
              } else {
                data.data.forEach(ch => {
                  const opt = document.createElement('option');
                  opt.value = ch.id;
                  opt.textContent = ch.title;
                  channelSelect.appendChild(opt);
                });
              }
            } else if (data.message) { // Error from our proxy
                channelSelect.innerHTML = `<option value=\"\">خطا: ${data.message}</option>`;
            } 
             else {
              channelSelect.innerHTML = '<option value=\"\">خطا در دریافت لیست کانال‌ها.</option>';
            }
          })
          .catch(error => {
            console.error("Error fetching channels:", error);
            channelSelect.innerHTML = '<option value=\"\">خطا در ارتباط برای دریافت کانال‌ها.</option>';
          });
        
        const arvanModalInstance = new bootstrap.Modal(document.getElementById('arvanModal'));
        arvanModalInstance.show();
      });
    });
    </script>
</body>
</html>