// Lokaler Testserver ohne Netlify-CLI: liefert public/ aus und ruft die Functions direkt auf.
// Start: npm run dev  (optional mit STRIPE_SECRET_KEY=sk_test_... für echten Testmodus)
import http from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { readdirSync, existsSync, readFileSync } from 'node:fs';

const root = fileURLToPath(new URL('..', import.meta.url));
const PORT = Number(process.env.PORT || 8888);

// .env laden (einfach, ohne Abhängigkeit)
if (existsSync(join(root, '.env'))) {
  for (const line of readFileSync(join(root, '.env'), 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && !process.env[m[1]]) process.env[m[1]] = m[2].replace(/^['"]|['"]$/g, '');
  }
}
process.env.SITE_URL ||= `http://localhost:${PORT}`;

const routes = [];
for (const f of readdirSync(join(root, 'netlify/functions')).filter((f) => f.endsWith('.mjs'))) {
  const mod = await import(pathToFileURL(join(root, 'netlify/functions', f)).href);
  const paths = [].concat(mod.config?.path || []);
  for (const p of paths) {
    const re = new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '/?$');
    routes.push({ re, handler: mod.default, preferStatic: mod.config?.preferStatic });
  }
}

const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.txt': 'text/plain', '.json': 'application/json' };

async function serveStatic(pathname, res) {
  const file = normalize(join(root, 'public', decodeURIComponent(pathname)));
  if (!file.startsWith(join(root, 'public'))) return false;
  try {
    const st = await stat(file);
    if (!st.isFile()) return false;
    res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream' });
    res.end(await readFile(file));
    return true;
  } catch { return false; }
}

http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);
  // Netlify Forms lokal nachstellen
  if (req.method === 'POST' && url.pathname === '/__forms.html') {
    let body = '';
    for await (const c of req) body += c;
    const data = Object.fromEntries(new URLSearchParams(body));
    if (data['bot-field']) { res.writeHead(200); res.end('ok'); return; }
    console.log('Formular-Eingang (lokal simuliert):', data);
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end('ok');
    return;
  }
  if (url.pathname !== '/' && (await serveStatic(url.pathname, res))) return;
  const route = routes.find((r) => r.re.test(url.pathname));
  if (!route) { res.writeHead(404); res.end('Not found'); return; }
  const chunks = [];
  for await (const c of req) chunks.push(c);
  const body = chunks.length ? Buffer.concat(chunks) : undefined;
  const request = new Request(url, { method: req.method, headers: req.headers, body: ['GET', 'HEAD'].includes(req.method) ? undefined : body });
  try {
    const out = await route.handler(request, {});
    const headers = {};
    out.headers.forEach((v, k) => { headers[k] = v; });
    res.writeHead(out.status, headers);
    res.end(Buffer.from(await out.arrayBuffer()));
  } catch (e) {
    console.error(e);
    res.writeHead(500); res.end('Fehler: ' + e.message);
  }
}).listen(PORT, () => console.log(`MH ART Shop läuft auf http://localhost:${PORT} (${process.env.STRIPE_SECRET_KEY ? 'Stripe ' + (process.env.STRIPE_SECRET_KEY.startsWith('sk_live') ? 'LIVE' : 'Testmodus') : 'Demo-Modus ohne Stripe'})`));
