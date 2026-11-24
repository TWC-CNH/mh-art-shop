<?php
declare(strict_types=1);

namespace App\View;

/**
 * Inhalte über den Künstler – Quelle: novaspes.de (Biographie, Auszeichnungen, Ausstellungen), Stand 23.11.2026.
 * Neue Ausstellungen in EXHIBITIONS nach Startdatum absteigend einsortieren; start/end im Format JJJJ-MM-TT
 * (steuert "Demnächst"/"Zuletzt"). Die Node-Fassung sortierte zur Laufzeit, hier steht die Liste bereits sortiert.
 */
final class Artist
{
    public const ARTIST = [
        'bio' => [
            'Geboren 1976 in Querfurt, lebt und arbeitet im Raum Leipzig. Ein introvertierter Künstler, der große Auftritte meidet und sich ganz seiner inneren Bildwelt widmet. Neben der Malerei komponiert und produziert er für die Electro-/Industrial-Band NOVA-SPES.',
            'Über die eigenen Bilder spricht er ungern, mit Absicht: Seine Werke geben keine Deutung vor. Sie öffnen einen Raum, den jede Betrachterin und jeder Betrachter selbst füllt.',
        ],
        'principles' => [
            ['title' => 'Schwarz-Weiß', 'text' => 'ist das Fundament jedes Werks.'],
            ['title' => 'Neon', 'text' => 'bricht die Komposition auf und erzeugt Spannung.'],
            ['title' => 'Die Nacht', 'text' => 'ist seine Arbeitszeit. Dann entstehen die stärksten Kontraste.'],
            ['title' => 'Freie Deutung', 'text' => 'Kein Bild schreibt vor, was man darin sieht.'],
        ],
        'ticker' => ['Modern Surrealistic Realism', 'Schwarz-Weiß als Fundament', 'Neon als Kontrast', 'Entstanden in der Nacht', 'UV-reaktiv', 'Jedes Werk ein Unikat'],
    ];

    public const EXHIBITIONS = [
        ['start' => '2027-05-01', 'end' => '2027-07-31', 'when' => 'Mai – Juli 2027', 'title' => 'Einzelausstellung', 'place' => 'Innenhofgalerie Bergmannstrost, Halle (Saale)'],
        ['start' => '2027-02-01', 'end' => '2028-02-29', 'when' => 'ab Februar 2027', 'title' => 'Modrý Brouk Art Exhibition', 'place' => 'Slovanský dům, Prag', 'works' => [['slug' => 'schock', 'title' => 'SCHOCK'], ['slug' => 'digital', 'title' => 'DIGITAL']]],
        ['start' => '2026-12-02', 'end' => '2026-12-05', 'when' => '02. – 05.12.2026', 'title' => 'Arrival Gallery Madrid Art Show', 'place' => 'Galería Nueva, Madrid', 'works' => [['slug' => 'neon-scream', 'title' => 'NEON SCREAM']]],
        ['start' => '2026-09-11', 'end' => '2026-09-17', 'when' => 'September 2026', 'title' => 'Open Art Festival ART.NUMBER23', 'place' => 'Rom'],
        ['start' => '2026-08-30', 'end' => '2026-08-30', 'when' => 'August 2026', 'title' => 'Farben des August (Einzelausstellung)', 'place' => 'TV Club Leipzig'],
        ['start' => '2026-08-07', 'end' => '2026-08-10', 'when' => 'August 2026', 'title' => 'Cosy Festival', 'place' => 'Schloss Dornburg an der Elbe'],
        ['start' => '2026-07-24', 'end' => '2026-08-20', 'when' => 'Juli – August 2026', 'title' => 'The Fusion Art Gallery', 'place' => 'Málaga'],
        ['start' => '2026-05-09', 'end' => '2026-05-31', 'when' => 'Mai 2026', 'title' => 'FAVORI N°1 – GENESIS', 'place' => 'Schloss Gleina'],
        ['start' => '2026-04-29', 'end' => '2026-05-02', 'when' => 'April 2026', 'title' => 'Arrival Gallery Madrid Art Show', 'place' => 'Madrid'],
        ['start' => '2026-04-11', 'end' => '2026-05-02', 'when' => 'April 2026', 'title' => 'BAZAAR OF NEON', 'place' => 'Neonbazaar Art Gallery, Stuttgart'],
        ['start' => '2026-01-31', 'end' => '2026-02-28', 'when' => 'Februar 2026', 'title' => 'PROVOCATEUR – Art of Disturbance', 'place' => 'Neonbazaar Art Gallery, Stuttgart'],
        ['start' => '2025-12-03', 'end' => '2025-12-07', 'when' => 'Dezember 2025', 'title' => 'Paris Art Expo', 'place' => 'Galerie 24b, Paris'],
        ['start' => '2025-04-12', 'end' => '2025-06-10', 'when' => 'April – Juni 2025', 'title' => 'FARBton (Einzelausstellung)', 'place' => 'Galerie TscharT, Leipzig'],
    ];

    public const AWARDS = [
        ['org' => 'Circle Art Foundation', 'title' => 'Art Star Award, Artist of the Year 2026'],
        ['org' => 'Accent Gallery', 'title' => 'Curators Choice Award, The White Exhibition 2026', 'works' => [['slug' => 'oberflaeche', 'title' => 'OBERFLÄCHE']]],
        ['org' => 'Accent Gallery', 'title' => 'Curators Choice Award, Black & Gold Art Show 2026'],
        ['org' => 'Fame Frame Gallery', 'title' => 'Merit Award, Faces of Humanity 2025', 'works' => [['slug' => 'neon-scream', 'title' => 'NEON SCREAM']]],
        ['org' => 'ARTAVITA', 'title' => 'Finalist, Artist of the Year 2025 (einer von 82 bei 857 Teilnehmern)', 'works' => [['slug' => 'generation', 'title' => 'GENERATION'], ['slug' => 'schock', 'title' => 'SCHOCK']]],
        ['org' => 'La Pinacothèque, Luxemburg', 'title' => 'Verdiensturkunde, Luxembourg Art Prize 2025', 'works' => [['slug' => 'chaos', 'title' => 'CHAOS'], ['slug' => 'adhs', 'title' => 'ADHS'], ['slug' => 'digital', 'title' => 'DIGITAL']]],
        ['org' => 'Teravarna Art Gallery, New York', 'title' => 'Honorable Mention, 10th FIGURATIVE International Juried Art Competition 2025', 'works' => [['slug' => 'schock', 'title' => 'SCHOCK']]],
    ];
}
