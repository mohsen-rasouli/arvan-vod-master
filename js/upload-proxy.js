const express = require('express');
const multer = require('multer');
const fs = require('fs');
const path = require('path');
const axios = require('axios');
const ini = require('ini');

const router = express.Router();
const upload = multer({ dest: '/tmp' });

// Read config.ini
const config = ini.parse(fs.readFileSync(path.join(__dirname, '../config.ini'), 'utf-8'));
const apiKey = config.arvan.api_key.replace(/^"|"$/g, '');
const arvanApiBaseUrl = config.arvan.api_base_url.replace(/^"|"$/g, '');

router.post('/', upload.single('videoFile'), async (req, res) => {
  try {
    if (!req.file) {
      console.log('[ERROR] No file received from client.');
      return res.status(400).json({ success: false, message: 'خطا در آپلود فایل.' });
    }
    const { title, description, channelId } = req.body;
    if (!title || !channelId) {
      console.log('[ERROR] Title or channelId missing.');
      return res.status(400).json({ success: false, message: 'عنوان و کانال الزامی است.' });
    }
    const filePath = req.file.path;
    const fileName = req.file.originalname;
    const fileSize = req.file.size;
    const fileType = req.file.mimetype;
    console.log(`[STEP] File received: ${fileName} (${fileSize} bytes, ${fileType})`);

    // مرحله ۱: ایجاد فایل TUS در آروان
    const encode = str => Buffer.from(String(str)).toString('base64');
    const uploadMetadata = `filename ${encode(fileName)},filetype ${encode(fileType)}`;
    const initHeaders = {
      'Tus-Resumable': '1.0.0',
      'Upload-Length': fileSize,
      'Upload-Metadata': uploadMetadata,
      'Authorization': apiKey,
      'Accept': 'application/json',
    };
    const initResp = await axios.post(`${arvanApiBaseUrl}/channels/${channelId}/files`, '', {
      headers: initHeaders,
      maxRedirects: 0,
      validateStatus: status => status >= 200 && status < 400,
    });
    const tusLocation = initResp.headers['location'];
    if (!tusLocation) {
      console.log('[ERROR] No Location header received from Arvan.');
      throw new Error('خطا در مرحله اول TUS (ایجاد فایل): Location header یافت نشد.');
    }
    const fileId = path.basename(new URL(tusLocation).pathname);
    console.log(`[STEP] TUS file created in Arvan. fileId: ${fileId}`);

    // مرحله ۲: آپلود فایل با PATCH به آروان
    const patchHeaders = {
      'Tus-Resumable': '1.0.0',
      'Upload-Offset': 0,
      'Content-Type': 'application/offset+octet-stream',
      'Authorization': apiKey,
      'Accept': 'application/json',
    };
    const fileStream = fs.createReadStream(filePath);
    let uploaded = 0;
    fileStream.on('data', chunk => {
      uploaded += chunk.length;
      const percent = Math.round((uploaded / fileSize) * 100);
      if (percent % 10 === 0 || percent === 100) {
        console.log(`[UPLOAD] PATCH upload to Arvan: ${percent}% (${uploaded}/${fileSize} bytes)`);
      }
    });
    await axios.patch(`${arvanApiBaseUrl}/channels/${channelId}/files/${fileId}`, fileStream, {
      headers: patchHeaders,
      maxContentLength: Infinity,
      maxBodyLength: Infinity,
      validateStatus: status => status === 204,
    });
    console.log('[STEP] PATCH upload to Arvan complete.');

    // مرحله ۳: ثبت ویدیو
    const videoData = {
      title,
      description,
      file_id: fileId,
      convert_mode: 'auto',
    };
    const regResp = await axios.post(`${arvanApiBaseUrl}/channels/${channelId}/videos`, videoData, {
      headers: {
        'Authorization': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
    });
    if (!regResp.data?.data?.id) {
      console.log('[ERROR] Video registration failed:', regResp.data);
      throw new Error('خطا در مرحله سوم (ثبت ویدیو): ' + (regResp.data?.message || ''));
    }
    const videoId = regResp.data.data.id;
    console.log(`[STEP] Video registered in Arvan. videoId: ${videoId}`);
    // Log video status object
    console.log('[INFO] Video status object:', JSON.stringify(regResp.data.data, null, 2));
    res.json({ success: true, video_id: videoId, data: regResp.data.data });
  } catch (e) {
    console.log('[ERROR] Exception:', e.message);
    res.status(400).json({ success: false, message: e.message });
  } finally {
    if (req.file) fs.unlink(req.file.path, () => {});
  }
});

module.exports = router; 