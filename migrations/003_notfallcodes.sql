-- Notfall-Codes für das Atelier: einmalig verwendbar, falls das Handy mit der Authenticator-App fehlt.
-- Gespeichert wird nur ein Passwort-Hash (Argon2id) jedes Codes, nicht der Code selbst.

CREATE TABLE IF NOT EXISTS atelier_notfallcodes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  benutzer_id INT UNSIGNED NOT NULL,
  code_hash   VARCHAR(255) NOT NULL,
  benutzt     DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_notfall_benutzer (benutzer_id, benutzt),
  CONSTRAINT fk_notfall_benutzer FOREIGN KEY (benutzer_id) REFERENCES atelier_benutzer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
