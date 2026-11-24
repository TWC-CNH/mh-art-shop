#!/usr/bin/env bash
# Rendert alle Seiten mit Node (lib/) und PHP (src/View/) und vergleicht die Ausgaben bytegenau.
# Exit-Code ungleich 0 bei jeder Abweichung.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php 2>/dev/null || echo /c/php85/php.exe)}"
NODE_BIN="${NODE_BIN:-node}"

rm -rf "$HERE/out"
mkdir -p "$HERE/out/node" "$HERE/out/php"

# Alle Schlüssel, die die Templates lesen, ausdrücklich setzen (leer = Standardwert in beiden Fassungen),
# damit weder eine .env noch die Umgebung des Rechners das Ergebnis beeinflusst.
base_env() {
  export STRIPE_SECRET_KEY='' URL='' APP_ENV=''
  export SITE_URL='https://shop.example.test'
  export SHOP_NAME='' ARTIST_NAME='' SHOP_TAGLINE='' CONTACT_EMAIL='' CONTACT_PHONE=''
  export INSTAGRAM_URL='' FACEBOOK_URL='' MAIN_SITE_URL=''
  export LEGAL_NAME='' LEGAL_STREET='' LEGAL_ZIP='' LEGAL_CITY='' VAT_ID=''
  export KLEINUNTERNEHMER='true'
  export SHIPPING_SMALL_EUR='' SHIPPING_MEDIUM_EUR='' SHIPPING_LARGE_EUR='' SHIPPING_COUNTRIES=''
  export PICKUP_ENABLED='' PICKUP_LABEL='' DELIVERY_DAYS='' RESERVE_MINUTES='' SHOW_SOLD=''
  export CREDIT_LABEL='' CREDIT_URL='' GOOGLE_TAG_ID='' GOOGLE_ADS_CONVERSION=''
}

render() {
  export PARITY_VARIANT="$1"
  (cd "$ROOT" && "$NODE_BIN" "$HERE/render-node.mjs")
  (cd "$ROOT" && "$PHP_BIN" "$HERE/render-php.php")
}

# Variante a: Standardwerte, Google-Tag und Ads-Conversion gesetzt, Kleinunternehmer
(
  base_env
  export GOOGLE_TAG_ID='G-TEST12345' GOOGLE_ADS_CONVERSION='AW-123456789/AbC-dE_f'
  render a
)

# Variante b: ohne Google, Regelbesteuerung, ohne Abholung, verkaufte Werke ausgeblendet, abweichende Werte
(
  base_env
  export SITE_URL='https://shop.example.test/'
  export KLEINUNTERNEHMER='false' PICKUP_ENABLED='nein' SHOW_SOLD='false'
  export SHIPPING_SMALL_EUR='29.5' SHIPPING_MEDIUM_EUR='0' SHIPPING_LARGE_EUR='120' SHIPPING_COUNTRIES='de, at,,ch'
  export RESERVE_MINUTES='45' DELIVERY_DAYS='5–10' SHOP_TAGLINE="Kunst & <Nacht> 'x'"
  export ARTIST_NAME='Max "Mustermann"' CREDIT_URL='https://example.test/?a=1&b=2'
  render b
)

count=$(ls "$HERE/out/node" | wc -l | tr -d ' ')
echo "Seiten verglichen: $count (Node) / $(ls "$HERE/out/php" | wc -l | tr -d ' ') (PHP)"
if diff -r "$HERE/out/node" "$HERE/out/php" > "$HERE/out/diff.txt"; then
  echo "Keine Unterschiede."
else
  echo "UNTERSCHIEDE gefunden (Auszug, vollständig in tests/parity/out/diff.txt):"
  head -n 60 "$HERE/out/diff.txt"
  exit 1
fi
