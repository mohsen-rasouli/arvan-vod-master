<?php
// v3/manage-channels.php
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مدیریت کانال‌های آروان</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .action-buttons .btn { margin-right: 5px; }
        #channelFormModal .modal-body .form-label { margin-bottom: 0.3rem; }
        #channelListTable th, #channelListTable td { vertical-align: middle; }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="mb-0">مدیریت کانال‌ها</h2>
                <button class="btn btn-success" id="showCreateChannelModalBtn"><i class="bi bi-plus-circle"></i> ایجاد کانال جدید</button>
            </div>
            <div class="card-body">
                <div id="alertPlaceholder"></div>
                <div class="table-responsive">
                    <table class="table table-hover" id="channelListTable">
                        <thead>
                            <tr>
                                <th scope="col">عنوان کانال</th>
                                <th scope="col">توضیحات</th>
                                <th scope="col">تعداد ویدیو</th>
                                <th scope="col">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="channelListBody">
                            <tr><td colspan="4" class="text-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">در حال بارگذاری کانال‌ها...</span></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
             <div class="card-footer text-center">
                 <a href="index.php" class="btn btn-link">بازگشت به صفحه اصلی</a>
            </div>
        </div>
    </div>

    <div class="modal fade" id="channelFormModal" tabindex="-1" aria-labelledby="channelFormModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="channelForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="channelFormModalLabel">ایجاد/ویرایش کانال</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="channelId" name="channelId">
                        <div class="mb-3">
                            <label for="channelTitle" class="form-label">عنوان کانال <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="channelTitle" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="channelDescription" class="form-label">توضیحات کانال</label>
                            <textarea class="form-control" id="channelDescription" name="description" rows="3"></textarea>
                        </div>
                        {/* Add other fields here if needed in the future, e.g., secure_link_enabled */}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                        <button type="submit" class="btn btn-primary" id="saveChannelBtn">ذخیره تغییرات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const channelListBody = document.getElementById('channelListBody');
    const channelFormModal = new bootstrap.Modal(document.getElementById('channelFormModal'));
    const channelForm = document.getElementById('channelForm');
    const channelFormModalLabel = document.getElementById('channelFormModalLabel');
    const channelIdInput = document.getElementById('channelId');
    const channelTitleInput = document.getElementById('channelTitle');
    const channelDescriptionInput = document.getElementById('channelDescription');
    const saveChannelBtn = document.getElementById('saveChannelBtn');
    const alertPlaceholder = document.getElementById('alertPlaceholder');

    function showAlert(message, type = 'success') {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = [
            `<div class="alert alert-${type} alert-dismissible" role="alert">`,
            `   <div>${message}</div>`,
            '   <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>',
            '</div>'
        ].join('');
        alertPlaceholder.append(wrapper);
        setTimeout(() => { // Auto-dismiss after 5 seconds
            if (wrapper.firstChild) bootstrap.Alert.getOrCreateInstance(wrapper.firstChild).close();
        }, 5000);
    }

    async function loadChannels() {
        channelListBody.innerHTML = '<tr><td colspan="4" class="text-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">در حال بارگذاری کانال‌ها...</span></div></td></tr>';
        try {
            const response = await fetch('channels-proxy.php'); //
            const result = await response.json();

            if (!response.ok || result.success === false) { // Assuming channels-proxy.php might also return {success: false}
                throw new Error(result.message || 'خطا در دریافت لیست کانال‌ها.');
            }
            
            if (result.data && Array.isArray(result.data)) {
                renderChannels(result.data);
            } else {
                channelListBody.innerHTML = '<tr><td colspan="4" class="text-center p-3">هیچ کانالی یافت نشد یا فرمت پاسخ نامعتبر است.</td></tr>';
            }
        } catch (error) {
            console.error('Error loading channels:', error);
            channelListBody.innerHTML = `<tr><td colspan="4" class="text-center p-3 text-danger">خطا در بارگذاری کانال‌ها: ${error.message}</td></tr>`;
            showAlert(`خطا در بارگذاری کانال‌ها: ${error.message}`, 'danger');
        }
    }

    function renderChannels(channels) {
        if (channels.length === 0) {
            channelListBody.innerHTML = '<tr><td colspan="4" class="text-center p-3">هیچ کانالی تاکنون ایجاد نشده است.</td></tr>';
            return;
        }
        channelListBody.innerHTML = ''; // Clear loading/previous
        channels.forEach(channel => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${channel.title || 'بدون عنوان'}</td>
                <td>${channel.description || '-'}</td>
                <td>${channel.videos_count !== undefined ? channel.videos_count : '-'}</td>
                <td class="action-buttons">
                    <button class="btn btn-sm btn-warning edit-channel-btn" data-id="${channel.id}" data-title="${escapeHtml(channel.title)}" data-description="${escapeHtml(channel.description || '')}" title="ویرایش کانال"><i class="bi bi-pencil-square"></i></button>
                    <button class="btn btn-sm btn-danger delete-channel-btn" data-id="${channel.id}" data-title="${escapeHtml(channel.title)}" title="حذف کانال"><i class="bi bi-trash"></i></button>
                </td>
            `;
            channelListBody.appendChild(tr);
        });
    }

    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe
             .toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    document.getElementById('showCreateChannelModalBtn').addEventListener('click', () => {
        channelForm.reset();
        channelIdInput.value = '';
        channelFormModalLabel.textContent = 'ایجاد کانال جدید';
        saveChannelBtn.textContent = 'ایجاد کانال';
        channelForm.classList.remove('was-validated');
        channelFormModal.show();
    });

    channelListBody.addEventListener('click', function(event) {
        const target = event.target.closest('button');
        if (!target) return;

        const channelId = target.dataset.id;
        const channelTitle = target.dataset.title;

        if (target.classList.contains('edit-channel-btn')) {
            const channelDescription = target.dataset.description;
            channelForm.reset();
            channelIdInput.value = channelId;
            channelTitleInput.value = channelTitle; // HTML entities will be decoded by browser
            channelDescriptionInput.value = channelDescription; // HTML entities will be decoded
            channelFormModalLabel.textContent = `ویرایش کانال: ${channelTitle}`;
            saveChannelBtn.textContent = 'ذخیره تغییرات';
            channelForm.classList.remove('was-validated');
            channelFormModal.show();
        } else if (target.classList.contains('delete-channel-btn')) {
            if (confirm(`آیا از حذف کانال "${channelTitle}" مطمئن هستید؟ این عملیات غیرقابل بازگشت است.`)) {
                deleteChannel(channelId);
            }
        }
    });

    channelForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        event.stopPropagation();
        
        if (!this.checkValidity()) {
            this.classList.add('was-validated');
            return;
        }
        this.classList.add('was-validated'); // Show validation styles immediately if needed

        const id = channelIdInput.value;
        const title = channelTitleInput.value;
        const description = channelDescriptionInput.value;
        const isUpdate = id ? true : false;
        
        const method = isUpdate ? 'PATCH' : 'POST';
        let url = 'manage-channels-action-proxy.php';
        if (isUpdate) {
            url += `?channel_id=${id}`;
        }

        saveChannelBtn.disabled = true;
        saveChannelBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> در حال ذخیره...';

        try {
            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ title, description }) // Only send title and description
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || `خطا در ${isUpdate ? 'بروزرسانی' : 'ایجاد'} کانال.`);
            }
            
            showAlert(`کانال با موفقیت ${isUpdate ? 'بروزرسانی شد' : 'ایجاد شد'}.`, 'success');
            channelFormModal.hide();
            loadChannels(); // Refresh the list

        } catch (error) {
            console.error(`Error ${isUpdate ? 'updating' : 'creating'} channel:`, error);
            showAlert(`خطا: ${error.message}`, 'danger');
        } finally {
            saveChannelBtn.disabled = false;
            saveChannelBtn.textContent = isUpdate ? 'ذخیره تغییرات' : 'ایجاد کانال';
        }
    });

    async function deleteChannel(id) {
        try {
            const response = await fetch(`manage-channels-action-proxy.php?channel_id=${id}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'خطا در حذف کانال.');
            }
            showAlert('کانال با موفقیت حذف شد.', 'success');
            loadChannels(); // Refresh the list
        } catch (error) {
            console.error('Error deleting channel:', error);
            showAlert(`خطا در حذف کانال: ${error.message}`, 'danger');
        }
    }
    
    // Utility to escape HTML for data attributes to prevent XSS or attribute breaking
    // This is now used when setting data attributes for edit button.
    // The browser automatically decodes HTML entities when setting input.value.


    // Initial load of channels
    loadChannels();
    </script>
</body>
</html>