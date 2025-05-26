<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ویدیوهای کانال آروان</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.rtl.min.css">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; padding-top: 20px; background-color: #f8f9fa; }
        .container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .status-area { margin-top: 15px; padding: 10px; border: 1px solid #ced4da; border-radius: .25rem; background-color: #f8f9fa; }
        .video-card { background: #f8f9fa; border: 1px solid #ced4da; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .video-thumb { width: 100%; border-radius: 6px; aspect-ratio: 16 / 9;}
        .modal-blur-bg { position: fixed; inset: 0; background: rgba(80,80,80,0.4); backdrop-filter: blur(6px); z-index: 1040; }
        .modal.show { display: block; }
        .json-box { background:#f8f9fa; border:1px solid #ced4da; border-radius:6px; padding:12px; margin-top:8px; max-height:400px; overflow:auto; text-align:left; direction:ltr; font-size:0.9em; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4 text-center">مشاهده ویدیوهای کانال آروان‌کلاد</h1>
        <div class="mb-3">
            <label for="channelSelect" class="form-label">انتخاب کانال:</label>
            <select id="channelSelect" class="form-select">
                <option value="">در حال بارگذاری کانال‌ها...</option>
            </select>
        </div>
        <div id="videosArea" class="row mt-4"></div>
        <div id="statusArea" class="status-area mt-3">وضعیت: منتظر انتخاب کانال...</div>
    </div>
    <!-- Modal & Blur BG -->
    <div id="modalBlurBg" class="modal-blur-bg" style="display:none;"></div>
    <div class="modal fade" id="videoModal" tabindex="-1" aria-hidden="true" style="display:none;">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="modalTitle">پخش ویدیو</h5>
            <button type="button" class="btn-close" id="closeModalBtn"></button>
          </div>
          <div class="modal-body" id="modalBody"></div>
        </div>
      </div>
    </div>
    <script>
    const arvanApiBaseUrl = 'https://napi.arvancloud.ir/vod/2.0';
    const apiKey = 'Apikey 238d40ba-4348-467e-96e3-c0b342266a0b';
    const channelSelect = document.getElementById('channelSelect');
    const videosArea = document.getElementById('videosArea');
    const statusArea = document.getElementById('statusArea');
    const modal = document.getElementById('videoModal');
    const modalBlurBg = document.getElementById('modalBlurBg');
    const modalBody = document.getElementById('modalBody');
    const modalTitle = document.getElementById('modalTitle');
    const closeModalBtn = document.getElementById('closeModalBtn');

    // Load channels
    function loadChannels() {
        fetch(`${arvanApiBaseUrl}/channels`, {
            headers: { 'Authorization': apiKey, 'Accept': 'application/json' }
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
                if (data.data.length === 0) channelSelect.innerHTML = '<option value="">کانالی یافت نشد</option>';
            } else {
                channelSelect.innerHTML = '<option value="">خطا در دریافت کانال‌ها</option>';
            }
        })
        .catch(err => {
            channelSelect.innerHTML = '<option value="">خطا در بارگذاری کانال‌ها</option>';
            statusArea.textContent = 'خطا در بارگذاری کانال‌ها: ' + err.message;
        });
    }
    loadChannels();

    channelSelect.addEventListener('change', function() {
        const channelId = channelSelect.value;
        if (!channelId) {
            videosArea.innerHTML = '';
            statusArea.textContent = 'وضعیت: منتظر انتخاب کانال...';
            return;
        }
        statusArea.textContent = 'در حال دریافت ویدیوها...';
        fetch(`${arvanApiBaseUrl}/channels/${channelId}/videos`, {
            headers: { 'Authorization': apiKey, 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            videosArea.innerHTML = '';
            if (data.data && Array.isArray(data.data) && data.data.length > 0) {
                data.data.forEach(video => {
                    const col = document.createElement('div');
                    col.className = 'col-md-4';
                    let dateStr = '-';
                    let dateIso = video.play_ready_at || video.created_at;
                    if (dateIso) {
                        try {
                            const d = new Date(dateIso);
                            dateStr = d.toLocaleString('fa-IR', { dateStyle: 'medium', timeStyle: 'short' });
                        } catch(e) { dateStr = dateIso; }
                    }
                    const statusMap = {
                        'complete': 'آماده پخش',
                        'getsize': 'دریافت اطلاعات',
                        'generating_thumbnail': 'تولید تصویر بندانگشتی',
                        'converting': 'در حال تبدیل',
                        'downloading': 'در حال دانلود',
                        'queue_download': 'در صف دانلود'
                    };
                    let statusFa = statusMap[video.status] || video.status || '-';
                    col.innerHTML = `
                    <div class="video-card p-3 mb-3">
                        <img src="${video.thumbnail_url || 'https://via.placeholder.com/320x180?text=No+Thumbnail'}" class="video-thumb mb-2" alt="thumbnail">
                        <h5 class="mb-1">${video.title || 'بدون عنوان'}</h5>
                        <div class="mb-1"><b>وضعیت:</b> ${statusFa}</div>
                        <div class="mb-1"><b>تاریخ:</b> ${dateStr}</div>
                        <button class="btn btn-primary btn-sm mt-2 play-btn" data-id="${video.id}">پخش ویدیو</button>
                        <button class="btn btn-outline-secondary btn-sm mt-2 ms-2 json-btn" data-id="${video.id}">نمایش JSON</button>
                        <div class="json-box" id="jsonbox-${video.id}" style="display:none;"></div>
                    </div>`;
                    videosArea.appendChild(col);
                });
            } else {
                videosArea.innerHTML = '<div class="col-12 text-center">ویدیویی یافت نشد.</div>';
            }
            statusArea.textContent = 'تعداد ویدیوها: ' + (data.data ? data.data.length : 0);
        })
        .catch(err => {
            videosArea.innerHTML = '';
            statusArea.textContent = 'خطا در دریافت ویدیوها: ' + err.message;
        });
    });

    // Play video modal
    videosArea.addEventListener('click', function(e) {
        if (e.target.classList.contains('play-btn')) {
            const videoId = e.target.getAttribute('data-id');
            showModalForVideo(videoId);
        } else if (e.target.classList.contains('json-btn')) {
            const videoId = e.target.getAttribute('data-id');
            toggleJsonBox(videoId);
        }
    });

    function showModalForVideo(videoId) {
        statusArea.textContent = 'در حال دریافت اطلاعات ویدیو...';
        fetch(`${arvanApiBaseUrl}/videos/${videoId}`, {
            headers: { 'Authorization': apiKey, 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.data) {
                modalTitle.textContent = data.data.title || 'پخش ویدیو';
                let playerHtml = '';
                if (data.data.player_url) {
                    playerHtml = `<iframe id='modalPlayerFrame' src="${data.data.player_url}" width="100%" style="aspect-ratio: 16 / 9; border:none;" allowfullscreen></iframe>`;
                } else if (data.data.hls_playlist) {
                    playerHtml = `<video controls width="100%" style="max-height:400px;" src="${data.data.hls_playlist}"></video>`;
                } else if (data.data.video_url) {
                    playerHtml = `<video controls width="100%" style="max-height:400px;" src="${data.data.video_url}"></video>`;
                } else {
                    playerHtml = '<div class="alert alert-warning">لینک پخش یافت نشد.</div>';
                }
                modalBody.innerHTML = playerHtml;
                showModal();
            } else {
                modalBody.innerHTML = '<div class="alert alert-danger">خطا در دریافت اطلاعات ویدیو</div>';
                showModal();
            }
            statusArea.textContent = '';
        })
        .catch(err => {
            modalBody.innerHTML = '<div class="alert alert-danger">خطا در دریافت اطلاعات ویدیو: ' + err.message + '</div>';
            showModal();
            statusArea.textContent = '';
        });
    }

    function showModal() {
        modal.style.display = 'block';
        modal.classList.add('show');
        modalBlurBg.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
    function hideModal() {
        const iframe = document.getElementById('modalPlayerFrame');
        if (iframe) iframe.src = '';
        const video = modalBody.querySelector('video');
        if (video) { video.pause(); video.currentTime = 0; }
        modal.style.display = 'none';
        modal.classList.remove('show');
        modalBlurBg.style.display = 'none';
        document.body.style.overflow = '';
    }
    closeModalBtn.onclick = hideModal;
    modalBlurBg.onclick = hideModal;
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') hideModal(); });

    // JSON toggle
    function toggleJsonBox(videoId) {
        const box = document.getElementById('jsonbox-' + videoId);
        if (!box) return;
        if (box.style.display === 'none') {
            box.textContent = 'در حال دریافت...';
            box.style.display = '';
            fetch(`${arvanApiBaseUrl}/videos/${videoId}`, {
                headers: { 'Authorization': apiKey, 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(data => {
                box.textContent = JSON.stringify(data.data, null, 2);
            })
            .catch(err => {
                box.textContent = 'خطا: ' + err.message;
            });
        } else {
            box.style.display = 'none';
        }
    }
    </script>
</body>
</html> 