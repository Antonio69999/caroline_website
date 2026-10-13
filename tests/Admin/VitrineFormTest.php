<?php

namespace App\Tests\Admin;

use App\Controller\Admin\CategorieCrudController;
use App\Entity\Admin;
use App\Entity\Bloc;
use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Formulaire admin d'une catégorie « vitrine » : ajout de blocs texte / son (avec
 * un vrai envoi de fichier), contrôles à l'enregistrement, ordre et suppression.
 *
 * Les blocs « nouveaux » sont ajoutés par du JavaScript dans le navigateur ; ici on
 * envoie directement ce que ce JavaScript enverrait (même noms de champs).
 */
class VitrineFormTest extends WebTestCase
{
    private const MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00" . "\xFF\xFB\x90\x00";

    /** @var list<string> */
    private array $tmpFiles = [];

    /** @var list<string> fichiers déposés dans public/uploads/vitrine par un test */
    private array $uploaded = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        // Les vitrines créées par ces tests : on les retrouve par leur titre
        foreach ($em->getRepository(Categorie::class)->findBy(['type' => Categorie::TYPE_VITRINE]) as $vitrine) {
            if (str_starts_with((string) $vitrine->getTitre(), 'Test vitrine')) {
                foreach ($vitrine->getBlocs() as $bloc) {
                    if ($bloc->getFichierName()) {
                        $this->uploaded[] = $bloc->getFichierName();
                    }
                }
                $em->remove($vitrine);
            }
        }
        $em->flush();

        // Ceinture et bretelles : rien ne doit rester dans le dossier d'envoi
        foreach ($this->uploaded as $name) {
            @unlink(static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/vitrine/' . $name);
        }
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function adminUrl(string $action, ?int $id = null): string
    {
        $generator = static::getContainer()->get(AdminUrlGeneratorInterface::class)
            ->setController(CategorieCrudController::class)
            ->setAction($action);

        if (null !== $id) {
            $generator->setEntityId($id);
        }

        return $generator->generateUrl();
    }

    private function loggedClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($em->getRepository(Admin::class)->findOneBy([]));

        return $client;
    }

    private function fixture(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vit');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Envoie le formulaire de la page (valeurs actuelles + $blocs) comme le ferait le navigateur.
     *
     * @param array<int, array<string, mixed>> $blocs   champs des blocs, par index
     * @param array<int, UploadedFile>         $fichiers fichier de chaque bloc, par index
     */
    private function submit($client, Crawler $crawler, string $titre, array $blocs, array $fichiers = [], bool $edit = false): Crawler
    {
        $form = $edit
            ? $crawler->filter('form.ea-edit-form')->form()
            : $crawler->selectButton('Créer')->form();

        $values = $form->getPhpValues();
        $values['Categorie']['titre'] = $titre;
        $values['Categorie']['type'] = 'vitrine';
        $values['Categorie']['blocs'] = $blocs;

        $files = [];
        foreach ($fichiers as $index => $fichier) {
            $files['Categorie']['blocs'][$index]['fichierFile']['file'] = $fichier;
        }

        return $client->request('POST', $form->getUri(), $values, $files);
    }

    public function testNewFormOffersTheVitrineTypeAndAnEmptyBlocsCollection(): void
    {
        $client = $this->loggedClient();

        $crawler = $client->request('GET', $this->adminUrl(Action::NEW));
        self::assertResponseIsSuccessful();

        $types = $crawler->filter('input[name="Categorie[type]"]')->each(fn ($n) => $n->attr('value'));
        self::assertSame(['galerie', 'texte', 'vitrine'], $types);
        self::assertSame('galerie', $crawler->filter('input[name="Categorie[type]"]:checked')->attr('value'), 'Le type par défaut ne doit pas changer');

        // La collection de blocs est là, avec son bouton « Ajouter »
        self::assertSelectorExists('[data-form-type-name-placeholder="__blocsname__"]');
        self::assertSelectorExists('[data-form-type-name-placeholder="__blocsname__"] .field-collection-add-button');

        // Le gabarit d'un bloc neuf contient tous les champs, démarre en « Texte » et porte la liste des articles citables
        $prototype = $crawler->filter('[data-form-type-name-placeholder="__blocsname__"]')->attr('data-prototype');
        foreach (['[type]', '[contenu]', '[titre]', '[largeur]', '[position]', '[fichierFile][file]'] as $champ) {
            self::assertStringContainsString('Categorie[blocs][__blocsname__]' . $champ, $prototype);
        }
        self::assertMatchesRegularExpression('/value="texte"\s+checked="checked"/', $prototype);
        self::assertStringContainsString('data-rich-category-editor', $prototype);
        self::assertStringContainsString('data-linkable-articles', $prototype);
    }

    public function testCreatingAVitrineWithATextBlockAndAnMp3Block(): void
    {
        $client = $this->loggedClient();
        $crawler = $client->request('GET', $this->adminUrl(Action::NEW));

        $this->submit($client, $crawler, 'Test vitrine création', [
            0 => ['type' => 'texte', 'contenu' => '<h2>Bienvenue</h2><p>Dans mon jardin</p>', 'titre' => '', 'largeur' => 'pleine', 'position' => '0'],
            1 => ['type' => 'son', 'contenu' => '', 'titre' => 'Chant du matin', 'largeur' => 'pleine', 'position' => '1'],
        ], [1 => $this->fixture('chant du matin.mp3', self::MP3)]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $vitrine = $em->getRepository(Categorie::class)->findOneBy(['titre' => 'Test vitrine création']);
        self::assertNotNull($vitrine, 'La vitrine doit être créée');
        self::assertTrue($vitrine->isVitrine());

        $blocs = $vitrine->getBlocs()->toArray();
        self::assertCount(2, $blocs);

        [$texte, $son] = $blocs;
        self::assertSame(Bloc::TYPE_TEXTE, $texte->getType());
        self::assertSame('<h2>Bienvenue</h2><p>Dans mon jardin</p>', $texte->getContenu());

        self::assertSame(Bloc::TYPE_SON, $son->getType());
        self::assertSame('Chant du matin', $son->getTitre());
        self::assertNotNull($son->getFichierName());
        self::assertStringEndsWith('.mp3', $son->getFichierName());
        $chemin = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/vitrine/' . $son->getFichierName();
        self::assertFileExists($chemin, 'Le fichier doit être déposé dans public/uploads/vitrine');
        $this->uploaded[] = $son->getFichierName();

        // Et la page publique le montre
        $client->request('GET', '/category/' . $vitrine->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vitrine-bloc--texte h2', 'Bienvenue');
        self::assertSelectorExists('.vitrine-bloc--son audio[src="/uploads/vitrine/' . $son->getFichierName() . '"]');
    }

    public function testIncompleteBlocksAreRejectedWithoutCreatingAnything(): void
    {
        $client = $this->loggedClient();
        $crawler = $client->request('GET', $this->adminUrl(Action::NEW));

        // Une image sans fichier ni description, et un texte vide
        $crawler = $this->submit($client, $crawler, 'Test vitrine incomplète', [
            0 => ['type' => 'image', 'contenu' => '', 'titre' => '', 'largeur' => 'pleine', 'position' => '0'],
            1 => ['type' => 'texte', 'contenu' => '<p><br></p>', 'titre' => '', 'largeur' => 'pleine', 'position' => '1'],
        ]);

        self::assertFalse($client->getResponse()->isRedirect(), 'Le formulaire doit être renvoyé avec ses erreurs');
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('Choisis un fichier', $html);
        self::assertStringContainsString("Décris l'image", html_entity_decode($html, ENT_QUOTES));
        self::assertStringContainsString('Écris un texte', $html);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(Categorie::class)->findOneBy(['titre' => 'Test vitrine incomplète']));
    }

    public function testAScriptDisguisedAsMp3IsRejected(): void
    {
        $client = $this->loggedClient();
        $crawler = $client->request('GET', $this->adminUrl(Action::NEW));

        $this->submit($client, $crawler, 'Test vitrine piégée', [
            0 => ['type' => 'son', 'contenu' => '', 'titre' => 'Louche', 'largeur' => 'pleine', 'position' => '0'],
        ], [0 => $this->fixture('inoffensif.mp3', '<?php system($_GET["c"]);')]);

        self::assertFalse($client->getResponse()->isRedirect());
        self::assertStringContainsString('Ce fichier ne convient pas', $client->getResponse()->getContent());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(Categorie::class)->findOneBy(['titre' => 'Test vitrine piégée']));

        $dossier = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/vitrine';
        self::assertSame([], glob($dossier . '/inoffensif*') ?: [], 'Un fichier refusé ne doit jamais atterrir sur le disque');
    }

    public function testEditingReordersBlocksAndRemovingOneDeletesItsFile(): void
    {
        $client = $this->loggedClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $dossier = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/vitrine';

        // Une vitrine avec trois blocs ; le troisième porte un vrai fichier sur le disque
        if (!is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        $nom = 'test-vitrine-suppression.mp3';
        file_put_contents($dossier . '/' . $nom, self::MP3);
        $this->uploaded[] = $nom;

        $vitrine = (new Categorie())->setTitre('Test vitrine édition')->setType(Categorie::TYPE_VITRINE)->setPosition(9401);
        $a = (new Bloc())->setType(Bloc::TYPE_TEXTE)->setContenu('<p>Premier</p>')->setPosition(0);
        $b = (new Bloc())->setType(Bloc::TYPE_TEXTE)->setContenu('<p>Deuxième</p>')->setPosition(1);
        $c = (new Bloc())->setType(Bloc::TYPE_SON)->setTitre('À supprimer')->setPosition(2);
        $c->setFichierName($nom);
        $vitrine->addBloc($a)->addBloc($b)->addBloc($c);
        $em->persist($vitrine);
        $em->flush();
        $id = $vitrine->getId();
        $em->clear();

        $crawler = $client->request('GET', $this->adminUrl(Action::EDIT, $id));
        self::assertResponseIsSuccessful();

        // Le formulaire montre les trois blocs et le fichier déjà en place
        self::assertCount(3, $crawler->filter('.field-collection-item'));
        self::assertStringContainsString($nom, $crawler->filter('.field-collection-item')->eq(2)->text());

        // On inverse les deux textes et on retire le son (il disparaît de l'envoi, comme au clic sur la corbeille)
        $form = $crawler->filter('form.ea-edit-form')->form();
        $values = $form->getPhpValues();
        $blocs = $values['Categorie']['blocs'];
        $blocs[0]['position'] = '1';
        $blocs[1]['position'] = '0';
        unset($blocs[2]);
        $values['Categorie']['blocs'] = $blocs;
        $client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();

        $em->clear();
        $apres = $em->getRepository(Categorie::class)->find($id);
        $contenus = array_map(fn (Bloc $bloc) => $bloc->getContenu(), $apres->getBlocs()->toArray());
        self::assertSame(['<p>Deuxième</p>', '<p>Premier</p>'], $contenus, "L'ordre enregistré doit suivre les positions envoyées");
        self::assertFileDoesNotExist($dossier . '/' . $nom, 'Supprimer un bloc doit supprimer son fichier');

        // La page publique suit le nouvel ordre
        $client->request('GET', '/category/' . $id);
        $textes = $client->getCrawler()->filter('.vitrine-bloc--texte')->each(fn ($n) => trim($n->text()));
        self::assertSame(['Deuxième', 'Premier'], $textes);
    }
}
