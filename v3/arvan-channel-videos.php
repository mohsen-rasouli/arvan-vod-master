<?php
// v3/arvan-channel-videos.php
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده و مدیریت ویدیوهای کانال‌های آروان</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .video-card {
            transition: transform .2s;
            height: 100%; 
        }
        .video-card:hover {
            transform: scale(1.03);
        }
        .video-card .card-img-top {
            width: 100%;
            height: 200px; 
            object-fit: cover; 
            background-color: #f0f0f0; /* Placeholder background */
        }
        .video-card .card-body {
            display: flex;
            flex-direction: column;
            justify-content: space-between; /* Pushes actions to bottom */
        }
        .video-card-actions .btn,
        .video-card-actions .dropdown { /* Ensure dropdown also has margin if needed */
            margin-top: 5px;
            margin-left: 5px; /* Spacing between buttons in the same line */
        }
        .video-card-actions .dropdown .btn {
             margin-left: 0; /* Reset margin for button inside dropdown div */
        }
        .status-badge {
            font-size: 0.8em;
        }
        .description-truncate {
            display: -webkit-box;
            -webkit-line-clamp: 2; /* Limit to 2 lines */
            -webkit-box-orient: vertical;  
            overflow: hidden;
            text-overflow: ellipsis;
            min-height: 2.4em; /* approx 2 lines height */
        }
        #loadingSpinner, #videosLoadingSpinner { display: none; }
        #alertPlaceholderVideoPage {
            position: fixed;
            top: 1rem;
            left: 1rem; /* For RTL, right: 1rem might be better */
            right: auto; /* Explicitly set for RTL */
            z-index: 1060; /* Above modals (Bootstrap modal z-index is 1055 for modal, 1056 for backdrop) */
            min-width: 300px;
            max-width: 90%;
        }
        [dir="rtl"] #alertPlaceholderVideoPage {
            left: auto;
            right: 1rem;
        }

        /* Styles for the Video Player Modal */
        .video-player-modal-custom .modal-content {
            background-color: #000; 
            border: none; 
            height: 100%; 
        }
        /* .video-player-modal-custom .modal-xl {
            max-width: 90vw; 
        } */
        .video-player-modal-custom .modal-header {
            border-bottom: 1px solid #333; 
            color: #fff; 
            background-color: #1a1a1a; 
        }
        .video-player-modal-custom .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%); 
        }
        .video-player-modal-custom .modal-body {
            display: flex; 
            align-items: center;
            justify-content: center;
            flex-grow: 1; 
        }
        #videoPlayerIframeContainer {
            width: 100%; 
        }
        #videoPlayerModal iframe {
            border: none;
            display: block; 
        }
        .modal-backdrop.fade.show {
           opacity: .75; 
        }
        .modal-fullscreen-lg-down.video-player-modal-custom .modal-dialog {
            margin: 0; 
            max-width: 100%;
            width: 100%;
            height: 100%;
        }
        .modal-fullscreen-lg-down.video-player-modal-custom .modal-content {
            border-radius: 0; 
        }
    </style>
</head>
<body class="bg-light">
    <div id="alertPlaceholderVideoPage"></div>

    <div class="container py-5">
        <div class="card shadow">
            <div class="card-header">
                <h2 class="mb-0 text-center">ویدیوهای کانال‌های آروان</h2>
            </div>
            <div class="card-body">
                <div class="row justify-content-center mb-4">
                    <div class="col-md-6">
                        <label for="arvanChannelSelect" class="form-label">یک کانال را انتخاب کنید:</label>
                        <div class="input-group">
                            <select id="arvanChannelSelect" class="form-select">
                                <option value="">در حال بارگذاری کانال‌ها...</option>
                            </select>
                            <button class="btn btn-outline-secondary" type="button" id="refreshChannelsBtn" title="بارگذاری مجدد کانال‌ها">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="loadingSpinner" class="text-center my-3">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">در حال بارگذاری...</span>
                    </div>
                </div>

                <div id="videoDisplayArea" class="mt-4">
                    <p class="text-center text-muted">برای نمایش ویدیوها، ابتدا یک کانال را از لیست بالا انتخاب نمایید.</p>
                </div>
                
                <div id="videosLoadingSpinner" class="text-center my-3">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">در حال بارگذاری ویدیوها...</span>
                    </div>
                </div>
                <div id="paginationArea" class="d-flex justify-content-center mt-4"></div>

            </div>
            <div class="card-footer text-center">
                 <a href="index.php" class="btn btn-link">بازگشت به صفحه اصلی</a>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editVideoModal" tabindex="-1" aria-labelledby="editVideoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="editVideoForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editVideoModalLabel">ویرایش اطلاعات ویدیو</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="editVideoId" name="videoId">
                        <div class="mb-3">
                            <label for="editVideoTitle" class="form-label">عنوان ویدیو <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editVideoTitle" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="editVideoDescription" class="form-label">توضیحات ویدیو</label>
                            <textarea class="form-control" id="editVideoDescription" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                        <button type="submit" class="btn btn-primary" id="saveVideoBtn">ذخیره تغییرات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade video-player-modal-custom" id="videoPlayerModal" tabindex="-1" aria-labelledby="videoPlayerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-lg-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="videoPlayerModalLabel">پخش ویدیو</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="ratio ratio-16x9" id="videoPlayerIframeContainer">
                        <!-- Iframe will be dynamically inserted here by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const channelSelect = document.getElementById('arvanChannelSelect');
    const videoDisplayArea = document.getElementById('videoDisplayArea');
    const loadingSpinner = document.getElementById('loadingSpinner'); 
    const videosLoadingSpinner = document.getElementById('videosLoadingSpinner'); 
    const paginationArea = document.getElementById('paginationArea');
    const refreshChannelsBtn = document.getElementById('refreshChannelsBtn');
    const alertPlaceholderVideoPage = document.getElementById('alertPlaceholderVideoPage');

    const editVideoModalElement = document.getElementById('editVideoModal');
    const editVideoModalInstance = new bootstrap.Modal(editVideoModalElement); // Renamed
    const editVideoForm = document.getElementById('editVideoForm');
    const editVideoIdInput = document.getElementById('editVideoId');
    const editVideoTitleInput = document.getElementById('editVideoTitle');
    const editVideoDescriptionInput = document.getElementById('editVideoDescription');
    const saveVideoBtn = document.getElementById('saveVideoBtn');

    const videoPlayerModalElement = document.getElementById('videoPlayerModal');
    const videoPlayerModalInstance = new bootstrap.Modal(videoPlayerModalElement); // Renamed
    const videoPlayerIframeContainer = document.getElementById('videoPlayerIframeContainer');
    const videoPlayerModalLabel = document.getElementById('videoPlayerModalLabel');
    let activeVideoPlayerIframe = null; 

    let currentChannelId = null;
    let currentPage = 1;
    let allVideosData = []; 

    const statusTranslations = {
        'uploading': 'در حال آپلود', 'pending': 'در انتظار', 'processing': 'در حال پردازش',
        'converting': 'در حال تبدیل', 'watermarking': 'در حال اعمال واترمارک',
        'generating_thumbnail': 'در حال ساخت پیش‌نمایش', 'complete': 'تکمیل شده',
        'failed': 'ناموفق', 'source_failed': 'خطا در فایل منبع', 'blocked': 'مسدود شده',
        'canceled': 'لغو شده', 'secure_upload_create': 'در حال ایجاد آپلود امن',
        'secure_upload_pending': 'در انتظار آپلود امن', 'secure_upload_failed': 'خطا در آپلود امن',
        'getsize': 'در حال دریافت اندازه', 'downloading': 'در حال دانلود از مبدا',
        'queue_download': 'در صف دانلود از مبدا', 'queue_convert': 'در صف تبدیل',
    };

    function getStatusClass(status) {
        switch (status) {
            case 'complete': return 'bg-success';
            case 'failed': case 'source_failed': case 'blocked': case 'canceled': case 'secure_upload_failed': return 'bg-danger';
            case 'processing': case 'converting': case 'watermarking': case 'generating_thumbnail':
            case 'downloading': case 'getsize': case 'secure_upload_create': case 'secure_upload_pending':
            case 'queue_download': case 'queue_convert': return 'bg-info text-dark';
            default: return 'bg-secondary';
        }
    }

    function showAlert(message, type = 'success', placeholder = alertPlaceholderVideoPage) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = [
            `<div class="alert alert-${type} alert-dismissible fade show" role="alert" style="margin-bottom: 0.5rem;">`,
            `   <div>${message}</div>`,
            '   <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>',
            '</div>'
        ].join('');
        while (placeholder.firstChild) { placeholder.removeChild(placeholder.firstChild); }
        placeholder.append(wrapper);
        setTimeout(() => { // Auto-dismiss after 7 seconds
             const alertInstance = bootstrap.Alert.getOrCreateInstance(wrapper.firstChild);
             if (alertInstance) alertInstance.close();
        }, 7000);
    }

    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    async function fetchChannels() {
        loadingSpinner.style.display = 'block';
        channelSelect.disabled = true;
        videoDisplayArea.innerHTML = '<p class="text-center text-muted">لطفا ابتدا یک کانال انتخاب کنید.</p>';
        paginationArea.innerHTML = '';
        try {
            const response = await fetch('channels-proxy.php'); //
            if (!response.ok) {
                const errData = await response.json().catch(() => ({ message: 'خطای ناشناخته در دریافت کانال‌ها' }));
                throw new Error(errData.message || `خطای سرور: ${response.status}`);
            }
            const data = await response.json();
            channelSelect.innerHTML = '<option value="">-- انتخاب کانال --</option>';
            if (data.data && Array.isArray(data.data) && data.data.length > 0) {
                data.data.forEach(channel => {
                    const option = document.createElement('option');
                    option.value = channel.id;
                    option.textContent = `${escapeHtml(channel.title)} (ویدیوها: ${channel.videos_count || 0})`;
                    channelSelect.appendChild(option);
                });
            } else if (data.data && data.data.length === 0) {
                 channelSelect.innerHTML = '<option value="">هیچ کانالی یافت نشد.</option>';
            } else {
                 channelSelect.innerHTML = `<option value="">${escapeHtml(data.message) || 'خطا یا عدم وجود کانال.'}</option>`;
            }
        } catch (error) {
            console.error('Error fetching channels:', error);
            channelSelect.innerHTML = '<option value="">خطا در بارگذاری کانال‌ها.</option>';
            showAlert(`امکان بارگذاری لیست کانال‌ها وجود ندارد: ${escapeHtml(error.message)}`, 'danger');
        } finally {
            loadingSpinner.style.display = 'none';
            channelSelect.disabled = false;
        }
    }
    
    async function fetchVideos(channelId, page = 1) {
        if (!channelId) {
            videoDisplayArea.innerHTML = '<p class="text-center text-muted">لطفا یک کانال انتخاب کنید.</p>';
            paginationArea.innerHTML = '';
            allVideosData = [];
            return;
        }
        videosLoadingSpinner.style.display = 'block';
        videoDisplayArea.innerHTML = ''; 
        paginationArea.innerHTML = '';
        currentChannelId = channelId;
        currentPage = page;

        try {
            const response = await fetch(`arvan-channel-videos-proxy.php?channel_id=${channelId}&page=${page}`);
            if (!response.ok) {
                const errData = await response.json().catch(() => ({ message: 'خطای ناشناخته در دریافت ویدیوها' }));
                throw new Error(errData.message || `خطای سرور: ${response.status}`);
            }
            const result = await response.json();

            if (result.success === false && result.message) { throw new Error(result.message); }
            if (!result.data) { throw new Error('ساختار پاسخ دریافتی برای ویدیوها نامعتبر است یا داده‌ای وجود ندارد.');}
            
            allVideosData = result.data; 

            if (allVideosData.length === 0) {
                videoDisplayArea.innerHTML = '<p class="text-center">هیچ ویدیویی در این کانال یافت نشد.</p>';
            } else {
                const row = document.createElement('div');
                row.className = 'row g-4';
                allVideosData.forEach(video => {
                    const col = document.createElement('div');
                    col.className = 'col-md-6 col-lg-4 video-item-col'; 
                    col.dataset.videoId = video.id; 
                    let thumbnailUrl = video.thumbnail_url || 'https://via.placeholder.com/300x200.png?text=' + encodeURIComponent(video.title || ' ویدیو');

                    col.innerHTML = `
                        <div class="card video-card shadow-sm h-100">
                            <img src="${thumbnailUrl}" class="card-img-top" alt="${escapeHtml(video.title || 'تصویر ویدیو')}" onerror="this.onerror=null;this.src='https://via.placeholder.com/300x200.png?text=${encodeURIComponent(escapeHtml(video.title || ' ویدیو'))}';">
                            <div class="card-body">
                                <h5 class="card-title video-title">${escapeHtml(video.title || 'بدون عنوان')}</h5>
                                <p class="card-text description-truncate text-muted small video-description">${escapeHtml(video.description || 'بدون توضیحات')}</p>
                                <div> 
                                    <p class="card-text mb-1"><small class="text-muted">تاریخ آپلود: ${new Date(video.created_at).toLocaleDateString('fa-IR')}</small></p>
                                    <p class="card-text"><small>وضعیت: <span class="badge ${getStatusClass(video.status)} status-badge video-status">${statusTranslations[video.status] || video.status}</span></small></p>
                                    <div class="video-card-actions mt-2 d-flex flex-wrap">
                                        ${video.player_url ? `<button type="button" class="btn btn-sm btn-primary play-video-btn" data-player-url="${video.player_url}" data-video-title="${escapeHtml(video.title || 'ویدیو')}"><i class="bi bi-play-circle"></i> پخش</button>` : ''}
                                        ${video.status === 'complete' && video.mp4_videos && video.mp4_videos.length > 0 ?
                                            `<div class="dropdown d-inline-block">
                                                <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" id="dropdownMenuButton_${video.id}" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-download"></i> دانلود
                                                </button>
                                                <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton_${video.id}">
                                                    ${video.mp4_videos.map((mp4, index) => {
                                                        const qualityLabel = video.converted_info && Array.isArray(video.converted_info) && video.converted_info[index] && video.converted_info[index].resolution
                                                                            ? video.converted_info[index].resolution
                                                                            : `کیفیت ${index + 1}`;
                                                        return `<li><a class="dropdown-item" href="${mp4}" target="_blank" rel="noopener noreferrer">${qualityLabel}</a></li>`;
                                                    }).join('')}
                                                </ul>
                                            </div>` : ''
                                        }
                                    </div>
                                    <div class="video-card-actions mt-2 d-flex flex-wrap"> 
                                        <button class="btn btn-sm btn-warning edit-video-btn" data-video-id="${video.id}" title="ویرایش ویدیو"><i class="bi bi-pencil-square"></i> ویرایش</button>
                                        <button class="btn btn-sm btn-danger delete-video-btn" data-video-id="${video.id}" data-video-title="${escapeHtml(video.title || 'این ویدیو')}" title="حذف ویدیو"><i class="bi bi-trash"></i> حذف</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    row.appendChild(col);
                });
                videoDisplayArea.appendChild(row);
            }
            if (result.meta) { renderPagination(result.meta); }
        } catch (error) {
            console.error('Error fetching videos:', error);
            showAlert(`امکان بارگذاری ویدیوها وجود ندارد: ${escapeHtml(error.message)}`, 'danger');
        } finally {
            videosLoadingSpinner.style.display = 'none';
        }
    }

    function renderPagination(meta) {
        paginationArea.innerHTML = '';
        if (!meta || meta.last_page <= 1) { return; }
        const nav = document.createElement('nav');
        nav.setAttribute('aria-label', 'Video navigation');
        const ul = document.createElement('ul');
        ul.className = 'pagination justify-content-center';

        const prevLi = document.createElement('li');
        prevLi.className = `page-item ${meta.current_page === 1 ? 'disabled' : ''}`;
        const prevA = document.createElement('a');
        prevA.className = 'page-link'; prevA.href = '#'; prevA.textContent = 'قبلی';
        prevA.addEventListener('click', (e) => { e.preventDefault(); if (meta.current_page > 1) { fetchVideos(currentChannelId, meta.current_page - 1); } });
        prevLi.appendChild(prevA); ul.appendChild(prevLi);

        const pagesToShow = []; const totalPages = meta.last_page; const currentPageNum = meta.current_page;
        if (totalPages <= 7) { for (let i = 1; i <= totalPages; i++) pagesToShow.push(i); } 
        else {
            pagesToShow.push(1); 
            if (currentPageNum > 3) pagesToShow.push('...');
            for (let i = Math.max(2, currentPageNum - 1); i <= Math.min(totalPages - 1, currentPageNum + 1); i++) { if (!pagesToShow.includes(i)) pagesToShow.push(i); }
            if (currentPageNum < totalPages - 2) pagesToShow.push('...');
            if (!pagesToShow.includes(totalPages)) pagesToShow.push(totalPages);
        }
        pagesToShow.forEach(pageNum => {
            const li = document.createElement('li');
            if (pageNum === '...') {
                li.className = 'page-item disabled'; const span = document.createElement('span');
                span.className = 'page-link'; span.textContent = '...'; li.appendChild(span);
            } else {
                li.className = `page-item ${pageNum === currentPageNum ? 'active' : ''}`; const a = document.createElement('a');
                a.className = 'page-link'; a.href = '#'; a.textContent = pageNum;
                a.addEventListener('click', (e) => { e.preventDefault(); fetchVideos(currentChannelId, pageNum); });
                li.appendChild(a);
            }
            ul.appendChild(li);
        });

        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${meta.current_page === meta.last_page ? 'disabled' : ''}`;
        const nextA = document.createElement('a');
        nextA.className = 'page-link'; nextA.href = '#'; nextA.textContent = 'بعدی';
        nextA.addEventListener('click', (e) => { e.preventDefault(); if (meta.current_page < meta.last_page) { fetchVideos(currentChannelId, meta.current_page + 1); } });
        nextLi.appendChild(nextA); ul.appendChild(nextLi);
        nav.appendChild(ul); paginationArea.appendChild(nav);
    }

    channelSelect.addEventListener('change', function() { fetchVideos(this.value, 1); });
    refreshChannelsBtn.addEventListener('click', fetchChannels);

    videoDisplayArea.addEventListener('click', function(event) {
        const targetButton = event.target.closest('button');
        if (!targetButton) return;
        const videoId = targetButton.dataset.videoId;

        if (targetButton.classList.contains('edit-video-btn')) {
            const videoToEdit = allVideosData.find(v => v.id === videoId);
            if (videoToEdit) {
                editVideoIdInput.value = videoToEdit.id;
                editVideoTitleInput.value = videoToEdit.title || '';
                editVideoDescriptionInput.value = videoToEdit.description || '';
                document.getElementById('editVideoModalLabel').textContent = `ویرایش ویدیو: ${escapeHtml(videoToEdit.title || 'بدون عنوان')}`;
                editVideoForm.classList.remove('was-validated');
                editVideoModalInstance.show();
            }
        } else if (targetButton.classList.contains('delete-video-btn')) {
            const videoTitle = targetButton.dataset.videoTitle;
            if (confirm(`آیا از حذف ویدیوی "${videoTitle}" مطمئن هستید؟ این عملیات غیرقابل بازگشت است.`)) {
                deleteVideo(videoId);
            }
        } else if (targetButton.classList.contains('play-video-btn')) {
            const playerUrl = targetButton.dataset.playerUrl;
            const videoTitle = targetButton.dataset.videoTitle || 'پخش ویدیو';
            if (playerUrl) {
                videoPlayerModalLabel.textContent = videoTitle;
                videoPlayerIframeContainer.innerHTML = ''; 
                activeVideoPlayerIframe = document.createElement('iframe');
                activeVideoPlayerIframe.setAttribute('src', playerUrl + (playerUrl.includes('?') ? '&' : '?') + 'autoplay=1');
                activeVideoPlayerIframe.setAttribute('frameborder', '0');
                activeVideoPlayerIframe.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; encrypted-media');
                activeVideoPlayerIframe.setAttribute('allowfullscreen', '');
                activeVideoPlayerIframe.style.position = 'absolute'; activeVideoPlayerIframe.style.top = '0'; activeVideoPlayerIframe.style.left = '0';
                activeVideoPlayerIframe.style.width = '100%'; activeVideoPlayerIframe.style.height = '100%';
                videoPlayerIframeContainer.appendChild(activeVideoPlayerIframe);
                videoPlayerModalInstance.show();
            }
        }
    });

    videoPlayerModalElement.addEventListener('hidden.bs.modal', function () {
        videoPlayerIframeContainer.innerHTML = '';
        activeVideoPlayerIframe = null; 
    });

    editVideoForm.addEventListener('submit', async function(event) {
        event.preventDefault(); event.stopPropagation();
        if (!this.checkValidity()) { this.classList.add('was-validated'); return; }
        this.classList.add('was-validated');
        const videoId = editVideoIdInput.value; const title = editVideoTitleInput.value; const description = editVideoDescriptionInput.value;
        saveVideoBtn.disabled = true; saveVideoBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> در حال ذخیره...';
        try {
            const response = await fetch(`manage-video-action-proxy.php?video_id=${videoId}`, {
                method: 'PATCH', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({ title, description })
            });
            const result = await response.json();
            if (!response.ok || !result.success) { throw new Error(result.message || 'خطا در بروزرسانی اطلاعات ویدیو.'); }
            showAlert('اطلاعات ویدیو با موفقیت بروزرسانی شد.', 'success');
            editVideoModalInstance.hide();
            updateVideoCard(videoId, title, description);
        } catch (error) {
            console.error('Error updating video:', error);
            showAlert(`خطا در بروزرسانی ویدیو: ${escapeHtml(error.message)}`, 'danger');
        } finally {
            saveVideoBtn.disabled = false; saveVideoBtn.textContent = 'ذخیره تغییرات';
        }
    });
    
    function updateVideoCard(videoId, newTitle, newDescription) {
        const videoCardCol = videoDisplayArea.querySelector(`.video-item-col[data-video-id="${videoId}"]`);
        if (videoCardCol) {
            const titleEl = videoCardCol.querySelector('.video-title');
            const descEl = videoCardCol.querySelector('.video-description');
            if (titleEl) titleEl.textContent = escapeHtml(newTitle || 'بدون عنوان');
            if (descEl) descEl.textContent = escapeHtml(newDescription || 'بدون توضیحات');
            const videoIndex = allVideosData.findIndex(v => v.id === videoId);
            if (videoIndex > -1) { allVideosData[videoIndex].title = newTitle; allVideosData[videoIndex].description = newDescription; }
        }
    }

    async function deleteVideo(videoId) {
        try {
            const response = await fetch(`manage-video-action-proxy.php?video_id=${videoId}`, {
                method: 'DELETE', headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || !result.success) { throw new Error(result.message || 'خطا در حذف ویدیو.'); }
            showAlert('ویدیو با موفقیت حذف شد.', 'success');
            const videoCardCol = videoDisplayArea.querySelector(`.video-item-col[data-video-id="${videoId}"]`);
            if (videoCardCol) { videoCardCol.remove(); }
            // Refresh count or page if necessary
            allVideosData = allVideosData.filter(v => v.id !== videoId);
            if (videoDisplayArea.querySelectorAll('.video-item-col').length === 0) {
                if (currentPage > 1) fetchVideos(currentChannelId, currentPage - 1);
                else videoDisplayArea.innerHTML = '<p class="text-center">هیچ ویدیویی در این کانال یافت نشد.</p>';
            }
        } catch (error) {
            console.error('Error deleting video:', error);
            showAlert(`خطا در حذف ویدیو: ${escapeHtml(error.message)}`, 'danger');
        }
    }

    // Initial load
    fetchChannels();
    </script>
</body>
</html>