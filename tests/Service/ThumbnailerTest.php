<?php

namespace App\Tests\Service;

use App\Service\ExifOrientation;
use App\Service\Thumbnailer;
use App\Service\ThumbnailStatus;
use PHPUnit\Framework\TestCase;

/**
 * Les vignettes des photos d'œuvres. Travaille dans un dossier temporaire : jamais dans
 * les vraies photos du site. Les tests qui fabriquent une image ont besoin de l'extension
 * PHP « GD » (comme le serveur) ; sans elle ils sont ignorés.
 */
class ThumbnailerTest extends TestCase
{
    private string $root;
    private string $originals;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/thumbnailer-test-' . uniqid();
        $this->originals = $this->root . '/public/uploads/attachments';
        mkdir($this->originals, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            is_dir($dir . '/' . $entry) ? $this->removeTree($dir . '/' . $entry) : unlink($dir . '/' . $entry);
        }

        rmdir($dir);
    }

    private function thumbnailer(): Thumbnailer
    {
        return new Thumbnailer($this->root);
    }

    private function requireGd(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped("L'extension GD n'est pas chargée : lancez PHPUnit avec « php -d extension=gd ».");
        }
    }

    /** Une image dont la moitié gauche est rouge et la moitié droite bleue. */
    private function redAndBlue(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width, $height, (int) imagecolorallocate($image, 0, 0, 255));

        return $image;
    }

    private function saveJpeg(string $name, \GdImage $image, ?int $orientation = null): string
    {
        $path = $this->originals . '/' . $name;
        imagejpeg($image, $path, 90);

        if (null !== $orientation) {
            $jpeg = file_get_contents($path);
            file_put_contents($path, substr($jpeg, 0, 2) . self::exifSegment($orientation) . substr($jpeg, 2));
        }

        return $path;
    }

    /** Le segment « EXIF » d'un JPEG qui ne contient que le réglage d'orientation. */
    private static function exifSegment(int $orientation, bool $bigEndian = true): string
    {
        $short = static fn (int $v): string => $bigEndian ? pack('n', $v) : pack('v', $v);
        $long = static fn (int $v): string => $bigEndian ? pack('N', $v) : pack('V', $v);

        $tiff = ($bigEndian ? 'MM' : 'II') . $short(42) . $long(8);
        $ifd = $short(1) . $short(0x0112) . $short(3) . $long(1) . $short($orientation) . "\0\0" . $long(0);
        $payload = "Exif\0\0" . $tiff . $ifd;

        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    /** @return array{0: int, 1: int, 2: int} Rouge, vert, bleu du pixel (x, y) */
    private function pixel(string $path, int $x, int $y): array
    {
        $image = imagecreatefromjpeg($path);
        $color = imagecolorat($image, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    public function testExifOrientationIsReadInBothByteOrders(): void
    {
        foreach ([true, false] as $bigEndian) {
            foreach (range(1, 8) as $orientation) {
                $path = $this->originals . '/exif.jpg';
                file_put_contents($path, "\xFF\xD8" . self::exifSegment($orientation, $bigEndian) . "\xFF\xD9");

                self::assertSame($orientation, ExifOrientation::ofJpegFile($path), sprintf('Orientation %d (%s)', $orientation, $bigEndian ? 'MM' : 'II'));
            }
        }
    }

    public function testExifOrientationFallsBackToUprightWhenThereIsNothingToRead(): void
    {
        $cases = [
            'pas de réglage' => "\xFF\xD8\xFF\xE0\x00\x04\x00\x00\xFF\xD9",
            'pas un JPEG' => 'GIF89a....',
            'vide' => '',
            'tronqué' => "\xFF\xD8\xFF\xE1\x00\x40Exif\0\0MM",
            'valeur impossible' => "\xFF\xD8" . self::exifSegment(9) . "\xFF\xD9",
        ];

        foreach ($cases as $label => $content) {
            file_put_contents($this->originals . '/x.jpg', $content);
            self::assertSame(1, ExifOrientation::ofJpegFile($this->originals . '/x.jpg'), $label);
        }

        self::assertSame(1, ExifOrientation::ofJpegFile($this->originals . '/inexistant.jpg'));
    }

    public function testUrlFallsBackToTheOriginalPhotoUntilTheThumbnailExists(): void
    {
        $thumbnailer = $this->thumbnailer();

        self::assertSame('/uploads/attachments/vase.jpg', $thumbnailer->url('vase.jpg'));
        self::assertSame('/uploads/attachments/un%20vase.jpg', $thumbnailer->url('un vase.jpg'));

        mkdir($this->originals . '/_vignettes');
        file_put_contents($this->originals . '/_vignettes/vase.jpg', 'x');

        self::assertSame('/uploads/attachments/_vignettes/vase.jpg', $thumbnailer->url('vase.jpg'));
    }

    public function testNamesThatAreNotPlainFilenamesAreRefused(): void
    {
        file_put_contents($this->root . '/secret.jpg', 'x');
        $thumbnailer = $this->thumbnailer();

        foreach (['../../secret.jpg', '../secret.jpg', 'a/b.jpg', '', "x\0.jpg"] as $name) {
            self::assertSame(ThumbnailStatus::Missing, $thumbnailer->generate($name), var_export($name, true));
        }

        $thumbnailer->remove('../../secret.jpg');
        self::assertFileExists($this->root . '/secret.jpg', 'Un nom piégé ne permet pas de supprimer un autre fichier');
    }

    public function testMissingPhotoIsReportedWithoutError(): void
    {
        self::assertSame(ThumbnailStatus::Missing, $this->thumbnailer()->generate('absente.jpg'));
    }

    public function testBigPhotoGetsAnEightHundredPixelThumbnail(): void
    {
        $this->requireGd();
        $source = $this->saveJpeg('grande.jpg', $this->redAndBlue(2000, 1000));
        $thumbnailer = $this->thumbnailer();

        self::assertSame(ThumbnailStatus::Created, $thumbnailer->generate('grande.jpg'));

        $thumb = $this->originals . '/_vignettes/grande.jpg';
        self::assertFileExists($thumb);
        self::assertSame([800, 400], array_slice(getimagesize($thumb), 0, 2));
        self::assertSame(\IMAGETYPE_JPEG, getimagesize($thumb)[2]);
        self::assertLessThan(filesize($source), filesize($thumb));
        self::assertSame([], glob($thumb . '.*'), 'Pas de fichier temporaire oublié');
        self::assertSame('/uploads/attachments/_vignettes/grande.jpg', $thumbnailer->url('grande.jpg'));

        // Les couleurs sont conservées (à la compression près)
        [$r, , $b] = $this->pixel($thumb, 100, 200);
        self::assertGreaterThan(200, $r);
        self::assertLessThan(60, $b);

        // Relancer ne refait rien, sauf si on le demande
        self::assertSame(ThumbnailStatus::Exists, $thumbnailer->generate('grande.jpg'));
        self::assertSame(ThumbnailStatus::Created, $thumbnailer->generate('grande.jpg', force: true));

        // La photo d'origine n'a pas bougé
        self::assertSame([2000, 1000], array_slice(getimagesize($source), 0, 2));
    }

    public function testPortraitPhotoIsLimitedOnItsLongestSide(): void
    {
        $this->requireGd();
        $this->saveJpeg('portrait.jpg', $this->redAndBlue(1200, 1800));

        self::assertSame(ThumbnailStatus::Created, $this->thumbnailer()->generate('portrait.jpg'));
        self::assertSame([533, 800], array_slice(getimagesize($this->originals . '/_vignettes/portrait.jpg'), 0, 2));
    }

    public function testSmallPhotoNeedsNoThumbnail(): void
    {
        $this->requireGd();
        $this->saveJpeg('petite.jpg', $this->redAndBlue(600, 400));
        $thumbnailer = $this->thumbnailer();

        self::assertSame(ThumbnailStatus::NotNeeded, $thumbnailer->generate('petite.jpg'));
        self::assertFileDoesNotExist($this->originals . '/_vignettes/petite.jpg');
        self::assertSame('/uploads/attachments/petite.jpg', $thumbnailer->url('petite.jpg'));
    }

    /**
     * Une photo de téléphone prise en portrait est enregistrée « couchée » avec la mention « tourner d'un quart
     * de tour ». Le navigateur obéit ; la vignette doit donc être redressée elle aussi.
     */
    public function testPhotoStoredSidewaysIsStraightenedLikeTheBrowserDoes(): void
    {
        $this->requireGd();

        // Gauche rouge, droite bleue, réglage « tourner d'un quart de tour vers la droite »
        $this->saveJpeg('couchee.jpg', $this->redAndBlue(1600, 800), 6);

        self::assertSame(ThumbnailStatus::Created, $this->thumbnailer()->generate('couchee.jpg'));

        $thumb = $this->originals . '/_vignettes/couchee.jpg';
        self::assertSame([400, 800], array_slice(getimagesize($thumb), 0, 2), 'Devenue une image en hauteur');

        // Le côté gauche (rouge) se retrouve en haut, le droit (bleu) en bas
        [$rTop, , $bTop] = $this->pixel($thumb, 200, 100);
        [$rBottom, , $bBottom] = $this->pixel($thumb, 200, 700);
        self::assertGreaterThan(200, $rTop);
        self::assertLessThan(60, $bTop);
        self::assertLessThan(60, $rBottom);
        self::assertGreaterThan(200, $bBottom);
    }

    public function testPhotoLighterThanItsOwnThumbnailKeepsItsOriginal(): void
    {
        $this->requireGd();

        // Une photo « bruitée » fortement compressée : plus grande en pixels qu'une vignette, mais bien plus légère
        mt_srand(42);
        $noise = imagecreatetruecolor(1000, 600);
        for ($x = 0; $x < 1000; $x += 2) {
            for ($y = 0; $y < 600; $y += 2) {
                imagefilledrectangle($noise, $x, $y, $x + 1, $y + 1, (int) imagecolorallocate($noise, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        imagejpeg($noise, $this->originals . '/legere.jpg', 3);

        $thumbnailer = $this->thumbnailer();

        // Le premier envoi (sans --force) ne laisse rien derrière lui
        self::assertSame(ThumbnailStatus::NotNeeded, $thumbnailer->generate('legere.jpg'));
        self::assertFileDoesNotExist($this->originals . '/_vignettes/legere.jpg');
        self::assertSame([], glob($this->originals . '/_vignettes/*') ?: [], 'Pas de fichier temporaire oublié');
        self::assertSame('/uploads/attachments/legere.jpg', $thumbnailer->url('legere.jpg'));

        // Une ancienne vignette plus lourde disparaît quand on refait le travail
        is_dir($this->originals . '/_vignettes') || mkdir($this->originals . '/_vignettes');
        file_put_contents($this->originals . '/_vignettes/legere.jpg', str_repeat('x', 500_000));
        self::assertSame(ThumbnailStatus::NotNeeded, $thumbnailer->generate('legere.jpg', force: true));
        self::assertFileDoesNotExist($this->originals . '/_vignettes/legere.jpg');
    }

    /**
     * Pour chaque réglage EXIF, ce que représentent la 1re ligne et la 1re colonne de l'image
     * enregistrée, d'après la table de la norme EXIF (et non d'après le code qui les corrige).
     */
    private const EXIF_AXES = [
        1 => ['top', 'left'],
        2 => ['top', 'right'],
        3 => ['bottom', 'right'],
        4 => ['bottom', 'left'],
        5 => ['left', 'top'],
        6 => ['right', 'top'],
        7 => ['right', 'bottom'],
        8 => ['left', 'bottom'],
    ];

    /**
     * L'image telle qu'un appareil l'enregistre pour qu'elle s'affiche comme $upright avec ce réglage.
     * Quatre quadrants de couleurs différentes suffisent pour reconnaître le sens.
     */
    private function storedLike(int $orientation, \GdImage $upright): \GdImage
    {
        $w = imagesx($upright);
        $h = imagesy($upright);
        [$rowAxis, $colAxis] = self::EXIF_AXES[$orientation];

        // Position (colonne, ligne) dans l'image enregistrée d'un point (x, y) de l'image à l'endroit
        $locate = static function (int $x, int $y) use ($w, $h, $rowAxis, $colAxis): array {
            $along = static fn (string $axis): int => match ($axis) {
                'top' => $y,
                'bottom' => $h - 1 - $y,
                'left' => $x,
                'right' => $w - 1 - $x,
            };

            return [$along($colAxis), $along($rowAxis)];
        };

        $stored = in_array($orientation, [5, 6, 7, 8], true) ? imagecreatetruecolor($h, $w) : imagecreatetruecolor($w, $h);

        foreach ([[0, 0], [intdiv($w, 2), 0], [0, intdiv($h, 2)], [intdiv($w, 2), intdiv($h, 2)]] as [$x0, $y0]) {
            $x1 = $x0 + intdiv($w, 2) - 1;
            $y1 = $y0 + intdiv($h, 2) - 1;
            [$ca, $ra] = $locate($x0, $y0);
            [$cb, $rb] = $locate($x1, $y1);

            imagefilledrectangle($stored, min($ca, $cb), min($ra, $rb), max($ca, $cb), max($ra, $rb), imagecolorat($upright, $x0, $y0));
        }

        return $stored;
    }

    public function testEveryExifOrientationEndsUpUpright(): void
    {
        $this->requireGd();

        // L'image qu'on veut voir : rouge en haut à gauche, vert en haut à droite, bleu en bas à gauche, jaune en bas à droite
        $upright = imagecreatetruecolor(900, 450);
        foreach ([[0, 0, [255, 0, 0]], [450, 0, [0, 255, 0]], [0, 225, [0, 0, 255]], [450, 225, [255, 255, 0]]] as [$x, $y, [$r, $g, $b]]) {
            imagefilledrectangle($upright, $x, $y, $x + 449, $y + 224, (int) imagecolorallocate($upright, $r, $g, $b));
        }

        $expected = ['rouge' => [255, 0, 0], 'vert' => [0, 255, 0], 'bleu' => [0, 0, 255], 'jaune' => [255, 255, 0]];
        // Centre de chaque quadrant de la vignette (800 × 400)
        $points = ['rouge' => [200, 100], 'vert' => [600, 100], 'bleu' => [200, 300], 'jaune' => [600, 300]];

        foreach (range(1, 8) as $orientation) {
            $this->saveJpeg("o$orientation.jpg", $this->storedLike($orientation, $upright), $orientation);

            self::assertSame(ThumbnailStatus::Created, $this->thumbnailer()->generate("o$orientation.jpg"), "orientation $orientation");

            $thumb = $this->originals . "/_vignettes/o$orientation.jpg";
            self::assertSame([800, 400], array_slice(getimagesize($thumb), 0, 2), "orientation $orientation : même forme que l'image à l'endroit");

            foreach ($points as $name => [$x, $y]) {
                $pixel = $this->pixel($thumb, $x, $y);

                foreach ($pixel as $channel => $value) {
                    self::assertEqualsWithDelta($expected[$name][$channel], $value, 40, sprintf('Orientation %d : le quadrant %s est mal placé', $orientation, $name));
                }
            }
        }
    }

    public function testPngKeepsItsTransparency(): void
    {
        $this->requireGd();
        $png = imagecreatetruecolor(1600, 800);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, (int) imagecolorallocatealpha($png, 0, 0, 0, 127));
        imagepng($png, $this->originals . '/calque.png');

        self::assertSame(ThumbnailStatus::Created, $this->thumbnailer()->generate('calque.png'));

        $thumb = imagecreatefrompng($this->originals . '/_vignettes/calque.png');
        self::assertSame(\IMAGETYPE_PNG, getimagesize($this->originals . '/_vignettes/calque.png')[2]);
        self::assertSame(127, (imagecolorat($thumb, 10, 10) >> 24) & 0x7F, 'Le fond reste transparent');
    }

    public function testGifAndBrokenFilesAreLeftAlone(): void
    {
        $this->requireGd();
        $gif = imagecreatetruecolor(1600, 800);
        imagegif($gif, $this->originals . '/anime.gif');
        file_put_contents($this->originals . '/faux.jpg', 'ceci n\'est pas une image');
        $thumbnailer = $this->thumbnailer();

        self::assertSame(ThumbnailStatus::UnsupportedFormat, $thumbnailer->generate('anime.gif'));
        self::assertSame(ThumbnailStatus::Failed, $thumbnailer->generate('faux.jpg'));
        self::assertSame('/uploads/attachments/anime.gif', $thumbnailer->url('anime.gif'));
        self::assertSame('/uploads/attachments/faux.jpg', $thumbnailer->url('faux.jpg'));
    }

    public function testRemoveDeletesOnlyTheThumbnail(): void
    {
        $this->requireGd();
        $source = $this->saveJpeg('vase.jpg', $this->redAndBlue(2000, 1000));
        $thumbnailer = $this->thumbnailer();
        $thumbnailer->generate('vase.jpg');

        $thumbnailer->remove('vase.jpg');

        self::assertFileDoesNotExist($this->originals . '/_vignettes/vase.jpg');
        self::assertFileExists($source);
        self::assertSame('/uploads/attachments/vase.jpg', $thumbnailer->url('vase.jpg'));
    }
}
