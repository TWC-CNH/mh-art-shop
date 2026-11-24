-- Zwei-Faktor: statt nur des letzten Zeitschritts die zuletzt benutzten Zeitschritte merken (kommagetrennt).
-- So gilt jeder Code genau einmal, und eine leicht vorgehende Handy-Uhr sperrt keinen späteren, korrekten Code aus.

ALTER TABLE atelier_benutzer
  ADD COLUMN totp_genutzt VARCHAR(100) NULL AFTER totp_letzter_schritt;
