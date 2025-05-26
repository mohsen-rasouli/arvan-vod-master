const express = require('express');
const bodyParser = require('body-parser');
const path = require('path');
const uploadProxy = require('./upload-proxy');
const videoStatusProxy = require('./video-status-proxy');
const channelsProxy = require('./channels-proxy');
const cors = require('cors');

const app = express();
const PORT = process.env.PORT || 3001;

app.use(cors());
app.use(bodyParser.json());
app.use(bodyParser.urlencoded({ extended: true }));

app.use('/upload-proxy', uploadProxy);
app.use('/video-status-proxy', videoStatusProxy);
app.use('/channels-proxy', channelsProxy);

app.get('/upload', (req, res) => {
  res.sendFile(path.join(__dirname, 'video-uploader.html'));
});

app.get('/', (req, res) => {
  res.send('Arvan VOD Node.js backend is running.');
});

app.listen(PORT, () => {
  console.log(`Server running on http://localhost:${PORT}`);
}); 