-- Notfall-Codes: die ersten zwei Zeichen als Kennung, damit beim Anmelden nur der passende Hash geprüft wird
-- (statt bis zu zehn langsamer Argon2id-Prüfungen nacheinander). Ältere Codes ohne Kennung werden weiter durchsucht.

ALTER TABLE atelier_notfallcodes
  ADD COLUMN kennung CHAR(2) NULL AFTER code_hash;
