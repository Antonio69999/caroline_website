<?php

namespace App\Tests\Service;

use App\Service\MediaUploadHandler;
use App\Service\Thumbnailer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Quand Caroline envoie une photo dans l'admin, sa vignette est fabriquée tout de suite ;
 * et si elle ne peut pas l'être, la photo est enregistrée quand même.
 * Travaille dans un dossier temporaire, jamais dans les vraies photos.
 */
class MediaUploadThumbnailTest extends TestCase
{
    private string $root;
    private string $source;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/upload-thumb-test-' . uniqid();
        $this->source = sys_get_temp_dir() . '/upload-source-' . uniqid();
        mkdir($this->root . '/public/uploads/attachments', 0775, true);
        mkdir($this->source, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->source] as $dir) {
            $this->removeTree($dir);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                is_dir($dir . '/' . $entry) ? $this->removeTree($dir . '/' . $entry) : unlink($dir . '/' . $entry);
            }
        }

        rmdir($dir);
    }

    private function handler(): MediaUploadHandler
    {
        return new MediaUploadHandler($this->root, $this->createMock(EntityManagerInterface::class), new Thumbnailer($this->root));
    }

    private function upload(string $name, string $content, string $mime): UploadedFile
    {
        $path = $this->source . '/' . $name;
        file_put_contents($path, $content);

        // Dernier argument « true » : fichier de test, déplaçable comme un vrai envoi
        return new UploadedFile($path, $name, $mime, null, true);
    }

    public function testANewPhotoGetsItsThumbnailRightAway(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped("L'extension GD n'est pas chargée : lancez PHPUnit avec « php -d extension=gd ».");
        }

        $image = imagecreatetruecolor(1920, 1280);
        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();

        $name = $this->handler()->upload($this->upload('Mon Tableau.jpg', $jpeg, 'image/jpeg'));

        self::assertSame('mon-tableau.jpg', $name);
        self::assertFileExists($this->root . '/public/uploads/attachments/mon-tableau.jpg');

        $thumb = $this->root . '/public/uploads/attachments/_vignettes/mon-tableau.jpg';
        self::assertFileExists($thumb);
        self::assertSame([800, 533], array_slice(getimagesize($thumb), 0, 2));
    }

    public function testAnUnreadablePhotoIsStillSavedWithoutThumbnail(): void
    {
        // Un faux « JPEG » : la vignette est impossible, l'envoi ne doit pas échouer pour autant
        $name = $this->handler()->upload($this->upload('cassee.jpg', 'pas une image', 'image/jpeg'));

        // (le serveur donne l'extension d'après le contenu réel du fichier, pas d'après son nom)
        self::assertStringStartsWith('cassee.', $name);
        self::assertFileExists($this->root . '/public/uploads/attachments/' . $name);
        self::assertFileDoesNotExist($this->root . '/public/uploads/attachments/_vignettes/' . $name);
    }
}
