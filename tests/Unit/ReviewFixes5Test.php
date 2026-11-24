<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\AtelierAuth;
use App\Config;
use App\Env;
use App\View\Legal;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** Regressionstests zum fünften Review von PR #1. */
final class ReviewFixes5Test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Stack::resetDatabase();
    }

    protected function tearDown(): void
    {
        foreach (['CONTACT_PHONE', 'INSTAGRAM_URL', 'FACEBOOK_URL'] as $k) {
            putenv($k);
        }
        Stack::applyToProcess();
    }

    public function testNotfallCodeMitLeerzeichenStattBindestrich(): void
    {
        [$name] = Stack::createUser();
        $codes = AtelierAuth::newRecoveryCodes($name);
        $this->assertNotNull(AtelierAuth::attempt($name, Stack::PASSWORD, str_replace('-', ' ', $codes[0])));
        $this->assertNotNull(AtelierAuth::attempt($name, Stack::PASSWORD, strtolower(str_replace('-', '', $codes[1]))));
    }

    public function testLeereOptionaleAngabenWerdenAusgeblendet(): void
    {
        $this->assertStringContainsString('Telefon:', Legal::render('impressum'), 'ohne Eintrag: Standardnummer');
        putenv('CONTACT_PHONE=');
        Config::reset();
        $this->assertTrue(Env::has('CONTACT_PHONE'));
        $this->assertSame('', Config::get()['contactPhone']);
        $this->assertStringNotContainsString('Telefon:', Legal::render('impressum'));
        $this->assertStringNotContainsString('Telefon:', Legal::render('widerruf'));

        putenv('INSTAGRAM_URL=');
        putenv('FACEBOOK_URL=');
        Config::reset();
        $html = \App\View\Render::notFound();
        $this->assertStringNotContainsString('>Instagram<', $html);
        $this->assertStringNotContainsString('>Facebook<', $html);
    }
}
