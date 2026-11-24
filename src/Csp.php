<?php
declare(strict_types=1);

namespace App;

/**
 * Content-Security-Policy: Skripte nur von der eigenen Seite (plus Google, wenn ein Tag eingerichtet ist).
 * Einziges eingebettetes Skript ist der Theme-Umschalter im <head>; er ist per Hash erlaubt.
 * Ein Test prüft, dass der Hash zum ausgelieferten Skript passt (tests/E2E/ShopTest.php).
 */
final class Csp
{
    /** Inhalt des Inline-Skripts in Render::layout() – bei Änderung dort auch hier anpassen. */
    public const THEME_SCRIPT = "try{if(localStorage.getItem('mh-theme')==='uv')document.documentElement.dataset.theme='uv'}catch(e){}";

    public static function header(): string
    {
        $hash = "'sha256-" . base64_encode(hash('sha256', self::THEME_SCRIPT, true)) . "'";
        $google = Config::get()['googleTagId'] !== '';
        $g = $google ? ' https://www.googletagmanager.com https://www.googleadservices.com https://googleads.g.doubleclick.net https://www.google.com' : '';
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' $hash" . $g,
            "style-src 'self' 'unsafe-inline'", // style-Attribute (Regler-Position, Größenvergleich)
            "img-src 'self' data: blob:" . ($google ? ' https://www.googletagmanager.com https://googleads.g.doubleclick.net https://www.google.com https://www.google.de' : ''),
            "font-src 'self'",
            "connect-src 'self'" . ($google ? ' https://www.google.com https://www.googleadservices.com https://googleads.g.doubleclick.net https://*.google-analytics.com https://*.analytics.google.com' : ''),
            'frame-src ' . ($google ? 'https://www.googletagmanager.com https://td.doubleclick.net' : "'none'"),
            "object-src 'none'",
            "base-uri 'self'",
            // Formular „Original kaufen“ ohne JavaScript leitet zu Stripe weiter
            "form-action 'self' https://checkout.stripe.com",
            "frame-ancestors 'self'",
        ]);
    }
}
