// Minimal stand-in for Vercel's static serving, for local previews and Lighthouse runs:
// the headers rules from <root>/vercel.json (so the CSP is enforced), brotli/gzip compression,
// trailing-slash redirects, dir/ -> dir/index.html, and missing paths -> /404.html with 404.
// Usage: node bin/serve-dist.js [root=dist] [port=8099]  (copied from jmcastillo-portfolio)
const http = require('http'), fs = require('fs'), path = require('path'), zlib = require('zlib');
const root = path.resolve(process.argv[2] || 'dist'), port = Number(process.argv[3] || 8099);
const types = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json',
  '.xml': 'application/xml', '.xsl': 'text/xsl', '.txt': 'text/plain', '.svg': 'image/svg+xml', '.webp': 'image/webp',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2', '.ico': 'image/x-icon', '.pdf': 'application/pdf' };
const compressible = /^(text\/|application\/(json|xml)|image\/svg)/;

// vercel.json "source" patterns here are plain prefixes plus "(.*)", so a regex is enough.
let rules = [];
try {
  rules = (JSON.parse(fs.readFileSync(path.join(root, 'vercel.json'), 'utf8')).headers || [])
    .map(r => ({ re: new RegExp('^' + r.source + '$'), headers: r.headers }));
} catch (e) { console.warn('no usable vercel.json in ' + root + ': serving without its headers'); }

http.createServer((req, res) => {
  const rel = decodeURIComponent(req.url.split('?')[0]);
  // Vercel redirects /page to /page/ (trailingSlash: true), except for files.
  if (!rel.endsWith('/') && !path.extname(rel)) { res.writeHead(308, { Location: rel + '/' }); return res.end(); }
  let file = path.join(root, rel);
  if (!file.startsWith(root)) { res.writeHead(403); return res.end(); }
  if (rel.endsWith('/')) file = path.join(file, 'index.html');
  let status = 200;
  // vercel.json is configuration, never served.
  if (rel === '/vercel.json' || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { file = path.join(root, '404.html'); status = 404; }
  const type = types[path.extname(file)] || 'application/octet-stream';
  const headers = { 'Content-Type': type };
  for (const rule of rules) if (rule.re.test(rel)) for (const h of rule.headers) headers[h.key] = h.value;
  let body = fs.readFileSync(file);
  const accept = req.headers['accept-encoding'] || '';
  if (compressible.test(type)) {
    if (accept.includes('br')) { body = zlib.brotliCompressSync(body); headers['Content-Encoding'] = 'br'; }
    else if (accept.includes('gzip')) { body = zlib.gzipSync(body); headers['Content-Encoding'] = 'gzip'; }
  }
  headers['Content-Length'] = body.length;
  res.writeHead(status, headers);
  res.end(body);
}).listen(port, '127.0.0.1', () => console.log(`serving ${root} on http://127.0.0.1:${port}`));
