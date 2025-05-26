const express = require('express');
const fs = require('fs');
const path = require('path');
const axios = require('axios');
const ini = require('ini');

const router = express.Router();

// Read config.ini
const config = ini.parse(fs.readFileSync(path.join(__dirname, '../config.ini'), 'utf-8'));
const apiKey = config.arvan.api_key.replace(/^"|"$/g, '');
const arvanApiBaseUrl = config.arvan.api_base_url.replace(/^"|"$/g, '');

router.get('/', async (req, res) => {
  try {
    const arvanResp = await axios.get(`${arvanApiBaseUrl}/channels`, {
      headers: {
        'Authorization': apiKey,
        'Accept': 'application/json',
      },
    });
    if (arvanResp.status !== 200) throw new Error('خطا در دریافت لیست کانال‌ها: ' + arvanResp.data);
    res.json(arvanResp.data);
  } catch (e) {
    res.status(400).json({ success: false, message: e.message });
  }
});

module.exports = router; 