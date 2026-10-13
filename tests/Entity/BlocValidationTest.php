<?php

namespace App\Tests\Entity;

use App\Entity\Bloc;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Règles de Bloc::validate() : ce qui est obligatoire selon le type de bloc,
 * et quels fichiers sont acceptés (contrôlés sur leur contenu, pas seulement leur nom).
 */
class BlocValidationTest extends KernelTestCase
{
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
    private function violations(Bloc $bloc): array
    {
        self::bootKernel();
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($bloc);

        $result = [];
        foreach ($violations as $violation) {
            $result[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $result;
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bloc');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        // true = mode test : le fichier n'est pas un vrai envoi HTTP
        return new UploadedFile($path, $name, null, null, true);
    }

    private const MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00" . "\xFF\xFB\x90\x00";
    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testTextBlockNeedsSomeText(): void
    {
        $bloc = (new Bloc())->setType(Bloc::TYPE_TEXTE)->setContenu('<p><br></p>');
        self::assertArrayHasKey('contenu', $this->violations($bloc), "Un bloc texte qui n'a que des balises vides est vide");

        $bloc->setContenu('<h2>Bonjour</h2>');
        self::assertSame([], $this->violations($bloc));
    }

    public function testTextBlockDoesNotNeedAFile(): void
    {
        $bloc = (new Bloc())->setType(Bloc::TYPE_TEXTE)->setContenu('<p>Seulement du texte</p>');

        self::assertSame([], $this->violations($bloc));
    }

    public function testFileBlocksNeedAFile(): void
    {
        foreach ([Bloc::TYPE_IMAGE, Bloc::TYPE_SON, Bloc::TYPE_VIDEO] as $type) {
            $bloc = (new Bloc())->setType($type)->setTitre('Un titre');

            self::assertArrayHasKey('fichierFile', $this->violations($bloc), "Le bloc « $type » sans fichier doit être refusé");
        }
    }

    public function testImageNeedsADescriptionButSoundAndVideoDoNot(): void
    {
        $image = (new Bloc())->setType(Bloc::TYPE_IMAGE);
        $image->setFichierName('deja-la.jpg');
        self::assertArrayHasKey('titre', $this->violations($image));

        $image->setTitre('Un paysage');
        self::assertSame([], $this->violations($image));

        foreach ([Bloc::TYPE_SON => 'morceau.mp3', Bloc::TYPE_VIDEO => 'film.mp4'] as $type => $fichier) {
            $bloc = (new Bloc())->setType($type);
            $bloc->setFichierName($fichier);
            self::assertSame([], $this->violations($bloc), "Le titre est facultatif pour « $type »");
        }
    }

    public function testRealMp3Mp4AndPngAreAccepted(): void
    {
        $son = (new Bloc())->setType(Bloc::TYPE_SON);
        $son->setFichierFile($this->upload('morceau.mp3', self::MP3));
        self::assertSame([], $this->violations($son));

        $video = (new Bloc())->setType(Bloc::TYPE_VIDEO);
        $video->setFichierFile($this->upload('film.mp4', self::MP4));
        self::assertSame([], $this->violations($video));

        $image = (new Bloc())->setType(Bloc::TYPE_IMAGE)->setTitre('Pixel');
        $image->setFichierFile($this->upload('pixel.png', base64_decode(self::PNG_BASE64)));
        self::assertSame([], $this->violations($image));
    }

    public function testScriptRenamedAsMp3IsRefused(): void
    {
        $son = (new Bloc())->setType(Bloc::TYPE_SON);
        $son->setFichierFile($this->upload('inoffensif.mp3', '<?php echo "pwned";'));

        self::assertArrayHasKey('fichierFile', $this->violations($son), 'Le contenu réel du fichier compte, pas son extension');
    }

    public function testFileOfTheWrongKindForTheBlockTypeIsRefused(): void
    {
        // Une image envoyée dans un bloc « son »
        $son = (new Bloc())->setType(Bloc::TYPE_SON);
        $son->setFichierFile($this->upload('pixel.png', base64_decode(self::PNG_BASE64)));
        self::assertArrayHasKey('fichierFile', $this->violations($son));

        // Un SVG (qui peut contenir du script) n'est jamais une image acceptée
        $image = (new Bloc())->setType(Bloc::TYPE_IMAGE)->setTitre('Logo');
        $image->setFichierFile($this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        self::assertArrayHasKey('fichierFile', $this->violations($image));
    }

    public function testTooHeavyFileIsRefusedWithAClearMessage(): void
    {
        $limite = Bloc::FICHIERS_ACCEPTES[Bloc::TYPE_IMAGE]['tailleMax'];
        $image = (new Bloc())->setType(Bloc::TYPE_IMAGE)->setTitre('Énorme');
        $image->setFichierFile($this->upload('enorme.png', base64_decode(self::PNG_BASE64) . str_repeat("\0", $limite)));

        $violations = $this->violations($image);

        self::assertArrayHasKey('fichierFile', $violations);
        self::assertStringContainsString('trop lourd', $violations['fichierFile']);
    }

    public function testToStringSummarisesTheBlock(): void
    {
        self::assertSame('Texte — Bonjour le monde', (string) (new Bloc())->setType(Bloc::TYPE_TEXTE)->setContenu('<p>Bonjour <strong>le monde</strong></p>'));
        self::assertSame('Son — Mon morceau', (string) (new Bloc())->setType(Bloc::TYPE_SON)->setTitre('Mon morceau'));
        self::assertSame('Vidéo', (string) (new Bloc())->setType(Bloc::TYPE_VIDEO));
    }
}
