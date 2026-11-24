-- Grundschema MH ART Shop. Läuft auf MariaDB (Hetzner) und MySQL 8 (lokal).
-- Personenbezogene Daten stehen nur verschlüsselt in den Spalten *_enc (libsodium, siehe src/Crypto.php).

CREATE TABLE IF NOT EXISTS werke (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug                  VARCHAR(140) NOT NULL,
  titel                 VARCHAR(120) NOT NULL,
  jahr                  SMALLINT UNSIGNED NULL,
  serie                 VARCHAR(60)  NOT NULL DEFAULT '',
  technik               VARCHAR(200) NOT NULL DEFAULT '',
  masse                 VARCHAR(80)  NOT NULL DEFAULT '',
  format                VARCHAR(10)  NULL,              -- klein|mittel|gross, NULL = aus den Maßen
  beschreibung          TEXT         NULL,
  preis_cent            INT UNSIGNED NULL,              -- NULL = Preis auf Anfrage
  versand_cent          INT UNSIGNED NULL,              -- NULL = nach Formatklasse
  status                VARCHAR(10)  NOT NULL DEFAULT 'available', -- available|sold (reserviert ergibt sich aus reserviert_bis)
  reserviert_bis        DATETIME     NULL,
  reserviert_session    VARCHAR(255) NULL,
  verkauft_am           DATE         NULL,
  verkauft_session      VARCHAR(255) NULL,
  doppelverkauf_session VARCHAR(255) NULL,              -- Hinweis zur Erstattung, falls zweimal bezahlt
  sichtbar              TINYINT(1)   NOT NULL DEFAULT 1,
  aktiv                 TINYINT(1)   NOT NULL DEFAULT 1, -- 0 = aus dem Shop entfernt (archiviert)
  position              INT          NOT NULL DEFAULT 9999,
  bild_breite           INT UNSIGNED NULL,
  bild_hoehe            INT UNSIGNED NULL,
  thumb                 VARCHAR(600) NULL,
  auszeichnungen        TEXT         NULL,              -- JSON-Liste
  ausstellungen         TEXT         NULL,              -- JSON-Liste
  erstellt              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  geaendert             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_werke_slug (slug),
  KEY ix_werke_shop (aktiv, sichtbar, status, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS werk_bilder (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  werk_id     INT UNSIGNED NOT NULL,
  pfad        VARCHAR(600) NOT NULL,
  rolle       VARCHAR(10)  NOT NULL,                   -- haupt|uv|detail|raum|ansicht
  reihenfolge INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_bilder_werk (werk_id, reihenfolge),
  CONSTRAINT fk_bilder_werk FOREIGN KEY (werk_id) REFERENCES werke (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bestellungen: nur Verweis auf Stripe, keine Käuferdaten (die bleiben bei Stripe)
CREATE TABLE IF NOT EXISTS bestellungen (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_session_id VARCHAR(255) NOT NULL,
  werk_id           INT UNSIGNED NOT NULL,
  betrag_cent       INT UNSIGNED NULL,
  status            VARCHAR(20)  NOT NULL DEFAULT 'offen', -- offen|bezahlt|abgelaufen|fehlgeschlagen
  erstellt          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bezahlt_am        DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bestellungen_session (stripe_session_id),
  KEY ix_bestellungen_werk (werk_id),
  CONSTRAINT fk_bestellungen_werk FOREIGN KEY (werk_id) REFERENCES werke (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jedes Stripe-Ereignis nur einmal verarbeiten
CREATE TABLE IF NOT EXISTS webhook_events (
  event_id    VARCHAR(255) NOT NULL,
  typ         VARCHAR(100) NOT NULL,
  verarbeitet DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Widerrufe nach § 356a BGB. Name, E-Mail und Vertragsangabe nur verschlüsselt.
CREATE TABLE IF NOT EXISTS widerrufe (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ref            VARCHAR(40)  NOT NULL,
  eingang        DATETIME     NOT NULL,
  daten_enc      TEXT         NOT NULL,
  email_hmac     CHAR(64)     NOT NULL,
  mail_versendet TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_widerrufe_ref (ref),
  KEY ix_widerrufe_eingang (eingang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anfragen aus dem Shop (früher Netlify Forms). Kontaktdaten und Nachricht nur verschlüsselt.
CREATE TABLE IF NOT EXISTS anfragen (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ref        VARCHAR(40)  NOT NULL,
  eingang    DATETIME     NOT NULL,
  anliegen   VARCHAR(20)  NOT NULL DEFAULT 'allgemein',
  werk_slug  VARCHAR(140) NULL,
  daten_enc  TEXT         NOT NULL,
  email_hmac CHAR(64)     NOT NULL,
  erledigt   TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_anfragen_ref (ref),
  KEY ix_anfragen_eingang (eingang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Missbrauchsschutz: Zeitpunkte je Zweck und geheimem Hash (HMAC) von IP bzw. E-Mail
CREATE TABLE IF NOT EXISTS rate_limits (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket     VARCHAR(30)  NOT NULL,
  schluessel CHAR(64)     NOT NULL,
  zeitpunkt  DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_rate (bucket, schluessel, zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Angemeldete Geräte im Atelier (Cookie enthält ein Zufallstoken, hier steht nur dessen Hash)
CREATE TABLE IF NOT EXISTS atelier_sessions (
  token_hash CHAR(64) NOT NULL,
  erstellt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  laeuft_ab  DATETIME NOT NULL,
  PRIMARY KEY (token_hash),
  KEY ix_sessions_ablauf (laeuft_ab)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Merker für den Cron (z. B. wann die tägliche Löschung zuletzt lief)
CREATE TABLE IF NOT EXISTS merker (
  name  VARCHAR(60)  NOT NULL,
  wert  VARCHAR(255) NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
