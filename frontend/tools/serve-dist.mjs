import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join } from 'node:path';
const DIST = new URL('../dist/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.ico': 'image/x-icon' };
const server = createServer(async (req, res) => {
  try {
    if (req.url.startsWith('/api/')) {
      const body = req.method === 'GET' || req.method === 'HEAD' ? undefined : req;
      const upstream = await fetch('http://127.0.0.1:8080' + req.url, { method: req.method, headers: { 'content-type': req.headers['content-type'] ?? 'application/json', authorization: req.headers.authorization ?? '' }, body });
      res.writeHead(upstream.status, { 'content-type': upstream.headers.get('content-type') ?? 'application/json' });
      res.end(Buffer.from(await upstream.arrayBuffer()));
      return;
    }
    const path = decodeURIComponent(req.url.split('?')[0]);
    const target = join(DIST, path === '/' ? '/index.html' : path);
    const data = await readFile(target);
    res.writeHead(200, { 'Content-Type': MIME[extname(target)] ?? 'application/octet-stream' });
    res.end(data);
  } catch {
    const data = await readFile(join(DIST, 'index.html'));
    res.writeHead(200, { 'Content-Type': MIME['.html'] });
    res.end(data);
  }
});
server.listen(4173, () => console.log('ready'));
