// Vergleichs-Harness: rendert alle Seiten mit den Node-Modulen nach tests/parity/out/node/.
// Aufruf über tests/parity/run.sh (setzt die Umgebungsvariablen und PARITY_VARIANT).
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { listWorks } from '../../lib/catalog.mjs';
import { renderHome, renderWork, renderThanks, renderNotFound } from '../../lib/render.mjs';
import { renderLegal, LEGAL_KEYS } from '../../lib/legal.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, 'out', 'node');
const variant = process.env.PARITY_VARIANT || 'default';
mkdirSync(out, { recursive: true });
const write = (name, html) => writeFileSync(join(out, `${variant}--${name}.html`), html);

const fixtures = JSON.parse(readFileSync(join(here, 'fixtures.json'), 'utf8'));
const works = await listWorks({ fresh: true });
const all = [...works, ...fixtures.edgeWorks];

write('home', renderHome(works));
write('home-edge', renderHome(all));
for (const w of works) write(`werk-${w.slug}`, renderWork(w, works));
for (const w of fixtures.edgeWorks) write(`werk-${w.slug}`, renderWork(w, all));
for (const key of LEGAL_KEYS) write(`legal-${key}`, renderLegal(key));
write('notfound', renderNotFound());
write('thanks-empty', renderThanks({ session: null, work: null }));
for (const [name, { session, workSlug }] of Object.entries(fixtures.sessions)) {
  write(`thanks-${name}`, renderThanks({ session, work: all.find((w) => w.slug === workSlug) || null }));
}
