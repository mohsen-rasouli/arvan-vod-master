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
    <script src="https://cdn.jsdelivr.net/npm/tus-js-client@4.3.1/dist/tus.js"></script>
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
        const arvanApiBaseUrl = 'https://napi.arvancloud.ir/vod/2.0';
        const apiKey = 'Apikey 238d40ba-4348-467e-96e3-c0b342266a0b';

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
            fetch(`${arvanApiBaseUrl}/channels`, {
                headers: {
                    'Authorization': apiKey,
                    'Accept': 'application/json'
                }
            })
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
            uploadStatus.textContent = 'در حال آماده‌سازی آپلود...';
            logMessage(`شروع آپلود فایل: ${file.name} به کانال ID: ${channelId} با عنوان: ${title}`);

            // مرحله ۱: ایجاد فایل در آروان (initiate TUS)
            const endpoint = `${arvanApiBaseUrl}/channels/${channelId}/files`;

            // متادیتا را طبق tus-js-client آماده کن
            const encode = str => btoa(unescape(encodeURIComponent(str)));
            const uploadMetadata = {
                filename: file.name,
                filetype: file.type
            };

            // مرحله ۱: ایجاد فایل و گرفتن upload URL
            fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Tus-Resumable': '1.0.0',
                    'Upload-Length': file.size,
                    'Upload-Metadata': `filename ${encode(file.name)},filetype ${encode(file.type)}`,
                    'Authorization': apiKey,
                    'Accept': 'application/json'
                },
                body: ''
            })
            .then(async response => {
                if (!response.ok) {
                    throw new Error('خطا در ایجاد فایل TUS: ' + response.status);
                }
                const location = response.headers.get('Location');
                if (!location) {
                    throw new Error('Location header یافت نشد.');
                }
                logMessage('TUS Location: ' + location);
                // مرحله ۲: آپلود فایل با tus-js-client
                startTusUpload(file, location, title, description, channelId);
            })
            .catch(error => {
                uploadButton.disabled = false;
                uploadStatus.textContent = 'خطا در ایجاد فایل TUS: ' + error.message;
                logMessage('خطا: ' + error.message);
            });
        });

        function startTusUpload(file, uploadUrl, title, description, channelId) {
            uploadStatus.textContent = 'در حال آپلود فایل به آروان...';
            logMessage('شروع آپلود با tus-js-client به: ' + uploadUrl);
            const upload = new tus.Upload(file, {
                endpoint: uploadUrl,
                uploadUrl: uploadUrl,
                retryDelays: [0, 1000, 3000, 5000],
                metadata: {
                    filename: file.name,
                    filetype: file.type
                },
                headers: {
                    'Authorization': 'Apikey 238d40ba-4348-467e-96e3-c0b342266a0b' // اگر نیاز به API Key سمت کلاینت است اینجا قرار دهید
                },
                onError: function(error) {
                    uploadButton.disabled = false;
                    uploadStatus.textContent = 'خطا در آپلود: ' + error;
                    logMessage('خطا در آپلود: ' + error);
                },
                onProgress: function(bytesUploaded, bytesTotal) {
                    const percentage = ((bytesUploaded / bytesTotal) * 100).toFixed(2);
                    progressBar.style.width = percentage + '%';
                    progressBar.textContent = percentage + '%';
                },
                onSuccess: function() {
                    uploadButton.disabled = false;
                    progressBar.style.width = '100%';
                    progressBar.textContent = '100% (آپلود کامل شد)';
                    uploadStatus.textContent = 'آپلود با موفقیت انجام شد!';
                    logMessage('آپلود کامل شد.');

                    // مرحله ۳: ثبت ویدیو
                    const fileId = upload.url.split('/').pop();
                    const registerUrl = `${arvanApiBaseUrl}/channels/${channelId}/videos`;

                    fetch(registerUrl, {
                        method: 'POST',
                        headers: {
                            'Authorization': apiKey,
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            title: title,
                            description: description,
                            file_id: fileId,
                            convert_mode: 'auto'
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.data && data.data.id) {
                            uploadStatus.textContent = 'ویدیو با موفقیت ثبت شد! Video ID: ' + data.data.id;
                            logMessage('ویدیو با موفقیت ثبت شد! Video ID: ' + data.data.id);
                            showVideoStatus(data.data.id);
                        } else {
                            uploadStatus.textContent = 'خطا در ثبت ویدیو: ' + (data.message || 'نامشخص');
                            logMessage('خطا در ثبت ویدیو: ' + (data.message || JSON.stringify(data)));
                        }
                    })
                    .catch(err => {
                        uploadStatus.textContent = 'خطا در ثبت ویدیو: ' + err.message;
                        logMessage('خطا در ثبت ویدیو: ' + err.message);
                    });
                }
            });
            upload.start();
        }

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
                fetch(`${arvanApiBaseUrl}/videos/${videoId}`, {
                    headers: {
                        'Authorization': apiKey,
                        'Accept': 'application/json'
                    }
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