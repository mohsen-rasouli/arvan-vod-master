<?php
// v3/arvan-channel-videos.php
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مشاهده ویدیوهای کانال‌های آروان</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .video-card {
            transition: transform .2s;
            height: 100%; /* Ensure cards in a row have same height base */
        }
        .video-card:hover {
            transform: scale(1.03);
        }
        .video-card .card-img-top {
            width: 100%;
            height: 200px; /* Fixed height for thumbnails */
            object-fit: cover; /* Scale image to cover, might crop */
            background-color: #f0f0f0; /* Placeholder bg */
        }
        .video-card .card-body {
            display: flex;
            flex-direction: column;
            justify-content: space-between; /* Pushes actions to bottom */
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
    </style>
</head>
<body class="bg-light">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const channelSelect = document.getElementById('arvanChannelSelect');
    const videoDisplayArea = document.getElementById('videoDisplayArea');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const videosLoadingSpinner = document.getElementById('videosLoadingSpinner');
    const paginationArea = document.getElementById('paginationArea');
    const refreshChannelsBtn = document.getElementById('refreshChannelsBtn');
    let currentChannelId = null;
    let currentPage = 1;

    const statusTranslations = {
        'uploading': 'در حال آپلود',
        'pending': 'در انتظار',
        'processing': 'در حال پردازش',
        'converting': 'در حال تبدیل',
        'watermarking': 'در حال اعمال واترمارک',
        'generating_thumbnail': 'در حال ساخت پیش‌نمایش',
        'complete': 'تکمیل شده',
        'failed': 'ناموفق',
        'source_failed': 'خطا در فایل منبع',
        'blocked': 'مسدود شده',
        'canceled': 'لغو شده',
        // from direct-arvan-uploader.php, ensuring consistency
        'secure_upload_create': 'در حال ایجاد آپلود امن',
        'secure_upload_pending': 'در انتظار آپلود امن',
        'secure_upload_failed': 'خطا در آپلود امن',
        'getsize': 'در حال دریافت اندازه',
        'downloading': 'در حال دانلود از مبدا',
        'queue_download': 'در صف دانلود از مبدا',
        'queue_convert': 'در صف تبدیل',
    };

    function getStatusClass(status) {
        switch (status) {
            case 'complete': return 'bg-success';
            case 'failed':
            case 'source_failed':
            case 'blocked':
            case 'canceled': 
            case 'secure_upload_failed':
                return 'bg-danger';
            case 'processing':
            case 'converting':
            case 'watermarking':
            case 'generating_thumbnail':
            case 'downloading':
            case 'getsize':
            case 'secure_upload_create':
            case 'secure_upload_pending':
            case 'queue_download':
            case 'queue_convert':
                return 'bg-info text-dark';
            default: return 'bg-secondary';
        }
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
                    option.textContent = `${channel.title} (ویدیوها: ${channel.videos_count || 0})`;
                    channelSelect.appendChild(option);
                });
            } else if (data.data && data.data.length === 0) {
                 channelSelect.innerHTML = '<option value="">هیچ کانالی یافت نشد.</option>';
            } 
             else {
                 channelSelect.innerHTML = `<option value="">${data.message || 'خطا یا عدم وجود کانال.'}</option>`;
            }
        } catch (error) {
            console.error('Error fetching channels:', error);
            channelSelect.innerHTML = '<option value="">خطا در بارگذاری کانال‌ها.</option>';
            videoDisplayArea.innerHTML = `<div class="alert alert-danger text-center">امکان بارگذاری لیست کانال‌ها وجود ندارد: ${error.message}</div>`;
        } finally {
            loadingSpinner.style.display = 'none';
            channelSelect.disabled = false;
        }
    }

    async function fetchVideos(channelId, page = 1) {
        if (!channelId) {
            videoDisplayArea.innerHTML = '<p class="text-center text-muted">لطفا یک کانال انتخاب کنید.</p>';
            paginationArea.innerHTML = '';
            return;
        }
        videosLoadingSpinner.style.display = 'block';
        videoDisplayArea.innerHTML = ''; // Clear previous videos
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

            if (result.success === false && result.message) { // Check for our proxy's specific error format
                 throw new Error(result.message);
            }
            if (!result.data) { // General check if data is missing even if success isn't explicitly false
                throw new Error('ساختار پاسخ دریافتی برای ویدیوها نامعتبر است یا داده‌ای وجود ندارد.');
            }
            
            const videos = result.data;

            if (videos.length === 0) {
                videoDisplayArea.innerHTML = '<p class="text-center">هیچ ویدیویی در این کانال یافت نشد.</p>';
            } else {
                const row = document.createElement('div');
                row.className = 'row g-4';
                videos.forEach(video => {
                    const col = document.createElement('div');
                    col.className = 'col-md-6 col-lg-4'; // Responsive grid
                    
                    let thumbnailUrl = video.thumbnail_url || 'https://via.placeholder.com/300x200.png?text=' + encodeURIComponent(video.title || ' ویدیو');

                    col.innerHTML = `
                        <div class="card video-card shadow-sm">
                            <img src="${thumbnailUrl}" class="card-img-top" alt="${video.title || 'تصویر ویدیو'}" onerror="this.onerror=null;this.src='https://via.placeholder.com/300x200.png?text=${encodeURIComponent(video.title || ' ویدیو')}';">
                            <div class="card-body">
                                <h5 class="card-title">${video.title || 'بدون عنوان'}</h5>
                                <p class="card-text description-truncate text-muted small">${video.description || 'بدون توضیحات'}</p>
                                <div>
                                    <p class="card-text mb-1"><small class="text-muted">تاریخ آپلود: ${new Date(video.created_at).toLocaleDateString('fa-IR')}</small></p>
                                    <p class="card-text"><small>وضعیت: <span class="badge ${getStatusClass(video.status)} status-badge">${statusTranslations[video.status] || video.status}</span></small></p>
                                    ${video.player_url ? `<a href="${video.player_url}" class="btn btn-primary btn-sm mt-2" target="_blank" rel="noopener noreferrer"><i class="bi bi-play-circle"></i> پخش</a>` : ''}
                                    ${video.status === 'complete' && video.mp4_videos && video.mp4_videos.length > 0 ?
                                        `<div class="dropdown d-inline-block ms-2 mt-2">
                                            <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" id="dropdownMenuButton_${video.id}" data-bs-toggle="dropdown" aria-expanded="false">
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
                            </div>
                        </div>
                    `;
                    row.appendChild(col);
                });
                videoDisplayArea.appendChild(row);
            }
            if (result.meta) {
                renderPagination(result.meta);
            }
        } catch (error) {
            console.error('Error fetching videos:', error);
            videoDisplayArea.innerHTML = `<div class="alert alert-danger text-center">امکان بارگذاری ویدیوها وجود ندارد: ${error.message}</div>`;
        } finally {
            videosLoadingSpinner.style.display = 'none';
        }
    }

    function renderPagination(meta) {
        paginationArea.innerHTML = '';
        if (!meta || meta.last_page <= 1) {
            return;
        }

        const nav = document.createElement('nav');
        nav.setAttribute('aria-label', 'Video navigation');
        const ul = document.createElement('ul');
        ul.className = 'pagination justify-content-center';

        // Previous button
        const prevLi = document.createElement('li');
        prevLi.className = `page-item ${meta.current_page === 1 ? 'disabled' : ''}`;
        const prevA = document.createElement('a');
        prevA.className = 'page-link';
        prevA.href = '#';
        prevA.textContent = 'قبلی';
        prevA.addEventListener('click', (e) => {
            e.preventDefault();
            if (meta.current_page > 1) {
                fetchVideos(currentChannelId, meta.current_page - 1);
            }
        });
        prevLi.appendChild(prevA);
        ul.appendChild(prevLi);

        // Page numbers (simplified: show first, last, current, and a few around current)
        const pagesToShow = [];
        const totalPages = meta.last_page;
        const currentPageNum = meta.current_page;

        if (totalPages <= 7) { // Show all pages if 7 or less
            for (let i = 1; i <= totalPages; i++) pagesToShow.push(i);
        } else {
            pagesToShow.push(1); // Always show first page
            if (currentPageNum > 3) pagesToShow.push('...'); // Ellipsis if far from start

            for (let i = Math.max(2, currentPageNum - 1); i <= Math.min(totalPages - 1, currentPageNum + 1); i++) {
                 if (!pagesToShow.includes(i)) pagesToShow.push(i);
            }
            
            if (currentPageNum < totalPages - 2) pagesToShow.push('...'); // Ellipsis if far from end
            if (!pagesToShow.includes(totalPages)) pagesToShow.push(totalPages); // Always show last page
        }
        
        pagesToShow.forEach(pageNum => {
            const li = document.createElement('li');
            if (pageNum === '...') {
                li.className = 'page-item disabled';
                const span = document.createElement('span');
                span.className = 'page-link';
                span.textContent = '...';
                li.appendChild(span);
            } else {
                li.className = `page-item ${pageNum === currentPageNum ? 'active' : ''}`;
                const a = document.createElement('a');
                a.className = 'page-link';
                a.href = '#';
                a.textContent = pageNum;
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    fetchVideos(currentChannelId, pageNum);
                });
                li.appendChild(a);
            }
            ul.appendChild(li);
        });


        // Next button
        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${meta.current_page === meta.last_page ? 'disabled' : ''}`;
        const nextA = document.createElement('a');
        nextA.className = 'page-link';
        nextA.href = '#';
        nextA.textContent = 'بعدی';
        nextA.addEventListener('click', (e) => {
            e.preventDefault();
            if (meta.current_page < meta.last_page) {
                fetchVideos(currentChannelId, meta.current_page + 1);
            }
        });
        nextLi.appendChild(nextA);
        ul.appendChild(nextLi);

        nav.appendChild(ul);
        paginationArea.appendChild(nav);
    }


    channelSelect.addEventListener('change', function() {
        const selectedChannelId = this.value;
        fetchVideos(selectedChannelId, 1); // Fetch first page on new channel selection
    });

    refreshChannelsBtn.addEventListener('click', fetchChannels);

    // Initial load
    fetchChannels();
    </script>
</body>
</html>