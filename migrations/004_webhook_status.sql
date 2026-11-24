-- Webhook-Ereignisse: "fertig" erst nach erfolgreicher Verarbeitung. Bricht ein Lauf hart ab (Speicher, Zeitlimit),
-- bleibt fertig = 0 und eine spätere Zustellung von Stripe übernimmt das Ereignis wieder.
-- Bestehende Einträge gelten als erledigt (Standard 1); neue werden ausdrücklich mit 0 angelegt.

ALTER TABLE webhook_events
  ADD COLUMN fertig TINYINT(1) NOT NULL DEFAULT 1 AFTER typ;
