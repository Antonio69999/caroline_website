<?php

namespace App\Tests\Service;

use App\Service\UploadLimits;
use PHPUnit\Framework\TestCase;

/**
 * Les limites d'envoi de PHP lues par l'admin de la vitrine : un mauvais calcul ici
 * laisserait passer un envoi que PHP jettera en bloc (ou bloquerait pour rien).
 */
class UploadLimitsTest extends TestCase
{
    /**
     * @dataProvider iniValues
     */
    public function testParseBytesReadsPhpIniNotation(string $ini, int $expected): void
    {
        self::assertSame($expected, UploadLimits::parseBytes($ini));
    }

    /** @return iterable<string, array{string, int}> */
    public static function iniValues(): iterable
    {
        yield 'mégaoctets' => ['8M', 8 * 1024 * 1024];
        yield 'minuscule' => ['2m', 2 * 1024 * 1024];
        yield 'kilo-octets' => ['512K', 512 * 1024];
        yield 'gigaoctets' => ['1G', 1024 * 1024 * 1024];
        yield 'octets bruts' => ['1048576', 1048576];
        yield 'espaces autour' => [' 8M ', 8 * 1024 * 1024];
        yield 'vide' => ['', 0];
        yield 'zéro (= sans limite pour post_max_size)' => ['0', 0];
    }

    /**
     * @dataProvider sizes
     */
    public function testFormatShowsMegabytesTheWayTheAdminDoes(int $bytes, string $expected): void
    {
        self::assertSame($expected, UploadLimits::format($bytes));
    }

    /** @return iterable<string, array{int, string}> */
    public static function sizes(): iterable
    {
        yield 'entier' => [2 * 1024 * 1024, '2 Mo'];
        yield 'décimal' => [(int) (1.5 * 1024 * 1024), '1,5 Mo'];
        yield 'dizaines' => [40 * 1024 * 1024, '40 Mo'];
    }

    public function testMaxFilesIsAlwaysAtLeastOne(): void
    {
        self::assertGreaterThanOrEqual(1, UploadLimits::maxFiles());
    }

    public function testPerFileLimitIsPositiveAndNeverAboveTheWholeRequest(): void
    {
        self::assertGreaterThan(0, UploadLimits::perFile());

        $total = UploadLimits::total();
        if ($total > 0) {
            self::assertLessThanOrEqual($total, UploadLimits::perFile(), 'Un fichier ne peut pas dépasser tout l\'envoi');
        }
    }
}
