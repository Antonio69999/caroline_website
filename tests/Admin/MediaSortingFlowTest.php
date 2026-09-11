<?php

namespace App\Tests\Admin;

use App\Controller\Admin\ArticleCrudController;
use App\Controller\Admin\MediaCrudController;
use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MediaSortingFlowTest extends WebTestCase
{
    public function testArticleEditPageShowsSortableGalleryAndReorderWorks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy([]);
        self::assertNotNull($admin, 'Aucun compte Admin trouvé en base de test');
        $client->loginUser($admin);

        /** @var Article|null $article */
        $article = $em->getRepository(Article::class)->createQueryBuilder('a')
            ->join('a.media', 'm')
            ->groupBy('a.id')
            ->having('COUNT(m.id) >= 2')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertNotNull($article, 'Aucun article avec au moins 2 photos trouvé pour le test');

        $urlGenerator = static::getContainer()->get(AdminUrlGeneratorInterface::class);
        $editUrl = $urlGenerator->setController(ArticleCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($article->getId())
            ->generateUrl();

        $client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.media-gallery-sortable');

        $mediaIds = array_map(fn (Media $m) => $m->getId(), $article->getMedia()->toArray());
        self::assertGreaterThanOrEqual(2, count($mediaIds));
        $reversed = array_reverse($mediaIds);

        $client->request(
            'POST',
            '/admin/media/reorder',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['orderedIds' => $reversed])
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('success', $payload['status']);

        $em->clear();
        $refreshedArticle = $em->getRepository(Article::class)->find($article->getId());
        $newOrderIds = array_map(fn (Media $m) => $m->getId(), $refreshedArticle->getMedia()->toArray());
        self::assertSame($reversed, $newOrderIds, 'Les photos ne sont pas réordonnées comme attendu après le drag & drop');
    }

    public function testMediathequeGridRendersAndQuickUpdateWorks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy([]);
        $client->loginUser($admin);

        $urlGenerator = static::getContainer()->get(AdminUrlGeneratorInterface::class);
        $indexUrl = $urlGenerator->setController(MediaCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.media-library-grid');
        self::assertSelectorExists('.media-library-card');

        /** @var Media $media */
        $media = $em->getRepository(Media::class)->findOneBy([]);
        self::assertNotNull($media);

        $client->request('POST', '/admin/media/' . $media->getId() . '/quick-update', [
            'legende' => 'Légende de test (vérification automatisée)',
        ]);
        self::assertResponseRedirects();

        $em->clear();
        $refreshed = $em->getRepository(Media::class)->find($media->getId());
        self::assertSame('Légende de test (vérification automatisée)', $refreshed->getLegende());
    }

    public function testMediaCreateFormRendersWithoutVichWidget(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy([]);
        $client->loginUser($admin);

        $urlGenerator = static::getContainer()->get(AdminUrlGeneratorInterface::class);
        $newUrl = $urlGenerator->setController(MediaCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl();

        $client->request('GET', $newUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="Media[newImages][]"]');
        self::assertSelectorExists('select[name="Media[article][autocomplete]"]');
    }

    public function testUploadingPhotosFromMediathequeCreatesFilesAndMediaRows(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy([]);
        $client->loginUser($admin);

        $article = $em->getRepository(Article::class)->findOneBy([]);
        self::assertNotNull($article);

        $urlGenerator = static::getContainer()->get(AdminUrlGeneratorInterface::class);
        $newUrl = $urlGenerator->setController(MediaCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl();

        $crawler = $client->request('GET', $newUrl);
        self::assertResponseIsSuccessful();

        $uploadDir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/attachments';
        $tmpFile1 = tempnam(sys_get_temp_dir(), 'upl1') . '.jpg';
        $tmpFile2 = tempnam(sys_get_temp_dir(), 'upl2') . '.jpg';
        file_put_contents($tmpFile1, 'fake-image-content-1');
        file_put_contents($tmpFile2, 'fake-image-content-2');

        $form = $crawler->selectButton('Créer')->form();
        $form['Media[legende]'] = 'Photo uploadée par le test';
        $form['Media[article][autocomplete]']->disableValidation()->setValue((string) $article->getId());

        $client->getContainer(); // ensure container is booted before touching request files
        $client->request(
            $form->getMethod(),
            $form->getUri(),
            $form->getPhpValues(),
            [
                'Media' => [
                    'newImages' => [
                        new UploadedFile($tmpFile1, 'super-photo-vacances.jpg', 'image/jpeg', null, true),
                        new UploadedFile($tmpFile2, 'super-photo-vacances.jpg', 'image/jpeg', null, true),
                    ],
                ],
            ]
        );

        self::assertResponseRedirects();

        $em->clear();
        $createdMedias = $em->getRepository(Media::class)->findBy(['legende' => 'Photo uploadée par le test']);
        self::assertCount(1, $createdMedias, 'Le premier fichier doit créer le Media en cours de création');

        $siblingMedias = $em->getRepository(Media::class)->createQueryBuilder('m')
            ->where('m.article = :article')
            ->andWhere('m.legende = :legende')
            ->setParameter('article', $article)
            ->setParameter('legende', 'super-photo-vacances')
            ->getQuery()
            ->getResult();
        self::assertCount(1, $siblingMedias, 'Le second fichier doit créer un Media "frère" avec sa propre légende auto');

        $allNewFiles = array_merge($createdMedias, $siblingMedias);
        foreach ($allNewFiles as $media) {
            $path = $uploadDir . '/' . $media->getImageName();
            self::assertFileExists($path, 'Le fichier uploadé doit être réellement déposé dans public/uploads/attachments');
            @unlink($path);
            $em->remove($em->getRepository(Media::class)->find($media->getId()));
        }
        $em->flush();

        @unlink($tmpFile1);
        @unlink($tmpFile2);
    }

    public function testDeletingMediaAlsoDeletesItsFileFromDisk(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Simule un fichier déjà présent dans public/uploads/attachments, comme
        // n'importe quelle photo réelle (uploadée via MediaUploadHandler, pas via Vich).
        $uploadDir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/attachments';
        $filename = 'test-delete-cleanup-' . uniqid() . '.jpg';
        file_put_contents($uploadDir . '/' . $filename, 'fake-image-content');
        self::assertFileExists($uploadDir . '/' . $filename);

        $media = new Media();
        $media->setImageName($filename);
        $media->setLegende('Photo de test à supprimer');
        $media->setCreeLe(new \DateTime());
        $em->persist($media);
        $em->flush();

        $em->remove($media);
        $em->flush();

        self::assertFileDoesNotExist(
            $uploadDir . '/' . $filename,
            'La suppression d\'un Media doit aussi supprimer son fichier sur le disque (géré par le listener de suppression de Vich)'
        );
    }
}
