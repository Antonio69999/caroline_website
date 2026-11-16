<?php

namespace App\Tests\Admin;

use App\Controller\Admin\ArticleCrudController;
use App\Controller\Admin\CategorieCrudController;
use App\Controller\Admin\MediaCrudController;
use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Brouillon automatique (admin_draft.js) et alerte « modifications non enregistrées »
 * (admin_unsaved_warning.js).
 *
 * Ces deux scripts ne tournent que dans un navigateur, mais ils s'appuient sur des détails de la page
 * qu'EasyAdmin génère : la classe du formulaire, l'identifiant du <body>, les attributs des collections,
 * le textarea piloté par Trix. Une mise à jour d'EasyAdmin qui les changerait rendrait ces scripts muets
 * SANS aucune erreur visible (c'est déjà arrivé : l'ancienne alerte ciblait « ea-crud-form », une classe
 * qui n'existe plus). Ces tests le font remarquer.
 */
class DraftFormsTest extends WebTestCase
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

        parent::tearDown();
    }

    private function publicFile(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/' . $path);
    }

    private function loggedClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($em->getRepository(Admin::class)->findOneBy([]));

        return $client;
    }

    private function adminUrl(string $controller, string $action, ?int $id = null): string
    {
        $generator = static::getContainer()->get(AdminUrlGeneratorInterface::class)
            ->unsetAll()
            ->setController($controller)
            ->setAction($action);

        if (null !== $id) {
            $generator->setEntityId($id);
        }

        return $generator->generateUrl();
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function newForms(): iterable
    {
        yield 'article' => [ArticleCrudController::class, 'Article'];
        yield 'photos' => [MediaCrudController::class, 'Media'];
        yield 'catégorie' => [CategorieCrudController::class, 'Categorie'];
    }

    /**
     * @dataProvider newForms
     *
     * @param class-string $controller
     */
    public function testNewFormLoadsTheDraftScriptBeforeTheWarningScript(string $controller, string $entity): void
    {
        $client = $this->loggedClient();
        $crawler = $client->request('GET', $this->adminUrl($controller, Action::NEW));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[rel="stylesheet"][href*="admin_draft.css"]');
        self::assertSelectorExists('script[src*="admin_draft.js"]');
        self::assertSelectorExists('script[src*="admin_unsaved_warning.js"]');

        // L'alerte interroge le brouillon : il doit être déjà chargé quand elle se met en place
        $html = $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'admin_unsaved_warning.js'), strpos($html, 'admin_draft.js'));

        // Ce que les deux scripts utilisent pour reconnaître le formulaire et savoir de quoi il s'agit
        self::assertCount(1, $crawler->filter('form.ea-new-form'));
        self::assertSame("ea-new-$entity", $crawler->filter('body')->attr('id'));
    }

    public function testEditFormsKeepTheClassAndBodyIdTheScriptsRelyOn(): void
    {
        $client = $this->loggedClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $categorie = (new Categorie())->setTitre('Catégorie brouillon')->setPosition(9701);
        $em->persist($categorie);
        $this->created[] = $categorie;

        $article = (new Article())->setTitre('Article brouillon')->setCategorie($categorie)->setPublie(true);
        $em->persist($article);
        $this->created[] = $article;
        $em->flush();

        $crawler = $client->request('GET', $this->adminUrl(ArticleCrudController::class, Action::EDIT, $article->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.ea-edit-form'));
        self::assertSame('ea-edit-Article-' . $article->getId(), $crawler->filter('body')->attr('id'));
        self::assertSelectorExists('script[src*="admin_draft.js"]');

        $crawler = $client->request('GET', $this->adminUrl(CategorieCrudController::class, Action::EDIT, $categorie->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.ea-edit-form'));
        self::assertSame('ea-edit-Categorie-' . $categorie->getId(), $crawler->filter('body')->attr('id'));
    }

    public function testListPagesLoadTheScriptSoItCanTellASaveWorked(): void
    {
        // Après un enregistrement réussi, EasyAdmin redirige vers la liste : c'est là que le script efface le brouillon.
        $client = $this->loggedClient();
        $crawler = $client->request('GET', $this->adminUrl(ArticleCrudController::class, Action::INDEX));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('script[src*="admin_draft.js"]');
        self::assertMatchesRegularExpression('/\bea-index-Article\b/', (string) $crawler->filter('body')->attr('class'));
    }

    public function testTheVitrineCollectionExposesWhatTheDraftNeedsToRebuildItsBlocks(): void
    {
        $client = $this->loggedClient();
        $crawler = $client->request('GET', $this->adminUrl(CategorieCrudController::class, Action::NEW));

        $collection = $crawler->filter('[data-ea-collection-field][data-form-type-name-placeholder="__blocsname__"]');
        self::assertCount(1, $collection, 'La collection de blocs n\'a plus les attributs qu\'utilisent la vitrine et le brouillon');
        self::assertCount(1, $collection->filter('.field-collection-add-button'));
    }

    public function testRichTextFieldsStillUseATextareaDrivenByTrix(): void
    {
        // Pour les textes riches non remplacés par Quill, le brouillon dit à Trix de recharger le texte retrouvé
        $client = $this->loggedClient();
        $crawler = $client->request('GET', $this->adminUrl(ArticleCrudController::class, Action::NEW));

        $textarea = $crawler->filter('textarea[name="Article[description]"]');
        self::assertCount(1, $textarea);
        self::assertCount(1, $crawler->filter('trix-editor[input="' . $textarea->attr('id') . '"]'));
    }

    // ------------------------------------------------------------------------------------------ scripts

    public function testTheWarningTargetsTheFormClassesOfEasyAdmin4(): void
    {
        $script = $this->publicFile('asset/js/admin_unsaved_warning.js');

        self::assertStringContainsString('form.ea-new-form', $script);
        self::assertStringContainsString('form.ea-edit-form', $script);
        // L'ancienne classe : l'alerte ne se déclenchait plus jamais
        self::assertStringNotContainsString('form.ea-crud-form', $script);
    }

    public function testTheDraftTargetsTheSameForms(): void
    {
        $script = $this->publicFile('asset/js/admin_draft.js');

        self::assertStringContainsString('form.ea-new-form, form.ea-edit-form', $script);
        self::assertStringNotContainsString('form.ea-crud-form', $script);
    }

    public function testTheScriptsThatMustAnswerTheDraftListenToTheEventsItSends(): void
    {
        $draft = $this->publicFile('asset/js/admin_draft.js');
        $editor = $this->publicFile('asset/js/admin_category_rich_text.js');
        $vitrine = $this->publicFile('asset/js/admin_vitrine_blocs.js');
        $images = $this->publicFile('asset/js/admin_image_resize.js');

        // Texte retrouvé → l'éditeur Quill le reprend
        self::assertStringContainsString("'carotte:restore'", $draft);
        self::assertStringContainsString("'carotte:restore'", $editor);

        // Blocs retrouvés → la vitrine rafraîchit leur aspect
        foreach (['carotte:item-restored', 'carotte:collection-restored'] as $event) {
            self::assertStringContainsString("'$event'", $draft);
            self::assertStringContainsString("'$event'", $vitrine);
        }

        // Le brouillon attend la photo réduite plutôt que de garder l'original
        self::assertStringContainsString('isBusy', $images);
        self::assertStringContainsString('isBusy', $draft);
    }

    public function testTheDraftNeverStoresTheSecurityToken(): void
    {
        // Le jeton CSRF d'un formulaire ne doit pas finir dans le navigateur, ni être « restauré » périmé
        self::assertStringContainsString('_token', $this->publicFile('asset/js/admin_draft.js'));
        self::assertMatchesRegularExpression('/IGNORED_NAME\s*=\s*\/[^\n]*_token/', $this->publicFile('asset/js/admin_draft.js'));
    }
}
