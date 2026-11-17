<?php

namespace App\Tests\Admin;

use App\Controller\Admin\ArticleCrudController;
use App\Controller\Admin\MediaCrudController;
use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Media;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Je ne peux toujours mettre que 20 photos par article » : PHP n'accepte que max_file_uploads
 * fichiers (20 par défaut) par envoi et ignore les autres sans rien dire. Au-delà, l'admin envoie
 * donc les photos par lots (/mon_petit_site/photos/envoi), puis enregistre le formulaire avec le
 * seul nom des photos déposées. Voir App\Service\PhotoBatchUpload et admin_photo_batches.js.
 *
 * Crée ses propres catégories, articles et fichiers, et les supprime après chaque test.
 */
class PhotoBatchUploadTest extends WebTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const ENVOI = '/mon_petit_site/photos/envoi';

    /** @var list<object> */
    private array $created = [];

    /** @var list<string> fichiers déposés dans public/uploads/attachments par le test */
    private array $files = [];

    protected function tearDown(): void
    {
        if ([] !== $this->created) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            // Dans l'ordre inverse : les articles (et leurs photos, par cascade), puis les catégories
            foreach (array_reverse($this->created) as $entity) {
                $managed = $em->find($entity::class, $entity->getId());
                if ($managed) {
                    $em->remove($managed);
                    $em->flush();
                }
            }
        }

        // Les photos déposées mais jamais rattachées (refusées, ou test de l'envoi seul)
        foreach ($this->files as $name) {
            foreach ([$this->uploadDir() . '/' . $name, $this->uploadDir() . '/_vignettes/' . $name] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        // PHPUnit garde l'objet de test jusqu'à la fin de la suite : on ne retient rien.
        $this->created = [];
        $this->files = [];

        parent::tearDown();
    }

    private function uploadDir(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/attachments';
    }

    private function loggedClient(): KernelBrowser
    {
        $client = static::createClient();
        $admin = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Admin::class)->findOneBy([]);
        self::assertNotNull($admin, 'Aucun compte Admin trouvé en base de test');
        $client->loginUser($admin);

        return $client;
    }

    private function adminUrl(string $controller, string $action, ?int $id = null): string
    {
        $generator = static::getContainer()->get(AdminUrlGeneratorInterface::class)
            ->setController($controller)
            ->setAction($action);
        if (null !== $id) {
            $generator->setEntityId($id);
        }

        return $generator->generateUrl();
    }

    private function persist(object $entity): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($entity);
        $em->flush();
        $this->created[] = $entity;
    }

    private function galerie(): Categorie
    {
        $categorie = (new Categorie())->setTitre('Envoi par lots test')->setPosition(9850);
        $this->persist($categorie);

        return $categorie;
    }

    private function article(Categorie $categorie, string $titre): Article
    {
        $article = (new Article())->setTitre($titre)->setCategorie($categorie)->setPosition(9850);
        $this->persist($article);

        return $article;
    }

    private function upload(string $clientName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lot');
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function photo(string $clientName): UploadedFile
    {
        return $this->upload($clientName, base64_decode(self::PNG));
    }

    /** Le jeton est posé par le serveur sur le champ « photos » du formulaire, comme le lit le script. */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', $this->adminUrl(ArticleCrudController::class, Action::NEW));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="Article[multipleFiles][]"]')->attr('data-photos-envoi-token');
    }

    /**
     * Un lot envoyé comme le fait le script.
     *
     * @param list<UploadedFile> $photos
     *
     * @return array{photos: list<array{fichier: string, legende: string}>, erreurs: list<string>}
     */
    private function envoyerUnLot(KernelBrowser $client, string $token, array $photos): array
    {
        $client->request('POST', self::ENVOI, ['_token' => $token], ['photos' => $photos]);
        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $client->getResponse()->getContent(), true);

        foreach ($reponse['photos'] as $photo) {
            $this->files[] = $photo['fichier'];
        }

        return $reponse;
    }

    /** @param list<array{fichier: string, legende: string}> $photos */
    private function creerUnArticle(KernelBrowser $client, Categorie $categorie, string $titre, array $photos): Article
    {
        $crawler = $client->request('GET', $this->adminUrl(ArticleCrudController::class, Action::NEW));
        $form = $crawler->selectButton('Créer')->form();
        $form['Article[titre]'] = $titre;
        $form['Article[categorie][autocomplete]']->disableValidation()->setValue((string) $categorie->getId());

        $values = $form->getPhpValues();
        $values['photos_envoyees'] = $photos;
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $article = $em->getRepository(Article::class)->findOneBy(['titre' => $titre]);
        self::assertNotNull($article);
        $this->created[] = $article;

        return $article;
    }

    /** @return list<array{string, string}> [nom du fichier, légende] des photos de l'article, dans l'ordre */
    private function photosDe(Article $article): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $medias = $em->getRepository(Media::class)->findBy(['article' => $article->getId()], ['position' => 'ASC']);

        return array_map(fn (Media $media) => [$media->getImageName(), $media->getLegende()], $medias);
    }

    public function testTheBatchSendingIsReservedToTheAdmin(): void
    {
        $client = static::createClient();
        $client->request('POST', self::ENVOI, ['_token' => 'x'], ['photos' => [$this->photo('intrus.png')]]);

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testABatchStoresThePhotosAndRefusesWhatIsNotAPhoto(): void
    {
        $client = $this->loggedClient();
        $token = $this->token($client);

        $reponse = $this->envoyerUnLot($client, $token, [
            $this->photo('Vacances 1.png'),
            $this->photo('Vacances 2.png'),
            $this->upload('notes.txt', 'pas une image'),
            $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ]);

        self::assertSame(['Vacances 1', 'Vacances 2'], array_column($reponse['photos'], 'legende'));
        foreach ($reponse['photos'] as $photo) {
            self::assertFileExists($this->uploadDir() . '/' . $photo['fichier']);
        }
        self::assertCount(2, $reponse['erreurs'], 'Le texte et le SVG sont refusés, avec un message');
        self::assertStringContainsString('notes.txt', $reponse['erreurs'][0]);

        // Mauvais jeton : rien n'est déposé
        $client->request('POST', self::ENVOI, ['_token' => 'faux'], ['photos' => [$this->photo('autre.png')]]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnArticleCanGetMorePhotosThanTheServerAcceptsInOneSending(): void
    {
        $client = $this->loggedClient();
        $token = $this->token($client);
        $nombre = UploadLimits::maxFiles() + 5;

        // Comme le script : un lot plein, puis le reste
        $photos = [];
        foreach (array_chunk(range(1, $nombre), UploadLimits::maxFiles()) as $lot) {
            $reponse = $this->envoyerUnLot($client, $token, array_map(fn (int $i) => $this->photo(sprintf('Expo %02d.png', $i)), $lot));
            array_push($photos, ...$reponse['photos']);
        }
        self::assertCount($nombre, $photos);

        $article = $this->creerUnArticle($client, $this->galerie(), 'Article aux nombreuses photos test', $photos);

        self::assertSame(
            array_map(fn (array $photo) => [$photo['fichier'], $photo['legende']], $photos),
            $this->photosDe($article),
            sprintf('Les %d photos sont rattachées, dans l\'ordre de la sélection', $nombre),
        );
    }

    public function testOnlyFreshPhotosOfTheFolderCanBeAttached(): void
    {
        $client = $this->loggedClient();
        $token = $this->token($client);
        $galerie = $this->galerie();

        // Une photo déjà utilisée par un autre article
        $autre = $this->article($galerie, 'Autre article test');
        $prise = $this->envoyerUnLot($client, $token, [$this->photo('Déjà prise.png')])['photos'][0]['fichier'];
        $media = (new Media())->setLegende('Déjà prise')->setPosition(0);
        $media->setImageName($prise);
        // Le client de test redémarre le noyau à chaque requête : on reprend l'article dans l'EntityManager actuel
        $media->setArticle(static::getContainer()->get(EntityManagerInterface::class)->find(Article::class, $autre->getId()));
        $this->persist($media);

        $neuve = $this->envoyerUnLot($client, $token, [$this->photo('Toute neuve.png')])['photos'][0];

        $article = $this->creerUnArticle($client, $galerie, 'Article qui emprunte test', [
            $neuve,
            ['fichier' => $prise, 'legende' => 'emprunt'],
            ['fichier' => '../../.env', 'legende' => 'chemin'],
            ['fichier' => 'inexistante-' . uniqid() . '.jpg', 'legende' => 'absente'],
            $neuve, // en double
        ]);

        self::assertSame([[$neuve['fichier'], 'Toute neuve']], $this->photosDe($article));
        self::assertSame([[$prise, 'Déjà prise']], $this->photosDe($autre), 'La photo de l\'autre article reste à lui');
    }

    public function testTheMediathequeAlsoAcceptsBatchUploadedPhotos(): void
    {
        $client = $this->loggedClient();
        $token = $this->token($client);
        $article = $this->article($this->galerie(), 'Article de la médiathèque test');

        $photos = $this->envoyerUnLot($client, $token, [$this->photo('Atelier A.png'), $this->photo('Atelier B.png'), $this->photo('Atelier C.png')])['photos'];

        $crawler = $client->request('GET', $this->adminUrl(MediaCrudController::class, Action::NEW));
        $form = $crawler->selectButton('Créer')->form();
        $form['Media[legende]'] = 'Première photo du lot';
        $form['Media[article][autocomplete]']->disableValidation()->setValue((string) $article->getId());
        $values = $form->getPhpValues();
        $values['photos_envoyees'] = $photos;
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        self::assertSame(
            [[$photos[0]['fichier'], 'Première photo du lot'], [$photos[1]['fichier'], 'Atelier B'], [$photos[2]['fichier'], 'Atelier C']],
            $this->photosDe($article),
        );
    }

    public function testThePhotoFieldsTellTheScriptTheServerLimits(): void
    {
        $client = $this->loggedClient();

        foreach ([
            [ArticleCrudController::class, 'Article[multipleFiles][]'],
            [MediaCrudController::class, 'Media[newImages][]'],
        ] as [$controller, $name]) {
            $crawler = $client->request('GET', $this->adminUrl($controller, Action::NEW));
            self::assertResponseIsSuccessful();

            $input = $crawler->filter(sprintf('input[name="%s"]', $name));
            self::assertCount(1, $input);
            self::assertSame(self::ENVOI, $input->attr('data-photos-envoi-url'));
            self::assertNotEmpty($input->attr('data-photos-envoi-token'));
            self::assertSame((string) UploadLimits::maxFiles(), $input->attr('data-max-files'));
            self::assertSame((string) UploadLimits::perFile(), $input->attr('data-max-bytes'));
            self::assertSame((string) UploadLimits::total(), $input->attr('data-max-total-bytes'));

            // Versionné : après une mise en ligne, le navigateur de Caroline recharge le script
            self::assertCount(1, $crawler->filter('script[src*="admin_photo_batches.js?v="]'));
        }
    }
}
