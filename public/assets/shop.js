// MH ART Shop – kleines, abhängigkeitsfreies Frontend-Skript
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch {} },
  };
  const root = document.documentElement;
  const isUV = () => root.dataset.theme === 'uv';

  // ---------- Toast ----------
  const toastEl = $('.toast');
  let toastTimer;
  const toast = (msg) => {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.hidden = false;
    requestAnimationFrame(() => toastEl.classList.add('is-shown'));
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastEl.classList.remove('is-shown'); setTimeout(() => (toastEl.hidden = true), 300); }, 2600);
  };

  // Link-Parameter ?uv=1 schaltet Schwarzlicht ein (z. B. für Anzeigen), ?uv=0 aus
  const qp = new URLSearchParams(location.search);
  if (qp.get('uv') === '1') { root.dataset.theme = 'uv'; store.set('mh-theme', 'uv'); }
  if (qp.get('uv') === '0') { root.dataset.theme = 'day'; store.set('mh-theme', 'day'); }

  // ---------- UV-Licht-Schalter ----------
  const toggle = $('.uv-toggle');
  const tip = $('.uv-tip');
  let tipTimer;
  const syncToggle = () => {
    toggle?.setAttribute('aria-pressed', String(isUV()));
    toggle?.setAttribute('title', isUV() ? 'Zurück zu Tageslicht' : 'Werke unter Schwarzlicht zeigen');
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', isUV() ? '#08070d' : '#ffffff');
  };
  syncToggle();
  toggle?.addEventListener('click', () => {
    root.dataset.theme = isUV() ? 'day' : 'uv';
    store.set('mh-theme', root.dataset.theme);
    syncToggle();
    if (tip) {
      tip.textContent = isUV()
        ? 'Schwarzlicht an: Werke mit UV-Aufnahme zeigen jetzt, wie sie im Dunkeln leuchten.'
        : 'Tageslicht: alle Werke wieder in normaler Ansicht.';
      tip.classList.add('is-shown');
      clearTimeout(tipTimer);
      tipTimer = setTimeout(() => tip.classList.remove('is-shown'), 3200);
    }
    document.dispatchEvent(new CustomEvent('mh:theme'));
  });

  // ---------- Header-Schatten beim Scrollen ----------
  const header = $('.site-header');
  const onScroll = () => header?.classList.toggle('is-scrolled', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // ---------- Vergleichsregler ----------
  const initCmp = (el) => {
    const range = $('.cmp-range', el);
    if (!range || el.dataset.ready) return;
    el.dataset.ready = '1';
    const set = (v) => el.style.setProperty('--pos', `${v}%`);
    range.addEventListener('input', () => set(range.value));
    // kleiner Hinweis-Schwenk, sobald der Regler sichtbar wird
    if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      const io = new IntersectionObserver((entries) => {
        if (!entries[0].isIntersecting) return;
        io.disconnect();
        if (el.dataset.touched) return;
        let t = 0;
        const start = performance.now();
        const step = (now) => {
          t = (now - start) / 1400;
          if (t >= 1 || el.dataset.touched) { set(range.value); return; }
          const v = 50 + Math.sin(t * Math.PI * 2) * 22;
          set(v.toFixed(1));
          requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      }, { threshold: 0.6 });
      io.observe(el);
    }
    range.addEventListener('pointerdown', () => (el.dataset.touched = '1'));
    range.addEventListener('keydown', () => (el.dataset.touched = '1'));
  };
  $$('[data-cmp]').forEach(initCmp);

  // ---------- Schwarzlicht-Auswahl ----------
  const bl = $('[data-blacklight]');
  if (bl) {
    const stage = $('.bl-stage', bl);
    const meta = $('[data-bl-meta]', bl);
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
    $$('.bl-thumb', bl).forEach((btn) => btn.addEventListener('click', () => {
      const d = JSON.parse(btn.dataset.bl);
      $$('.bl-thumb', bl).forEach((b) => b.classList.toggle('is-active', b === btn));
      stage.innerHTML = `<div class="cmp" data-cmp style="--pos:50%">
        <img class="cmp-day" src="${esc(d.day)}" alt="${esc(d.title)} bei Tageslicht">
        <div class="cmp-uv"><img src="${esc(d.uv)}" alt="${esc(d.title)} unter Schwarzlicht"></div>
        <span class="cmp-line" aria-hidden="true"><span class="cmp-knob">↔</span></span>
        <span class="cmp-tag cmp-tag--day">Tageslicht</span><span class="cmp-tag cmp-tag--uv">Schwarzlicht</span>
        <input class="cmp-range" type="range" min="0" max="100" value="50" aria-label="Regler: links Tageslicht, rechts Schwarzlicht">
      </div>`;
      initCmp($('[data-cmp]', stage));
      if (meta) meta.innerHTML = `<a href="/werk/${encodeURIComponent(d.slug)}">${esc(d.title)}</a> · ${esc(d.price)}`;
    }));
  }

  // ---------- Merkliste (nur lokal im Browser) ----------
  const favs = new Set((() => { try { return JSON.parse(store.get('mh-favs') || '[]'); } catch { return []; } })());
  const saveFavs = () => store.set('mh-favs', JSON.stringify([...favs]));
  const syncFavs = () => {
    $$('[data-fav]').forEach((b) => {
      const on = favs.has(b.dataset.fav);
      b.setAttribute('aria-pressed', String(on));
      b.classList.toggle('is-on', on);
      const ico = $('.tool-ico', b) || (b.classList.contains('fav-btn') ? b : null);
      if (ico) ico.textContent = on ? '♥' : '♡';
      const label = $('[data-fav-label]', b);
      if (label) label.textContent = on ? 'Gemerkt' : 'Merken';
    });
    const n = favs.size;
    $$('[data-fav-count]').forEach((c) => (c.textContent = n));
    $$('[data-fav-link]').forEach((l) => (l.hidden = n === 0));
    $$('[data-fav-chip]').forEach((c) => { c.hidden = n === 0; c.textContent = `♥ Merkliste (${n})`; });
  };
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-fav]');
    if (!b) return;
    e.preventDefault();
    e.stopPropagation();
    const slug = b.dataset.fav;
    if (favs.has(slug)) { favs.delete(slug); toast('Von der Merkliste entfernt'); }
    else { favs.add(slug); toast('Auf der Merkliste – oben rechts unter ♡'); }
    saveFavs();
    syncFavs();
    if (current === 'merkliste') apply('merkliste');
  });

  // ---------- Filter & Sortierung (mit teilbaren Links: ?filter=uv, ?sort=preis-auf) ----------
  const grid = $('[data-grid]');
  const FILTER_ALIAS = { uv: 'uv:ja', verfuegbar: 'status:available', klein: 'format:klein', mittel: 'format:mittel', gross: 'format:gross', merkliste: 'merkliste', 'bis-1000': 'pricecat:bis1000', 'bis-2500': 'pricecat:bis2500', 'ab-2500': 'pricecat:ab2500' };
  const ALIAS_OF = Object.fromEntries(Object.entries(FILTER_ALIAS).map(([k, v]) => [v, k]));
  const SORT_ALIAS = { 'preis-auf': 'price-asc', 'preis-ab': 'price-desc' };
  let current = 'all';
  let apply = () => {};
  if (grid) {
    const cards = $$('.card', grid);
    const original = [...cards];
    const empty = $('[data-empty]');
    const writeUrl = () => {
      const u = new URL(location.href);
      const f = ALIAS_OF[current] || (current.startsWith('series:') ? `serie-${current.slice(7).toLowerCase()}` : '');
      f && current !== 'all' ? u.searchParams.set('filter', f) : u.searchParams.delete('filter');
      const sv = $('[data-sort]')?.value;
      const sa = Object.entries(SORT_ALIAS).find(([, v]) => v === sv)?.[0];
      sa ? u.searchParams.set('sort', sa) : u.searchParams.delete('sort');
      history.replaceState(null, '', u.pathname + u.search + u.hash);
    };
    apply = (filter, { url = true } = {}) => {
      current = filter;
      $$('.chip').forEach((c) => c.classList.toggle('is-active', c.dataset.filter === filter));
      let visible = 0;
      cards.forEach((card) => {
        let show = true;
        if (filter === 'merkliste') show = favs.has(card.dataset.slug);
        else if (filter !== 'all') {
          const [key, val] = filter.split(':');
          show = card.dataset[key] === val;
        }
        card.hidden = !show;
        if (show) visible++;
      });
      if (empty) empty.hidden = visible > 0;
      if (url) writeUrl();
    };
    $$('[data-filter]').forEach((b) => b.addEventListener('click', () => apply(b.dataset.filter)));
    const sortSel = $('[data-sort]');
    const doSort = (mode) => {
      const price = (c) => (c.dataset.price ? Number(c.dataset.price) : null);
      const list = [...original];
      if (mode !== 'default') {
        list.sort((a, b) => {
          const pa = price(a), pb = price(b);
          if (pa === null && pb === null) return 0;
          if (pa === null) return 1;
          if (pb === null) return -1;
          return mode === 'price-asc' ? pa - pb : pb - pa;
        });
      }
      list.forEach((c) => grid.appendChild(c));
    };
    sortSel?.addEventListener('change', (e) => { doSort(e.target.value); apply(current); });
    // Startzustand aus dem Link
    const fParam = qp.get('filter');
    const sParam = qp.get('sort');
    if (sParam && SORT_ALIAS[sParam] && sortSel) { sortSel.value = SORT_ALIAS[sParam]; doSort(sortSel.value); }
    let startFilter = FILTER_ALIAS[fParam] || '';
    if (!startFilter && fParam?.startsWith('serie-')) {
      const chip = $$('[data-filter^="series:"]').find((c) => c.dataset.filter.slice(7).toLowerCase() === fParam.slice(6));
      if (chip) startFilter = chip.dataset.filter;
    }
    if (startFilter) apply(startFilter, { url: false });
  }
  syncFavs();

  // ---------- Teilen ----------
  $$('[data-share]').forEach((b) => b.addEventListener('click', async () => {
    const data = { title: b.dataset.title || document.title, url: location.origin + location.pathname };
    try {
      if (navigator.share) { await navigator.share(data); return; }
      await navigator.clipboard.writeText(data.url);
      toast('Link kopiert');
    } catch (err) {
      if (err?.name !== 'AbortError') prompt('Link zum Kopieren:', data.url);
    }
  }));

  // ---------- Anfrage-Dialog ----------
  const dlg = $('[data-inquiry-dialog]');
  if (dlg && typeof dlg.showModal === 'function') {
    const form = $('[data-inquiry-form]', dlg);
    const done = $('[data-inq-done]', dlg);
    const err = $('[data-inq-error]', dlg);
    const TEXT = {
      preis: (w) => ({ eyebrow: 'Preisanfrage', title: `Preis für „${w}“`, sub: 'Ich nenne Ihnen den Preis und beantworte gern Fragen zu Versand und Hängung.', msg: `Guten Tag Herr Hübner,\n\nich interessiere mich für „${w}“ und bitte um den Preis.\n\nViele Grüße` }),
      frage: (w) => ({ eyebrow: 'Frage zum Werk', title: `Frage zu „${w}“`, sub: 'Zu Maßen, Rahmen, Versand oder einem Besuch im Atelier.', msg: `Guten Tag Herr Hübner,\n\nich habe eine Frage zu „${w}“:\n\n` }),
      warteliste: (w) => ({ eyebrow: 'Warteliste', title: `Warteliste für „${w}“`, sub: 'Sollte das Werk wieder frei werden, melde ich mich zuerst bei Ihnen.', msg: `Guten Tag Herr Hübner,\n\nbitte setzen Sie mich auf die Warteliste für „${w}“.\n\nViele Grüße` }),
      aehnlich: (w) => ({ eyebrow: 'Anfrage', title: 'Ein ähnliches Werk', sub: `„${w}“ ist bereits verkauft. Gern erzähle ich Ihnen von verwandten Arbeiten oder einer Auftragsarbeit.`, msg: `Guten Tag Herr Hübner,\n\nich interessiere mich für ein Werk ähnlich wie „${w}“.\n\nViele Grüße` }),
      allgemein: () => ({ eyebrow: 'Anfrage', title: 'Schreiben Sie mir', sub: 'Auftragsarbeiten, Editionen, ein Besuch im Atelier oder eine Frage zum Kauf.', msg: '' }),
    };
    let lastFocus;
    const open = (type, work, slug) => {
      const t = (TEXT[type] || TEXT.allgemein)(work || '');
      $('[data-inq-eyebrow]', dlg).textContent = t.eyebrow;
      $('[data-inq-title]', dlg).textContent = t.title;
      $('[data-inq-sub]', dlg).textContent = t.sub;
      form.anliegen.value = type || 'allgemein';
      form.werk.value = work ? `${work}${slug ? ` (${slug})` : ''}` : '';
      form.seite.value = location.href;
      if (!form.nachricht.value || form.nachricht.dataset.auto === '1') { form.nachricht.value = t.msg; form.nachricht.dataset.auto = '1'; }
      form.hidden = false; done.hidden = true; err.hidden = true;
      lastFocus = document.activeElement;
      dlg.showModal();
      (form.name.value ? form.nachricht : form.name).focus();
    };
    const close = () => { dlg.close(); lastFocus?.focus?.(); };
    form.nachricht.addEventListener('input', () => (form.nachricht.dataset.auto = '0'));
    document.addEventListener('click', (e) => {
      const a = e.target.closest('[data-inquiry]');
      if (!a) return;
      e.preventDefault();
      open(a.dataset.inquiry, a.dataset.work, a.dataset.slug);
    });
    $$('[data-inquiry-close]', dlg).forEach((b) => b.addEventListener('click', close));
    dlg.addEventListener('click', (e) => { if (e.target === dlg) close(); });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('button[type="submit"]', form);
      btn.disabled = true;
      err.hidden = true;
      try {
        const res = await fetch('/anfrage', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body: new URLSearchParams(new FormData(form)).toString(), credentials: 'same-origin' });
        if (!res.ok) throw new Error(res.status);
        form.reset();
        form.hidden = true;
        done.hidden = false;
        $('[data-inquiry-close]', done)?.focus();
      } catch {
        err.hidden = false;
      }
      btn.disabled = false;
    });
  }

  // ---------- Werkseite: Ansichten ----------
  const stageEl = $('[data-stage]');
  if (stageEl) {
    const show = (id) => {
      let found = false;
      $$('.view', stageEl).forEach((v) => { const on = v.dataset.view === id; v.hidden = !on; if (on) found = true; });
      if (!found) return;
      $$('.view-btn').forEach((b) => { const on = b.dataset.show === id; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', String(on)); });
      const cmp = $(`[data-view="${id}"] [data-cmp]`, stageEl);
      if (cmp) initCmp(cmp);
    };
    $$('.view-btn').forEach((b) => b.addEventListener('click', () => show(b.dataset.show)));
    const hasUV = !!$('[data-view="uv"]', stageEl);
    if (hasUV && isUV()) show('uv');
    document.addEventListener('mh:theme', () => { if (hasUV) show(isUV() ? 'uv' : 'day'); });
  }

  // ---------- Hinweise aus der URL ----------
  const params = new URLSearchParams(location.search);
  $$('[data-notice]').forEach((n) => { if (params.has(n.dataset.notice)) n.hidden = false; });

  // ---------- Live-Status (Seiten sind gecacht; hier wird der aktuelle Stand nachgeladen) ----------
  const LABEL = { sold: 'Verkauft', reserved: 'Reserviert' };
  fetch('/api/status', { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : null)).then((data) => {
    if (!data?.status) return;
    $$('.card[data-slug]').forEach((card) => {
      const st = data.status[card.dataset.slug];
      if (!st || st === card.dataset.status) return;
      card.dataset.status = st;
      const img = $('.card-img', card);
      $('.badge', img)?.remove();
      if (LABEL[st]) img.insertAdjacentHTML('beforeend', `<span class="badge badge--${st === 'sold' ? 'sold' : 'reserved'}">${LABEL[st]}</span>`);
      if (st === 'sold') { const p = $('.card-price', card); if (p) p.textContent = 'Privatsammlung'; }
    });
    const art = $('[data-work-slug]');
    if (art) {
      const st = data.status[art.dataset.workSlug];
      if (st && st !== art.dataset.workStatus && st !== 'available') {
        const form = $('[data-checkout]');
        if (form) {
          form.querySelector('button').disabled = true;
          const n = $('[data-notice="vergeben"]');
          if (n) { n.textContent = st === 'sold' ? 'Dieses Werk wurde gerade verkauft.' : 'Dieses Werk ist gerade reserviert, weil jemand im Bezahlvorgang ist. Schauen Sie in einer halben Stunde noch einmal vorbei.'; n.hidden = false; }
        }
      }
    }
  }).catch(() => {});

  // ---------- Checkout ----------
  $$('[data-checkout]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('button', form);
      if (btn.disabled) return;
      const label = btn.innerHTML;
      btn.disabled = true;
      btn.classList.add('is-loading');
      btn.textContent = 'Bezahlung wird vorbereitet …';
      try {
        const res = await fetch(form.action, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ slug: form.slug.value }),
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (data.url) { location.href = data.url; return; }
        const flag = ['demo', 'vergeben', 'zuviele'].includes(data.error) ? data.error : 'fehler';
        $$('[data-notice]').forEach((n) => (n.hidden = n.dataset.notice !== flag));
        $(`[data-notice="${flag}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      } catch {
        form.submit();
        return;
      }
      btn.disabled = false;
      btn.classList.remove('is-loading');
      btn.innerHTML = label;
    });
  });
  window.addEventListener('pageshow', (e) => {
    if (e.persisted) $$('[data-checkout] button').forEach((b) => { b.disabled = false; b.classList.remove('is-loading'); b.innerHTML = 'Original kaufen <span aria-hidden="true">→</span>'; });
  });

  // ---------- Lightbox mit Blättern (Pfeiltasten, Wischen) ----------
  const lb = $('[data-lightbox]');
  if (lb) {
    const img = $('img', lb);
    const items = $$('[data-zoom]').map((b) => b.dataset.zoom);
    let at = 0;
    const show = (i) => { at = (i + items.length) % items.length; img.src = items[at]; };
    const open = (src) => { show(Math.max(0, items.indexOf(src))); lb.hidden = false; document.body.style.overflow = 'hidden'; $('.lb-close', lb).focus(); };
    const close = () => { lb.hidden = true; document.body.style.overflow = ''; };
    $$('[data-zoom]').forEach((b) => b.addEventListener('click', () => open(b.dataset.zoom)));
    lb.addEventListener('click', (e) => { if (e.target.closest('.lb-nav')) return; close(); });
    $('.lb-prev', lb)?.addEventListener('click', () => show(at - 1));
    $('.lb-next', lb)?.addEventListener('click', () => show(at + 1));
    if (items.length < 2) $$('.lb-nav', lb).forEach((n) => (n.hidden = true));
    let x0 = null;
    lb.addEventListener('touchstart', (e) => (x0 = e.touches[0].clientX), { passive: true });
    lb.addEventListener('touchend', (e) => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 50) show(at + (dx < 0 ? 1 : -1)); x0 = null; });
    document.addEventListener('keydown', (e) => {
      if (lb.hidden) return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowRight') show(at + 1);
      if (e.key === 'ArrowLeft') show(at - 1);
    });
  }
  // Auf der Werkseite: ← / → blättert zum vorigen / nächsten Werk
  const pager = $('.pager');
  if (pager) document.addEventListener('keydown', (e) => {
    if (lb && !lb.hidden) return;
    if (e.target.closest('input, textarea, select, dialog[open]') || e.metaKey || e.ctrlKey || e.altKey) return;
    const links = $$('a', pager);
    if (e.key === 'ArrowLeft' && links[0]?.textContent.startsWith('←')) location.href = links[0].href;
    if (e.key === 'ArrowRight') { const n = links.find((a) => a.textContent.trim().endsWith('→')); if (n) location.href = n.href; }
  });

  // ---------- Sanftes Einblenden ----------
  if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const targets = $$('.artist, .record-col, .steps li, .contact-cta, .bl-copy');
    targets.forEach((t) => t.classList.add('reveal'));
    const io = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -8% 0px' });
    targets.forEach((t) => io.observe(t));
  }

  // ---------- Einwilligung für Google-Tag (nur wenn konfiguriert) ----------
  const consent = $('.consent');
  if (consent) {
    const tagId = consent.dataset.tag;
    const loadTag = () => {
      if (window.__mhTag) return;
      window.__mhTag = true;
      window.dataLayer = window.dataLayer || [];
      window.gtag = function () { dataLayer.push(arguments); };
      gtag('consent', 'default', { ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'denied' });
      gtag('js', new Date());
      gtag('config', tagId);
      const s = document.createElement('script');
      s.async = true;
      s.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(tagId)}`;
      document.head.appendChild(s);
      if (window.__mhConversion) gtag('event', 'conversion', window.__mhConversion);
    };
    const choice = store.get('mh-consent');
    if (choice === 'grant') loadTag();
    else if (!choice) consent.hidden = false;
    $$('[data-consent]', consent).forEach((b) => b.addEventListener('click', () => {
      store.set('mh-consent', b.dataset.consent);
      consent.hidden = true;
      if (b.dataset.consent === 'grant') loadTag();
    }));
    $$('[data-consent-open]').forEach((b) => b.addEventListener('click', () => {
      if (store.get('mh-consent') === 'grant') { store.set('mh-consent', ''); location.reload(); return; }
      consent.hidden = false;
    }));
  }
})();

// Menü auf dem Handy
(() => {
  const btn = document.querySelector('[data-menu]');
  const header = document.querySelector('.site-header');
  if (!btn || !header) return;
  const set = (open) => { header.classList.toggle('nav-open', open); btn.setAttribute('aria-expanded', String(open)); };
  btn.addEventListener('click', () => set(btn.getAttribute('aria-expanded') !== 'true'));
  header.querySelectorAll('.site-nav a').forEach((a) => a.addEventListener('click', () => set(false)));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') set(false); });
  document.addEventListener('click', (e) => { if (!header.contains(e.target)) set(false); });
})();

// Drucken-Knopf (z. B. Widerrufsbestätigung), ohne Inline-Skript
document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
