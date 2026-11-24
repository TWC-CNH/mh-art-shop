<?php
declare(strict_types=1);

namespace App\View;

/**
 * Rechtstexte. Vor dem Livegang bitte prüfen lassen (z. B. Händlerbund, IT-Recht-Kanzlei oder Anwalt).
 */
final class Legal
{
    public const KEYS = ['impressum', 'agb', 'widerruf', 'versand', 'datenschutz'];

    private static function addr(array $c): string
    {
        return e($c['address']['name']) . '<br>' . e($c['address']['street']) . '<br>' . e($c['address']['zip']) . ' ' . e($c['address']['city']) . '<br>' . e($c['address']['country']);
    }

    /** @return array{title:string,html:string}|null */
    private static function page(string $key, array $c): ?array
    {
        $n = [Render::class, 'str'];
        switch ($key) {
            case 'impressum':
                return [
                    'title' => 'Impressum',
                    'html' => '<h1>Impressum</h1>
<h2>Angaben gemäß § 5 DDG</h2>
<p>' . self::addr($c) . '</p>
<h2>Kontakt</h2>
<p>E-Mail: <a href="mailto:' . e($c['contactEmail']) . '">' . e($c['contactEmail']) . '</a><br>Telefon: ' . e($c['contactPhone']) . '</p>
<h2>Umsatzsteuer</h2>
<p>Umsatzsteuer-Identifikationsnummer gemäß § 27a UStG: ' . e($c['vatId']) . ($c['kleinunternehmer'] ? '<br>Kleinunternehmer gemäß § 19 UStG, es wird keine Umsatzsteuer ausgewiesen.' : '') . '</p>
<h2>Berufsbezeichnung</h2>
<p>Bildender Künstler, Musiker</p>
<h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2>
<p>' . e($c['address']['name']) . ', Anschrift wie oben</p>
<h2>Verbraucherstreitbeilegung</h2>
<p>Ich bin nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>
<h2>Urheberrecht</h2>
<p>Alle Werke und Abbildungen auf dieser Website sind urheberrechtlich geschützt. Jede Verwendung außerhalb der Grenzen des Urheberrechts bedarf der schriftlichen Zustimmung von ' . e($c['address']['name']) . '. Der Erwerb eines Originals überträgt kein Vervielfältigungs- oder Nutzungsrecht.</p>',
                ];

            case 'agb':
                return [
                    'title' => 'Allgemeine Geschäftsbedingungen',
                    'html' => '<h1>Allgemeine Geschäftsbedingungen</h1>
<h2>§ 1 Geltungsbereich</h2>
<p>Diese AGB gelten für alle Bestellungen über den Online-Shop von ' . e($c['address']['name']) . ', ' . e($c['address']['street']) . ', ' . e($c['address']['zip']) . ' ' . e($c['address']['city']) . ' (nachfolgend „Verkäufer“). Verbraucher ist jede natürliche Person, die ein Rechtsgeschäft zu Zwecken abschließt, die überwiegend weder ihrer gewerblichen noch ihrer selbständigen beruflichen Tätigkeit zugerechnet werden können.</p>
<h2>§ 2 Vertragsgegenstand</h2>
<p>Angeboten werden Originalgemälde. Jedes Werk ist ein handsigniertes Unikat und wird mit einem Echtheitszertifikat (Certificate of Authenticity) geliefert. Die Abbildungen im Shop sind Fotografien der Originale; geringe Abweichungen in Farbe und Wirkung durch Bildschirm, Licht und Material sind möglich und kein Mangel. Mit dem Erwerb geht das Eigentum am Original über, nicht aber Urheber- oder Nutzungsrechte.</p>
<h2>§ 3 Vertragsschluss</h2>
<p>Die Darstellung der Werke im Shop ist kein rechtlich bindendes Angebot, sondern eine Aufforderung zur Bestellung. Mit dem Klick auf „Original kaufen“ gelangen Sie zum Bezahlvorgang unseres Zahlungsdienstleisters Stripe. Dort geben Sie Ihre Daten ein und können sie vor dem Absenden prüfen und korrigieren. Mit dem Klick auf den Bezahl-Button geben Sie ein verbindliches Kaufangebot ab. Der Vertrag kommt zustande, wenn die Zahlung erfolgreich abgeschlossen ist; die Bestätigung erhalten Sie per E-Mail. Der Vertragstext wird nicht gespeichert, die Bestelldaten erhalten Sie mit der Bestätigung. Vertragssprache ist Deutsch.</p>
<h2>§ 4 Reservierung</h2>
<p>Während des Bezahlvorgangs ist das Werk für ' . $n($c['reserveMinutes']) . ' Minuten reserviert. Wird der Vorgang in dieser Zeit nicht abgeschlossen, wird die Reservierung aufgehoben. Sollte ein Werk trotz Reservierung durch einen technischen Fehler doppelt bezahlt werden, wird die spätere Zahlung unverzüglich und vollständig erstattet.</p>
<h2>§ 5 Preise und Versandkosten</h2>
<p>' . ($c['kleinunternehmer'] ? 'Alle Preise sind Endpreise. Als Kleinunternehmer gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' : 'Alle Preise enthalten die gesetzliche Umsatzsteuer.') . ' Hinzu kommen die im Bezahlvorgang angezeigten Versandkosten, siehe <a href="/versand">Versand &amp; Zahlung</a>.</p>
<h2>§ 6 Zahlung</h2>
<p>Die Zahlung erfolgt über Stripe Payments Europe Ltd. Die verfügbaren Zahlungsarten werden im Bezahlvorgang angezeigt. Der Kaufpreis ist mit Vertragsschluss fällig.</p>
<h2>§ 7 Lieferung und Gefahrübergang</h2>
<p>Die Lieferung erfolgt versichert an die angegebene Adresse in ' . e(implode(', ', $c['shippingCountries'])) . ' innerhalb von ' . e($c['deliveryDays']) . ' Werktagen nach Zahlungseingang, sofern beim Werk nichts anderes angegeben ist. Bei Abholung wird der Termin individuell vereinbart. Bei Verbrauchern geht die Gefahr erst mit Übergabe des Werks auf den Käufer über.</p>
<h2>§ 8 Eigentumsvorbehalt</h2>
<p>Das Werk bleibt bis zur vollständigen Bezahlung Eigentum des Verkäufers.</p>
<h2>§ 9 Widerrufsrecht</h2>
<p>Verbrauchern steht ein Widerrufsrecht zu. Einzelheiten finden Sie in der <a href="/widerruf">Widerrufsbelehrung</a>.</p>
<h2>§ 10 Gewährleistung</h2>
<p>Es gilt das gesetzliche Mängelhaftungsrecht. Transportschäden zeigen Sie bitte möglichst sofort beim Zusteller und per E-Mail an; Ihre gesetzlichen Rechte bleiben davon unberührt.</p>
<h2>§ 11 Schlussbestimmungen</h2>
<p>Es gilt das Recht der Bundesrepublik Deutschland unter Ausschluss des UN-Kaufrechts. Gegenüber Verbrauchern gilt diese Rechtswahl nur, soweit dadurch nicht der Schutz durch zwingende Bestimmungen des Rechts des Staates ihres gewöhnlichen Aufenthalts entzogen wird.</p>',
                ];

            case 'widerruf':
                return [
                    'title' => 'Widerrufsbelehrung',
                    'html' => '<h1>Widerrufsbelehrung</h1>
<h2>Widerrufsrecht</h2>
<p>Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag, an dem Sie oder ein von Ihnen benannter Dritter, der nicht der Beförderer ist, die Ware in Besitz genommen haben.</p>
<p>Um Ihr Widerrufsrecht auszuüben, müssen Sie mich (' . e($c['address']['name']) . ', ' . e($c['address']['street']) . ', ' . e($c['address']['zip']) . ' ' . e($c['address']['city']) . ', E-Mail: <a href="mailto:' . e($c['contactEmail']) . '">' . e($c['contactEmail']) . '</a>, Telefon: ' . e($c['contactPhone']) . ') mittels einer eindeutigen Erklärung (z. B. ein mit der Post versandter Brief oder eine E-Mail) über Ihren Entschluss, diesen Vertrag zu widerrufen, informieren. Sie können dafür das beigefügte Muster-Widerrufsformular verwenden, das jedoch nicht vorgeschrieben ist. Sie können Ihren Widerruf auch elektronisch über die Schaltfläche „Vertrag widerrufen“ erklären, die Sie im Seitenfuß jeder Seite und unter <a href="/vertrag-widerrufen">' . e($c['siteUrl']) . '/vertrag-widerrufen</a> finden. Wenn Sie diese Möglichkeit nutzen, sende ich Ihnen unverzüglich per E-Mail eine Bestätigung über den Eingang Ihres Widerrufs mit dessen Inhalt sowie Datum und Uhrzeit des Eingangs. Zur Wahrung der Widerrufsfrist reicht es aus, dass Sie die Mitteilung über die Ausübung des Widerrufsrechts vor Ablauf der Widerrufsfrist absenden.</p>
<h2>Folgen des Widerrufs</h2>
<p>Wenn Sie diesen Vertrag widerrufen, habe ich Ihnen alle Zahlungen, die ich von Ihnen erhalten habe, einschließlich der Lieferkosten (mit Ausnahme der zusätzlichen Kosten, die sich daraus ergeben, dass Sie eine andere Art der Lieferung als die von mir angebotene, günstigste Standardlieferung gewählt haben), unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag zurückzuzahlen, an dem die Mitteilung über Ihren Widerruf bei mir eingegangen ist. Für diese Rückzahlung verwende ich dasselbe Zahlungsmittel, das Sie bei der ursprünglichen Transaktion eingesetzt haben, es sei denn, mit Ihnen wurde ausdrücklich etwas anderes vereinbart; in keinem Fall werden Ihnen wegen dieser Rückzahlung Entgelte berechnet. Ich kann die Rückzahlung verweigern, bis ich die Ware wieder zurückerhalten habe oder bis Sie den Nachweis erbracht haben, dass Sie die Ware zurückgesandt haben, je nachdem, welches der frühere Zeitpunkt ist.</p>
<p>Sie haben die Ware unverzüglich und in jedem Fall spätestens binnen vierzehn Tagen ab dem Tag, an dem Sie mich über den Widerruf dieses Vertrags unterrichten, an mich zurückzusenden oder zu übergeben. Die Frist ist gewahrt, wenn Sie die Ware vor Ablauf der Frist von vierzehn Tagen absenden. Sie tragen die unmittelbaren Kosten der Rücksendung der Ware. Da Gemälde aufgrund ihrer Größe und ihres Werts in der Regel nicht normal mit der Post zurückgesandt werden können, werden die Kosten auf höchstens etwa ' . $n($c['shipping']['gross']) . ' EUR geschätzt. Sie müssen für einen etwaigen Wertverlust der Ware nur aufkommen, wenn dieser Wertverlust auf einen zur Prüfung der Beschaffenheit, Eigenschaften und Funktionsweise der Ware nicht notwendigen Umgang mit ihr zurückzuführen ist. Bitte verpacken Sie das Werk für die Rücksendung so sorgfältig, wie Sie es erhalten haben.</p>
<h2>Muster-Widerrufsformular</h2>
<p class="form-sample">(Wenn Sie den Vertrag widerrufen wollen, dann füllen Sie bitte dieses Formular aus und senden Sie es zurück.)<br><br>
An ' . e($c['address']['name']) . ', ' . e($c['address']['street']) . ', ' . e($c['address']['zip']) . ' ' . e($c['address']['city']) . ', E-Mail: ' . e($c['contactEmail']) . '<br><br>
Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über den Kauf der folgenden Waren (*)/die Erbringung der folgenden Dienstleistung (*)<br>
– Bestellt am (*)/erhalten am (*)<br>
– Name des/der Verbraucher(s)<br>
– Anschrift des/der Verbraucher(s)<br>
– Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier)<br>
– Datum<br><br>
(*) Unzutreffendes streichen.</p>',
                ];

            case 'versand':
                return [
                    'title' => 'Versand & Zahlung',
                    'html' => '<h1>Versand &amp; Zahlung</h1>
<h2>Versand</h2>
<p>Jedes Werk wird von mir persönlich verpackt und versichert verschickt. Versandkosten je nach Format (längste Seite):</p>
<table class="ship-table"><tbody>
<tr><th>Klein (bis 60 cm)</th><td>' . $n($c['shipping']['klein']) . ' €</td></tr>
<tr><th>Mittel (60 bis 99 cm)</th><td>' . $n($c['shipping']['mittel']) . ' €</td></tr>
<tr><th>Groß (ab 100 cm)</th><td>' . $n($c['shipping']['gross']) . ' €</td></tr>
' . ($c['pickupEnabled'] ? '<tr><th>' . e($c['pickupLabel']) . '</th><td>kostenlos</td></tr>' : '') . '
</tbody></table>
<p>Lieferung nach: ' . e(implode(', ', $c['shippingCountries'])) . '. Für andere Länder und sehr große Formate schreiben Sie mir bitte, ich mache Ihnen ein Angebot. Lieferzeit: ' . e($c['deliveryDays']) . ' Werktage nach Zahlungseingang.</p>
<h2>Zahlung</h2>
<p>Die Zahlung läuft verschlüsselt über Stripe. Welche Zahlungsarten verfügbar sind (z. B. Kreditkarte, Apple Pay, Google Pay, SEPA, Klarna, PayPal), sehen Sie im Bezahlvorgang. Ihre Kartendaten werden nicht auf dieser Website verarbeitet oder gespeichert.</p>
<h2>Reservierung</h2>
<p>Sobald Sie auf „Original kaufen“ klicken, ist das Werk ' . $n($c['reserveMinutes']) . ' Minuten für Sie reserviert. Danach wird es automatisch wieder freigegeben.</p>',
                ];

            case 'datenschutz':
                $tag = $c['googleTagId'] !== '';
                return [
                    'title' => 'Datenschutzerklärung',
                    'html' => '<h1>Datenschutzerklärung</h1>
<h2>1. Verantwortlicher</h2>
<p>' . self::addr($c) . '<br>E-Mail: <a href="mailto:' . e($c['contactEmail']) . '">' . e($c['contactEmail']) . '</a></p>
<h2>2. Hosting</h2>
<p>Diese Website wird bei Netlify, Inc., 512 2nd Street, Suite 200, San Francisco, CA 94107, USA gehostet. Beim Aufruf werden technisch notwendige Daten (IP-Adresse, Zeitpunkt, aufgerufene Seite, Browserangaben) in Server-Logfiles verarbeitet, um die Website auszuliefern und abzusichern (Art. 6 Abs. 1 lit. f DSGVO). Netlify ist unter dem EU-US Data Privacy Framework zertifiziert; zusätzlich bestehen Standardvertragsklauseln.</p>
<h2>3. Bestellung und Zahlung über Stripe</h2>
<p>Wenn Sie ein Werk kaufen, werden Sie zum Bezahlvorgang von Stripe Payments Europe Ltd., 1 Grand Canal Street Lower, Dublin 2, Irland, weitergeleitet. Dort verarbeitet Stripe Ihre Kontakt-, Liefer- und Zahlungsdaten zur Abwicklung der Zahlung und zur Betrugsprävention. Ich erhalte Ihren Namen, Ihre E-Mail-Adresse, Lieferadresse und Telefonnummer (falls angegeben) sowie den Zahlungsstatus, um den Vertrag zu erfüllen (Art. 6 Abs. 1 lit. b DSGVO). Kartendaten erhalte ich nicht. Stripe kann Daten auch in die USA übermitteln; Stripe ist unter dem EU-US Data Privacy Framework zertifiziert. Datenschutzhinweise von Stripe: <a href="https://stripe.com/de/privacy" rel="noopener" target="_blank">stripe.com/de/privacy</a>.</p>
<p>Bestelldaten bewahre ich gemäß den handels- und steuerrechtlichen Aufbewahrungspflichten (bis zu 10 Jahre) auf (Art. 6 Abs. 1 lit. c DSGVO).</p>
<h2>4. Anfrageformular und E-Mail</h2>
<p>Wenn Sie mir über das Anfrageformular oder per E-Mail schreiben, verarbeite ich Ihre Angaben (Name, E-Mail, optional Telefon, Nachricht, betreffendes Werk), um Ihre Anfrage zu beantworten (Art. 6 Abs. 1 lit. b bzw. f DSGVO). Formulareingaben werden über die Formularfunktion von Netlify (siehe Hosting) entgegengenommen und mir per E-Mail weitergeleitet. Ich lösche die Anfrage, wenn sie erledigt ist und keine Aufbewahrungspflichten bestehen.</p>
<h2>4a. Merkliste</h2>
<p>Wenn Sie Werke merken, wird die Liste nur lokal in Ihrem Browser gespeichert und nicht an mich übertragen.</p>
<h2>4b. Widerruf über die Widerrufsfunktion</h2>
<p>Wenn Sie einen Kauf über „Vertrag widerrufen“ widerrufen, verarbeite ich Ihren Namen, die Angaben zum Kauf und Ihre E-Mail-Adresse, um den Widerruf zu bearbeiten und Ihnen den Eingang zu bestätigen (Art. 6 Abs. 1 lit. b und c DSGVO, § 356a BGB). Die Angaben werden als Nachweis gespeichert (bei Netlify) und per E-Mail über mein E-Mail-Postfach versendet. Ich lösche sie nach Ablauf der gesetzlichen Aufbewahrungs- und Verjährungsfristen.</p>
<h2>5. Cookies und lokale Speicherung</h2>
<p>Diese Website setzt ohne Ihre Einwilligung keine Tracking-Cookies. Gespeichert wird lediglich lokal in Ihrem Browser, ob Sie die Ansicht „UV-Licht“ gewählt haben' . ($tag ? ' und wie Sie sich beim Cookie-Hinweis entschieden haben' : '') . ' (§ 25 Abs. 2 TDDDG).</p>
' . ($tag ? '<h2>6. Google Ads Conversion-Tracking</h2>
<p>Nur wenn Sie im Cookie-Hinweis einwilligen, wird das Google-Tag von Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland, geladen. Damit messe ich, ob ein Kauf über eine meiner Anzeigen zustande kam (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG). Dabei können Daten an Google LLC in den USA übermittelt werden; Google ist unter dem EU-US Data Privacy Framework zertifiziert. Ihre Einwilligung können Sie jederzeit über „Cookie-Einstellungen“ im Seitenfuß widerrufen.</p>' : '') . '
<h2>' . ($tag ? '7' : '6') . '. Ihre Rechte</h2>
<p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch sowie das Recht, sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren, z. B. beim Landesbeauftragten für den Datenschutz Sachsen-Anhalt.</p>',
                ];
        }
        return null;
    }

    /** Rechtsseite als komplettes HTML; null bei unbekanntem Schlüssel (wie renderLegal in Node). */
    public static function render(string $key): ?string
    {
        $c = cfg();
        $p = self::page($key, $c);
        if ($p === null) {
            return null;
        }
        return Render::layout(['title' => $p['title'], 'body' => '<section class="legal">' . $p['html'] . '</section>', 'canonical' => '/' . $key, 'bodyClass' => 'page-legal']);
    }
}
