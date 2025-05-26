<?php
// v3/incomplete-uploads.php
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>آپلودهای ناتمام</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .upload-item { margin-bottom: 1rem; }
        .file-input-sm { padding: .25rem .5rem; font-size: .875rem; border-radius: .2rem; }
        .progress { height: 20px; margin-top: 0.5rem;}
        .progress-bar { font-size: 0.8rem; line-height: 20px; }
         #alertPlaceholderResumePage {
            position: fixed; top: 1rem; left: 1rem; right: auto;
            z-index: 1060; min-width: 300px; max-width: 90%;
        }
        [dir="rtl"] #alertPlaceholderResumePage { left: auto; right: 1rem; }
        .action-buttons-group button, .action-buttons-group input { margin-bottom: 0.5rem; margin-left: 0.5rem;}
    </style>
</head>
<body class="bg-light">
    <div id="alertPlaceholderResumePage"></div>
    <div class="container py-5">
        <div class="card shadow">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="mb-0">پیگیری و ادامه آپلودهای ناتمام</h2>
                <button class="btn btn-sm btn-outline-primary" id="refreshIncompleteListBtn" title="بارگذاری مجدد لیست">
                    <i class="bi bi-arrow-clockwise"></i> تازه‌سازی لیست
                </button>
            </div>
            <div class="card-body">
                <div id="loadingMessage" class="text-center p-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">در حال بارگذاری لیست آپلودهای ناتمام...</span>
                    </div>
                    <p class="mt-2">در حال بارگذاری...</p>
                </div>
                <div id="incompleteUploadsList">
                    {/* لیست آپلودهای ناتمام اینجا نمایش داده می‌شود */}
                </div>
            </div>
            <div class="card-footer text-center">
                <a href="index.php" class="btn btn-link">بازگشت به صفحه اصلی</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const incompleteUploadsListDiv = document.getElementById('incompleteUploadsList');
    const loadingMessageDiv = document.getElementById('loadingMessage');
    const refreshIncompleteListBtn = document.getElementById('refreshIncompleteListBtn');
    const alertPlaceholderResumePage = document.getElementById('alertPlaceholderResumePage');
    let currentResumingClientId = null;
    let resumePollingIntervalId = null;

    function showAlert(message, type = 'success', placeholder = alertPlaceholderResumePage) {
        // ... (کد تابع showAlert بدون تغییر باقی می‌ماند)
        const wrapper = document.createElement('div');
        wrapper.innerHTML = [
            `<div class="alert alert-${type} alert-dismissible fade show" role="alert" style="margin-bottom: 0.5rem;">`,
            `   <div>${message}</div>`,
            '   <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>',
            '</div>'
        ].join('');
        while (placeholder.firstChild) { placeholder.removeChild(placeholder.firstChild); }
        placeholder.append(wrapper);
         setTimeout(() => {
             const alertInstance = bootstrap.Alert.getOrCreateInstance(wrapper.firstChild);
             if (alertInstance) alertInstance.close();
        }, 7000);
    }

    function formatBytes(bytes, decimals = 2) { /* ... (کد تابع formatBytes بدون تغییر) ... */ 
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }


    async function fetchIncompleteUploads() {
        loadingMessageDiv.style.display = 'block';
        incompleteUploadsListDiv.innerHTML = '';
        try {
            const response = await fetch('get-incomplete-uploads-proxy.php');
            const result = await response.json();

            if (!result.success || !result.data) {
                throw new Error(result.message || 'خطا در دریافت لیست آپلودهای ناتمام.');
            }

            if (result.data.length === 0) {
                incompleteUploadsListDiv.innerHTML = '<p class="text-center text-muted">هیچ آپلود ناتمامی یافت نشد.</p>';
            } else {
                result.data.forEach(upload => {
                    const progressPercent = (upload.total_filesize > 0 && upload.current_offset_on_arvan > 0)
                        ? ((upload.current_offset_on_arvan / upload.total_filesize) * 100).toFixed(2)
                        : 0;

                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'card upload-item shadow-sm';
                    itemDiv.id = `upload-item-${upload.client_upload_id}`;
                    itemDiv.innerHTML = `
                        <div class="card-body">
                            <h5 class="card-title">${upload.video_title || 'بدون عنوان'} <small class="text-muted">(${upload.original_filename})</small></h5>
                            <p class="card-text mb-1">
                                <small>شناسه آپلود: ${upload.client_upload_id}</small><br>
                                <small>شناسه فایل آروان (TUS ID): ${upload.arvan_file_id || 'ایجاد نشده'}</small><br>
                                <small>اندازه فایل: ${formatBytes(upload.total_filesize)}</small><br>
                                <small>آخرین وضعیت: ${upload.status} (پیشرفت تخمینی: ${progressPercent}%)</small><br>
                                ${upload.last_error_message ? `<small class="text-danger">آخرین خطا: ${upload.last_error_message}</small><br>` : ''}
                                <small>آخرین به‌روزرسانی: ${new Date(upload.updated_at).toLocaleString('fa-IR')}</small>
                            </p>
                            <div class="mt-2 resume-form-container">
                                <label for="file-${upload.client_upload_id}" class="form-label">برای ادامه، فایل اصلی را مجدداً انتخاب کنید:</label>
                                <div class="action-buttons-group d-flex flex-wrap align-items-center">
                                    <input type="file" class="form-control form-control-sm file-input-for-resume flex-grow-1" style="max-width: 300px;" id="file-${upload.client_upload_id}" accept="video/*">
                                    <button class="btn btn-primary btn-sm resume-upload-btn" 
                                            data-client-id="${upload.client_upload_id}"
                                            data-original-filename="${upload.original_filename}"
                                            data-total-filesize="${upload.total_filesize}"
                                            data-channel-id="${upload.target_channel_id}"
                                            data-video-title="${upload.video_title || ''}"
                                            data-video-description="${upload.video_description || ''}"
                                            disabled>
                                        <i class="bi bi-play-fill"></i> ادامه آپلود
                                    </button>
                                    ${upload.arvan_file_id ? // فقط اگر شناسه فایل آروان وجود دارد، دکمه حذف را نشان بده
                                    `<button class="btn btn-danger btn-sm delete-tus-file-btn"
                                            data-client-id="${upload.client_upload_id}"
                                            data-arvan-file-id="${upload.arvan_file_id}"
                                            data-original-filename="${upload.original_filename}"
                                            title="حذف این آپلود ناتمام از سرور آروان و لیست">
                                        <i class="bi bi-x-octagon"></i> حذف نهایی
                                    </button>` : ''}
                                </div>
                                <div class="progress mt-2" style="display:none;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: ${progressPercent}%;" aria-valuenow="${progressPercent}" aria-valuemin="0" aria-valuemax="100">${progressPercent}%</div>
                                </div>
                                <div class="status-message small mt-1"></div>
                            </div>
                        </div>
                    `;
                    incompleteUploadsListDiv.appendChild(itemDiv);
                });
            }
        } catch (error) {
            console.error('Error loading incomplete uploads:', error);
            incompleteUploadsListDiv.innerHTML = `<div class="alert alert-danger">خطا در بارگذاری لیست: ${error.message}</div>`;
        } finally {
            loadingMessageDiv.style.display = 'none';
        }
    }

    // ... (کد تابع incompleteUploadsListDiv.addEventListener('change', ...) بدون تغییر باقی می‌ماند) ...
    incompleteUploadsListDiv.addEventListener('change', function(event) {
        if (event.target.classList.contains('file-input-for-resume')) {
            const fileInput = event.target;
            const resumeBtn = fileInput.closest('.resume-form-container').querySelector('.resume-upload-btn');
            if (fileInput.files.length > 0) {
                const selectedFile = fileInput.files[0];
                const originalFilename = resumeBtn.dataset.originalFilename;
                const totalFilesize = parseInt(resumeBtn.dataset.totalFilesize, 10);

                if (selectedFile.name === originalFilename && selectedFile.size === totalFilesize) {
                    resumeBtn.disabled = false;
                    fileInput.classList.remove('is-invalid');
                    fileInput.classList.add('is-valid');
                } else {
                    resumeBtn.disabled = true;
                    fileInput.classList.remove('is-valid');
                    fileInput.classList.add('is-invalid');
                    showAlert('فایل انتخاب شده با فایل اصلی آپلود ناتمام مطابقت ندارد (نام یا اندازه متفاوت است).', 'warning', 
                              fileInput.closest('.upload-item').querySelector('.status-message'));
                }
            } else {
                resumeBtn.disabled = true;
                 fileInput.classList.remove('is-valid', 'is-invalid');
            }
        }
    });


    incompleteUploadsListDiv.addEventListener('click', async function(event) {
        const targetButton = event.target.closest('button');
        if (!targetButton) return;

        if (targetButton.classList.contains('resume-upload-btn')) {
            // ... (کد مربوط به ادامه آپلود بدون تغییر باقی می‌ماند) ...
            const btn = targetButton;
            const clientUploadId = btn.dataset.clientId;
            const channelId = btn.dataset.channelId;
            const videoTitle = btn.dataset.videoTitle;
            const videoDescription = btn.dataset.videoDescription;
            
            const itemDiv = document.getElementById(`upload-item-${clientUploadId}`);
            const fileInput = itemDiv.querySelector('.file-input-for-resume');
            const progressBarDiv = itemDiv.querySelector('.progress');
            const progressBar = progressBarDiv.querySelector('.progress-bar');
            const statusMessageDiv = itemDiv.querySelector('.status-message');

            if (!fileInput.files || fileInput.files.length === 0) {
                showAlert('لطفاً ابتدا فایل را انتخاب کنید.', 'warning', statusMessageDiv);
                return;
            }
            const fileToUpload = fileInput.files[0];

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> در حال آماده سازی...';
            progressBarDiv.style.display = 'block';
            statusMessageDiv.textContent = 'در حال ارسال اطلاعات برای ادامه آپلود...';

            currentResumingClientId = clientUploadId; 

            const formData = new FormData();
            formData.append('uploadId', clientUploadId); 
            formData.append('videoFile', fileToUpload);
            formData.append('channelId', channelId);
            formData.append('title', videoTitle);
            formData.append('description', videoDescription);

            try {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'arvan-direct-upload-proxy.php', true); //
                
                xhr.onload = function() {
                    btn.disabled = false; 
                    btn.innerHTML = '<i class="bi bi-play-fill"></i> ادامه آپلود';
                    if (resumePollingIntervalId) { clearTimeout(resumePollingIntervalId); resumePollingIntervalId = null; }

                    let responseJson;
                    try {
                        responseJson = JSON.parse(xhr.responseText);
                        if (xhr.status >= 200 && xhr.status < 300 && responseJson.success) {
                            statusMessageDiv.textContent = `موفق: ${responseJson.message}`;
                            progressBar.style.width = '100%';
                            progressBar.textContent = '100%';
                            progressBar.classList.remove('progress-bar-animated', 'bg-primary');
                            progressBar.classList.add('bg-success');
                            showAlert(`آپلود برای "${videoTitle}" با موفقیت ادامه یافت و تکمیل شد.`, 'success');
                            btn.closest('.upload-item').remove(); 
                            if(incompleteUploadsListDiv.children.length === 0) {
                                incompleteUploadsListDiv.innerHTML = '<p class="text-center text-muted">هیچ آپلود ناتمامی یافت نشد.</p>';
                            }
                        } else {
                            statusMessageDiv.textContent = `خطا: ${responseJson.message || xhr.statusText}`;
                            progressBar.classList.add('bg-danger');
                            showAlert(`ادامه آپلود برای "${videoTitle}" با خطا مواجه شد: ${responseJson.message || 'خطای سرور'}`, 'danger');
                        }
                    } catch (e) {
                        statusMessageDiv.textContent = 'خطا در پردازش پاسخ سرور.';
                        progressBar.classList.add('bg-danger');
                        showAlert(`ادامه آپلود برای "${videoTitle}" با خطای پاسخ سرور مواجه شد.`, 'danger');
                        console.error("Error parsing JSON response: ", xhr.responseText, e);
                    }
                };
                xhr.onerror = function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-play-fill"></i> ادامه آپلود';
                    if (resumePollingIntervalId) { clearTimeout(resumePollingIntervalId); resumePollingIntervalId = null; }
                    statusMessageDiv.textContent = 'خطای شبکه در هنگام ارسال.';
                    progressBar.classList.add('bg-danger');
                    showAlert(`خطای شبکه در ادامه آپلود برای "${videoTitle}".`, 'danger');
                };

                xhr.send(formData);
                setTimeout(() => pollResumeProgress(clientUploadId, progressBar, statusMessageDiv), 1000);

            } catch (error) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-play-fill"></i> ادامه آپلود';
                statusMessageDiv.textContent = `خطای کلاینت: ${error.message}`;
                progressBar.classList.add('bg-danger');
                showAlert(`خطای پیش‌بینی نشده در شروع ادامه آپلود: ${error.message}`, 'danger');
                console.error("Error resuming upload: ", error);
            }

        } else if (targetButton.classList.contains('delete-tus-file-btn')) {
            const clientUploadId = targetButton.dataset.clientId;
            const arvanFileId = targetButton.dataset.arvanFileId;
            const originalFilename = targetButton.dataset.originalFilename;

            if (!arvanFileId || arvanFileId === 'null' || arvanFileId === 'undefined') {
                showAlert('این آپلود هنوز در سرور آروان ایجاد نشده و فقط رکورد محلی آن قابل حذف است (در صورت نیاز). برای حذف، ابتدا باید یکبار تلاش برای ادامه آپلود انجام شود تا شناسه فایل آروان مشخص گردد یا از طریق دیتابیس مستقیما حذف شود.', 'info');
                // یا می‌توانید یک گزینه برای حذف فقط رکورد دیتابیس اضافه کنید اگر arvan_file_id وجود ندارد
                if(confirm(`آپلود "${originalFilename}" هنوز به سرور آروان ارسال نشده یا شناسه فایل آروان آن ثبت نشده است. آیا مایل به حذف رکورد این تلاش از لیست محلی هستید؟ (این عمل فایل را از آروان حذف نمی‌کند)`)){
                     deleteLocalUploadAttempt(clientUploadId, originalFilename);
                }
                return;
            }

            if (confirm(`آیا از حذف نهایی آپلود ناتمام "${originalFilename}" (با شناسه فایل آروان: ${arvanFileId}) از سرور آروان و لیست محلی مطمئن هستید؟ این عملیات فایل را از سرور آروان نیز حذف می‌کند.`)) {
                deleteTusFile(clientUploadId, arvanFileId, originalFilename, targetButton);
            }
        }
    });

    async function deleteTusFile(clientUploadId, arvanFileId, originalFilename, deleteButton) {
        deleteButton.disabled = true;
        deleteButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        const itemDiv = document.getElementById(`upload-item-${clientUploadId}`);
        const statusMessageDiv = itemDiv.querySelector('.status-message');
        statusMessageDiv.textContent = `در حال ارسال درخواست حذف برای ${originalFilename}...`;

        try {
            const response = await fetch(`manage_tus_file_proxy.php?arvan_file_id=${arvanFileId}&client_upload_id=${clientUploadId}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'خطا در حذف فایل از سرور آروان یا دیتابیس محلی.');
            }
            showAlert(`آپلود ناتمام "${originalFilename}" با موفقیت از آروان و لیست محلی حذف شد.`, 'success');
            deleteButton.closest('.upload-item').remove();
             if(incompleteUploadsListDiv.children.length === 0) {
                incompleteUploadsListDiv.innerHTML = '<p class="text-center text-muted">هیچ آپلود ناتمامی یافت نشد.</p>';
            }

        } catch (error) {
            showAlert(`خطا در حذف آپلود ناتمام "${originalFilename}": ${error.message}`, 'danger');
            statusMessageDiv.textContent = `خطا: ${error.message}`;
            console.error('Error deleting TUS file:', error);
            deleteButton.disabled = false;
            deleteButton.innerHTML = '<i class="bi bi-x-octagon"></i> حذف نهایی';
        }
    }
    
    async function deleteLocalUploadAttempt(clientUploadId, originalFilename) {
        // این تابع در صورتی فراخوانی می‌شود که arvan_file_id وجود نداشته باشد
        // و فقط می‌خواهیم رکورد را از دیتابیس محلی (جدول upload_attempts) حذف کنیم.
        // شما باید یک endpoint در manage_tus_file_proxy.php یا یک پروکسی جدید برای این کار ایجاد کنید
        // که فقط رکورد دیتابیس را حذف کند و فایل persistent_temp_filepath را اگر وجود دارد.
        // برای سادگی فعلا فقط پیام می‌دهیم و لیست را رفرش می‌کنیم.
        console.log("درخواست حذف رکورد محلی برای client_upload_id:", clientUploadId);
        showAlert(`برای حذف رکورد محلی "${originalFilename}" (بدون حذف از آروان)، نیاز به پیاده‌سازی سمت سرور است. در حال حاضر لیست رفرش می‌شود.`, 'info');
        // به عنوان مثال، اگر یک endpoint برای این کار داشتید:
        /*
        try {
            const response = await fetch(`manage_tus_file_proxy.php?action=delete_local_attempt&client_upload_id=${clientUploadId}`, {
                method: 'DELETE', headers: { 'Accept': 'application/json' }
            });
            // ... handle response ...
        } catch (error) { ... }
        */
        fetchIncompleteUploads(); // Refresh the list
    }


    // ... (کد تابع pollResumeProgress بدون تغییر باقی می‌ماند) ...
    function pollResumeProgress(clientUploadId, progressBarElem, statusMsgElem) {
        if (currentResumingClientId !== clientUploadId) { 
            clearTimeout(resumePollingIntervalId);
            resumePollingIntervalId = null;
            return;
        }

        fetch(`arvan-progress.php?uploadId=${clientUploadId}`) //
            .then(response => response.json())
            .then(data => {
                if (currentResumingClientId !== clientUploadId) return;

                progressBarElem.style.width = (data.progress || 0) + '%';
                progressBarElem.textContent = (data.progress || 0) + '%';
                statusMsgElem.textContent = data.message || data.status || 'در حال بررسی پیشرفت...';

                if (data.status === 'completed') {
                    progressBarElem.classList.remove('progress-bar-animated', 'bg-primary');
                    progressBarElem.classList.add('bg-success');
                    clearTimeout(resumePollingIntervalId);
                    resumePollingIntervalId = null;
                } else if (data.status === 'error') {
                    progressBarElem.classList.remove('progress-bar-animated', 'bg-primary');
                    progressBarElem.classList.add('bg-danger');
                    clearTimeout(resumePollingIntervalId);
                    resumePollingIntervalId = null;
                } else {
                    progressBarElem.classList.remove('bg-success', 'bg-danger');
                    progressBarElem.classList.add('bg-primary', 'progress-bar-animated');
                    resumePollingIntervalId = setTimeout(() => pollResumeProgress(clientUploadId, progressBarElem, statusMsgElem), 1500);
                }
            })
            .catch(error => {
                console.error('Error polling progress:', error);
                statusMsgElem.textContent = 'خطا در دریافت وضعیت پیشرفت.';
                if (currentResumingClientId === clientUploadId) {
                   resumePollingIntervalId = setTimeout(() => pollResumeProgress(clientUploadId, progressBarElem, statusMsgElem), 5000);
                }
            });
    }

    refreshIncompleteListBtn.addEventListener('click', fetchIncompleteUploads);

    // Initial load
    fetchIncompleteUploads();
    </script>
</body>
</html>