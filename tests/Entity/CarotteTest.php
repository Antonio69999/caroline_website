<?php

namespace App\Tests\Entity;

use App\Entity\Carotte;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Les carottes cachées : la page visée (cible), les réglages de CHAQUE visite (choisis par
 * Caroline ou tirés au sort) et ce que valide Carotte::validate().
 */
class CarotteTest extends KernelTestCase
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
    private function violations(Carotte $carotte): array
    {
        self::bootKernel();
        $result = [];
        foreach (self::getContainer()->get(ValidatorInterface::class)->validate($carotte) as $violation) {
            $result[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $result;
    }

    private function carotteValide(): Carotte
    {
        $carotte = (new Carotte())->setNom('Pas de…');
        $carotte->setImageName('deja-la.jpg');

        return $carotte;
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'car');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    public function testANewCarrotIsActiveEverywhereAndNormal(): void
    {
        $carotte = new Carotte();

        self::assertTrue($carotte->isActif());
        self::assertSame('partout', $carotte->getCible());
        self::assertSame(Carotte::MODE_CHOISI, $carotte->getModeReglage());
        self::assertSame(
            ['taille' => 'normale', 'opacite' => 100, 'ligne' => 'bas', 'colonne' => 'droite', 'imageTaille' => 'moyenne', 'imageLigne' => 'milieu', 'imageColonne' => 'centre'],
            $carotte->reglages(),
        );
    }

    /**
     * @return iterable<string, array{string, ?string, ?int}>
     */
    public static function ciblesProvider(): iterable
    {
        yield 'partout' => ['partout', 'partout', null];
        yield 'accueil' => ['accueil', 'accueil', null];
        yield 'une catégorie' => ['categorie:3', 'categorie', 3];
        yield 'un article' => ['article:128', 'article', 128];
        yield 'numéro zéro' => ['article:0', null, null];
        yield 'numéro absent' => ['article:', null, null];
        yield 'numéro négatif' => ['article:-4', null, null];
        yield 'genre inconnu' => ['media:4', null, null];
        yield 'n\'importe quoi' => ['n\'importe quoi', null, null];
    }

    /**
     * @dataProvider ciblesProvider
     */
    public function testTheTargetIsReadBackAsATypeAndAnId(string $cible, ?string $type, ?int $id): void
    {
        $carotte = (new Carotte())->setCible($cible);

        self::assertSame($type, $carotte->cibleType());
        self::assertSame($id, $carotte->cibleId());
    }

    public function testTheTargetBuilderMatchesWhatIsRead(): void
    {
        self::assertSame('article:12', Carotte::cibleDe(Carotte::CIBLE_ARTICLE, 12));
        self::assertSame('categorie:7', Carotte::cibleDe(Carotte::CIBLE_CATEGORIE, 7));
    }

    public function testChosenSettingsAreReturnedAsTheyAre(): void
    {
        $carotte = (new Carotte())
            ->setCarotteTaille('minuscule')
            ->setCarotteOpacite(10)
            ->setCarottePlace('haut-gauche')
            ->setImageTaille('carte')
            ->setImagePlace('haut-droite');

        self::assertSame(
            ['taille' => 'minuscule', 'opacite' => 10, 'ligne' => 'haut', 'colonne' => 'gauche', 'imageTaille' => 'carte', 'imageLigne' => 'haut', 'imageColonne' => 'droite'],
            $carotte->reglages(),
        );
        // Chosen settings never change from one visit to the next
        self::assertSame($carotte->reglages(), $carotte->reglages());
    }

    public function testAValueOutsideTheAllowedListsIsBroughtBackToAnAllowedOne(): void
    {
        // Une valeur écrite à la main en base : la page doit rester affichable
        $carotte = (new Carotte())
            ->setCarotteTaille('géante')
            ->setCarotteOpacite(900)
            ->setCarottePlace('ailleurs')
            ->setImageTaille('énorme')
            ->setImagePlace('nulle-part');

        $reglages = $carotte->reglages();

        self::assertSame('normale', $reglages['taille']);
        self::assertSame(100, $reglages['opacite']);
        self::assertSame('bas', $reglages['ligne']);
        self::assertSame('droite', $reglages['colonne']);
        self::assertSame('moyenne', $reglages['imageTaille']);
        self::assertSame('milieu', $reglages['imageLigne']);
        self::assertSame('centre', $reglages['imageColonne']);

        self::assertSame(5, (new Carotte())->setCarotteOpacite(0)->reglages()['opacite'], 'Jamais totalement invisible');
    }

    public function testRandomModeAlwaysDrawsAllowedValuesAndChangesFromOneVisitToTheNext(): void
    {
        // Les réglages choisis sont ignorés en mode « hasard »
        $carotte = (new Carotte())
            ->setModeReglage(Carotte::MODE_HASARD)
            ->setCarotteTaille('minuscule')
            ->setCarotteOpacite(5);

        $vus = ['taille' => [], 'opacite' => [], 'place' => [], 'imageTaille' => [], 'imagePlace' => []];

        for ($i = 0; $i < 300; ++$i) {
            $r = $carotte->reglages();

            self::assertContains($r['taille'], Carotte::TAILLES_CAROTTE);
            self::assertContains($r['ligne'] . '-' . $r['colonne'], Carotte::PLACES_CAROTTE);
            self::assertContains($r['imageTaille'], Carotte::TAILLES_IMAGE);
            self::assertContains($r['imageLigne'] . '-' . $r['imageColonne'], Carotte::PLACES_IMAGE);
            self::assertGreaterThanOrEqual(Carotte::OPACITE_MIN_HASARD, $r['opacite'], 'Au hasard, jamais trop transparente pour être trouvée');
            self::assertLessThanOrEqual(Carotte::OPACITE_MAX, $r['opacite']);

            $vus['taille'][$r['taille']] = true;
            $vus['opacite'][$r['opacite']] = true;
            $vus['place'][$r['ligne'] . '-' . $r['colonne']] = true;
            $vus['imageTaille'][$r['imageTaille']] = true;
            $vus['imagePlace'][$r['imageLigne'] . '-' . $r['imageColonne']] = true;
        }

        // Sur 300 tirages, chaque réglage a pris plusieurs valeurs (le hasard est bien là)
        foreach ($vus as $nom => $valeurs) {
            self::assertGreaterThan(1, count($valeurs), sprintf('Le réglage « %s » ne change jamais', $nom));
        }
        self::assertGreaterThan(1, count($vus['taille']), 'Plusieurs tailles de carotte');
    }

    public function testAValidCarrotHasNoViolation(): void
    {
        self::assertSame([], $this->violations($this->carotteValide()));
    }

    public function testANameIsRequired(): void
    {
        $carotte = $this->carotteValide()->setNom('   ');

        self::assertArrayHasKey('nom', $this->violations($carotte));
    }

    public function testAnImageIsRequiredForANewCarrot(): void
    {
        $carotte = (new Carotte())->setNom('Sans image');

        self::assertArrayHasKey('imageFile', $this->violations($carotte));
    }

    public function testAMeaninglessTargetIsRefused(): void
    {
        self::assertArrayHasKey('cible', $this->violations($this->carotteValide()->setCible('article:abc')));
        self::assertArrayNotHasKey('cible', $this->violations($this->carotteValide()->setCible('categorie:4')));
    }

    public function testSettingsMustStayInsideTheirLimits(): void
    {
        $violations = $this->violations(
            $this->carotteValide()
                ->setCarotteTaille('géante')
                ->setCarotteOpacite(2)
                ->setCarottePlace('ailleurs')
                ->setDelai(-1)
                ->setImageTaille('énorme')
                ->setImagePlace('nulle-part')
                ->setModeReglage('chaos')
        );

        foreach (['carotteTaille', 'carotteOpacite', 'carottePlace', 'delai', 'imageTaille', 'imagePlace', 'modeReglage'] as $champ) {
            self::assertArrayHasKey($champ, $violations, $champ);
        }

        self::assertArrayHasKey('carotteOpacite', $this->violations($this->carotteValide()->setCarotteOpacite(101)));
        self::assertArrayHasKey('delai', $this->violations($this->carotteValide()->setDelai(Carotte::DELAI_MAX + 1)));
        self::assertSame([], $this->violations($this->carotteValide()->setCarotteOpacite(5)->setDelai(Carotte::DELAI_MAX)));
    }

    public function testAUploadedImageIsCheckedOnItsRealContent(): void
    {
        $carotte = (new Carotte())->setNom('Avec fichier');

        $carotte->setImageFile($this->upload('photo.png', base64_decode(self::PNG)));
        self::assertSame([], $this->violations($carotte));

        // Une page web renommée en .png reste une page web
        $faux = (new Carotte())->setNom('Faux');
        $faux->setImageFile($this->upload('photo.png', '<html><script>alert(1)</script></html>'));
        self::assertArrayHasKey('imageFile', $this->violations($faux));

        // Un SVG peut contenir du script : refusé même avec un vrai contenu SVG
        $svg = (new Carotte())->setNom('Svg');
        $svg->setImageFile($this->upload('dessin.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        self::assertArrayHasKey('imageFile', $this->violations($svg));
    }
}
