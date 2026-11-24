// Atelier – Fotoverwaltung im Browser (Verkleinern, Hochladen, Reihenfolge, Rollen)
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const ROLE = { haupt: 'Hauptfoto', uv: 'Schwarzlicht', detail: 'Detail', raum: 'Im Raum', ansicht: 'Ansicht' };
  const MAX = 8;

  // Sicherheitsabfrage für "Entfernen"
  $$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));

  // Suche in der Werkliste
  const search = $('[data-at-search]');
  if (search) search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    $$('[data-at-list] .at-row').forEach((r) => (r.hidden = q && !r.textContent.toLowerCase().includes(q)));
  });

  const form = $('[data-at-edit]');
  if (!form) return;
  const jsonInput = $('[data-at-images-json]', form);
  const list = $('[data-at-photos]', form);
  const fileInput = $('[data-at-file]', form);
  const status = $('[data-at-upload-status]', form);
  const submit = $('[data-at-submit]', form);
  let images = [];
  try { images = JSON.parse(jsonInput.value || '[]'); } catch { images = []; }
  let pending = 0;

  const thumb = (u) => (/\/bilder\/[a-z0-9-]+\.jpg$/.test(u) ? u.replace(/\.jpg$/, '-xs.jpg') : u);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  const setMainSize = (url) => {
    if (!url) return;
    const img = new Image();
    img.onload = () => { $('[data-at-w]', form).value = img.naturalWidth; $('[data-at-h]', form).value = img.naturalHeight; };
    img.src = url;
  };

  const render = () => {
    images.forEach((x, i) => { if (i === 0) x.role = 'haupt'; else if (x.role === 'haupt') x.role = 'ansicht'; });
    // höchstens ein Schwarzlicht-Foto
    let uvSeen = false;
    images.forEach((x) => { if (x.role === 'uv') { if (uvSeen) x.role = 'ansicht'; uvSeen = true; } });
    jsonInput.value = JSON.stringify(images);
    list.innerHTML = '';
    if (!images.length) list.innerHTML = '<li class="at-photo-empty">Noch keine Fotos. Das erste Foto wird das Hauptfoto.</li>';
    images.forEach((x, i) => {
      const li = document.createElement('li');
      li.className = 'at-photo' + (i === 0 ? ' is-main' : '');
      const roleOpts = Object.entries(ROLE).filter(([k]) => k !== 'haupt').map(([k, l]) => `<option value="${k}" ${x.role === k ? 'selected' : ''}>${l}</option>`).join('');
      li.innerHTML = `
        <img src="${esc(thumb(x.url))}" alt="">
        <div class="at-photo-meta">
          ${i === 0 ? '<span class="at-role-main">Hauptfoto</span>' : `<select aria-label="Was zeigt das Foto?">${roleOpts}</select>`}
          <div class="at-photo-btns">
            <button type="button" data-a="up" ${i === 0 ? 'disabled' : ''} aria-label="Nach vorn">↑</button>
            <button type="button" data-a="down" ${i === images.length - 1 ? 'disabled' : ''} aria-label="Nach hinten">↓</button>
            ${i > 0 ? '<button type="button" data-a="main" title="Als Hauptfoto">Als Hauptfoto</button>' : ''}
            <button type="button" data-a="del" class="at-del" aria-label="Foto entfernen">Entfernen</button>
          </div>
        </div>`;
      $('select', li)?.addEventListener('change', (e) => { x.role = e.target.value; render(); });
      $$('button', li).forEach((b) => b.addEventListener('click', () => {
        const a = b.dataset.a;
        if (a === 'up' && i > 0) [images[i - 1], images[i]] = [images[i], images[i - 1]];
        if (a === 'down' && i < images.length - 1) [images[i + 1], images[i]] = [images[i], images[i + 1]];
        if (a === 'main') { images.splice(i, 1); images.unshift(x); }
        if (a === 'del') images.splice(i, 1);
        if (a === 'up' || a === 'main' || (a === 'del' && i === 0) || (a === 'down' && i === 0)) setMainSize(images[0]?.url);
        render();
      }));
      list.appendChild(li);
    });
    fileInput.disabled = images.length >= MAX;
  };

  // Foto im Browser verkleinern (lange Seite), als JPG
  const loadImage = async (file) => {
    if ('createImageBitmap' in window) {
      try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch {}
    }
    return await new Promise((res, rej) => {
      const img = new Image();
      img.onload = () => res(img);
      img.onerror = () => rej(new Error('Dieses Bildformat kann der Browser nicht lesen. Bitte als JPG oder PNG speichern.'));
      img.src = URL.createObjectURL(file);
    });
  };
  const resize = (src, longSide, quality) => {
    const w0 = src.width, h0 = src.height;
    const k = Math.min(1, longSide / Math.max(w0, h0));
    const c = document.createElement('canvas');
    c.width = Math.round(w0 * k); c.height = Math.round(h0 * k);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(src, 0, 0, c.width, c.height);
    return new Promise((r) => c.toBlob((b) => r({ blob: b, w: c.width, h: c.height }), 'image/jpeg', quality));
  };

  const busy = (on) => {
    pending += on ? 1 : -1;
    submit.disabled = pending > 0;
    status.textContent = pending > 0 ? `Lade ${pending} Foto${pending > 1 ? 's' : ''} hoch …` : '';
  };

  fileInput.addEventListener('change', async () => {
    const files = [...fileInput.files].slice(0, MAX - images.length);
    fileInput.value = '';
    for (const file of files) {
      busy(true);
      try {
        const src = await loadImage(file);
        const full = await resize(src, 1600, 0.86);
        const sm = await resize(src, 720, 0.82);
        const xs = await resize(src, 420, 0.8);
        const fd = new FormData();
        fd.append('full', full.blob, 'full.jpg');
        fd.append('sm', sm.blob, 'sm.jpg');
        fd.append('xs', xs.blob, 'xs.jpg');
        const res = await fetch('/atelier/upload', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.url) throw new Error(data.error || 'Hochladen fehlgeschlagen');
        const guessUV = /uv|schwarz|black/i.test(file.name) && !images.some((x) => x.role === 'uv');
        images.push({ url: data.url, role: images.length === 0 ? 'haupt' : guessUV ? 'uv' : 'ansicht' });
        if (images.length === 1) { $('[data-at-w]', form).value = full.w; $('[data-at-h]', form).value = full.h; }
        render();
      } catch (e) {
        alert(`„${file.name}“: ${e.message}`);
      }
      busy(false);
    }
  });

  form.addEventListener('submit', (e) => {
    if (pending > 0) { e.preventDefault(); alert('Bitte warten, bis alle Fotos hochgeladen sind.'); return; }
    jsonInput.value = JSON.stringify(images);
    submit.disabled = true;
    submit.textContent = 'Wird gespeichert …';
  });

  render();
  if (images[0] && !$('[data-at-w]', form).value) setMainSize(images[0].url);
})();
