const http = require('http');
const fs = require('fs');
const path = require('path');

process.chdir(__dirname);
const config = require('./.next/required-server-files.json').config;
process.env.__NEXT_PRIVATE_STANDALONE_CONFIG = JSON.stringify(config);
const app = require('next')({ dev: false, dir: __dirname, conf: config });
const socket = path.join(__dirname, '../storefront.sock');

app.prepare().then(() => {
  if (fs.existsSync(socket)) fs.unlinkSync(socket);
  http.createServer(app.getRequestHandler()).listen(socket, () => {
    fs.chmodSync(socket, 0o600);
    console.log('Dropbell socket ready');
  });
}).catch(error => { console.error(error); process.exit(1); });
