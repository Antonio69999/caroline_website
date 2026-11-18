<?php

namespace App\Tests\Admin;

use App\Controller\Admin\CarotteCrudController;
use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Carotte;
use App\Entity\Categorie;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Carottes cachées » dans l'admin : la liste déroulante propose les vraies pages du site, une
 * carotte se crée avec son image et ses réglages, ce que le serveur ne doit pas accepter est
 * refusé, et une carotte dont la page a disparu reste modifiable.
 *
 * Chaque test part d'une table vide et la vide après lui ; les catégories, articles et fichiers
 * créés par un test sont supprimés après lui.
 */
class CarotteFormTest extends WebTestCase
{
    /** Une image PNG valide de 1 pixel (en base64) : le serveur contrôle le vrai contenu des fichiers. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tmpFiles = [];

    /** @var list<string> fichiers déposés dans public/uploads/carottes par un test */
    private array $uploaded = [];

    /** @var list<object> */
    private array $created = [];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        // Le client démarre le noyau : il doit exister avant tout accès au conteneur
        $this->client = static::createClient();
        $this->viderCarottes();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->getRepository(Carotte::class)->findAll() as $carotte) {
            if ($carotte->getImageName()) {
                $this->uploaded[] = $carotte->getImageName();
            }
        }
        $this->viderCarottes();

        foreach (array_reverse($this->created) as $entity) {
            $managed = $em->find($entity::class, $entity->getId());
            if ($managed) {
                $em->remove($managed);
                $em->flush();
            }
        }

        // Ceinture et bretelles : rien ne doit rester dans le dossier d'envoi
        foreach ($this->uploaded as $nom) {
            @unlink($this->dossier() . '/' . $nom);
        }
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();

        // PHPUnit garde chaque objet de test jusqu'à la fin de la suite : le client porte tout le noyau, on le lâche
        unset($this->client);
        $this->tmpFiles = [];
        $this->uploaded = [];
        $this->created = [];
    }

    private function viderCarottes(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->getRepository(Carotte::class)->findAll() as $carotte) {
            $em->remove($carotte);
        }
        $em->flush();
        $em->clear();
    }

    private function dossier(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/carottes';
    }

    private function loggedClient(): KernelBrowser
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($em->getRepository(Admin::class)->findOneBy([]));

        return $this->client;
    }

    private function adminUrl(string $action, ?int $id = null): string
    {
        $generator = static::getContainer()->get(AdminUrlGeneratorInterface::class)
            ->setController(CarotteCrudController::class)
            ->setAction($action);

        if (null !== $id) {
            $generator->setEntityId($id);
        }

        return $generator->generateUrl();
    }

    /** @return list<Carotte> */
    private function carottes(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Carotte::class)->findBy([], ['id' => 'ASC']);
    }

    private function fixture(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'car');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function categorie(string $titre, int $position = 9850): Categorie
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $categorie = (new Categorie())->setTitre($titre)->setPosition($position);
        $em->persist($categorie);
        $em->flush();
        $this->created[] = $categorie;

        return $categorie;
    }

    private function article(Categorie $categorie, string $titre, ?int $annee = null, bool $publie = true): Article
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $article = (new Article())->setTitre($titre)->setCategorie($em->getReference(Categorie::class, $categorie->getId()))->setPosition(0)->setAnnee($annee)->setPublie($publie);
        $em->persist($article);
        $em->flush();
        $this->created[] = $article;

        return $article;
    }

    private function carotte(string $nom, string $cible, ?string $image = 'une-image.png'): Carotte
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $carotte = (new Carotte())->setNom($nom)->setCible($cible);
        $carotte->setImageName($image);
        $em->persist($carotte);
        $em->flush();
        $em->clear();

        return $carotte;
    }

    private function formulaireNouvelle(KernelBrowser $client): Crawler
    {
        $crawler = $client->request('GET', $this->adminUrl(Action::NEW));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * Envoie le formulaire « nouvelle carotte » comme le ferait le navigateur.
     *
     * @param array<string, mixed>        $champs   valeurs à saisir, ex. ['nom' => 'Pas de…', 'cible' => 'accueil']
     * @param array<string, UploadedFile> $fichiers fichier par champ (imageFile)
     */
    private function envoyerNouvelle(KernelBrowser $client, Crawler $crawler, array $champs, array $fichiers = []): Crawler
    {
        $form = $crawler->filter('form.ea-new-form')->form();
        $values = $form->getPhpValues();
        foreach ($champs as $champ => $valeur) {
            $values['Carotte'][$champ] = $valeur;
        }

        $files = [];
        foreach ($fichiers as $champ => $fichier) {
            $files['Carotte'][$champ]['file'] = $fichier;
        }

        return $client->request('POST', $form->getUri(), $values, $files);
    }

    public function testTheMenuLinksToTheListAndTheEmptyListInvitesToAddACarrot(): void
    {
        $client = $this->loggedClient();
        $crawler = $client->request('GET', '/mon_petit_site');
        self::assertResponseIsSuccessful();

        $lien = $crawler->filter('a[href*="CarotteCrudController"]');
        self::assertGreaterThanOrEqual(1, $lien->count());
        self::assertSame('Carottes cachées', trim($lien->first()->text()));

        $crawler = $client->request('GET', $this->adminUrl(Action::INDEX));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Carottes cachées');
        self::assertSame('Ajouter une carotte', trim($crawler->filter('.action-new')->text()));
    }

    public function testTheAdminAreaIsClosedToVisitors(): void
    {
        foreach ([$this->adminUrl(Action::INDEX), $this->adminUrl(Action::NEW)] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects(null, null, $url);
            self::assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
        }
    }

    public function testTheDropdownOffersTheRealPagesOfTheSiteGroupedByCategory(): void
    {
        $gravure = $this->categorie('Gravure de test', 9851);
        $peinture = $this->categorie('Peinture de test', 9852);
        $a = $this->article($gravure, 'Les trois grâces', 2021);
        $b = $this->article($peinture, 'Minimum', 2018);
        $brouillon = $this->article($peinture, 'En préparation', null, false);

        $crawler = $this->formulaireNouvelle($this->loggedClient());
        $select = $crawler->filter('select[name="Carotte[cible]"]');

        self::assertCount(1, $select);
        self::assertSame('ea-autocomplete', $select->attr('data-ea-widget'), 'Liste dans laquelle on peut taper pour chercher');

        // Valeurs : le site entier, l'accueil, puis les catégories et les articles tels qu'ils sont en base
        $valeurs = $select->filter('option')->each(fn (Crawler $o) => $o->attr('value'));
        foreach (['partout', 'accueil', 'categorie:' . $gravure->getId(), 'categorie:' . $peinture->getId(), 'article:' . $a->getId(), 'article:' . $b->getId(), 'article:' . $brouillon->getId()] as $attendue) {
            self::assertContains($attendue, $valeurs, $attendue);
        }

        // Les articles sont rangés sous leur catégorie, avec leur année
        $groupes = $select->filter('optgroup')->each(fn (Crawler $g) => $g->attr('label'));
        self::assertContains('Articles : Gravure de test', $groupes);
        self::assertContains('Articles : Peinture de test', $groupes);
        self::assertContains('Catégories', $groupes);

        $optionGrace = $select->filter('option[value="article:' . $a->getId() . '"]');
        self::assertSame('Les trois grâces, 2021', trim($optionGrace->text()));
        self::assertStringContainsString('brouillon', $select->filter('option[value="article:' . $brouillon->getId() . '"]')->text());
    }

    public function testTwoPagesWithTheSameTitleCanBothBeChosen(): void
    {
        $categorie = $this->categorie('Galerie doublons');
        $premier = $this->article($categorie, 'Même titre');
        $second = $this->article($categorie, 'Même titre');

        $crawler = $this->formulaireNouvelle($this->loggedClient());
        $select = $crawler->filter('select[name="Carotte[cible]"]');

        $textes = $select->filter('option[value="article:' . $premier->getId() . '"], option[value="article:' . $second->getId() . '"]')->each(fn (Crawler $o) => trim($o->text()));
        self::assertCount(2, $textes);
        self::assertCount(2, array_unique($textes), 'Chaque libellé de la liste doit être unique');
        self::assertContains('Même titre (n° ' . $second->getId() . ')', $textes);
    }

    public function testTheNewFormStartsWithSensibleDefaultsAndTheScriptHooks(): void
    {
        $crawler = $this->formulaireNouvelle($this->loggedClient());

        self::assertSame('choisi', $crawler->filter('input[name="Carotte[modeReglage]"]:checked')->attr('value'));
        self::assertSame(['choisi', 'hasard'], $crawler->filter('input[name="Carotte[modeReglage]"]')->each(fn (Crawler $n) => $n->attr('value')));
        self::assertSame('normale', $crawler->filter('input[name="Carotte[carotteTaille]"]:checked')->attr('value'));
        self::assertSame('100', $crawler->filter('input[name="Carotte[carotteOpacite]"]')->attr('value'));
        self::assertSame('bas-droite', $crawler->filter('select[name="Carotte[carottePlace]"] option[selected]')->attr('value'));
        self::assertSame('0', $crawler->filter('input[name="Carotte[delai]"]')->attr('value'));
        self::assertSame('moyenne', $crawler->filter('input[name="Carotte[imageTaille]"]:checked')->attr('value'));
        self::assertSame('milieu-centre', $crawler->filter('select[name="Carotte[imagePlace]"] option[selected]')->attr('value'));

        // Neuf places sur la page, neuf sur l'écran
        self::assertCount(9, $crawler->filter('select[name="Carotte[carottePlace]"] option'));
        self::assertCount(9, $crawler->filter('select[name="Carotte[imagePlace]"] option'));

        // Les réglages à la main portent la classe que le script masque en mode « au hasard »
        self::assertGreaterThanOrEqual(5, $crawler->filter('.carotte-reglage')->count());

        // Le script refuse une image plus lourde que ce que PHP accepte, au lieu de laisser PHP jeter le formulaire
        $fichier = $crawler->filter('input[type="file"][data-carotte-image]');
        self::assertSame('Carotte[imageFile][file]', $fichier->attr('name'));
        self::assertSame((string) UploadLimits::perFile(), $fichier->attr('data-max-bytes'));
        self::assertNotEmpty($fichier->attr('data-krot'), 'Le script a de quoi dessiner l\'exemple de carotte');
        self::assertNull($fichier->attr('data-preview-url'));

        self::assertSelectorExists('script[src*="admin_carottes.js"]');
        self::assertSelectorExists('link[href*="admin_carottes.css"]');
    }

    public function testCreatingACarrotSavesItsImageAndAllItsSettings(): void
    {
        $categorie = $this->categorie('Galerie création');
        $article = $this->article($categorie, 'Les dames de Chavanelles');
        $client = $this->loggedClient();

        $this->envoyerNouvelle($client, $this->formulaireNouvelle($client), [
            'nom' => 'Sauce karote',
            'actif' => '1',
            'cible' => 'article:' . $article->getId(),
            'description' => 'Une carotte transparente',
            'modeReglage' => 'choisi',
            'carotteTaille' => 'minuscule',
            'carotteOpacite' => '10',
            'carottePlace' => 'bas-gauche',
            'delai' => '30',
            'imageTaille' => 'carte',
            'imagePlace' => 'haut-droite',
        ], ['imageFile' => $this->fixture('sauce karote.png', base64_decode(self::PNG))]);

        self::assertResponseRedirects();

        $carottes = $this->carottes();
        self::assertCount(1, $carottes);
        $c = $carottes[0];
        self::assertSame('Sauce karote', $c->getNom());
        self::assertTrue($c->isActif());
        self::assertSame('article:' . $article->getId(), $c->getCible());
        self::assertSame('Une carotte transparente', $c->getDescription());
        self::assertSame(Carotte::MODE_CHOISI, $c->getModeReglage());
        self::assertSame(['minuscule', 10, 'bas-gauche', 30, 'carte', 'haut-droite'], [$c->getCarotteTaille(), $c->getCarotteOpacite(), $c->getCarottePlace(), $c->getDelai(), $c->getImageTaille(), $c->getImagePlace()]);
        self::assertNotNull($c->getCreeLe());
        self::assertNotNull($c->getModifieLe());

        self::assertTrue($c->hasImage());
        self::assertStringEndsWith('.png', $c->getImageName());
        self::assertFileExists($this->dossier() . '/' . $c->getImageName());

        // La liste la montre, avec le nom de la page et un lien d'aperçu qui ouvre la bonne page
        $crawler = $client->request('GET', $this->adminUrl(Action::INDEX));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sauce karote', $crawler->filter('table')->text());
        self::assertStringContainsString('Article : Les dames de Chavanelles', $crawler->filter('table')->text());
        self::assertCount(1, $crawler->filter('table img[src="/uploads/carottes/' . $c->getImageName() . '"]'));

        $voir = $crawler->filter('a.action-voir');
        self::assertCount(1, $voir);
        self::assertStringContainsString('carotte=' . $c->getId(), $voir->attr('href'));
        self::assertStringContainsString('/article/' . $article->getId() . '/les-dames-de-chavanelles', $voir->attr('href'));
        self::assertSame('_blank', $voir->attr('target'));

        // Et le lien d'aperçu mène bien à la carotte (l'administrateur voit la carotte tout de suite)
        $crawler = $client->request('GET', $voir->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSame('/uploads/carottes/' . $c->getImageName(), $crawler->filter('#carottes .carotte')->attr('data-image'));
        self::assertSame('1', $crawler->filter('#carottes .carotte')->attr('data-forcee'));
    }

    public function testARandomCarrotCanBeCreatedAndKeepsItsMode(): void
    {
        $client = $this->loggedClient();

        $this->envoyerNouvelle($client, $this->formulaireNouvelle($client), [
            'nom' => 'Chaos',
            'cible' => 'partout',
            'modeReglage' => 'hasard',
        ], ['imageFile' => $this->fixture('chaos.png', base64_decode(self::PNG))]);

        self::assertResponseRedirects();
        $carottes = $this->carottes();
        self::assertCount(1, $carottes);
        self::assertSame(Carotte::MODE_HASARD, $carottes[0]->getModeReglage());
        self::assertSame('partout', $carottes[0]->getCible());

        $crawler = $client->request('GET', $this->adminUrl(Action::INDEX));
        self::assertStringContainsString('Au hasard', $crawler->filter('table')->text());
    }

    public function testACarrotWithoutImageIsRefusedAndNothingIsSaved(): void
    {
        $client = $this->loggedClient();

        $crawler = $this->envoyerNouvelle($client, $this->formulaireNouvelle($client), ['nom' => 'Sans image', 'cible' => 'accueil']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisis l\'image que cette carotte révèle', $crawler->filter('form.ea-new-form')->text());
        self::assertSame([], $this->carottes());
    }

    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $client = $this->loggedClient();

        $crawler = $this->envoyerNouvelle(
            $client,
            $this->formulaireNouvelle($client),
            ['nom' => 'Faux', 'cible' => 'accueil'],
            ['imageFile' => $this->fixture('page.png', '<html><script>alert(1)</script></html>')],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ce fichier ne convient pas', $crawler->filter('form.ea-new-form')->text());
        self::assertSame([], $this->carottes());
        self::assertSame([], glob($this->dossier() . '/*') ?: [], 'Rien n\'est resté dans le dossier d\'envoi');
    }

    public function testANameAndAValidPageAreRequiredAndSettingsStayInTheirLimits(): void
    {
        $client = $this->loggedClient();
        $png = $this->fixture('ok.png', base64_decode(self::PNG));

        // Sans nom
        $crawler = $this->envoyerNouvelle($client, $this->formulaireNouvelle($client), ['nom' => '', 'cible' => 'accueil'], ['imageFile' => $png]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Donne un nom à cette carotte', $crawler->filter('form.ea-new-form')->text());

        // Une page qui n'est pas dans la liste (valeur trafiquée)
        $this->envoyerNouvelle($client, $this->formulaireNouvelle($client), ['nom' => 'Trafiquée', 'cible' => 'article:abc'], ['imageFile' => $this->fixture('ok2.png', base64_decode(self::PNG))]);
        self::assertResponseStatusCodeSame(422);

        // Une transparence hors limites
        $crawler = $this->envoyerNouvelle($client, $this->formulaireNouvelle($client), ['nom' => 'Invisible', 'cible' => 'accueil', 'carotteOpacite' => '1'], ['imageFile' => $this->fixture('ok3.png', base64_decode(self::PNG))]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('La transparence va de 5', $crawler->filter('form.ea-new-form')->text());

        self::assertSame([], $this->carottes());
    }

    public function testEditingKeepsTheImageUnlessANewOneIsChosen(): void
    {
        $client = $this->loggedClient();
        $this->envoyerNouvelle($client, $this->formulaireNouvelle($client), ['nom' => 'Ancienne', 'cible' => 'accueil'], ['imageFile' => $this->fixture('avant.png', base64_decode(self::PNG))]);
        $avant = $this->carottes()[0];
        $image = $avant->getImageName();

        $crawler = $client->request('GET', $this->adminUrl(Action::EDIT, $avant->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('/uploads/carottes/' . $image, $crawler->filter('input[type="file"][data-carotte-image]')->attr('data-preview-url'));

        $form = $crawler->filter('form.ea-edit-form')->form();
        $values = $form->getPhpValues();
        $values['Carotte']['nom'] = 'Renommée';
        $values['Carotte']['actif'] = '0';
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $apres = $this->carottes()[0];
        self::assertSame('Renommée', $apres->getNom());
        self::assertSame($image, $apres->getImageName(), 'Sans nouvelle image, l\'ancienne reste');
        self::assertFileExists($this->dossier() . '/' . $image);
        self::assertGreaterThanOrEqual($avant->getModifieLe(), $apres->getModifieLe());
    }

    public function testDeletingACarrotAlsoDeletesItsImageFile(): void
    {
        $client = $this->loggedClient();
        $this->envoyerNouvelle($client, $this->formulaireNouvelle($client), ['nom' => 'À supprimer', 'cible' => 'accueil'], ['imageFile' => $this->fixture('supprimer.png', base64_decode(self::PNG))]);
        $carotte = $this->carottes()[0];
        $fichier = $this->dossier() . '/' . $carotte->getImageName();
        self::assertFileExists($fichier);

        $crawler = $client->request('GET', $this->adminUrl(Action::INDEX));
        $formulaire = $crawler->filter('form#action-confirmation-form');
        self::assertCount(1, $formulaire, 'EasyAdmin fournit le formulaire de suppression de la liste');

        $client->request('POST', $this->adminUrl(Action::DELETE, $carotte->getId()), ['token' => $formulaire->filter('input[name="token"]')->attr('value')]);

        self::assertSame([], $this->carottes());
        self::assertFileDoesNotExist($fichier);
    }

    public function testACarrotWhosePageDisappearedCanStillBeOpenedAndIsFlagged(): void
    {
        $categorie = $this->categorie('Galerie disparue');
        $article = $this->article($categorie, 'Article qui disparaît');
        $carotte = $this->carotte('Orpheline', 'article:' . $article->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->remove($em->find(Article::class, $article->getId()));
        $em->flush();
        $em->clear();

        $client = $this->loggedClient();

        // La liste le signale et n'offre pas de lien d'aperçu qui mènerait à une erreur
        $crawler = $client->request('GET', $this->adminUrl(Action::INDEX));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Page supprimée', $crawler->filter('table')->text());
        self::assertCount(0, $crawler->filter('a.action-voir'));

        // Le formulaire s'ouvre quand même, avec la page manquante signalée
        $crawler = $client->request('GET', $this->adminUrl(Action::EDIT, $carotte->getId()));
        self::assertResponseIsSuccessful();
        $option = $crawler->filter('select[name="Carotte[cible]"] option[value="article:' . $article->getId() . '"]');
        self::assertCount(1, $option);
        self::assertStringContainsString('n\'existe plus', $option->text());
    }

    public function testThePreviewLinkOfAnUnpublishedArticleIsNotOffered(): void
    {
        $categorie = $this->categorie('Galerie brouillon');
        $article = $this->article($categorie, 'Brouillon', null, false);
        $this->carotte('Dans un brouillon', 'article:' . $article->getId());

        $crawler = $this->loggedClient()->request('GET', $this->adminUrl(Action::INDEX));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.action-voir'), 'Un brouillon répond 404 : le lien ne mènerait à rien');
    }

    public function testThePreviewLinksOfTheHomeAndOfACategoryPointToTheRightPages(): void
    {
        $categorie = $this->categorie('Gravure aperçu');
        $partout = $this->carotte('Partout', 'partout');
        $accueil = $this->carotte('Accueil', 'accueil');
        $cat = $this->carotte('Catégorie', 'categorie:' . $categorie->getId());

        $crawler = $this->loggedClient()->request('GET', $this->adminUrl(Action::INDEX));
        self::assertResponseIsSuccessful();

        $liens = $crawler->filter('a.action-voir')->each(fn (Crawler $a) => $a->attr('href'));
        sort($liens);
        $attendus = [
            '/?carotte=' . $partout->getId(),
            '/?carotte=' . $accueil->getId(),
            '/category/' . $categorie->getId() . '/gravure-apercu?carotte=' . $cat->getId(),
        ];
        sort($attendus);
        self::assertSame($attendus, $liens);
    }
}
