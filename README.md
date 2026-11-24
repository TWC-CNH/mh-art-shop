# MH ART Shop – Matthias Hübner

Schlanker Online-Shop für Originalgemälde. Kein Shopsystem, kein Abo: Der Shop läuft kostenlos auf **Netlify**, **Stripe** ist gleichzeitig Kasse und Artikelverwaltung. Matthias pflegt seine Bilder selbst auf einer passwortgeschützten Seite `/atelier` (Fotos, Preise, Texte, Verkauft/Ausblenden), gespeichert wird alles in Stripe.

## Was der Shop kann

**Gestaltungsidee:** Schwarz-Weiß als Fundament, Neon als Kontrast, wie in Matthias' Bildern. Im Tageslicht bewusst reduziert: weißer Grund wie auf novaspes.de, Schwarz-Weiß, breite Archivo-Schrift (passend zum Logo) mit Serif-Kursiv als Akzent. Neon und sein „Farbstrich“ erscheinen erst, wenn der UV-Licht-Schalter an ist.

- **UV-Licht-Schalter (oben rechts):** Die ganze Seite geht in den Schwarzlicht-Modus. Werke, von denen es eine echte UV-Aufnahme gibt (aktuell 14), wechseln auf dieses Foto und leuchten; alle anderen treten dunkel zurück. Die Wahl bleibt beim nächsten Besuch erhalten.
- **Tageslicht/Schwarzlicht-Regler** im Hero, in der Sektion „Schwarzlicht“ (Werk auswählen, Regler ziehen) und auf jeder Werkseite mit UV-Foto
- **Werkseite mit Ansichten:** Tageslicht, Schwarzlicht, Vergleich, Details und Raumansichten (als Visualisierung gekennzeichnet)
- **Auszeichnungen und Ausstellungen** je Werk und als Übersicht auf der Startseite (Quelle: novaspes.de, Pflege in `lib/artist.mjs`)
- Künstler-Sektion mit Porträt, Biografie und seinen vier Grundsätzen

- Startseite mit allen Werken, Filter (verfügbar, Format, Serie) und Sortierung nach Preis
- Werkseite je Bild mit großer Ansicht und Zoom, Technik, Maßen, Preis, Beschreibung und Google-tauglichen Produktdaten (schema.org, Breadcrumbs)
- **Größenvergleich:** jedes Werk in echter Größe an der Wand neben einer 1,75-m-Person (und über einem Sofa, wenn es passt), automatisch aus den Maßen
- Schnelle Bilder: WebP in drei Größen (420/720/1600 px), Lighthouse lokal 95–97 Performance, 100 Barrierefreiheit/Best Practices/SEO
- „Original kaufen“ → Stripe-Checkout (Karte, Apple Pay, Google Pay, SEPA, Klarna, PayPal – je nach Freischaltung im Stripe-Dashboard)
- **Unikat-Logik:** Beim Klick auf „Kaufen“ ist das Werk 30 Minuten reserviert. Nach Zahlung wird es automatisch „Verkauft“, bei Abbruch oder Ablauf sofort wieder frei
- Werke ohne Preis zeigen „Preis auf Anfrage“ mit vorbereiteter Anfrage-Mail
- Versand nach Formatklasse (klein/mittel/groß) plus kostenlose Abholung im Atelier
- Kleinunternehmer-Hinweis nach § 19 UStG
- Impressum, AGB, Widerruf (mit Muster-Formular), Versand & Zahlung, Datenschutz
- Widerrufsbutton „Vertrag widerrufen“ nach § 356a BGB (Pflicht seit 19.06.2026) mit automatischer Eingangsbestätigung per E-Mail
- Atelier `/atelier`: Matthias pflegt Werke, Fotos, Preise und Status selbst (Passwort)
- Menü auf dem Handy
- Weißer Grund wie auf novaspes.de; „UV-Licht“-Schalter für eine dunkle Ansicht als Anspielung auf die UV-reaktiven Bilder
- Sitemap, robots.txt, Open-Graph-Vorschaubilder
- **Anfrage-Formular** (Preisanfrage, Frage zum Werk, Warteliste, ähnliches Werk, allgemeine Anfrage) als Dialog mit vorausgefülltem Text. Läuft über Netlify Forms, ohne JavaScript greift automatisch der E-Mail-Link
- **Merkliste**: Herz auf jeder Karte und Werkseite, Zähler oben rechts, Filter „Merkliste“ (nur im Browser gespeichert, keine Anmeldung)
- **Teilen** auf der Werkseite (Handy: Teilen-Menü, Desktop: Link kopieren)
- **Preisfilter** (bis 1.000 € / 1.000–2.500 € / ab 2.500 €) und **teilbare Filter-Links**, z. B. für Anzeigen: `/?filter=uv`, `/?filter=bis-1000`, `/?filter=gross&sort=preis-auf`, `/?filter=serie-schirm`; `?uv=1` öffnet die Seite direkt im Schwarzlicht-Modus
- **Live-Status**: Seiten sind schnell gecacht, „Reserviert“/„Verkauft“ wird trotzdem sofort nachgeladen (`/api/status`), der Kauf-Button sperrt sich dann
- **Lightbox zum Blättern** (Pfeile, Pfeiltasten, Wischen) und ← / → zum vorigen/nächsten Werk
- **Google-Shopping-Feed** unter `/feed.xml` (für das Google Merchant Center: kostenlose Produkteinträge und Shopping-Anzeigen) und Bild-Sitemap für die Google-Bildersuche
- **Gutscheincodes** optional (`ALLOW_PROMOTION_CODES=true`, Codes im Stripe-Dashboard anlegen)
- Kleiner Hinweis im Seitenfuß „Realisiert mit Unterstützung von Tobias Wolfrum Consulting“ mit Link auf tobiaswolfrum.de (änderbar über `CREDIT_LABEL` / `CREDIT_URL`)
- Optional Google-Ads-Conversion-Tracking, lädt erst nach Einwilligung
- Demo-Modus ohne Stripe-Schlüssel (zeigt die Werke aus `data/catalog.json`)

## Einrichtung (ca. 30 Minuten)

### 1. Stripe-Konto (auf Matthias)
1. Matthias legt ein Stripe-Konto auf seinen Namen an (er ist der Verkäufer): <https://dashboard.stripe.com/register>. Geschäftskonto als Auszahlungskonto hinterlegen.
2. Unter **Einstellungen → Zahlungsmethoden** die gewünschten Zahlarten aktivieren (Empfehlung: Karte, Apple Pay, Google Pay, PayPal, Klarna, SEPA-Lastschrift).
3. Unter **Einstellungen → Branding** Logo (`public/assets/logo-mhart.png`) und Farbe (#1f1e24) setzen, damit der Checkout nach MH ART aussieht.
4. Unter **Einstellungen → Kunden-E-Mails** „Erfolgreiche Zahlungen“ aktivieren (Käufer bekommt automatisch eine Quittung).
5. Unter **Einstellungen → Öffentliche Details** die Website-URL und die AGB-URL (`https://…/agb`) eintragen.

### 2. Netlify
1. Ordner in ein (privates) GitHub-Repo legen oder per Netlify-CLI deployen (`npx netlify deploy --prod`).
2. Neue Netlify-Site aus dem Repo anlegen. Build-Einstellungen kommen aus `netlify.toml`, es ist nichts weiter einzustellen.
3. **Environment variables** setzen (Vorlage: `.env.example`):
   - `STRIPE_SECRET_KEY` – erst den Testschlüssel `sk_test_…`, später `sk_live_…`
   - `SITE_URL` – z. B. `https://shop.novaspes.de`
   - `STRIPE_WEBHOOK_SECRET` – kommt in Schritt 4
4. Eigene Domain verbinden (z. B. `shop.novaspes.de` als CNAME auf die Netlify-Adresse).

### 3. Werke einmalig nach Stripe übertragen
Die 33 aktuell verfügbaren Werke inkl. Fotos, Maßen, Beschreibungen und Preisen (Preise laut Matthias' MenschKunst-Profil, Stand 23.11.2026; 26 Werke direkt kaufbar, 6 noch ohne Preis) liegen in `data/catalog.json`. Nach dem ersten Deploy:

```bash
npm install
STRIPE_SECRET_KEY=sk_test_... SITE_URL=https://shop.novaspes.de npm run seed
```

Das Skript kann beliebig oft laufen, es aktualisiert statt doppelt anzulegen. Für den Livegang dasselbe noch einmal mit `sk_live_…`.

### 4. Webhook anlegen
Stripe-Dashboard → **Entwickler → Webhooks → Endpunkt hinzufügen**
- URL: `https://shop.novaspes.de/api/stripe-webhook`
- Ereignisse: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `product.created`, `product.updated`, `product.deleted`, `price.created`, `price.updated`
- Signaturgeheimnis (`whsec_…`) als `STRIPE_WEBHOOK_SECRET` in Netlify eintragen und neu deployen.

### 5. Anfrage-Formular einschalten
Netlify → **Forms** → „Enable form detection“ aktivieren und neu deployen. Unter **Forms → Form notifications** eine E-Mail-Benachrichtigung an `anfragen@matthiashuebner.art` anlegen. Ab dann landet jede Anfrage aus dem Shop als E-Mail bei Matthias (Netlify Free: 100 Anfragen/Monat).

### 5a. E-Mail-Versand für den Widerrufsbutton (Pflicht)
Seit 19.06.2026 muss jeder Onlineshop einen Button „Vertrag widerrufen“ anbieten (§ 356a BGB). Der Shop hat ihn im Seitenfuß jeder Seite (`/vertrag-widerrufen`): Käufer geben Name, den Kauf und ihre E-Mail ein und klicken „Widerruf bestätigen“. Der Shop speichert den Widerruf als Nachweis (im Atelier unter „Widerrufe“), schickt dem Käufer **sofort** die gesetzlich vorgeschriebene Eingangsbestätigung mit Inhalt, Datum und Uhrzeit und informiert Matthias per E-Mail.

Dafür braucht der Shop die SMTP-Zugangsdaten des Postfachs `anfragen@matthiashuebner.art` (stehen beim E-Mail-Anbieter, z. B. IONOS, Strato, Google Workspace). In Netlify eintragen:

| Variable | Beispiel |
|---|---|
| `SMTP_HOST` | smtp.ionos.de |
| `SMTP_PORT` | 465 (oder 587) |
| `SMTP_USER` | anfragen@matthiashuebner.art |
| `SMTP_PASS` | Passwort des Postfachs |

Danach einmal selbst einen Test-Widerruf abschicken und prüfen, ob beide E-Mails ankommen.

### 6. Google Merchant Center (optional, für Shopping-Anzeigen)
Im Merchant Center einen Feed vom Typ „Geplanter Abruf“ mit der URL `https://shop.novaspes.de/feed.xml` anlegen (täglich). Werke ohne Preis und verkaufte Werke sind automatisch nicht enthalten.

### 7. Testkauf
Im Testmodus ein Werk kaufen (Testkarte `4242 4242 4242 4242`, beliebiges Datum in der Zukunft, beliebige Prüfziffer). Danach muss das Werk im Shop als „Verkauft“ erscheinen. Danach im Atelier wieder auf „Verfügbar“ stellen. Dann auf Live-Schlüssel umstellen.

## Für Matthias: Werke pflegen im Atelier

Matthias pflegt alles selbst unter **`/atelier`** (z. B. `https://shop.novaspes.de/atelier`), am Rechner oder am Handy. Anmelden mit dem Atelier-Passwort, die Anmeldung hält 30 Tage.

- **Neues Werk:** „+ Neues Werk“ → Fotos hinzufügen → Titel, Technik, Maße, Beschreibung, Preis → „Werk anlegen“
- **Fotos:** bis zu 8 je Werk. Das erste ist das Hauptfoto. Bei jedem weiteren Foto auswählen, was es zeigt: *Schwarzlicht*, *Detail*, *Im Raum* oder *Ansicht*. Mit ↑ ↓ sortieren, „Als Hauptfoto“ tauschen. Große Handyfotos werden beim Hochladen automatisch verkleinert (drei Größen für schnelle Ladezeiten). Heißt die Datei z. B. `nacht-uv.jpg`, wird sie automatisch als Schwarzlicht-Foto erkannt.
- **Preis:** einfach die Zahl, z. B. `2400` oder `2.400 €`. Leer = „Preis auf Anfrage“ mit Anfrage-Formular
- **Status:** Verfügbar / Verkauft (z. B. bei Verkauf in der Galerie). Verkäufe über den Shop stellt der Shop selbst auf „Verkauft“
- **Sichtbarkeit:** „Ausblenden“ nimmt ein Werk vorübergehend aus dem Shop, ohne es zu löschen
- **Position:** kleine Zahl = weiter vorn im Shop
- **Auszeichnungen / Ausstellungen:** eine pro Zeile
- **Werk entfernen:** unten auf der Werkseite. Das Werk wird in Stripe archiviert, die Verkaufshistorie bleibt erhalten

Änderungen sind nach spätestens ein paar Minuten im Shop sichtbar. Alles wird in Stripe gespeichert, die Fotos im Netlify-Speicher (Netlify Blobs, im kostenlosen Tarif enthalten).

**Einrichtung:** In Netlify unter *Environment variables* `ATELIER_PASSWORD` setzen (mindestens 12 Zeichen, nur Matthias und Tobias kennen es). Optional `ATELIER_SECRET` (beliebige lange Zeichenkette): Wird sie geändert, werden alle angemeldeten Geräte abgemeldet. Ohne `ATELIER_PASSWORD` ist das Atelier gesperrt.

**Alternativ** geht die Pflege auch direkt im Stripe-Dashboard (Produktkatalog). Dort ist allerdings nur ein Foto je Produkt möglich. Die Metadaten, die der Shop liest:

| Schlüssel | Beispiel | Wirkung |
|---|---|---|
| `technique` | Acryl auf Leinwand, UV reaktiv | Zeile „Technik“ |
| `size` | 100 x 70 x 2 cm | Zeile „Maße“, bestimmt Versandklasse |
| `year` | 2026 | Jahr hinter dem Titel |
| `series` | Schirm | Filter „Serie …“ |
| `sort` | 1 | Reihenfolge (kleine Zahl = weiter vorn) |
| `shipping_eur` | 120 | eigener Versandpreis für dieses Werk |
| `price_on_request` | true | Preis ausblenden, nur Anfrage |
| `hidden` | true | Werk vorübergehend ausblenden |
| `status` | sold | Werk als verkauft markieren |
| `uv` | 2 | Position des Schwarzlicht-Fotos in der Bilderliste |
| `kinds` | raum,detail | Art der übrigen Fotos in Reihenfolge |
| `awards` | Merit Award 2025 \| Finalist 2025 | Auszeichnungen, mit „\|“ getrennt |
| `exhibitions` | Madrid, 12/2026 | Ausstellungen des Werks, mit „\|“ getrennt |

**Bei einem Verkauf** bekommt Matthias die Stripe-Benachrichtigung mit Name, Adresse und Telefonnummer des Käufers. Das Werk steht automatisch auf „Verkauft“. **Bei einem Widerruf:** im Stripe-Dashboard erstatten und, wenn das Bild zurück ist, im Atelier wieder auf „Verfügbar“ stellen.

## Lokal entwickeln

```bash
npm install
npm run dev     # http://localhost:8888, ohne .env im Demo-Modus
npm test        # End-to-End-Test gegen einen lokalen Stripe-Ersatz
```

## Aufbau

```
netlify/functions/site.mjs            Seiten (Start, Werk, Danke, Rechtstexte, Sitemap) – serverseitig gerendert, CDN-gecached
netlify/functions/checkout.mjs        Stripe-Checkout starten + Reservierung
netlify/functions/cancel.mjs          Abbruch: Sitzung schließen, Werk freigeben
netlify/functions/stripe-webhook.mjs  Verkauft/freigeben, Cache leeren
lib/                                   Konfiguration, Katalog, Templates, Rechtstexte
public/                                CSS, JS, Logo, Schriften (lokal, DSGVO), Werkfotos
data/catalog.json                      Startbestand / Demo-Daten
scripts/seed-stripe.mjs               Werke nach Stripe übertragen
test/                                  End-to-End-Test
```

Laufende Kosten: Netlify Free (0 €), Stripe nur Transaktionsgebühren (ca. 1,5 % + 0,25 € je EU-Kartenzahlung). Keine Shop-Provision.

## Vor dem Livegang prüfen
- [ ] Rechtstexte (AGB, Widerruf, Datenschutz) juristisch prüfen lassen, z. B. über Händlerbund oder IT-Recht-Kanzlei
- [ ] Kleinunternehmer-Status bestätigen (sonst `KLEINUNTERNEHMER=false`; Originale von Künstlern laufen dann mit 7 % USt)
- [ ] Versandpreise und Lieferländer mit Matthias abstimmen
- [ ] Preise der Werke ohne Preis („auf Anfrage“) in Stripe ergänzen, wenn sie direkt kaufbar sein sollen
- [ ] Testkauf und Testabbruch im Stripe-Testmodus
- [ ] SMTP eingetragen und Test-Widerruf abgeschickt (Bestätigung kommt beim Käufer und bei Matthias an)
- [ ] Atelier-Passwort gesetzt, Atelier einmal mit Matthias durchgegangen
