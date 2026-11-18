<?php

namespace App\Tests\Admin;

use App\Controller\Admin\AccueilCrudController;
use App\Entity\Accueil;
use App\Entity\Admin;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Page d'accueil » dans l'admin : le menu mène au formulaire de l'unique ligne, la photo
 * (et la photo « téléphone ») s'envoient pour de vrai, le cadrage est enregistré, retirer la
 * photo ramène à l'accueil d'origine, et ce que le serveur ne doit pas accepter est refusé.
 *
 * L'accueil n'a qu'une ligne en base : chaque test part d'une table vide et la vide après lui.
 */
class AccueilFormTest extends WebTestCase
{
    /** Une image PNG valide de 1 pixel (en base64) : le serveur contrôle le vrai contenu des fichiers. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tmpFiles = [];

    /** @var list<string> fichiers déposés dans public/uploads/accueil par un test */
    private array $uploaded = [];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        // Le client démarre le noyau : il doit exister avant tout accès au conteneur
        $this->client = static::createClient();
        $this->viderAccueil();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->getRepository(Accueil::class)->findAll() as $accueil) {
            foreach ([$accueil->getImageName(), $accueil->getImageMobileName()] as $nom) {
                if ($nom) {
                    $this->uploaded[] = $nom;
                }
            }
        }
        $this->viderAccueil();

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
    }

    private function viderAccueil(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->getRepository(Accueil::class)->findAll() as $accueil) {
            $em->remove($accueil);
        }
        $em->flush();
        $em->clear();
    }

    private function dossier(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/accueil';
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
            ->setController(AccueilCrudController::class)
            ->setAction($action);

        if (null !== $id) {
            $generator->setEntityId($id);
        }

        return $generator->generateUrl();
    }

    /** Ouvre le formulaire comme le fait le lien du menu. */
    private function ouvrir(KernelBrowser $client): Crawler
    {
        $client->request('GET', '/admin/accueil');
        self::assertResponseRedirects();

        return $client->followRedirect();
    }

    private function accueil(): ?Accueil
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Accueil::class)->findOneBy([]);
    }

    private function fixture(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'acc');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Envoie le formulaire (valeurs actuelles + $changements) comme le ferait le navigateur.
     *
     * @param array<string, mixed>        $changements champs à modifier, ex. ['bureauZoom' => '150']
     * @param array<string, UploadedFile> $fichiers    fichier par champ (imageFile, imageMobileFile)
     */
    private function envoyer(KernelBrowser $client, Crawler $crawler, array $changements = [], array $fichiers = []): Crawler
    {
        $form = $crawler->filter('form.ea-edit-form')->form();
        $values = $form->getPhpValues();
        foreach ($changements as $champ => $valeur) {
            $values['Accueil'][$champ] = $valeur;
        }

        $files = [];
        foreach ($fichiers as $champ => $fichier) {
            $files['Accueil'][$champ]['file'] = $fichier;
        }

        return $client->request('POST', $form->getUri(), $values, $files);
    }

    public function testTheMenuLinkOpensTheSingleEditFormAndCreatesTheRowOnce(): void
    {
        $client = $this->loggedClient();

        self::assertNull($this->accueil());
        $this->ouvrir($client);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('crudAction=edit', $client->getRequest()->getUri());
        self::assertSelectorTextContains('h1', 'Page d\'accueil');
        self::assertNotNull($this->accueil());

        // Rouvrir ne crée pas une deuxième ligne
        $this->ouvrir($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(Accueil::class)->findAll());

        // Le menu de l'admin propose bien ce lien
        self::assertSelectorExists('a[href*="routeName=admin_accueil"]');
    }

    public function testFollowingTheMenuLinkAsEasyAdminBuildsItReachesTheForm(): void
    {
        $client = $this->loggedClient();
        $crawler = $client->request('GET', '/mon_petit_site');
        self::assertResponseIsSuccessful();

        // Le lien tel que le menu l'écrit (EasyAdmin le fait passer par le tableau de bord)
        $lien = $crawler->filter('a[href*="routeName=admin_accueil"]');
        self::assertCount(1, $lien);
        self::assertSame('Page d\'accueil', trim($lien->text()));

        $client->click($lien->link());
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.ea-edit-form[name="Accueil"]');
    }

    public function testThereIsNoListNoCreationNoDeletionAndNoSearch(): void
    {
        $client = $this->loggedClient();
        $crawler = $this->ouvrir($client);
        $id = $this->accueil()->getId();

        self::assertSelectorNotExists('form.form-action-search', 'La recherche mènerait à une liste qui n\'existe pas');
        self::assertSelectorNotExists('.action-delete');
        // (les liens « index » du menu, eux, mènent aux autres rubriques)
        self::assertCount(0, $crawler->filter('a[href*="crudAction=index"][href*="AccueilCrudController"]'));

        foreach ([$this->adminUrl(Action::INDEX), $this->adminUrl(Action::NEW), $this->adminUrl(Action::DELETE, $id)] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertNotNull($this->accueil(), 'La page d\'accueil ne peut pas être supprimée');
    }

    public function testFormStartsWithTheOriginalFramingAndTellsTheScriptTheServerLimit(): void
    {
        $client = $this->loggedClient();
        $crawler = $this->ouvrir($client);

        self::assertSame(['entiere', 'plein'], $crawler->filter('input[name="Accueil[bureauMode]"]')->each(fn ($n) => $n->attr('value')));
        self::assertSame('entiere', $crawler->filter('input[name="Accueil[bureauMode]"]:checked')->attr('value'));
        self::assertSame('plein', $crawler->filter('input[name="Accueil[mobileMode]"]:checked')->attr('value'));
        foreach (['bureauX', 'bureauY', 'mobileX', 'mobileY'] as $champ) {
            self::assertSame('50', $crawler->filter('input[name="Accueil[' . $champ . ']"]')->attr('value'), $champ);
        }
        foreach (['bureauZoom', 'mobileZoom'] as $champ) {
            self::assertSame('100', $crawler->filter('input[name="Accueil[' . $champ . ']"]')->attr('value'), $champ);
        }
        self::assertSame('#ffffff', $crawler->filter('input[name="Accueil[couleurFond]"]')->attr('value'));

        // Le script refuse une photo plus lourde que ce que PHP accepte, au lieu de laisser PHP jeter le formulaire
        $principale = $crawler->filter('input[type="file"][data-accueil-photo="principale"]');
        $mobile = $crawler->filter('input[type="file"][data-accueil-photo="mobile"]');
        self::assertSame('Accueil[imageFile][file]', $principale->attr('name'));
        self::assertSame('Accueil[imageMobileFile][file]', $mobile->attr('name'));
        self::assertSame((string) UploadLimits::perFile(), $principale->attr('data-max-bytes'));
        self::assertSame((string) UploadLimits::perFile(), $mobile->attr('data-max-bytes'));

        // Rien d'enregistré encore : pas d'aperçu à montrer
        self::assertNull($principale->attr('data-preview-url'));
        // Le formulaire est celui du site entier, sans les scripts d'autres pages
        self::assertSelectorExists('script[src*="admin_accueil.js"]');
        self::assertSelectorExists('link[href*="admin_accueil.css"]');
    }

    public function testChoosingAPhotoSavesItStaysOnTheFormAndTheHomePageShowsIt(): void
    {
        $client = $this->loggedClient();
        $crawler = $this->ouvrir($client);

        $this->envoyer($client, $crawler, [
            'description' => 'Un mur de lettres',
            'bureauMode' => 'plein',
            'bureauX' => '25',
            'bureauY' => '75',
            'bureauZoom' => '150',
            'mobileMode' => 'plein',
            'mobileX' => '100',
            'couleurFond' => '#102030',
        ], ['imageFile' => $this->fixture('mon accueil.png', base64_decode(self::PNG))]);

        // On reste sur le formulaire (il n'y a pas de liste où retourner) et on est prévenu
        self::assertResponseRedirects();
        self::assertStringContainsString('crudAction=edit', $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.alert, .flash-message, [role="alert"], .content-flash', 'accueil est enregistrée');

        $accueil = $this->accueil();
        self::assertTrue($accueil->hasImage());
        self::assertStringEndsWith('.png', $accueil->getImageName());
        self::assertFileExists($this->dossier() . '/' . $accueil->getImageName());
        self::assertSame('Un mur de lettres', $accueil->getDescription());
        self::assertSame([Accueil::MODE_PLEIN, 25, 75, 150], [$accueil->getBureauMode(), $accueil->getBureauX(), $accueil->getBureauY(), $accueil->getBureauZoom()]);
        self::assertSame([Accueil::MODE_PLEIN, 100, 50, 100], [$accueil->getMobileMode(), $accueil->getMobileX(), $accueil->getMobileY(), $accueil->getMobileZoom()]);
        self::assertSame('#102030', $accueil->getCouleurFond());
        self::assertNotNull($accueil->getModifieLe());

        // Rouvert, le formulaire montre la photo enregistrée (aperçu) et le cadrage choisi
        self::assertSame('/uploads/accueil/' . $accueil->getImageName(), $crawler->filter('input[data-accueil-photo="principale"]')->attr('data-preview-url'));
        self::assertNull($crawler->filter('input[data-accueil-photo="mobile"]')->attr('data-preview-url'));
        self::assertSame('plein', $crawler->filter('input[name="Accueil[bureauMode]"]:checked')->attr('value'));
        self::assertSame('150', $crawler->filter('input[name="Accueil[bureauZoom]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('input[type="checkbox"][name="Accueil[imageFile][delete]"]'), 'On peut retirer la photo enregistrée');

        // Et la page publique la montre
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.accueil-hero img[src="/uploads/accueil/' . $accueil->getImageName() . '"][alt="Un mur de lettres"]');
        self::assertStringContainsString('--bureau-pos:25% 75%;--bureau-zoom:1.5', $client->getCrawler()->filter('.accueil-hero')->attr('style'));
    }

    public function testAMobilePhotoCanBeAddedLaterAndRemovedWithoutTouchingTheMainOne(): void
    {
        $client = $this->loggedClient();
        $crawler = $this->ouvrir($client);
        $png = base64_decode(self::PNG);

        $this->envoyer($client, $crawler, [], ['imageFile' => $this->fixture('principale.png', $png)]);
        self::assertResponseRedirects();
        $principale = $this->accueil()->getImageName();

        $crawler = $this->ouvrir($client);
        $this->envoyer($client, $crawler, [], ['imageMobileFile' => $this->fixture('verticale.png', $png)]);
        self::assertResponseRedirects();
        $accueil = $this->accueil();
        $mobile = $accueil->getImageMobileName();
        self::assertNotNull($mobile);
        self::assertSame($principale, $accueil->getImageName());
        self::assertFileExists($this->dossier() . '/' . $mobile);

        $client->request('GET', '/');
        self::assertSelectorExists('.accueil-hero picture source[srcset="/uploads/accueil/' . $mobile . '"]');

        // « Retirer cette photo » sur la photo téléphone : la principale reste, le fichier disparaît
        $crawler = $this->ouvrir($client);
        $this->envoyer($client, $crawler, ['imageMobileFile' => ['delete' => '1']]);
        self::assertResponseRedirects();
        $accueil = $this->accueil();
        self::assertNull($accueil->getImageMobileName());
        self::assertSame($principale, $accueil->getImageName());
        self::assertFileDoesNotExist($this->dossier() . '/' . $mobile);
        self::assertFileExists($this->dossier() . '/' . $principale);
    }

    public function testRemovingThePhotoBringsBackTheOriginalHome(): void
    {
        $client = $this->loggedClient();
        $crawler = $this->ouvrir($client);
        $this->envoyer($client, $crawler, [], ['imageFile' => $this->fixture('a-retirer.png', base64_decode(self::PNG))]);
        $nom = $this->accueil()->getImageName();
        self::assertFileExists($this->dossier() . '/' . $nom);

        $crawler = $this->ouvrir($client);
        $this->envoyer($client, $crawler, ['imageFile' => ['delete' => '1']]);
        self::assertResponseRedirects();

        self::assertFalse($this->accueil()->hasImage());
        self::assertFileDoesNotExist($this->dossier() . '/' . $nom, 'La photo retirée ne doit pas rester sur le disque');

        $client->request('GET', '/');
        self::assertSelectorExists('.parent-div > .main-content');
        self::assertSelectorNotExists('.accueil-hero');
    }

    public function testReplacingThePhotoDeletesTheOldFile(): void
    {
        $client = $this->loggedClient();
        $png = base64_decode(self::PNG);
        $this->envoyer($client, $this->ouvrir($client), [], ['imageFile' => $this->fixture('avant.png', $png)]);
        $avant = $this->accueil()->getImageName();

        $this->envoyer($client, $this->ouvrir($client), [], ['imageFile' => $this->fixture('apres.png', $png)]);
        self::assertResponseRedirects();
        $apres = $this->accueil()->getImageName();

        self::assertNotSame($avant, $apres);
        self::assertFileExists($this->dossier() . '/' . $apres);
        self::assertFileDoesNotExist($this->dossier() . '/' . $avant, 'L\'ancienne photo ne doit pas s\'accumuler sur le serveur');
    }

    public function testAMobilePhotoAloneIsRejectedWithoutCreatingAnything(): void
    {
        $client = $this->loggedClient();
        $crawler = $this->ouvrir($client);

        $this->envoyer($client, $crawler, [], ['imageMobileFile' => $this->fixture('verticale.png', base64_decode(self::PNG))]);

        self::assertFalse($client->getResponse()->isRedirect(), 'Le formulaire doit être renvoyé avec son erreur');
        self::assertStringContainsString('Choisis d&#039;abord la photo principale', $client->getResponse()->getContent());
        self::assertNull($this->accueil()->getImageMobileName());
        self::assertSame([], glob($this->dossier() . '/verticale*') ?: []);
    }

    public function testAScriptDisguisedAsAPhotoIsRejectedAndNeverReachesTheDisk(): void
    {
        $client = $this->loggedClient();
        $crawler = $this->ouvrir($client);

        $this->envoyer($client, $crawler, [], ['imageFile' => $this->fixture('innocente.jpg', '<?php system($_GET["c"]);')]);

        self::assertFalse($client->getResponse()->isRedirect());
        self::assertStringContainsString('Ce fichier ne convient pas', $client->getResponse()->getContent());
        self::assertFalse($this->accueil()->hasImage());
        self::assertSame([], glob($this->dossier() . '/innocente*') ?: []);
    }

    public function testFramingOutOfItsLimitsIsRejected(): void
    {
        $client = $this->loggedClient();
        $crawler = $this->ouvrir($client);

        $this->envoyer($client, $crawler, ['bureauZoom' => '900', 'bureauX' => '150', 'couleurFond' => 'rouge', 'bureauMode' => 'plein']);

        self::assertFalse($client->getResponse()->isRedirect(), 'Des réglages impossibles ne doivent pas être enregistrés');
        $accueil = $this->accueil();
        self::assertSame(100, $accueil->getBureauZoom());
        self::assertSame(Accueil::MODE_ENTIERE, $accueil->getBureauMode());
    }
}
