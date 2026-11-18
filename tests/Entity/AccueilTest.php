<?php

namespace App\Tests\Entity;

use App\Entity\Accueil;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Les réglages de l'accueil : ce que style() donne au CSS (valeurs par défaut, bornes, mode
 * « photo entière ») et ce que valide Accueil::validate() (fichiers contrôlés sur leur contenu,
 * photo « téléphone » impossible sans photo principale).
 */
class AccueilTest extends KernelTestCase
{
    /** Une image PNG valide de 1 pixel (en base64) : le serveur contrôle le vrai contenu des fichiers. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** @return array<string, string> chemin de la violation => message */
    private function violations(Accueil $accueil): array
    {
        self::bootKernel();
        $result = [];
        foreach (self::getContainer()->get(ValidatorInterface::class)->validate($accueil) as $violation) {
            $result[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $result;
    }

    private function upload(string $name, string $content, int $padTo = 0): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'acc');
        file_put_contents($path, $content);
        if ($padTo > 0) {
            // Fichier « creux » : lourd sans rien occuper en mémoire
            $handle = fopen($path, 'r+');
            ftruncate($handle, $padTo);
            fclose($handle);
        }
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function accueilAvecPhoto(): Accueil
    {
        $accueil = new Accueil();
        $accueil->setImageName('photo.jpg');

        return $accueil;
    }

    public function testDefaultsKeepAPanoramaWholeOnComputersAndFillThePhoneScreen(): void
    {
        $accueil = new Accueil();

        self::assertFalse($accueil->hasImage(), 'Sans photo choisie, le site garde son accueil d\'origine');
        self::assertSame(
            '--accueil-fond:#ffffff;--bureau-fit:contain;--bureau-pos:50% 50%;--bureau-zoom:1;--mobile-fit:cover;--mobile-pos:50% 50%;--mobile-zoom:1',
            $accueil->style(),
        );
    }

    public function testFullScreenFramingBecomesCssVariables(): void
    {
        $accueil = (new Accueil())
            ->setBureauMode(Accueil::MODE_PLEIN)->setBureauX(20)->setBureauY(70)->setBureauZoom(150)
            ->setMobileMode(Accueil::MODE_PLEIN)->setMobileX(100)->setMobileY(0)->setMobileZoom(125)
            ->setCouleurFond('#112233');

        self::assertSame(
            '--accueil-fond:#112233;--bureau-fit:cover;--bureau-pos:20% 70%;--bureau-zoom:1.5;--mobile-fit:cover;--mobile-pos:100% 0%;--mobile-zoom:1.25',
            $accueil->style(),
        );
    }

    public function testWholePhotoModeIgnoresTheFramingBecauseNothingIsCropped(): void
    {
        $accueil = (new Accueil())->setBureauMode(Accueil::MODE_ENTIERE)->setBureauX(10)->setBureauY(90)->setBureauZoom(250);

        self::assertStringContainsString('--bureau-fit:contain;--bureau-pos:50% 50%;--bureau-zoom:1;', $accueil->style());
    }

    public function testWholeZoomsAreWrittenWithoutUselessDecimals(): void
    {
        foreach ([100 => '1', 105 => '1.05', 200 => '2', 300 => '3'] as $pourcent => $attendu) {
            $accueil = (new Accueil())->setMobileMode(Accueil::MODE_PLEIN)->setMobileZoom($pourcent);
            self::assertStringContainsString('--mobile-zoom:' . $attendu, $accueil->style(), $pourcent . ' %');
        }
    }

    public function testStoredValuesOutOfRangeNeverBreakThePage(): void
    {
        $accueil = (new Accueil())
            ->setBureauMode(Accueil::MODE_PLEIN)->setBureauX(250)->setBureauY(-40)->setBureauZoom(9999)
            ->setCouleurFond('rouge');

        $css = $accueil->style();
        self::assertStringContainsString('--bureau-pos:100% 0%;--bureau-zoom:3', $css);
        self::assertStringContainsString('--accueil-fond:#ffffff', $css, 'Une couleur qui n\'en est pas une est remplacée par le blanc');
    }

    public function testEmptyFormValuesFallBackToTheDefaults(): void
    {
        // Un champ vidé dans le formulaire arrive en null : on ne veut ni erreur ni colonne vide
        $accueil = (new Accueil())->setBureauX(null)->setBureauY(null)->setBureauZoom(null)->setMobileX(null)->setMobileY(null)->setMobileZoom(null)
            ->setBureauMode(null)->setMobileMode(null)->setCouleurFond(null)->setDescription('   ');

        self::assertSame([], $this->violations($accueil));
        self::assertNull($accueil->getDescription());
        self::assertSame(Accueil::MODE_ENTIERE, $accueil->getBureauMode());
        self::assertSame(Accueil::MODE_PLEIN, $accueil->getMobileMode());
        self::assertSame(50, $accueil->getBureauX());
        self::assertSame(100, $accueil->getMobileZoom());
    }

    public function testFramingOutOfItsLimitsIsRefused(): void
    {
        $accueil = $this->accueilAvecPhoto()->setBureauZoom(99)->setMobileZoom(301)->setBureauX(101)->setMobileY(-1)
            ->setBureauMode('diagonale')->setCouleurFond('#12');

        $violations = $this->violations($accueil);
        foreach (['bureauZoom', 'mobileZoom', 'bureauX', 'mobileY', 'bureauMode', 'couleurFond'] as $champ) {
            self::assertArrayHasKey($champ, $violations);
        }
    }

    public function testMobilePhotoNeedsTheMainPhoto(): void
    {
        $accueil = new Accueil();
        $accueil->setImageMobileName('mobile.jpg');

        self::assertArrayHasKey('imageMobileFile', $this->violations($accueil));

        $accueil->setImageName('photo.jpg');
        self::assertSame([], $this->violations($accueil));
    }

    public function testAPhotoJustChosenCountsEvenBeforeItIsSaved(): void
    {
        // Première visite : la photo principale et la photo « téléphone » arrivent ensemble
        $accueil = new Accueil();
        $accueil->setImageFile($this->upload('grande.png', base64_decode(self::PNG)));
        $accueil->setImageMobileFile($this->upload('verticale.png', base64_decode(self::PNG)));

        self::assertSame([], $this->violations($accueil));
    }

    public function testAScriptDisguisedAsAPhotoIsRejected(): void
    {
        $accueil = new Accueil();
        $accueil->setImageFile($this->upload('innocente.jpg', '<?php system($_GET["c"]);'));

        self::assertStringContainsString('Ce fichier ne convient pas', $this->violations($accueil)['imageFile'] ?? '');
    }

    public function testWrongFormatsAreRejectedEvenWithAnImageContent(): void
    {
        // Un vrai PNG mais sous le nom d'un GIF ou d'un SVG : formats refusés pour l'accueil
        foreach (['animation.gif', 'dessin.svg', 'photo.heic'] as $nom) {
            $accueil = new Accueil();
            $accueil->setImageFile($this->upload($nom, base64_decode(self::PNG)));

            self::assertArrayHasKey('imageFile', $this->violations($accueil), $nom);
        }
    }

    public function testATooHeavyPhotoIsRejected(): void
    {
        $accueil = new Accueil();
        $accueil->setImageFile($this->upload('enorme.png', base64_decode(self::PNG), 10 * 1024 * 1024 + 1));

        self::assertStringContainsString('trop lourde', $this->violations($accueil)['imageFile'] ?? '');
    }
}
