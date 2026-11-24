# MH ART Shop – Matthias Hübner

Schlanker Online-Shop für Originalgemälde unter **https://matthiashuebner.art**. Kein Shopsystem, kein Abo:
PHP und MariaDB auf einem Hetzner-Webhosting, **Stripe** nur als Kasse. Matthias pflegt seine Bilder selbst
auf der passwortgeschützten Seite `/atelier` (Fotos, Preise, Texte, Verkauft/Ausblenden).

> Die frühere Netlify-Fassung (Node.js, Stripe als Artikelverwaltung) liegt auf dem Branch `main`.

## Was der Shop kann

**Gestaltungsidee:** Schwarz-Weiß als Fundament, Neon als Kontrast, wie in Matthias' Bildern. Im Tageslicht bewusst reduziert: weißer Grund wie auf novaspes.de, Schwarz-Weiß, breite Archivo-Schrift (passend zum Logo) mit Serif-Kursiv als Akzent. Neon und sein „Farbstrich“ erscheinen erst, wenn der UV-Licht-Schalter an ist.

- **UV-Licht-Schalter (oben rechts):** Die ganze Seite geht in den Schwarzlicht-Modus. Werke mit echter UV-Aufnahme wechseln auf dieses Foto und leuchten, alle anderen treten dunkel zurück. Die Wahl bleibt beim nächsten Besuch erhalten.
- **Tageslicht/Schwarzlicht-Regler** im Hero, in der Sektion „Schwarzlicht“ und auf jeder Werkseite mit UV-Foto
- **Werkseite mit Ansichten:** Tageslicht, Schwarzlicht, Vergleich, Details und Raumansichten (als Visualisierung gekennzeichnet), Zoom, Lightbox zum Blättern, ← / → zum vorigen/nächsten Werk
- **Größenvergleich:** jedes Werk in echter Größe an der Wand neben einer 1,75-m-Person (und über einem Sofa, wenn es passt), automatisch aus den Maßen
- **Auszeichnungen und Ausstellungen** je Werk und als Übersicht auf der Startseite (Pflege in `src/View/Artist.php`)
- Startseite mit Filtern (verfügbar, Format, Serie, Preis, Merkliste) und Sortierung; **teilbare Filter-Links**, z. B. `/?filter=uv`, `/?filter=bis-1000`, `/?filter=gross&sort=preis-auf`; `?uv=1` öffnet direkt im Schwarzlicht-Modus
- **„Original kaufen“** → Stripe Checkout (Karte, Apple Pay, Google Pay, SEPA, Klarna, PayPal – je nach Freischaltung im Stripe-Dashboard)
- **Unikat-Logik:** Beim Klick auf „Kaufen“ ist das Werk 30 Minuten reserviert, garantiert nur für einen Käufer. Nach Zahlung automatisch „Verkauft“, bei Abbruch oder Ablauf sofort wieder frei
- Werke ohne Preis: „Preis auf Anfrage“ mit **Anfrage-Formular** (Preisanfrage, Frage, Warteliste, ähnliches Werk); ohne JavaScript greift der E-Mail-Link
- **Live-Status** über `/api/status`: „Reserviert“/„Verkauft“ erscheint sofort, der Kauf-Button sperrt sich
- Versand nach Formatklasse (klein/mittel/groß) plus kostenlose Abholung im Atelier; Kleinunternehmer-Hinweis nach § 19 UStG
- Impressum, AGB, Widerruf (mit Muster-Formular), Versand & Zahlung, Datenschutz
- **Widerrufsbutton** „Vertrag widerrufen“ nach § 356a BGB mit automatischer Eingangsbestätigung per E-Mail
- **Merkliste** (nur im Browser), **Teilen**, Menü auf dem Handy
- Schnelle Bilder (WebP in drei Größen), Sitemap mit Bildern, robots.txt, Open-Graph, schema.org-Produktdaten
- **Google-Shopping-Feed** unter `/feed.xml`; optional Google-Ads-Conversion-Tracking, lädt erst nach Einwilligung
- **Gutscheincodes** optional (`ALLOW_PROMOTION_CODES=true`, Codes im Stripe-Dashboard)
- Hinweis im Seitenfuß „Realisiert mit Unterstützung von Tobias Wolfrum Consulting“ (`CREDIT_LABEL` / `CREDIT_URL`)
- Ohne Stripe-Schlüssel: Werke sichtbar, Kaufen gesperrt („Demo-Modus“)

## Sicherheit und Datenschutz

- **Personenbezogene Daten** (Widerrufe, Anfragen) liegen nur verschlüsselt in der Datenbank: libsodium XChaCha20-Poly1305, Schlüssel `DATA_ENCRYPTION_KEY`, Kontext je Eintrag gebunden (Manipulation wird erkannt). Ohne Schlüssel wird nichts gespeichert, schon gar nicht im Klartext. Schlüsselwechsel über `DATA_ENCRYPTION_KEY_OLD`.
- **Käuferdaten** (Name, Adresse, Telefon) bleiben bei Stripe. In der Datenbank steht nur die Bestellnummer.
- **Löschfristen** (Cron): Anfragen nach 12 Monaten, Widerrufe nach 3 Jahren (`RETENTION_*`).
- **Atelier:** Anmeldung mit Benutzername, Passwort (Argon2id-Hash) und Code aus einer Authenticator-App (TOTP, jeder Code nur einmal gültig; Geheimnis verschlüsselt). Sitzungen serverseitig (Abmelden, neues Passwort oder Sperren machen sie ungültig), CSRF-Token in allen Formularen, Sperre nach 10 Fehlversuchen je IP bzw. 20 je Benutzer.
- **Missbrauchsschutz:** je IP begrenzt sind Bezahlvorgänge (Reservierungen), Widerrufe und Anfragen; Bestätigungsmails je Empfänger. Gespeichert werden nur geheime Hashes (HMAC), nie IP oder E-Mail im Klartext.
- **Stripe-Webhook** prüft die Signatur, jedes Ereignis wird nur einmal verarbeitet.
- **Uploads:** nur JPG, mit GD neu gespeichert (entfernt Metadaten wie GPS), im Ordner `public/bilder/` laufen keine Skripte.
- **Header:** strenge Content-Security-Policy (keine fremden Skripte außer Google nach Einwilligung), HSTS, nosniff, Frame-Schutz.

## Für Matthias: Werke pflegen im Atelier

Alles unter **`https://matthiashuebner.art/atelier`**, am Rechner oder am Handy. Anmelden mit Benutzername, Passwort und dem 6-stelligen Code aus der Authenticator-App; die Anmeldung hält 30 Tage.

**Zugänge verwalten** (per SSH): `php bin/atelier-user.php anlegen <name>` (neuer Zugang: zeigt einen QR-Code zum Scannen mit der Authenticator-App, den Schlüssel zum Eintippen und 10 Notfall-Codes zum Ausdrucken), `passwort <name>`, `2fa <name>` (neues Handy), `notfallcodes <name>`, `sperren <name>`, `liste`. Für helle Terminals `--hell` anhängen.

**Handy verloren?** Auf der Anmeldeseite „Notfall-Code verwenden“ aufklappen und einen der ausgedruckten Codes eingeben (jeder gilt nur einmal). Danach per SSH `2fa <name>` ausführen und die App neu einrichten.

- **Neues Werk:** „+ Neues Werk“ → Fotos hinzufügen → Titel, Technik, Maße, Beschreibung, Preis → „Werk anlegen“
- **Fotos:** bis zu 8 je Werk. Das erste ist das Hauptfoto. Bei jedem weiteren auswählen, was es zeigt: *Schwarzlicht*, *Detail*, *Im Raum* oder *Ansicht*. Mit ↑ ↓ sortieren. Große Handyfotos werden beim Hochladen automatisch verkleinert. Heißt die Datei z. B. `nacht-uv.jpg`, wird sie als Schwarzlicht-Foto erkannt.
- **Preis:** einfach die Zahl, z. B. `2400` oder `2.400 €`. Leer = „Preis auf Anfrage“
- **Status:** Verfügbar / Verkauft (z. B. bei Verkauf in der Galerie). Verkäufe über den Shop stellt der Shop selbst um
- **Sichtbarkeit:** „Ausblenden“ nimmt ein Werk vorübergehend aus dem Shop
- **Position:** kleine Zahl = weiter vorn
- **Werk entfernen:** unten auf der Werkseite (wird archiviert, Bestellungen bleiben nachvollziehbar)
- **Anfragen** und **Widerrufe:** eigene Seiten im Atelier; jede kommt zusätzlich per E-Mail

Änderungen sind sofort im Shop sichtbar.

**Bei einem Verkauf** kommt die Stripe-Benachrichtigung mit Name, Adresse und Telefon des Käufers. **Bei einem Widerruf:** Rückgabe abstimmen, in Stripe erstatten und das Werk im Atelier wieder auf „Verfügbar“ stellen.

## Lokal entwickeln

Voraussetzungen: PHP 8.3+ mit `pdo_mysql`, `sodium`, `gd`, `mbstring`, `curl`; Composer; MySQL 8 oder MariaDB.

```bash
composer install
cp .env.example .env            # DB-Zugang eintragen, APP_ENV=local, SITE_URL=http://localhost:8888
php bin/generate-key.php        # → DATA_ENCRYPTION_KEY in die .env
php bin/migrate.php
php bin/atelier-user.php anlegen matthias   # Passwort + Authenticator-App einrichten
php bin/import-catalog.php      # 33 Werke aus data/catalog.json
composer serve                  # http://localhost:8888
composer test                   # PHPUnit: Unit- und Ende-zu-Ende-Tests
```

Die Ende-zu-Ende-Tests starten selbst einen PHP-Server und einen Stripe-Ersatz und nutzen eine eigene Datenbank
`mhart_shop_test` (gleiche Zugangsdaten, abweichend über `TEST_DB_*`). Sie wird bei jedem Lauf geleert.

## Einrichtung auf Hetzner (Erstausrollung)

Managed Server bzw. Webhosting: kein `sudo`, kein `apt`. Datenbank, SSL, Domain, Cron und PHP-Version in **konsoleH**,
Dateien per **FTP**, alles Weitere per **SSH**.

1. **Domain** `matthiashuebner.art` in konsoleH anlegen, **Dokumentenwurzel auf `…/matthiashuebner.art/public`** (mit `/public` am Ende!), PHP-Version 8.3 oder neuer
2. **SSL** (Let's Encrypt) in konsoleH aktivieren und warten, bis das Zertifikat steht
3. **Datenbank** in konsoleH anlegen, Zugangsdaten notieren; **Postfach** `anfragen@matthiashuebner.art` für SMTP
4. Per SSH: PHP-Binary und Erweiterungen prüfen, Ordner anlegen
   ```bash
   ls /usr/bin/php*
   /usr/bin/php8.4 -m | grep -E 'pdo_mysql|sodium|gd|curl|mbstring'
   mkdir -p ~/logs ~/run ~/backups
   ```
5. Lokal: `composer test`, dann `php bin/build-release.php` – stellt `build/release/` zusammen (mit `vendor/` ohne Entwicklungspakete, ohne `.env`, Tests und Git-Daten)
6. Inhalt von `build/release/` per **FTP** nach `/public_html/matthiashuebner.art` hochladen
7. `.env` auf dem Server anlegen (Vorlage `.env.example`), `chmod 600 .env`. Dort `LOG_DIR` und `RUN_DIR` auf `~/logs` und `~/run` (absolute Pfade)
8. Schema und Werke, dann Selbsttest:
   ```bash
   cd ~/public_html/matthiashuebner.art
   /usr/bin/php8.4 bin/migrate.php
   /usr/bin/php8.4 bin/import-catalog.php
   /usr/bin/php8.4 bin/atelier-user.php anlegen matthias    # Passwort, QR-Code, Notfall-Codes
   /usr/bin/php8.4 bin/atelier-user.php anlegen christian
   /usr/bin/php8.4 bin/smoke.php
   ```
9. **Dokumentenwurzel prüfen – nur mit Markierungsdatei**, nie durch Abrufe von `/.env`, `/vendor/…` o. Ä. (Hetzner sperrt sonst die anfragende IP):
   ```bash
   M=twc-wurzel-$(date +%s); echo innen > public/$M.txt; echo aussen > $M.txt; echo $M
   # im Browser genau https://matthiashuebner.art/<M>.txt öffnen: muss "innen" zeigen
   rm -f public/$M.txt $M.txt
   ```
10. **Proxy prüfen:** Im Atelier anmelden und im Server-Log schauen, ob Einträge echte Besucher-IPs zeigen. Sitzt ein Proxy vor Apache, dessen Adresse in `TRUSTED_PROXIES` eintragen – sonst zählen alle Besucher als eine IP
11. **Stripe** (Konto auf Matthias, zuerst im Testmodus):
    - Zahlungsmethoden aktivieren, Branding (Logo `public/assets/logo-mhart.png`, Farbe #1f1e24), Kunden-E-Mails „Erfolgreiche Zahlungen“, Website- und AGB-URL eintragen
    - Webhook: `https://matthiashuebner.art/api/stripe-webhook` mit den Ereignissen `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`; Signaturgeheimnis als `STRIPE_WEBHOOK_SECRET`
12. **Testkauf** mit Testkarte `4242 4242 4242 4242`: Werk muss danach „Verkauft“ zeigen, Bestellung im Atelier/Stripe sichtbar. Testabbruch: Werk sofort wieder frei. Danach im Atelier zurück auf „Verfügbar“
13. **Cron** in konsoleH, alle 5 Minuten, absolute Pfade:
    `/usr/bin/php8.4 /home/BENUTZER/public_html/matthiashuebner.art/bin/cron.php`
    Nach zehn Minuten zeigt `bin/smoke.php` den letzten Lauf
14. **Sicherung:** Datenbank-Backup in konsoleH aktivieren; `.env` (mit `DATA_ENCRYPTION_KEY`!) und `public/bilder/` mitsichern
15. Auf **Live-Schlüssel** umstellen (`sk_live_…`, neuer Webhook im Live-Modus), Google Merchant Center: Feed `https://matthiashuebner.art/feed.xml`

**Nach jedem späteren Deployment:** `php bin/build-release.php`, hochladen, dann `bin/migrate.php` und `bin/smoke.php`.

## Aufbau

```
public/index.php          einziger Einstiegspunkt (Router), .htaccess leitet alles hierher
public/assets, img, fonts CSS, JavaScript, Logos, Werkfotos, Schriften (lokal, DSGVO)
public/bilder/            Uploads aus dem Atelier (keine Skriptausführung)
src/Controller/           Shop, Checkout/Webhook, Widerruf, Anfrage, Atelier
src/View/                 HTML der Seiten, Rechtstexte, Künstlerdaten
src/                      Konfiguration, Datenbank, Werkkatalog, Verschlüsselung, Begrenzung, Mail, CSP
migrations/               Datenbankschema
bin/                      migrate, import-catalog, cron, smoke, atelier-user, generate-key, build-release
data/catalog.json         Startbestand der Werke
tests/                    PHPUnit (Unit, Ende-zu-Ende mit Stripe-Ersatz)
```

Laufende Kosten: das Hetzner-Paket; Stripe nur Transaktionsgebühren (ca. 1,5 % + 0,25 € je EU-Kartenzahlung). Keine Shop-Provision.

## Vor dem Livegang prüfen
- [ ] Rechtstexte (AGB, Widerruf, Datenschutz) juristisch prüfen lassen; Datenschutzerklärung an Hetzner (statt Netlify) und die gespeicherten Anfragen/Widerrufe samt Löschfristen anpassen
- [ ] Kleinunternehmer-Status bestätigen (sonst `KLEINUNTERNEHMER=false`; Originale von Künstlern laufen dann mit 7 % USt)
- [ ] Versandpreise und Lieferländer mit Matthias abstimmen
- [ ] Preise der Werke ohne Preis im Atelier ergänzen, wenn sie direkt kaufbar sein sollen
- [ ] Testkauf und Testabbruch im Stripe-Testmodus
- [ ] SMTP eingetragen und Test-Widerruf abgeschickt (Bestätigung kommt beim Käufer und bei Matthias an)
- [ ] Atelier-Zugänge `matthias` und `christian` angelegt (`bin/atelier-user.php`), Authenticator-Apps eingerichtet, Notfall-Codes ausgedruckt, Atelier einmal mit Matthias durchgegangen
- [ ] `DATA_ENCRYPTION_KEY` im Passwortmanager gesichert
- [ ] Cron läuft, Datenbanksicherung aktiv
