<?php

namespace App\Tests\Admin;

use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les actions que les scripts de l'admin appellent (réordonner articles, photos et catégories,
 * renommer ou déplacer une photo) vivaient sous /admin/..., que security.yaml ne protégeait pas :
 * n'importe qui pouvait les appeler sans être connecté. Désormais, toute route d'un contrôleur
 * de l'admin renvoie un visiteur anonyme vers la connexion, y compris une route ajoutée plus tard.
 *
 * Crée ses propres catégorie / articles / photo et les supprime après chaque test.
 */
class AdminRoutesAccessTest extends WebTestCase
{
    /** @var list<object> */
    private array $created = [];

    protected function tearDown(): void
    {
        if ([] !== $this->created) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            foreach (array_reverse($this->created) as $entity) {
                $managed = $em->find($entity::class, $entity->getId());
                if ($managed) {
                    $em->remove($managed);
                    $em->flush();
                }
            }
        }

        // PHPUnit garde l'objet de test jusqu'à la fin de la suite : on ne retient rien.
        $this->created = [];

        parent::tearDown();
    }

    private function persist(object $entity): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($entity);
        $em->flush();
        $this->created[] = $entity;
    }

    private function reload(object $entity): object
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find($entity::class, $entity->getId());
    }

    private function assertSentToLogin(KernelBrowser $client, string $what): void
    {
        self::assertTrue($client->getResponse()->isRedirection(), $what . ' : un visiteur anonyme doit être renvoyé vers la connexion');
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'), $what);
    }

    public function testEveryAdminRouteSendsAnonymousVisitorsToTheLogin(): void
    {
        $client = static::createClient();
        $routes = static::getContainer()->get('router')->getRouteCollection();

        $checked = [];
        foreach ($routes as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (!str_starts_with($controller, 'App\\Controller\\Admin\\')) {
                continue;
            }

            $path = preg_replace('/\{[^}]+\}/', '1', $route->getPath());
            $method = $route->getMethods()[0] ?? 'GET';
            $client->request($method, $path, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

            $this->assertSentToLogin($client, sprintf('%s %s (%s)', $method, $path, $name));
            $checked[] = $route->getPath();
        }

        // Garde-fou : les actions appelées par les scripts de l'admin sont bien du lot vérifié
        foreach (['/admin/article/reorder', '/admin/categorie/reorder', '/admin/media/reorder', '/admin/media/{id}/quick-update', '/mon_petit_site'] as $expected) {
            self::assertContains($expected, $checked);
        }
    }

    public function testAnAnonymousAttemptChangesNothingButTheAdminCanStillReorder(): void
    {
        $client = static::createClient();

        $categorie = (new Categorie())->setTitre('Accès admin test')->setPosition(9950);
        $this->persist($categorie);
        $a = (new Article())->setTitre('Accès A test')->setCategorie($categorie)->setPosition(1);
        $b = (new Article())->setTitre('Accès B test')->setCategorie($categorie)->setPosition(2);
        $this->persist($a);
        $this->persist($b);
        $media = (new Media())->setLegende('Légende de Caroline')->setPosition(0);
        $media->setImageName('acces-admin-test-' . uniqid() . '.jpg');
        $media->setArticle($b);
        $this->persist($media);

        // Anonyme : inverser l'ordre, renommer la photo et la déplacer… sans effet
        $client->request('POST', '/admin/article/reorder', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'orderedIds' => [$b->getId(), $a->getId()],
        ]));
        $this->assertSentToLogin($client, 'reorder anonyme');
        $client->request('POST', '/admin/media/' . $media->getId() . '/quick-update', ['legende' => 'Piraté', 'article' => $a->getId()]);
        $this->assertSentToLogin($client, 'quick-update anonyme');

        self::assertSame([1, 2], [$this->reload($a)->getPosition(), $this->reload($b)->getPosition()]);
        $media = $this->reload($media);
        self::assertSame('Légende de Caroline', $media->getLegende());
        self::assertSame($b->getId(), $media->getArticle()->getId());

        // Connectée, Caroline réordonne toujours ses articles
        $admin = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Admin::class)->findOneBy([]);
        self::assertNotNull($admin, 'Aucun compte Admin trouvé en base de test');
        $client->loginUser($admin);
        $client->request('POST', '/admin/article/reorder', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'orderedIds' => [$b->getId(), $a->getId()],
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame([2, 1], [$this->reload($a)->getPosition(), $this->reload($b)->getPosition()]);
    }
}
