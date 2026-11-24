// Serverseitiges Rendering aller Seiten (kein Framework, reines HTML).
import { getConfig, FORMAT_LABELS } from './config.mjs';
import { euro, shippingFor } from './catalog.mjs';
import { ARTIST, EXHIBITIONS, AWARDS } from './artist.mjs';

export const esc = (s) =>
  String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const ASSET_V = '15';

const isLocalImg = (src, cfg = getConfig()) => !!src && /\.jpe?g$/i.test(src) && (src.startsWith('/img/') || src.startsWith(cfg.siteUrl + '/img/'));
// Im Atelier hochgeladene Fotos (Netlify Blobs) – liegen in 1600/720/420 px als JPG vor
const isAtelierImg = (src, cfg = getConfig()) => !!src && /\/bilder\/[a-z0-9-]+\.jpg$/i.test(src) && (src.startsWith('/bilder/') || src.startsWith(cfg.siteUrl + '/bilder/'));

// <picture> mit WebP für eigene Bilder (/img/…), srcset für Atelier-Fotos, normales <img> für sonstige URLs.
export function pic(src, attrs = '', sizes = '') {
  if (isAtelierImg(src)) {
    const stem = src.replace(/(-sm|-xs)?\.jpg$/i, '');
    return sizes
      ? `<img src="${esc(src)}" srcset="${esc(`${stem}-xs.jpg 420w, ${stem}-sm.jpg 720w, ${stem}.jpg 1600w`)}" sizes="${esc(sizes)}" ${attrs}>`
      : `<img src="${esc(src)}" ${attrs}>`;
  }
  const img = `<img src="${esc(src)}" ${attrs}>`;
  if (!isLocalImg(src)) return img;
  // Varianten: -xs (420 px), -sm (720 px), Original (1600 px)
  const stem = src.replace(/(-sm|-xs)?\.jpe?g$/i, '');
  const set = sizes ? `${stem}-xs.webp 420w, ${stem}-sm.webp 720w, ${stem}.webp 1600w` : src.replace(/\.jpe?g$/i, '.webp');
  return `<picture><source type="image/webp" srcset="${esc(set)}"${sizes ? ` sizes="${esc(sizes)}"` : ''}>${img}</picture>`;
}

// Gleiche Bildquelle als einfache URL in passender Größe (für JS-Umschaltung, Lightbox, SVG)
const sized = (src, size = '') => (isLocalImg(src) || isAtelierImg(src) ? src.replace(/(-sm|-xs)?\.jpe?g$/i, `${size}.jpg`) : src);

function absUrl(cfg, path) {
  if (!path) return '';
  return /^https?:\/\//.test(path) ? path : cfg.siteUrl + path;
}

export function layout({ title, description = '', body, canonical = '/', ogImage = '', jsonLd = null, bodyClass = '', noindex = false, preload = '', preloadSizes = '(max-width: 1020px) 92vw, 60vw' }) {
  const cfg = getConfig();
  const fullTitle = title ? `${title} | ${cfg.artistName}` : `${cfg.artistName} · ${cfg.shopName} – Originale kaufen`;
  const desc = description || `Signierte Originalgemälde von ${cfg.artistName}. ${cfg.tagline}: figurative Malerei in Schwarz-Weiß mit Neon- und UV-reaktiven Farben. Direkt aus dem Atelier, mit Echtheitszertifikat.`;
  return `<!doctype html>
<html lang="de" data-theme="day">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(fullTitle)}</title>
<meta name="description" content="${esc(desc)}">
<link rel="canonical" href="${esc(cfg.siteUrl + canonical)}">
${noindex ? '<meta name="robots" content="noindex">' : ''}
<meta property="og:type" content="website">
<meta property="og:title" content="${esc(fullTitle)}">
<meta property="og:description" content="${esc(desc)}">
<meta property="og:url" content="${esc(cfg.siteUrl + canonical)}">
${ogImage ? `<meta property="og:image" content="${esc(absUrl(cfg, ogImage))}">` : ''}
<meta name="theme-color" content="#ffffff">
<link rel="icon" href="/favicon.png" sizes="32x32">
<link rel="icon" href="/assets/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<link rel="preload" href="/fonts/archivo-latin-wdth-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/newsreader-latin-opsz-italic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/shop.css?v=${ASSET_V}">
${preload ? `<link rel="preload" as="image" type="image/webp" imagesrcset="${esc(preload)}" imagesizes="${esc(preloadSizes)}" fetchpriority="high">` : ''}
<script>try{if(localStorage.getItem('mh-theme')==='uv')document.documentElement.dataset.theme='uv'}catch(e){}</script>
${jsonLd ? `<script type="application/ld+json">${JSON.stringify(jsonLd).replace(/</g, '\\u003c')}</script>` : ''}
</head>
<body class="${esc(bodyClass)}">
<a class="skip" href="#main">Zum Inhalt springen</a>
<header class="site-header">
  <a class="brand" href="/" aria-label="${esc(cfg.artistName)} – ${esc(cfg.shopName)} Startseite">
    <img class="logo logo--day" src="/assets/logo-mhart.png" alt="" width="126" height="60">
    <img class="logo logo--uv" src="/assets/logo-mhart-light.png" alt="" width="126" height="60" aria-hidden="true">
    <span class="brand-name">${esc(cfg.artistName)}</span>
  </a>
  <nav class="site-nav" id="site-nav" aria-label="Hauptnavigation">
    <a href="/#werke">Werke</a>
    <a href="/#schwarzlicht">Schwarzlicht</a>
    <a href="/#kuenstler">Künstler</a>
    <a href="/#kaufen">Kaufen</a>
  </nav>
  <a class="fav-link" href="/?filter=merkliste#werke" hidden data-fav-link aria-label="Merkliste">♡ <span data-fav-count>0</span></a>
  <button class="uv-toggle" type="button" aria-pressed="false" aria-describedby="uv-tip">
    <span class="uv-switch" aria-hidden="true"><span></span></span><span class="uv-label">UV-Licht</span>
  </button>
  <button class="menu-btn" type="button" aria-expanded="false" aria-controls="site-nav" data-menu><span aria-hidden="true"></span><span class="sr-only">Menü</span></button>
  <p class="uv-tip" id="uv-tip" role="status">Schwarzlicht an: Werke mit UV-Aufnahme zeigen jetzt, wie sie im Dunkeln leuchten.</p>
</header>
<main id="main">
${body}
</main>
<footer class="site-footer">
  <p class="wordmark" aria-hidden="true"><span>Matthias</span> <span>Hübner<em>.</em></span></p>
  <div class="footer-grid">
    <div>
      <img class="logo logo--day" src="/assets/logo-mhart.png" alt="${esc(cfg.shopName)}" width="105" height="50">
      <img class="logo logo--uv" src="/assets/logo-mhart-light.png" alt="" width="105" height="50" aria-hidden="true">
      <p class="muted">${esc(cfg.artistName)} · ${esc(cfg.tagline)}<br>Atelier im Raum Leipzig</p>
    </div>
    <div>
      <p class="eyebrow">Kontakt</p>
      <p><a href="mailto:${esc(cfg.contactEmail)}">${esc(cfg.contactEmail)}</a><br>
      <a href="${esc(cfg.instagram)}" rel="noopener" target="_blank">Instagram</a> · <a href="${esc(cfg.facebook)}" rel="noopener" target="_blank">Facebook</a><br>
      <a href="${esc(cfg.mainSite)}" rel="noopener" target="_blank">novaspes.de</a></p>
    </div>
    <div>
      <p class="eyebrow">Rechtliches</p>
      <p><a href="/impressum">Impressum</a><br><a href="/agb">AGB</a><br><a href="/widerruf">Widerrufsbelehrung</a><br><a href="/versand">Versand &amp; Zahlung</a><br><a href="/datenschutz">Datenschutz</a>${cfg.googleTagId ? '<br><button type="button" class="linklike" data-consent-open>Cookie-Einstellungen</button>' : ''}</p>
    </div>
  </div>
  <p class="withdraw-cta"><a class="btn-withdraw" href="/vertrag-widerrufen">Vertrag widerrufen</a><span>Online gekauft? Hier können Sie innerhalb der Widerrufsfrist widerrufen.</span></p>
  <p class="fine">${cfg.kleinunternehmer ? 'Alle Preise sind Endpreise. Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' : 'Alle Preise inkl. gesetzlicher Umsatzsteuer.'} Zzgl. <a href="/versand">Versandkosten</a>. Sichere Zahlung über Stripe. Raumansichten sind Visualisierungen.</p>
  ${cfg.creditUrl ? `<p class="credit">Realisiert mit Unterstützung von <a href="${esc(cfg.creditUrl)}" target="_blank" rel="noopener">${esc(cfg.creditLabel)}</a></p>` : ''}
</footer>
${inquiryDialog(cfg)}
<p class="toast" role="status" aria-live="polite" hidden></p>
${consentBanner(cfg)}
<script src="/assets/shop.js?v=${ASSET_V}" defer></script>
</body>
</html>`;
}

function consentBanner(cfg) {
  if (!cfg.googleTagId) return '';
  return `<div class="consent" hidden data-tag="${esc(cfg.googleTagId)}">
  <p>Ich nutze Google-Dienste, um zu messen, ob meine Anzeigen funktionieren. Das passiert nur mit Ihrer Einwilligung. <a href="/datenschutz">Mehr erfahren</a></p>
  <div class="consent-actions"><button type="button" class="btn btn--ghost" data-consent="deny">Ablehnen</button><button type="button" class="btn" data-consent="grant">Einverstanden</button></div>
</div>`;
}

const priceLabel = (w) => (w.priceCents ? euro(w.priceCents) : 'Preis auf Anfrage');

function statusBadge(w) {
  if (w.status === 'sold') return '<span class="badge badge--sold">Verkauft</span>';
  if (w.status === 'reserved') return '<span class="badge badge--reserved">Reserviert</span>';
  return '';
}

// Bild so in die 4:5-"Wand" einpassen, dass es nie übersteht (Breite in % der Karte).
function fitPct(w) {
  if (!w.w || !w.h) return 70;
  const r = w.w / w.h;
  return r >= 0.8 ? 78 : 78 * (r / 0.8);
}

// Tageslicht / Schwarzlicht als Vergleichs-Regler (Hero, Schwarzlicht-Sektion, Werkseite)
function uvCompare(w, { sizes = '(max-width: 860px) 92vw, 46vw', priority = false, label = true } = {}) {
  if (!w?.uvImage) return '';
  return `<div class="cmp" data-cmp style="--pos:50%">
    ${pic(w.image, `class="cmp-day" alt="${esc(w.title)} bei Tageslicht" ${w.w ? `width="${w.w}" height="${w.h}"` : ''} ${priority ? 'fetchpriority="high"' : 'loading="lazy"'}`, sizes)}
    <div class="cmp-uv">${pic(w.uvImage, `alt="${esc(w.title)} unter Schwarzlicht" ${priority ? 'fetchpriority="low"' : 'loading="lazy"'}`, sizes)}</div>
    <span class="cmp-line" aria-hidden="true"><span class="cmp-knob">↔</span></span>
    ${label ? '<span class="cmp-tag cmp-tag--day">Tageslicht</span><span class="cmp-tag cmp-tag--uv">Schwarzlicht</span>' : ''}
    <input class="cmp-range" type="range" min="0" max="100" value="50" aria-label="Regler: links Tageslicht, rechts Schwarzlicht">
  </div>`;
}

function priceCat(w) {
  if (!w.priceCents) return 'anfrage';
  const e = w.priceCents / 100;
  return e < 1000 ? 'bis1000' : e <= 2500 ? 'bis2500' : 'ab2500';
}

// Anfrage-Dialog (ein Formular für Preisanfrage, Frage, Warteliste, allgemeine Anfrage).
// Wird von Netlify Forms entgegengenommen; ohne JavaScript greift der normale mailto-Link.
function inquiryDialog(cfg) {
  return `<dialog class="inquiry" data-inquiry-dialog aria-labelledby="inq-h">
  <form method="post" action="/__forms.html" name="anfrage" data-inquiry-form>
    <input type="hidden" name="form-name" value="anfrage">
    <input type="hidden" name="anliegen" value="allgemein">
    <input type="hidden" name="werk" value="">
    <input type="hidden" name="seite" value="">
    <p class="visually-hidden"><label>Nicht ausfüllen: <input name="bot-field" tabindex="-1" autocomplete="off"></label></p>
    <button type="button" class="inq-close" data-inquiry-close aria-label="Schließen">×</button>
    <p class="eyebrow" data-inq-eyebrow>Anfrage</p>
    <h2 id="inq-h" class="display" data-inq-title>Schreiben Sie mir</h2>
    <p class="inq-sub" data-inq-sub>Ich antworte in der Regel innerhalb eines Tages.</p>
    <div class="inq-grid">
      <label class="field"><span>Name</span><input name="name" required autocomplete="name"></label>
      <label class="field"><span>E-Mail</span><input name="email" type="email" required autocomplete="email"></label>
      <label class="field field--wide"><span>Telefon <em>(optional)</em></span><input name="telefon" type="tel" autocomplete="tel"></label>
      <label class="field field--wide"><span>Nachricht</span><textarea name="nachricht" rows="5" required></textarea></label>
    </div>
    <label class="check"><input type="checkbox" name="datenschutz" value="ja" required><span>Ich bin einverstanden, dass meine Angaben zur Beantwortung der Anfrage verarbeitet werden (<a href="/datenschutz" target="_blank">Datenschutz</a>).</span></label>
    <button class="btn btn--wide" type="submit">Anfrage senden <span aria-hidden="true">→</span></button>
    <p class="inq-alt">Lieber per E-Mail? <a href="mailto:${esc(cfg.contactEmail)}">${esc(cfg.contactEmail)}</a></p>
    <p class="inq-error" hidden data-inq-error>Das hat leider nicht geklappt. Bitte versuchen Sie es noch einmal oder schreiben Sie direkt an ${esc(cfg.contactEmail)}.</p>
  </form>
  <div class="inq-done" hidden data-inq-done>
    <p class="eyebrow">Gesendet</p>
    <h2 class="display">Danke für Ihre <em class="accent">Nachricht.</em></h2>
    <p>Ich melde mich so bald wie möglich bei Ihnen.</p>
    <button type="button" class="btn btn--ghost" data-inquiry-close>Schließen</button>
  </div>
</dialog>`;
}

function card(w, i) {
  const pct = fitPct(w).toFixed(1);
  const award = w.awards?.length ? '<span class="tag tag--award">Ausgezeichnet</span>' : '';
  const uvTag = w.uvImage ? '<span class="tag tag--uv" title="Mit Aufnahme unter Schwarzlicht">UV</span>' : '';
  return `<article class="card${w.uvImage ? ' has-uv' : ''}" data-format="${esc(w.format)}" data-status="${esc(w.status)}" data-price="${w.priceCents ?? ''}" data-series="${esc(w.series)}" data-uv="${w.uvImage ? 'ja' : 'nein'}" data-slug="${esc(w.slug)}" data-pricecat="${priceCat(w)}">
  <a href="/werk/${esc(w.slug)}" class="card-link">
    <div class="card-img">
      <span class="art" style="width:${pct}%">
        ${pic(w.thumb, `class="art-day" alt="${esc(w.title)} – ${esc(w.technique)}, ${esc(w.size)}" loading="${i < 4 ? 'eager' : 'lazy'}" decoding="async" ${w.w ? `width="${w.w}" height="${w.h}"` : ''}`, '(max-width: 560px) 44vw, (max-width: 1020px) 30vw, 250px')}
        ${w.uvImage ? pic(sized(w.uvImage, '-sm'), 'class="art-uv" alt="" aria-hidden="true" loading="lazy" decoding="async"', '(max-width: 560px) 44vw, (max-width: 1020px) 30vw, 250px') : ''}
      </span>
      ${statusBadge(w)}
      <span class="tags">${uvTag}${award}</span>
    </div>
    <div class="card-meta">
      <h3 class="card-title">${esc(w.title)}${w.year ? ` <span class="year">${w.year}</span>` : ''}</h3>
      <p class="card-sub">${esc(w.size)}</p>
      <p class="card-price">${w.status === 'sold' ? 'Privatsammlung' : esc(priceLabel(w))}</p>
    </div>
  </a>
  <span class="card-fav"><button type="button" class="fav-btn" data-fav="${esc(w.slug)}" aria-pressed="false" aria-label="${esc(w.title)} merken" title="Merken">♡</button></span>
</article>`;
}

// Akzentwort: Serif kursiv mit gesetztem Neon-Farbstrich (Motiv aus seinen Bildern)
const strich = (word, color = 'yellow') => `<em class="accent strich strich--${color}">${word}</em>`;
const idx = (n, label) => `<p class="eyebrow eyebrow--idx"><span class="idx">${n}</span>${label}</p>`;

export function renderHome(works) {
  const cfg = getConfig();
  const available = works.filter((w) => w.status !== 'sold');
  const uvWorks = works.filter((w) => w.uvImage && w.status !== 'sold');
  const hero = uvWorks.find((w) => w.slug === 'nacht') || uvWorks[0] || available[0] || works[0];
  const series = [...new Set(works.map((w) => w.series).filter(Boolean))];
  const formats = ['klein', 'mittel', 'gross'].filter((f) => works.some((w) => w.format === f));
  const today = new Date().toISOString().slice(0, 10);
  const upcoming = EXHIBITIONS.filter((e) => (e.end || e.start) >= today);
  const past = EXHIBITIONS.filter((e) => (e.end || e.start) < today).slice(0, 8);
  const bySlug = Object.fromEntries(works.map((w) => [w.slug, w]));
  const workLink = (slug, text) => (bySlug[slug] ? `<a href="/werk/${esc(slug)}">${esc(text)}</a>` : esc(text));
  const featuredUv = uvWorks.filter((w) => w.slug !== hero?.slug).slice(0, 8);

  const body = `
<section class="hero">
  <div class="hero-copy">
    <p class="eyebrow eyebrow--idx"><span class="idx">MH</span>${esc(cfg.tagline)}</p>
    <h1 class="display hero-title"><span class="line">Schwarz-Weiß</span> <span class="line">ist das ${strich('Fundament.', 'yellow')}</span> <span class="line">Neon ist der</span> <span class="line">${strich('Kontrast.', 'magenta')}</span></h1>
    <p class="lead">Figurative Malerei von ${esc(cfg.artistName)}, entstanden in der Nacht. Viele Werke tragen UV-reaktive Farben und beginnen unter Schwarzlicht ein zweites Mal zu leuchten.</p>
    <div class="hero-actions"><a class="btn" href="#werke">${available.length} Originale ansehen</a><a class="btn btn--ghost" href="#schwarzlicht">Im Schwarzlicht sehen</a></div>
    <ul class="hero-facts">
      <li><strong>Unikate</strong>signiert, mit Echtheitszertifikat</li>
      <li><strong>Ausgezeichnet</strong>u. a. New York, Luxemburg</li>
      <li><strong>Ausgestellt</strong>Paris · Rom · Madrid · Málaga</li>
    </ul>
  </div>
  <figure class="hero-art">
    ${hero?.uvImage ? uvCompare(hero, { priority: true, sizes: '(max-width: 860px) 92vw, 44vw' }) : hero ? pic(hero.image, `alt="${esc(hero.title)}" fetchpriority="high"`, '(max-width: 860px) 92vw, 44vw') : ''}
    ${hero ? `<figcaption><a href="/werk/${esc(hero.slug)}">${esc(hero.title)}${hero.year ? `, ${hero.year}` : ''}</a> · ${esc(hero.technique)} · ${esc(hero.size)}${hero.uvImage ? '<span class="hint-drag">Regler ziehen: Tageslicht ↔ Schwarzlicht</span>' : ''}</figcaption>` : ''}
  </figure>
</section>

<div class="ticker" aria-hidden="true"><div class="ticker-track">${Array(2).fill(ARTIST.ticker.map((t) => `<span>${esc(t)}</span>`).join('<span class="dot">●</span>') + '<span class="dot">●</span>').join('')}</div></div>

<section class="works" id="werke" aria-labelledby="werke-h">
  <div class="section-head">
    <div>
      ${idx('01', 'Shop')}
      <h2 id="werke-h" class="display">Verfügbare ${strich('Originale', 'cyan')}</h2>
    </div>
    <div class="filters" role="group" aria-label="Werke filtern">
      <button type="button" class="chip is-active" data-filter="all">Alle</button>
      <button type="button" class="chip" data-filter="status:available">Verfügbar</button>
      <button type="button" class="chip chip--uv" data-filter="uv:ja">Mit UV-Aufnahme</button>
      ${formats.map((f) => `<button type="button" class="chip" data-filter="format:${f}">${esc(FORMAT_LABELS[f].split(' (')[0])}</button>`).join('')}
      ${series.map((s) => `<button type="button" class="chip" data-filter="series:${esc(s)}">Serie ${esc(s)}</button>`).join('')}
      <button type="button" class="chip" data-filter="pricecat:bis1000">bis 1.000 €</button>
      <button type="button" class="chip" data-filter="pricecat:bis2500">1.000–2.500 €</button>
      <button type="button" class="chip" data-filter="pricecat:ab2500">ab 2.500 €</button>
      <button type="button" class="chip chip--fav" data-filter="merkliste" hidden data-fav-chip>♡ Merkliste</button>
      <label class="sort"><span class="visually-hidden">Sortieren</span>
        <select data-sort>
          <option value="default">Empfohlen</option>
          <option value="price-asc">Preis aufsteigend</option>
          <option value="price-desc">Preis absteigend</option>
        </select>
      </label>
    </div>
  </div>
  <div class="grid" data-grid>
    ${works.map(card).join('\n')}
  </div>
  <p class="empty" hidden data-empty>Für diese Auswahl ist gerade kein Werk da. <button type="button" class="linklike" data-filter="all">Alle Werke zeigen</button></p>
</section>

${featuredUv.length ? `<section class="blacklight" id="schwarzlicht" aria-labelledby="sl-h" data-blacklight>
  <div class="bl-inner">
    <div class="bl-copy">
      ${idx('02', 'Schwarzlicht')}
      <h2 id="sl-h" class="display">Erst im Dunkeln ${strich('vollständig.', 'magenta')}</h2>
      <p>${esc(cfg.artistName)} malt mit Farben, die auf UV-Licht reagieren. Unter Schwarzlicht verschwindet das Tageslicht-Bild, und eine zweite Ebene tritt hervor. Wählen Sie ein Werk und ziehen Sie den Regler.</p>
      <div class="bl-thumbs">
        ${[hero, ...featuredUv].filter(Boolean).map((w, i) => `<button type="button" class="bl-thumb${i === 0 ? ' is-active' : ''}" data-bl='${esc(JSON.stringify({ slug: w.slug, title: w.title, year: w.year, day: sized(w.image, '-sm'), uv: sized(w.uvImage, '-sm'), dayW: sized(w.image), uvW: sized(w.uvImage), price: w.status === 'sold' ? 'Verkauft' : priceLabel(w) }))}' aria-label="${esc(w.title)} im Vergleich zeigen">${pic(sized(w.uvImage, '-xs'), 'alt="" loading="lazy"')}</button>`).join('')}
      </div>
      <p class="bl-meta" data-bl-meta>${hero ? `<a href="/werk/${esc(hero.slug)}">${esc(hero.title)}</a> · ${esc(hero.status === 'sold' ? 'Verkauft' : priceLabel(hero))}` : ''}</p>
    </div>
    <div class="bl-stage">${hero ? uvCompare(hero, { sizes: '(max-width: 860px) 92vw, 50vw' }) : ''}</div>
  </div>
</section>` : ''}

<section class="artist" id="kuenstler" aria-labelledby="kuenstler-h">
  <figure class="artist-img">
    ${pic('/img/artist-night.jpg', `alt="Porträt ${esc(cfg.artistName)} in Schwarz-Weiß" loading="lazy" width="1400" height="1802"`)}
  </figure>
  <div class="artist-copy">
    ${idx('03', 'Der Künstler')}
    <h2 id="kuenstler-h" class="display">Matthias ${strich('Hübner', 'yellow')}</h2>
    ${ARTIST.bio.map((p) => `<p>${esc(p)}</p>`).join('')}
    <ol class="principles">
      ${ARTIST.principles.map((p) => `<li><strong>${esc(p.title)}</strong><span>${esc(p.text)}</span></li>`).join('')}
    </ol>
    <p><a class="text-link" href="${esc(cfg.mainSite)}/mhbio" rel="noopener" target="_blank">Vollständige Biografie auf novaspes.de</a></p>
  </div>
</section>

<section class="record" aria-labelledby="record-h">
  <div class="record-head">
    ${idx('04', 'Werdegang')}
    <h2 id="record-h" class="display">Ausgestellt in Europa, ${strich('ausgezeichnet', 'cyan')} in New York.</h2>
  </div>
  <div class="record-col">
    <p class="eyebrow">Ausstellungen</p>
    ${upcoming.length ? `<h3 class="record-h">Demnächst</h3><ul class="timeline timeline--next">${upcoming.map((e) => `<li><span class="when">${esc(e.when)}</span><span class="what"><strong>${esc(e.title)}</strong>${esc(e.place)}${e.works ? ` · ${e.works.map((x) => workLink(x.slug, x.title)).join(', ')}` : ''}</span></li>`).join('')}</ul>` : ''}
    <h3 class="record-h">Zuletzt</h3>
    <ul class="timeline">${past.map((e) => `<li><span class="when">${esc(e.when)}</span><span class="what"><strong>${esc(e.title)}</strong>${esc(e.place)}</span></li>`).join('')}</ul>
  </div>
  <div class="record-col">
    <p class="eyebrow">Auszeichnungen</p>
    <ul class="awards">${AWARDS.map((a) => `<li><span class="award-org">${esc(a.org)}</span><strong>${esc(a.title)}</strong>${a.works?.length ? `<span class="award-works">${a.works.map((x) => workLink(x.slug, x.title)).join(', ')}</span>` : ''}</li>`).join('')}</ul>
  </div>
</section>

<section class="buy" id="kaufen" aria-labelledby="kaufen-h">
  ${idx('05', 'Kauf')}
  <h2 id="kaufen-h" class="display">So kommt ein Original ${strich('zu Ihnen.', 'cyan')}</h2>
  <ol class="steps">
    <li><span class="num">01</span><h3>Werk wählen</h3><p>Jedes Bild gibt es genau einmal. Im Bezahlvorgang ist es ${cfg.reserveMinutes} Minuten für Sie reserviert.</p></li>
    <li><span class="num">02</span><h3>Sicher bezahlen</h3><p>Verschlüsselt über Stripe: Karte, Apple Pay, Google Pay und weitere Verfahren.</p></li>
    <li><span class="num">03</span><h3>Persönlich verpackt</h3><p>Versichert unterwegs in ${esc(cfg.deliveryDays)} Werktagen, oder Sie holen es im Atelier ab.</p></li>
    <li><span class="num">04</span><h3>Mit Zertifikat</h3><p>Signiertes Unikat mit Certificate of Authenticity. 14 Tage Widerrufsrecht.</p></li>
  </ol>
</section>

<section class="contact-cta">
  ${idx('06', 'Anfragen')}
  <h2 class="display">Ein Werk im Kopf, das hier ${strich('nicht steht?', 'magenta')}</h2>
  <p>Auftragsarbeiten, limitierte Editionen oder ein Besuch im Atelier: Schreiben Sie einfach.</p>
  <a class="btn" href="mailto:${esc(cfg.contactEmail)}?subject=${encodeURIComponent('Anfrage über den Shop')}" data-inquiry="allgemein">Anfrage schreiben <span aria-hidden="true">→</span></a>
  <p class="cta-mail">oder direkt an <a href="mailto:${esc(cfg.contactEmail)}">${esc(cfg.contactEmail)}</a></p>
</section>`;

  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    itemListElement: works.slice(0, 40).map((w, i) => ({ '@type': 'ListItem', position: i + 1, url: `${cfg.siteUrl}/werk/${w.slug}`, name: w.title })),
  };
  const person = {
    '@context': 'https://schema.org',
    '@type': 'Person',
    name: cfg.artistName,
    jobTitle: 'Bildender Künstler',
    url: cfg.siteUrl,
    sameAs: [cfg.mainSite, cfg.instagram, cfg.facebook].filter(Boolean),
  };
  const heroPre = hero && isLocalImg(hero.image) ? ((st) => `${st}-xs.webp 420w, ${st}-sm.webp 720w, ${st}.webp 1600w`)(hero.image.replace(/\.jpe?g$/i, '')) : '';
  return layout({ title: '', body, canonical: '/', ogImage: hero?.image, jsonLd: [jsonLd, person], bodyClass: 'page-home', preload: heroPre, preloadSizes: '(max-width: 860px) 92vw, 44vw' });
}

// Maße "H x B (x T) cm" lesen; Ausrichtung zur Sicherheit am Foto prüfen.
export function dimsOf(work) {
  const nums = String(work.size || '').match(/\d+(?:[.,]\d+)?/g)?.slice(0, 2).map((n) => parseFloat(n.replace(',', '.'))) || [];
  if (nums.length < 2 || nums.some((n) => !n || n > 600)) return null;
  let [h, b] = nums;
  if (work.w && work.h) {
    const portrait = work.h >= work.w;
    if ((portrait && b > h) || (!portrait && h > b)) [h, b] = [b, h];
  }
  return { h, b };
}

// Größenvergleich: Bild in echter Größe neben einer 1,75 m großen Person, Bildmitte auf 1,55 m.
function sizeCompare(work) {
  const d = dimsOf(work);
  if (!d) return '';
  const fmt = (n) => String(n).replace('.', ',');
  const CENTER = 155; // Bildmitte über dem Boden (Galerie-Standard)
  const bottom = CENTER - d.h / 2;
  const sofa = bottom >= 100; // Sofa nur, wenn das Bild darüber Platz hat
  const SOFA_W = 200;
  const span = Math.max(d.b, sofa ? SOFA_W : 0);
  const P = 12; // Person links
  const cx = 85 + span / 2;
  const W = cx + span / 2 + 40;
  const floor = Math.max(220, Math.ceil(CENTER + d.h / 2 + 22));
  const top = floor - CENTER - d.h / 2;
  const x = cx - d.b / 2;
  const person = `<g class="sz-person" transform="translate(${P},${floor - 175})">
      <circle cx="17.5" cy="12" r="11"/>
      <path d="M3 31 Q3 25 9 25 H26 Q32 25 32 31 V86 Q32 91 27 91 V171 Q27 175 23 175 H19.5 V97 H15.5 V175 H12 Q8 175 8 171 V91 Q3 91 3 86 Z"/>
    </g>`;
  const sofaSvg = sofa
    ? `<g class="sz-sofa" transform="translate(${cx - SOFA_W / 2},${floor - 80})">
        <rect x="8" y="0" width="${SOFA_W - 16}" height="48" rx="6"/>
        <rect x="0" y="30" width="${SOFA_W}" height="38" rx="7"/>
        <rect x="14" y="68" width="6" height="12"/><rect x="${SOFA_W - 20}" y="68" width="6" height="12"/>
      </g>`
    : '';
  return `<figure class="sizecmp">
    <svg viewBox="0 0 ${W} ${floor + 6}" role="img" aria-label="Größenvergleich: ${fmt(d.h)} × ${fmt(d.b)} cm neben einer 1,75 m großen Person" preserveAspectRatio="xMidYMax meet">
      <line class="sz-floor" x1="0" y1="${floor}" x2="${W}" y2="${floor}"/>
      ${person}
      ${sofaSvg}
      <rect class="sz-frame" x="${x}" y="${top}" width="${d.b}" height="${d.h}"/>
      <image href="${esc(sized(work.thumb, "-sm"))}" x="${x}" y="${top}" width="${d.b}" height="${d.h}" preserveAspectRatio="xMidYMid slice"/>
      <text class="sz-label" x="${cx}" y="${top - 7}" text-anchor="middle">${fmt(d.b)} cm</text>
      <text class="sz-label" x="${x + d.b + 7}" y="${top + d.h / 2}" dominant-baseline="middle">${fmt(d.h)} cm</text>
      <text class="sz-label sz-muted" x="${P + 17.5}" y="${floor - 183}" text-anchor="middle">1,75 m</text>
    </svg>
    <figcaption>Größenvergleich: das Werk in echter Größe an der Wand${sofa ? ' über einem 2 m breiten Sofa' : ''}, daneben eine Person von 1,75 m.</figcaption>
  </figure>`;
}

const KIND_LABEL = { detail: 'Detail', raum: 'Im Raum', ansicht: 'Ansicht' };

export function renderWork(work, works) {
  const cfg = getConfig();
  const ship = shippingFor(work, cfg);
  const others = works.filter((w) => w.slug !== work.slug && w.status !== 'sold').slice(0, 10);
  const idx = works.findIndex((w) => w.slug === work.slug);
  const prev = works[idx - 1];
  const next = works[idx + 1];
  const mailSubject = encodeURIComponent(`Anfrage: ${work.title}${work.year ? ` (${work.year})` : ''}`);
  const inq = `data-work="${esc(work.title)}" data-slug="${esc(work.slug)}"`;
  const mailBody = encodeURIComponent(`Guten Tag Herr Hübner,\n\nich interessiere mich für das Werk „${work.title}“ (${work.size}).\n${cfg.siteUrl}/werk/${work.slug}\n\nViele Grüße\n`);

  let action;
  if (work.status === 'sold') {
    action = `<p class="state state--sold">Dieses Werk befindet sich in einer Privatsammlung.</p>
      <a class="btn btn--ghost btn--wide" href="mailto:${esc(cfg.contactEmail)}?subject=${mailSubject}" data-inquiry="aehnlich" ${inq}>Ähnliches Werk anfragen</a>`;
  } else if (work.status === 'reserved') {
    action = `<p class="state state--reserved">Gerade reserviert. Ein anderer Interessent ist im Bezahlvorgang. Schauen Sie in einer halben Stunde noch einmal vorbei.</p>
      <a class="btn btn--ghost btn--wide" href="mailto:${esc(cfg.contactEmail)}?subject=${mailSubject}&body=${mailBody}" data-inquiry="warteliste" ${inq}>Auf die Warteliste</a>`;
  } else if (!work.priceCents) {
    action = `<a class="btn btn--wide" href="mailto:${esc(cfg.contactEmail)}?subject=${mailSubject}&body=${mailBody}" data-inquiry="preis" ${inq}>Preis anfragen</a>
      <p class="hint">Antwort in der Regel innerhalb eines Tages.</p>`;
  } else {
    action = `<form class="buy-form" method="post" action="/api/checkout" data-checkout>
        <input type="hidden" name="slug" value="${esc(work.slug)}">
        <button class="btn btn--wide btn--buy" type="submit">Original kaufen <span aria-hidden="true">→</span></button>
      </form>
      <p class="hint">Weiter zur sicheren Bezahlung bei Stripe. Das Werk ist dort ${cfg.reserveMinutes} Minuten für Sie reserviert.</p>
      <a class="text-link" href="mailto:${esc(cfg.contactEmail)}?subject=${mailSubject}&body=${mailBody}" data-inquiry="frage" ${inq}>Lieber erst eine Frage stellen</a>`;
  }

  // Ansichten: Tageslicht, Schwarzlicht, Vergleich, weitere Fotos
  const views = [{ id: 'day', label: 'Tageslicht', thumb: work.thumb }];
  if (work.uvImage) views.push({ id: 'uv', label: 'Schwarzlicht', thumb: work.uvImage }, { id: 'cmp', label: 'Vergleich', thumb: work.uvImage, cmp: true });
  (work.gallery || []).forEach((g, i) => views.push({ id: `g${i}`, label: KIND_LABEL[g.kind] || 'Ansicht', thumb: g.src, src: g.src, kind: g.kind }));
  const sizes = '(max-width: 1020px) 92vw, 60vw';

  const stage = `<div class="stage" data-stage>
      <button type="button" class="zoom view is-active" data-view="day" data-zoom="${esc(sized(work.image))}" aria-label="Bild vergrößern">
        ${pic(work.image, `alt="${esc(work.title)} von ${esc(cfg.artistName)} – ${esc(work.technique)}, ${esc(work.size)}" ${work.w && work.h ? `width="${work.w}" height="${work.h}"` : ''} fetchpriority="high"`, sizes)}
      </button>
      ${work.uvImage ? `<button type="button" class="zoom view" data-view="uv" data-zoom="${esc(sized(work.uvImage))}" aria-label="Schwarzlicht-Aufnahme vergrößern" hidden>${pic(work.uvImage, `alt="${esc(work.title)} unter Schwarzlicht" loading="lazy"`, sizes)}</button>
      <div class="view view--cmp" data-view="cmp" hidden>${uvCompare(work, { sizes })}</div>` : ''}
      ${(work.gallery || []).map((g, i) => `<button type="button" class="zoom view" data-view="g${i}" data-zoom="${esc(sized(g.src))}" aria-label="${esc(KIND_LABEL[g.kind] || 'Ansicht')} vergrößern" hidden>${pic(g.src, `alt="${esc(work.title)} – ${esc(KIND_LABEL[g.kind] || 'Ansicht')}${g.kind === 'raum' ? ' (Visualisierung)' : ''}" loading="lazy"`, sizes)}${g.kind === 'raum' ? '<span class="viz-note">Visualisierung</span>' : ''}</button>`).join('')}
    </div>
    ${views.length > 1 ? `<div class="views" role="tablist" aria-label="Ansichten">${views.map((v, i) => `<button type="button" role="tab" class="view-btn${i === 0 ? ' is-active' : ''}${v.id === 'uv' || v.id === 'cmp' ? ' view-btn--uv' : ''}" data-show="${v.id}" aria-selected="${i === 0}">${pic(sized(v.thumb, '-xs'), 'alt="" loading="lazy"')}<span>${esc(v.label)}</span></button>`).join('')}</div>` : ''}`;

  const body = `
<nav class="crumbs" aria-label="Brotkrumen"><a href="/">Shop</a> <span aria-hidden="true">/</span> <a href="/#werke">Werke</a> <span aria-hidden="true">/</span> <span>${esc(work.title)}</span></nav>
<div class="notice" hidden data-notice="abgebrochen">Bezahlung abgebrochen. Die Reservierung ist aufgehoben, das Werk ist wieder frei.</div>
<div class="notice" hidden data-notice="demo">Demo-Modus: Stripe ist noch nicht verbunden, deshalb startet hier noch keine echte Bezahlung.</div>
<div class="notice notice--warn" hidden data-notice="vergeben">Dieses Werk ist gerade reserviert oder schon verkauft. Bitte versuchen Sie es später noch einmal oder schreiben Sie mir.</div>
<div class="notice notice--warn" hidden data-notice="zuviele">Von Ihrem Anschluss wurden gerade mehrere Bezahlvorgänge gestartet. Bitte versuchen Sie es in einer halben Stunde noch einmal oder schreiben Sie mir eine E-Mail.</div>
<div class="notice notice--warn" hidden data-notice="fehler">Der Bezahlvorgang konnte nicht gestartet werden. Bitte versuchen Sie es noch einmal oder schreiben Sie mir eine E-Mail.</div>
<article class="work${work.uvImage ? ' has-uv' : ''}" data-work-slug="${esc(work.slug)}" data-work-status="${esc(work.status)}">
  <div class="work-media">
    ${stage}
    <p class="media-note">Fotografien des Originals. Farben können je nach Bildschirm leicht abweichen.${work.uvImage ? ' Die Schwarzlicht-Aufnahme zeigt die UV-reaktiven Farben im Dunkeln.' : ''}</p>
    ${sizeCompare(work)}
  </div>
  <div class="work-info">
    <p class="eyebrow">${esc(cfg.artistName)}${work.series ? ` · Serie ${esc(work.series)}` : ''}</p>
    <h1 class="display work-title">${esc(work.title)}${work.year ? ` <span class="year">${work.year}</span>` : ''}</h1>
    ${work.uvImage || work.awards?.length ? `<p class="work-tags">${work.uvImage ? '<span class="tag tag--uv">UV-reaktiv</span>' : ''}${work.awards?.length ? '<span class="tag tag--award">Ausgezeichnet</span>' : ''}</p>` : ''}
    <dl class="facts">
      ${work.technique ? `<div><dt>Technik</dt><dd>${esc(work.technique)}</dd></div>` : ''}
      ${work.size ? `<div><dt>Maße</dt><dd>${esc(work.size)}</dd></div>` : ''}
      <div><dt>Ausführung</dt><dd>Signiertes Unikat mit Echtheitszertifikat (COA)</dd></div>
    </dl>
    <div class="buybox">
    <p class="price">${work.status === 'sold' ? 'Verkauft' : esc(priceLabel(work))}</p>
    ${work.priceCents && work.status !== 'sold' ? `<p class="price-note">${cfg.kleinunternehmer ? 'Endpreis, keine Umsatzsteuer gemäß § 19 UStG' : 'inkl. MwSt.'} · zzgl. ${ship ? euro(ship * 100) : 'kostenlosem'} versichertem Versand${cfg.pickupEnabled ? ' oder kostenlose Abholung' : ''}</p>` : ''}
    <div class="actions">${action}</div>
    </div>
    <div class="tools">
      <button type="button" class="tool" data-fav="${esc(work.slug)}" aria-pressed="false"><span class="tool-ico" aria-hidden="true">♡</span><span data-fav-label>Merken</span></button>
      <button type="button" class="tool" data-share data-title="${esc(work.title)} – ${esc(cfg.artistName)}"><span class="tool-ico" aria-hidden="true">↗</span>Teilen</button>
    </div>
    <ul class="assurances">
      <li>Versicherter Versand in ${esc(cfg.deliveryDays)} Werktagen, persönlich verpackt</li>
      <li>14 Tage Widerrufsrecht</li>
      <li>Zahlung verschlüsselt über Stripe</li>
    </ul>
    ${work.description ? `<div class="desc"><h2>Über das Werk</h2><p>${esc(work.description)}</p></div>` : ''}
    ${work.awards?.length ? `<div class="honors"><h2>Auszeichnungen</h2><ul>${work.awards.map((a) => `<li>${esc(a)}</li>`).join('')}</ul></div>` : ''}
    ${work.exhibitions?.length ? `<div class="honors"><h2>Ausgestellt</h2><ul>${work.exhibitions.map((a) => `<li>${esc(a)}</li>`).join('')}</ul></div>` : ''}
    <nav class="pager" aria-label="Weitere Werke blättern">
      ${prev ? `<a href="/werk/${esc(prev.slug)}">← ${esc(prev.title)}</a>` : '<span></span>'}
      ${next ? `<a href="/werk/${esc(next.slug)}">${esc(next.title)} →</a>` : '<span></span>'}
    </nav>
  </div>
</article>
${others.length ? `<section class="more"><h2 class="display">Weitere Originale</h2><div class="strip">${others.map((w) => `<a href="/werk/${esc(w.slug)}" class="strip-item"><span class="strip-img">${pic(w.thumb, `alt="${esc(w.title)}" loading="lazy"`, '210px')}</span><span>${esc(w.title)}</span><span class="muted">${esc(priceLabel(w))}</span></a>`).join('')}</div></section>` : ''}
<div class="lightbox" hidden data-lightbox role="dialog" aria-label="Bildansicht"><button type="button" class="lb-close" aria-label="Schließen">×</button><button type="button" class="lb-nav lb-prev" aria-label="Vorheriges Bild">‹</button><img alt=""><button type="button" class="lb-nav lb-next" aria-label="Nächstes Bild">›</button></div>`;

  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': ['Product', 'VisualArtwork'],
    name: work.title,
    image: [absUrl(cfg, work.image), ...(work.uvImage ? [absUrl(cfg, work.uvImage)] : [])],
    description: work.description || `${work.technique}, ${work.size}`,
    artform: 'Malerei',
    artMedium: work.technique,
    creator: { '@type': 'Person', name: cfg.artistName },
    ...(work.year ? { dateCreated: String(work.year) } : {}),
    ...(work.awards?.length ? { award: work.awards } : {}),
    brand: { '@type': 'Brand', name: cfg.shopName },
    ...(work.priceCents
      ? {
          offers: {
            '@type': 'Offer',
            url: `${cfg.siteUrl}/werk/${work.slug}`,
            priceCurrency: 'EUR',
            price: (work.priceCents / 100).toFixed(2),
            availability: work.status === 'available' ? 'https://schema.org/InStock' : work.status === 'reserved' ? 'https://schema.org/LimitedAvailability' : 'https://schema.org/SoldOut',
            itemCondition: 'https://schema.org/NewCondition',
          },
        }
      : {}),
  };
  const breadcrumb = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Werke', item: `${cfg.siteUrl}/` },
      { '@type': 'ListItem', position: 2, name: work.title, item: `${cfg.siteUrl}/werk/${work.slug}` },
    ],
  };
  const desc = `${work.title}${work.year ? ` (${work.year})` : ''} von ${cfg.artistName}: ${work.technique}, ${work.size}. ${work.priceCents ? `Original für ${euro(work.priceCents)}` : 'Preis auf Anfrage'}, signiert mit Echtheitszertifikat.`;
  const pre = isLocalImg(work.image) ? ((st) => `${st}-xs.webp 420w, ${st}-sm.webp 720w, ${st}.webp 1600w`)(work.image.replace(/\.jpe?g$/i, '')) : '';
  return layout({ title: `${work.title} – Original kaufen`, description: desc, body, canonical: `/werk/${work.slug}`, ogImage: work.image, preload: pre, jsonLd: [jsonLd, breadcrumb], bodyClass: 'page-work' });
}

export function renderThanks({ session, work }) {
  const cfg = getConfig();
  const name = session?.customer_details?.name?.split(' ')[0];
  const email = session?.customer_details?.email;
  const total = session?.amount_total;
  const isPickup = session?.shipping_cost?.amount_total === 0 && cfg.pickupEnabled;
  const conversion =
    cfg.googleAdsConversion && session
      ? `<script>window.__mhConversion=${JSON.stringify({ send_to: cfg.googleAdsConversion, value: (total || 0) / 100, currency: 'EUR', transaction_id: session.id })};</script>`
      : '';
  const body = `
<section class="thanks">
  ${work ? `${pic(work.thumb, `class="thanks-img" alt="${esc(work.title)}"`)}` : ''}
  <div>
    <p class="eyebrow">Bestellung eingegangen</p>
    <h1>Danke${name ? `, ${esc(name)}` : ''}. ${work ? `„${esc(work.title)}“ gehört jetzt Ihnen.` : 'Ihr Kauf ist bestätigt.'}</h1>
    <p class="lead">${session?.payment_status === 'paid' ? 'Die Zahlung ist bestätigt.' : 'Ihre Zahlung wird noch verarbeitet. Sobald sie bestätigt ist, bekommen Sie eine E-Mail.'}${email ? ` Eine Bestätigung geht an <strong>${esc(email)}</strong>.` : ''}</p>
    <ul class="assurances">
      <li>${isPickup ? 'Ich melde mich in den nächsten Tagen, um einen Abholtermin im Atelier mit Ihnen abzustimmen.' : `Ich verpacke das Werk persönlich und versende es versichert innerhalb von ${esc(cfg.deliveryDays)} Werktagen. Die Sendungsnummer bekommen Sie per E-Mail.`}</li>
      <li>Das signierte Echtheitszertifikat liegt dem Werk bei.</li>
      <li>Fragen? <a href="mailto:${esc(cfg.contactEmail)}">${esc(cfg.contactEmail)}</a></li>
    </ul>
    ${total ? `<p class="muted">Gesamtbetrag: ${euro(total)}${session?.id ? ` · Bestellreferenz: ${esc(session.id.slice(-10).toUpperCase())}` : ''}</p>` : ''}
    <p><a class="btn btn--ghost" href="/">Zurück zum Shop</a></p>
  </div>
</section>${conversion}`;
  return layout({ title: 'Danke für Ihren Kauf', body, canonical: '/danke', noindex: true, bodyClass: 'page-thanks' });
}

export function renderNotFound() {
  return layout({
    title: 'Seite nicht gefunden',
    noindex: true,
    canonical: '/404',
    body: `<section class="legal"><h1>Dieses Werk ist nicht (mehr) hier.</h1><p>Vielleicht wurde es gerade verkauft oder der Link ist veraltet.</p><p><a class="btn" href="/#werke">Alle verfügbaren Werke</a></p></section>`,
  });
}
