<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>آپلود ویدیو به آروان VOD با TUS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.rtl.min.css">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; padding-top: 20px; background-color: #f8f9fa; }
        .container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .progress-container { width: 100%; background-color: #e9ecef; border-radius: .25rem; margin-top: 10px; }
        .progress-bar-custom { height: 20px; background-color: #0d6efd; width: 0%; text-align: center; line-height: 20px; color: white; border-radius: .25rem; transition: width 0.4s ease; }
        .status-area { margin-top: 15px; padding: 10px; border: 1px solid #ced4da; border-radius: .25rem; background-color: #f8f9fa; }
        .log-area { margin-top: 15px; padding: 10px; border: 1px solid #ced4da; border-radius: .25rem; background-color: #e9ecef; min-height: 100px; font-family: monospace; font-size: 0.9em; white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4 text-center">آپلود ویدیو به آروان‌کلاد با TUS (کلاینت مستقیم)</h1>
        <form id="uploadForm">
            <div class="mb-3">
                <label for="channelSelect" class="form-label">انتخاب کانال آروان:</label>
                <select id="channelSelect" name="channelId" class="form-select" required>
                    <option value="">در حال بارگذاری کانال‌ها...</option>
                </select>
            </div>
            <div class="mb-3">
                <label for="videoTitle" class="form-label">عنوان ویدیو:</label>
                <input type="text" id="videoTitle" name="title" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="videoDescription" class="form-label">توضیحات ویدیو (اختیاری):</label>
                <textarea id="videoDescription" name="description" class="form-control" rows="2"></textarea>
            </div>
            <div class="mb-3">
                <label for="fileInput" class="form-label">انتخاب فایل ویدیو:</label>
                <input type="file" id="fileInput" name="videoFile" class="form-control" required accept="video/*">
            </div>
            <button type="submit" id="uploadButton" class="btn btn-primary w-100 mt-3">شروع آپلود</button>
        </form>
        <div class="progress-container mt-4">
            <div id="progressBar" class="progress-bar-custom">0%</div>
        </div>
        <div id="uploadStatus" class="status-area mt-3">وضعیت آپلود: منتظر شروع...</div>
        <div id="videoStatusArea" class="status-area mt-2">وضعیت پردازش ویدیو: -</div>
        <div id="videoLinksArea" class="status-area mt-2" style="display:none;"></div>
        <div id="videoJsonToggleArea" class="mt-2" style="display:none;"></div>
        <div id="videoPlayerArea" class="mt-3"></div>
        <div id="logArea" class="log-area mt-3">لاگ‌ها:</div>
    </div>
    <script>
        const uploadForm = document.getElementById('uploadForm');
        const fileInput = document.getElementById('fileInput');
        const videoTitleInput = document.getElementById('videoTitle');
        const videoDescriptionInput = document.getElementById('videoDescription');
        const channelSelect = document.getElementById('channelSelect');
        const uploadButton = document.getElementById('uploadButton');
        const progressBar = document.getElementById('progressBar');
        const uploadStatus = document.getElementById('uploadStatus');
        const logArea = document.getElementById('logArea');
        const videoStatusArea = document.getElementById('videoStatusArea');
        const videoLinksArea = document.getElementById('videoLinksArea');
        const videoJsonToggleArea = document.getElementById('videoJsonToggleArea');
        const videoPlayerArea = document.getElementById('videoPlayerArea');

        // --- وضعیت‌های ویدیو و ترجمه فارسی ---
        const statusTranslations = {
            'complete': 'تکمیل شده',
            'getsize': 'در حال دریافت اندازه',
            'generating_thumbnail': 'در حال ساخت تصویر بندانگشتی',
            'converting': 'در حال تبدیل',
            'downloading': 'در حال دانلود',
            'queue_download': 'در صف دانلود',
            'queue_convert': 'در صف تبدیل',
            'failed': 'خطا در پردازش',
            'canceled': 'لغو شده'
        };

        function logMessage(message) {
            console.log(message);
            logArea.textContent = message + '\n' + logArea.textContent;
            logArea.scrollTop = 0;
        }

        // --- Load channels via AJAX and populate dropdown ---
        function loadChannels() {
            fetch('channels-proxy.php')
                .then(res => res.json())
                .then(data => {
                    channelSelect.innerHTML = '';
                    if (data.data && Array.isArray(data.data)) {
                        data.data.forEach(channel => {
                            const opt = document.createElement('option');
                            opt.value = channel.id;
                            opt.textContent = channel.title + (channel.status === 'active' ? '' : ' (غیرفعال)');
                            channelSelect.appendChild(opt);
                        });
                        if (data.data.length === 0) {
                            channelSelect.innerHTML = '<option value="">کانالی یافت نشد</option>';
                        }
                    } else {
                        channelSelect.innerHTML = '<option value="">خطا در دریافت کانال‌ها</option>';
                    }
                })
                .catch(err => {
                    channelSelect.innerHTML = '<option value="">خطا در بارگذاری کانال‌ها</option>';
                    logMessage('خطا در بارگذاری کانال‌ها: ' + err.message);
                });
        }
        loadChannels();

        uploadForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const file = fileInput.files[0];
            const title = videoTitleInput.value.trim();
            const description = videoDescriptionInput.value.trim();
            const channelId = channelSelect.value;

            if (!file) {
                alert('لطفاً یک فایل انتخاب کنید.');
                logMessage('خطا: فایل انتخاب نشده است.');
                return;
            }
            if (!channelId) {
                alert('لطفاً یک کانال انتخاب کنید.');
                logMessage('خطا: کانال انتخاب نشده است.');
                return;
            }
            if (!title) {
                alert('لطفاً عنوان ویدیو را وارد کنید.');
                logMessage('خطا: عنوان ویدیو وارد نشده است.');
                return;
            }

            uploadButton.disabled = true;
            progressBar.style.width = '0%';
            progressBar.textContent = '0%';
            uploadStatus.textContent = 'در حال ارسال فایل به سرور...';
            videoStatusArea.textContent = '-';
            videoLinksArea.style.display = 'none';
            videoJsonToggleArea.style.display = 'none';
            videoPlayerArea.innerHTML = '';
            logMessage(`شروع ارسال فایل: ${file.name} به سرور`);

            const formData = new FormData();
            formData.append('videoFile', file);
            formData.append('title', title);
            formData.append('description', description);
            formData.append('channelId', channelId);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'upload-proxy.php', true);

            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable) {
                    const percentage = ((event.loaded / event.total) * 100).toFixed(2);
                    progressBar.style.width = percentage + '%';
                    progressBar.textContent = percentage + '%';
                }
            };

            xhr.onload = function() {
                uploadButton.disabled = false;
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            progressBar.style.width = '100%';
                            progressBar.textContent = '100% (ارسال به سرور انجام شد)';
                            uploadStatus.textContent = 'آپلود با موفقیت به سرور انجام شد!';
                            logMessage('فایل با موفقیت به سرور ارسال شد.');
                            if (response.video_id) {
                                logMessage('ویدیو با موفقیت ثبت شد! Video ID: ' + response.video_id);
                                // شروع بررسی وضعیت ویدیو
                                showVideoStatus(response.video_id);
                            }
                        } else {
                            uploadStatus.textContent = 'خطا: ' + (response.message || 'خطای ناشناخته');
                            logMessage('خطا: ' + (response.message || 'خطای ناشناخته'));
                        }
                    } catch (e) {
                        uploadStatus.textContent = 'خطا در پردازش پاسخ سرور';
                        logMessage('خطا در پردازش پاسخ سرور: ' + e.message);
                    }
                } else {
                    uploadStatus.textContent = 'خطا در ارتباط با سرور';
                    logMessage('خطا در ارتباط با سرور: ' + xhr.statusText);
                }
            };

            xhr.onerror = function() {
                uploadButton.disabled = false;
                uploadStatus.textContent = 'خطای شبکه در ارسال فایل.';
                logMessage('خطای شبکه در ارسال فایل.');
            };

            xhr.send(formData);
        });

        function showVideoStatus(videoId) {
            const statusArea = document.getElementById('videoStatusArea');
            const linksArea = document.getElementById('videoLinksArea');
            const playerArea = document.getElementById('videoPlayerArea');
            const jsonToggleArea = document.getElementById('videoJsonToggleArea');
            linksArea.style.display = 'none';
            playerArea.innerHTML = '';
            jsonToggleArea.style.display = 'none';
            let stopped = false;
            let lastStatus = null;
            let repeatCount = 0;
            let logStack = [];
            const poll = () => {
                fetch('video-status-proxy.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ video_id: videoId })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.data && data.data.status) {
                        const statusFa = statusTranslations[data.data.status] || data.data.status;
                        statusArea.textContent = `وضعیت پردازش ویدیو: ${data.data.status} (${statusFa})`;
                        let logMsg = `وضعیت ویدیو: ${data.data.status} (${statusFa})`;
                        if (lastStatus === data.data.status) {
                            repeatCount++;
                            if (logStack.length > 0) {
                                logStack[0] = logMsg + (repeatCount > 1 ? ` (${repeatCount})` : '');
                            } else {
                                logStack.unshift(logMsg);
                            }
                        } else {
                            repeatCount = 1;
                            logStack.unshift(logMsg);
                        }
                        lastStatus = data.data.status;
                        logArea.textContent = logStack.join('\n');
                        if (data.data.status === 'complete') {
                            stopped = true;
                            statusArea.textContent += ' (ویدیو آماده است)';
                            showVideoLinksAndPlayer(data.data);
                            return;
                        }
                        if ([ 'failed', 'canceled' ].includes(data.data.status)) {
                            stopped = true;
                            statusArea.textContent += data.data.status === 'failed' ? ' (خطا در پردازش ویدیو)' : ' (پردازش لغو شد)';
                            return;
                        }
                    } else {
                        statusArea.textContent = 'خطا در دریافت وضعیت ویدیو';
                        logStack.unshift('خطا در دریافت وضعیت ویدیو');
                        logArea.textContent = logStack.join('\n');
                    }
                    if (!stopped) {
                        setTimeout(poll, 2000);
                    }
                })
                .catch(err => {
                    statusArea.textContent = 'خطا در دریافت وضعیت ویدیو: ' + err.message;
                    logStack.unshift('خطا در دریافت وضعیت ویدیو: ' + err.message);
                    logArea.textContent = logStack.join('\n');
                    if (!stopped) {
                        setTimeout(poll, 2000);
                    }
                });
            };
            poll();
        }

        function showVideoLinksAndPlayer(videoData) {
            const linksArea = document.getElementById('videoLinksArea');
            const playerArea = document.getElementById('videoPlayerArea');
            const jsonToggleArea = document.getElementById('videoJsonToggleArea');
            let html = '<b>لینک‌های ویدیو:</b><ul style="direction:ltr;text-align:left;">';
            if (videoData.video_url) {
                html += `<li>ویدیو اصلی: <a href="${videoData.video_url}" target="_blank">${videoData.video_url}</a></li>`;
            }
            if (Array.isArray(videoData.mp4_videos)) {
                videoData.mp4_videos.forEach((url, i) => {
                    html += `<li>MP4 کیفیت ${videoData.converted_info && videoData.converted_info[i] ? videoData.converted_info[i].resolution : ''}: <a href="${url}" target="_blank">${url}</a></li>`;
                });
            }
            if (videoData.hls_playlist) {
                html += `<li>HLS: <a href="${videoData.hls_playlist}" target="_blank">${videoData.hls_playlist}</a></li>`;
            }
            if (videoData.dash_playlist) {
                html += `<li>DASH: <a href="${videoData.dash_playlist}" target="_blank">${videoData.dash_playlist}</a></li>`;
            }
            if (videoData.player_url) {
                html += `<li>پلیر آروان: <a href="${videoData.player_url}" target="_blank">${videoData.player_url}</a></li>`;
            }
            html += '</ul>';
            if (videoData.thumbnail_url) {
                html += `<img src="${videoData.thumbnail_url}" alt="thumbnail" style="max-width:200px;display:block;margin-bottom:10px;">`;
            }
            // دکمه نمایش/مخفی کردن JSON
            html += `<button id="toggleJsonBtn" class="btn btn-outline-secondary btn-sm mt-2">نمایش اطلاعات کامل JSON</button>`;
            linksArea.innerHTML = html;
            linksArea.style.display = '';
            // باکس JSON (ابتدا مخفی)
            jsonToggleArea.innerHTML = `<pre id="videoJsonBox" style="display:none; background:#f8f9fa; border:1px solid #ced4da; border-radius:6px; padding:12px; margin-top:8px; max-height:400px; overflow:auto; text-align:left; direction:ltr;"></pre>`;
            jsonToggleArea.style.display = '';
            const jsonBox = document.getElementById('videoJsonBox');
            const toggleBtn = document.getElementById('toggleJsonBtn');
            let jsonVisible = false;
            toggleBtn.onclick = function() {
                jsonVisible = !jsonVisible;
                if (jsonVisible) {
                    jsonBox.style.display = '';
                    jsonBox.textContent = JSON.stringify(videoData, null, 2);
                    toggleBtn.textContent = 'مخفی کردن اطلاعات JSON';
                } else {
                    jsonBox.style.display = 'none';
                    toggleBtn.textContent = 'نمایش اطلاعات کامل JSON';
                }
            };
            // نمایش پلیر آروان
            if (videoData.player_url) {
                playerArea.innerHTML = `<iframe src="${videoData.player_url}" width="100%" style="aspect-ratio: 16 / 9;" allowfullscreen style="border:none;"></iframe>`;
            } else if (videoData.hls_playlist) {
                // پلیر ساده HTML5 برای HLS (در مرورگرهای مدرن)
                playerArea.innerHTML = `<video controls width="100%" height="400" src="${videoData.hls_playlist}"></video>`;
            } else if (videoData.video_url) {
                playerArea.innerHTML = `<video controls width="100%" height="400" src="${videoData.video_url}"></video>`;
            }
        }
    </script>
</body>
</html> 