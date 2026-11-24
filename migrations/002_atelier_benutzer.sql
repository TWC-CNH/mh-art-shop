-- Atelier-Anmeldung mit Benutzername, Passwort und Zwei-Faktor-Code (TOTP).
-- Das TOTP-Geheimnis steht nur verschlüsselt in der Datenbank (src/Crypto.php).

CREATE TABLE IF NOT EXISTS atelier_benutzer (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  benutzer             VARCHAR(60)  NOT NULL,
  passwort_hash        VARCHAR(255) NOT NULL,
  totp_enc             TEXT         NULL,
  totp_letzter_schritt BIGINT       NULL,     -- zuletzt benutzter Zeitschritt: jeder Code gilt nur einmal
  aktiv                TINYINT(1)   NOT NULL DEFAULT 1,
  erstellt             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  letzter_login        DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_benutzer (benutzer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bisherige Sitzungen (nur Passwort) sind damit ungültig
DELETE FROM atelier_sessions;

ALTER TABLE atelier_sessions
  ADD COLUMN benutzer_id INT UNSIGNED NOT NULL AFTER token_hash;

ALTER TABLE atelier_sessions
  ADD CONSTRAINT fk_sessions_benutzer FOREIGN KEY (benutzer_id) REFERENCES atelier_benutzer (id) ON DELETE CASCADE;
