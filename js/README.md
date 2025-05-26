# Arvan VOD Node.js Backend

این پوشه شامل نسخه Node.js (Express) برای پروکسی امن آپلود ویدیو، وضعیت ویدیو و لیست کانال‌های آروان است.

## راه‌اندازی سریع

1. **نصب وابستگی‌ها**
   ```sh
   cd js
   npm install
   ```

2. **اجرای سرور**
   ```sh
   npm start
   # یا
   node server.js
   ```

3. **مسیرهای API**
   - `POST /upload-proxy` : آپلود و ثبت ویدیو
   - `POST /video-status-proxy` : وضعیت ویدیو
   - `GET /channels-proxy` : لیست کانال‌ها

4. **فرانت‌اند**
   - فایل `video-uploader.html` را با مرورگر باز کنید.
   - این فایل به صورت AJAX به همین Node.js backend متصل است.

## نکات مهم
- فایل `config.ini` باید در ریشه پروژه (یک پوشه بالاتر از js/) باشد.
- اگر می‌خواهید این HTML را از طریق Node.js سرو کنید، می‌توانید این خط را به server.js اضافه کنید:
  ```js
  app.use(express.static(__dirname));
  ```
  سپس با آدرس `http://localhost:3001/video-uploader.html` قابل دسترسی است.

## وابستگی‌ها
- express
- multer
- axios
- ini
- cors
- body-parser

---

هر سوالی داشتی بپرس! 