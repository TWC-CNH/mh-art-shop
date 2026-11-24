<?php
// Kleine globale Helfer, die in Templates und Controllern gebraucht werden.
declare(strict_types=1);

if (!function_exists('e')) {
    /** HTML-Escaping wie esc() in der früheren Node-Fassung: & < > " ' */
    function e(mixed $s): string
    {
        // Bewusst identisch zur früheren JS-Fassung (gleiche Ausgabe, z. B. &#39; für Apostroph).
        $s = mb_scrub((string) ($s ?? ''), 'UTF-8');
        return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }
}

if (!function_exists('cfg')) {
    /** Konfiguration als Array (Schlüssel wie früher getConfig() in Node). */
    function cfg(): array
    {
        return \App\Config::get();
    }
}
