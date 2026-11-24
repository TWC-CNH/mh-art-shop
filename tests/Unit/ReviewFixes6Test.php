<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Catalog;
use App\Config;
use App\View\Render;
use App\Works;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** Regressionstests zum sechsten Review von PR #1. */
final class ReviewFixes6Test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Stack::resetDatabase();
    }

    public function testSlugBleibtKurzGenugAuchMitUmlauten(): void
    {
        $title = mb_substr(str_repeat('Größe Übermäßig ', 10), 0, 120);
        $slug = Works::slugify($title);
        $this->assertLessThanOrEqual(110, strlen($slug));
        $this->assertDoesNotMatchRegularExpression('/-$/', $slug);
        $this->assertStringStartsWith('groesse-uebermaessig', $slug);

        \App\Db::run("INSERT INTO werke (slug, titel, masse) VALUES (?, 'x', '')", [$slug]);
        $unique = Catalog::uniqueSlug($title);
        $this->assertSame($slug . '-2', $unique);
        $this->assertLessThanOrEqual(140, strlen($unique));
    }

    public function testWebpNurWennAlleGroessenDaSind(): void
    {
        Stack::applyToProcess();
        $dir = Config::get()['uploadDir'];
        $id = 'aaaaaaaa-bbbb-cccc-dddd-' . bin2hex(random_bytes(6));
        foreach (['', '-sm', '-xs'] as $s) {
            touch("$dir/$id$s.jpg");
        }
        touch("$dir/$id.webp");
        $html = Render::pic("/bilder/$id.jpg", 'alt=""', '100vw');
        $this->assertStringNotContainsString('image/webp', $html, 'nur die große WebP-Datei: keine WebP-Quelle anbieten');

        touch("$dir/$id-sm.webp");
        touch("$dir/$id-xs.webp");
        $this->assertStringContainsString('<source type="image/webp" srcset="/bilder/' . $id . '-xs.webp 420w', Render::pic("/bilder/$id.jpg", 'alt=""', '100vw'));
    }
}
