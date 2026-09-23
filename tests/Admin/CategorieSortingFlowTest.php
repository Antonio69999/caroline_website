<?php

namespace App\Tests\Admin;

use App\Controller\Admin\CategorieCrudController;
use App\Entity\Admin;
use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CategorieSortingFlowTest extends WebTestCase
{
    public function testCategorieIndexShowsDatagridAndReorderWorks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy([]);
        self::assertNotNull($admin, 'Aucun compte Admin trouvé en base de test');
        $client->loginUser($admin);

        $urlGenerator = static::getContainer()->get(AdminUrlGeneratorInterface::class);
        $indexUrl = $urlGenerator->setController(CategorieCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table.datagrid');

        $categories = $em->getRepository(Categorie::class)->findBy([], ['position' => 'ASC']);
        self::assertGreaterThanOrEqual(2, count($categories), 'Il faut au moins 2 catégories en base de test');

        $categorieIds = array_map(fn (Categorie $c) => $c->getId(), $categories);
        $reversed = array_reverse($categorieIds);

        $client->request(
            'POST',
            '/admin/categorie/reorder',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['orderedIds' => $reversed])
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('success', $payload['status']);

        $em->clear();
        $newOrderIds = array_map(
            fn (Categorie $c) => $c->getId(),
            $em->getRepository(Categorie::class)->findBy([], ['position' => 'ASC'])
        );
        self::assertSame($reversed, $newOrderIds, 'Les catégories ne sont pas réordonnées comme attendu après le drag & drop');
    }
}
