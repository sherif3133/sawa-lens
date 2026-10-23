/* Static server for the /app phone harness: serves sawa-lens/ on http://localhost:9420 (no caching).
   node sawa-lens/tools/harness/serve.js  →  open http://localhost:9420/tools/harness/#review */
const http = require('http');
const fs = require('fs');
const path = require('path');
const ROOT = path.resolve(__dirname, '../..');
const PORT = +process.env.PORT || 9420;
const TYPES = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.wav': 'audio/wav' };

http.createServer((req, res) => {
	let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
	if (p.endsWith('/')) p += 'index.html';
	const f = path.join(ROOT, p);
	if (!f.startsWith(ROOT)) { res.writeHead(403); return res.end(); }
	fs.readFile(f, (err, data) => {
		if (err) { res.writeHead(404); return res.end('not found'); }
		res.writeHead(200, { 'Content-Type': (TYPES[path.extname(f)] || 'application/octet-stream') + '; charset=utf-8', 'Cache-Control': 'no-store' });
		res.end(data);
	});
}).listen(PORT, '127.0.0.1', () => console.log('harness on http://localhost:' + PORT + '/tools/harness/'));
