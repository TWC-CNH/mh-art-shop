// Minimaler Stripe-Ersatz für den End-to-End-Test (nur die Endpunkte, die der Shop nutzt).
import http from 'node:http';

function parseForm(body) {
  const out = {};
  for (const [k, v] of new URLSearchParams(body)) {
    const path = k.replace(/\]/g, '').split('[');
    let o = out;
    path.forEach((p, i) => {
      if (i === path.length - 1) o[p] = v;
      else o = o[p] ??= /^\d+$/.test(path[i + 1]) ? [] : {};
    });
  }
  return out;
}

export function startFakeStripe(port = 12111) {
  const products = new Map();
  const prices = new Map();
  const sessions = new Map();
  const log = [];
  let n = 0;
  const id = (p) => `${p}_${(++n).toString().padStart(6, '0')}TEST`;

  const withPrice = (p, expand) => (expand && p.default_price ? { ...p, default_price: prices.get(p.default_price) } : p);

  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x');
    let body = '';
    for await (const c of req) body += c;
    const params = req.method === 'GET' ? Object.fromEntries(url.searchParams) : parseForm(body);
    const expand = [...url.searchParams.keys(), ...Object.keys(params)].some((k) => k.startsWith('expand')) || body.includes('expand');
    log.push({ method: req.method, path: url.pathname, params });
    const send = (code, obj) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };
    const seg = url.pathname.split('/').filter(Boolean); // v1, products, id
    try {
      if (seg[1] === 'products' && req.method === 'GET' && !seg[2]) {
        const data = [...products.values()].filter((p) => params.active === undefined || String(p.active) === params.active).map((p) => withPrice(p, true));
        return send(200, { object: 'list', data, has_more: false, url: '/v1/products' });
      }
      if (seg[1] === 'products' && req.method === 'POST' && !seg[2]) {
        const p = { id: id('prod'), object: 'product', active: true, name: params.name, description: params.description || null, images: params.images || [], metadata: params.metadata || {}, default_price: null, created: Math.floor(Date.now() / 1000) };
        if (params.default_price_data) { const pr = { id: id('price'), object: 'price', product: p.id, currency: params.default_price_data.currency, unit_amount: Number(params.default_price_data.unit_amount) }; prices.set(pr.id, pr); p.default_price = pr.id; }
        products.set(p.id, p);
        return send(200, p);
      }
      if (seg[1] === 'products' && seg[2]) {
        const p = products.get(seg[2]);
        if (!p) return send(404, { error: { type: 'invalid_request_error', message: 'No such product' } });
        if (req.method === 'POST') {
          for (const [k, v] of Object.entries(params.metadata || {})) { if (v === '') delete p.metadata[k]; else p.metadata[k] = v; }
          for (const k of ['name', 'description', 'default_price']) if (params[k] !== undefined) p[k] = params[k];
          if (params.images !== undefined) p.images = params.images === '' ? [] : params.images;
          if (params.active !== undefined) p.active = params.active === 'true';
        }
        return send(200, withPrice(p, expand));
      }
      if (seg[1] === 'prices' && req.method === 'POST' && seg[2]) {
        const pr = prices.get(seg[2]);
        if (!pr) return send(404, { error: { type: 'invalid_request_error', message: 'No such price' } });
        if (params.active !== undefined) pr.active = params.active === 'true';
        return send(200, pr);
      }
      if (seg[1] === 'prices' && req.method === 'POST') {
        const pr = { id: id('price'), object: 'price', product: params.product, currency: params.currency, unit_amount: Number(params.unit_amount) };
        prices.set(pr.id, pr);
        return send(200, pr);
      }
      if (seg[1] === 'checkout' && seg[2] === 'sessions' && req.method === 'POST' && !seg[3]) {
        const s = { id: id('cs_test'), object: 'checkout.session', status: 'open', payment_status: 'unpaid', url: `https://checkout.stripe.test/pay/${n}`, metadata: params.metadata || {}, expires_at: Number(params.expires_at), amount_total: null, params };
        const li = params.line_items?.[0];
        const pr = prices.get(li?.price);
        if (!pr) return send(400, { error: { type: 'invalid_request_error', message: 'No such price' } });
        s.amount_total = pr.unit_amount + Number(params.shipping_options?.[0]?.shipping_rate_data?.fixed_amount?.amount || 0);
        sessions.set(s.id, s);
        return send(200, s);
      }
      if (seg[1] === 'checkout' && seg[2] === 'sessions' && seg[3]) {
        const s = sessions.get(seg[3]);
        if (!s) return send(404, { error: { type: 'invalid_request_error', message: 'No such session' } });
        if (seg[4] === 'expire') s.status = 'expired';
        return send(200, s);
      }
      send(404, { error: { type: 'invalid_request_error', message: `Unbekannt: ${req.method} ${url.pathname}` } });
    } catch (e) {
      send(500, { error: { type: 'api_error', message: e.message } });
    }
  });
  return new Promise((r) => server.listen(port, () => r({ server, products, prices, sessions, log, close: () => server.close() })));
}
