<?php

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Media;
use App\Seo\SiteUrls;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Référencement des pages publiques : ce que lisent Google et les aperçus de partage
 * (titre, description, adresse officielle, données structurées), les redirections de
 * l'ancienne adresse vers la nouvelle, les vraies pages « introuvable », et le sitemap.
 *
 * Crée ses propres catégories/articles/photos (aux fichiers inexistants) et les supprime après chaque test.
 */
class SeoPagesTest extends WebTestCase
{
    private const SITE = 'https://carottecake.com';

    /** @var list<object> */
    private array $created = [];

    protected function tearDown(): void
    {
        if ([] !== $this->created) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            // Dans l'ordre inverse : les photos, puis les articles, puis les catégories
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

    private function persist(object ...$entities): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($entities as $entity) {
            $em->persist($entity);
            $this->created[] = $entity;
        }
        $em->flush();
    }

    private function urls(): SiteUrls
    {
        return static::getContainer()->get(SiteUrls::class);
    }

    private function galerie(string $titre, int $position, ?string $description = null): Categorie
    {
        $categorie = (new Categorie())->setTitre($titre)->setDescription($description)->setPosition($position);
        $this->persist($categorie);

        return $categorie;
    }

    /**
     * @param list<string> $legendes Une photo (fichier inexistant, donc rien à supprimer) par légende
     */
    private function article(Categorie $categorie, string $titre, int $position, array $legendes = [], bool $publie = true, ?string $description = null): Article
    {
        $article = (new Article())
            ->setTitre($titre)
            ->setDescription($description)
            ->setCategorie($categorie)
            ->setPosition($position)
            ->setPublie($publie)
            ->setcreeLe(new \DateTimeImmutable('2024-05-01 10:00:00'));
        $this->persist($article);

        foreach ($legendes as $index => $legende) {
            // setImageName() ne renvoie rien : on ne peut pas enchaîner dessus
            $media = new Media();
            $media->setImageName(sprintf('seo-test-%s-%d.jpg', uniqid(), $index));
            $media->setLegende($legende)->setPosition($index)->setArticle($article);
            $this->persist($media);
            $article->addMedia($media);
        }

        return $article;
    }

    /**
     * @return list<array<string, mixed>> Les éléments du @graph des données structurées de la page
     */
    private function graph(Crawler $crawler): array
    {
        $scripts = $crawler->filter('script[type="application/ld+json"]');
        self::assertCount(1, $scripts, 'Un seul bloc de données structurées par page');

        $data = json_decode($scripts->first()->text(null, false), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('https://schema.org', $data['@context']);

        return $data['@graph'];
    }

    /**
     * @param list<array<string, mixed>> $graph
     *
     * @return array<string, mixed>
     */
    private function node(array $graph, string $type): array
    {
        foreach ($graph as $node) {
            if ($type === $node['@type']) {
                return $node;
            }
        }

        self::fail(sprintf('Pas d\'élément « %s » dans les données structurées', $type));
    }

    public function testHomePageHasTheBasicsGoogleLooksFor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');

        // Pas de traduction automatique : le site est déjà en français et la traduction casse les polices
        self::assertSelectorExists('html[translate="no"].notranslate');
        self::assertSame('notranslate', $crawler->filter('meta[name="google"]')->attr('content'));

        self::assertSame('Caroline Marcellier, artiste – peinture, gravure, calligraphie', trim($crawler->filter('title')->text()));
        self::assertStringContainsString('Loire', $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame(self::SITE . '/', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame(self::SITE . '/', $crawler->filter('meta[property="og:url"]')->attr('content'));
        self::assertStringContainsString('index', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringNotContainsString('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));

        // Un seul titre de niveau 1 : celui de la page. Le nom dans le menu n'en est plus un.
        self::assertCount(1, $crawler->filter('h1'));
        self::assertStringContainsString('peinture, gravure et calligraphie', $crawler->filter('h1')->text());
        self::assertCount(0, $crawler->filter('#main-menu h1'));
        self::assertSelectorTextContains('#main-menu #openModal', 'Caroline MARCELLIER');

        // Le zoom au pincement n'est plus interdit
        $viewport = $crawler->filter('meta[name="viewport"]')->attr('content');
        self::assertStringNotContainsString('user-scalable', $viewport);
        self::assertStringNotContainsString('maximum-scale', $viewport);

        $graph = $this->graph($crawler);
        $person = $this->node($graph, 'Person');
        self::assertSame('Caroline Marcellier', $person['name']);
        self::assertSame('Chambles', $person['address']['addressLocality']);
        self::assertArrayNotHasKey('streetAddress', $person['address'], "L'adresse précise n'est pas publiée");
        self::assertArrayNotHasKey('sameAs', $person, 'Pas de profil inventé : la liste est vide tant que Caroline ne les a pas donnés');
        self::assertSame('WebSite', $this->node($graph, 'WebSite')['@type']);
    }

    public function testEveryPageHasItsOwnShareCard(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Aperçu de partage test', 9501, 'Une galerie dont la description fait assez de caractères pour servir telle quelle.');
        $article = $this->article($galerie, 'Œuvre partagée', 9501, ['Un tableau vu de près']);

        $crawler = $client->request('GET', $this->urls()->articlePath($article));
        self::assertResponseIsSuccessful();

        $og = fn (string $property): string => $crawler->filter(sprintf('meta[property="%s"]', $property))->attr('content');
        $url = self::SITE . $this->urls()->articlePath($article);

        // Plus de « Bienvenue dans mon jardin » et d'adresse d'accueil recopiées sur chaque page
        self::assertStringContainsString('Œuvre partagée', $og('og:title'));
        self::assertSame($url, $og('og:url'));
        self::assertSame($url, $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('Un tableau vu de près', $og('og:image:alt'));
        self::assertStringStartsWith(self::SITE . '/uploads/attachments/seo-test-', $og('og:image'));
        self::assertSame($og('og:image'), $crawler->filter('meta[name="twitter:image"]')->attr('content'));
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        self::assertSame('fr_FR', $og('og:locale'));
        self::assertCount(0, $crawler->filter('meta[property="fb:app_id"]'), 'Plus de balise vide');
    }

    public function testGalleryPageDescribesItselfAndItsWorks(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie(
            'Gravure sur verre test',
            9502,
            '<div>Des gravures sur verre réalisées à la main : <strong>verre soufflé</strong>, motifs végétaux et lettres.</div>',
        );
        $premier = $this->article($galerie, 'Vase aux fougères', 9502, ['Vase gravé, détail des fougères']);
        $second = $this->article($galerie, 'Coupe aux lettres', 9503, ['Coupe gravée de lettres']);

        $crawler = $client->request('GET', $this->urls()->categoryPath($galerie));

        self::assertResponseIsSuccessful();
        self::assertSame('Gravure sur verre test | Caroline Marcellier', trim($crawler->filter('title')->text()));
        self::assertSame(
            'Des gravures sur verre réalisées à la main : verre soufflé, motifs végétaux et lettres.',
            $crawler->filter('meta[name="description"]')->attr('content'),
            "La description affichée par Google est le texte de Caroline, sans balises",
        );
        self::assertSame(self::SITE . '/category/' . $galerie->getId() . '/gravure-sur-verre-test', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertStringContainsString('seo-test-', $crawler->filter('meta[property="og:image"]')->attr('content'));

        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('Gravure sur verre test', trim($crawler->filter('h1')->text()));
        self::assertCount(0, $crawler->filter('h2'), "Le titre de la page est le h1, il n'y a plus de h2 à sa place");

        // Les liens et le texte alternatif des vignettes disent ce qu'il y a sur la photo, pas le nom du fichier
        $cartes = $crawler->filter('a.category-card');
        self::assertCount(2, $cartes);
        self::assertSame($this->urls()->articlePath($premier), $cartes->eq(0)->attr('href'));
        self::assertSame('Vase gravé, détail des fougères', $cartes->eq(0)->filter('img')->attr('alt'));
        self::assertSame($this->urls()->articlePath($second), $cartes->eq(1)->attr('href'));

        $graph = $this->graph($crawler);
        $page = $this->node($graph, 'CollectionPage');
        self::assertSame('Gravure sur verre test', $page['name']);
        self::assertSame('fr-FR', $page['inLanguage']);
        self::assertSame(
            [self::SITE . $this->urls()->articlePath($premier), self::SITE . $this->urls()->articlePath($second)],
            array_column($page['mainEntity']['itemListElement'], 'url'),
        );
        self::assertSame(['Accueil', 'Gravure sur verre test'], array_column($this->node($graph, 'BreadcrumbList')['itemListElement'], 'name'));
    }

    public function testGalleryWithoutDescriptionGetsAUsefulOne(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Calligraphie test', 9504);
        $this->article($galerie, 'Alphabet dansé', 9504, ['Alphabet']);
        $this->article($galerie, 'Lettre rouge', 9505, ['Lettre']);

        $crawler = $client->request('GET', $this->urls()->categoryPath($galerie));

        self::assertResponseIsSuccessful();
        $description = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringContainsString('Calligraphie test', $description);
        self::assertStringContainsString('Alphabet dansé', $description);
        self::assertStringContainsString('Caroline Marcellier', $description);
        self::assertLessThanOrEqual(160, mb_strlen($description));
    }

    public function testEmptyGalleryStaysOutOfGoogle(): void
    {
        $client = static::createClient();
        $vide = $this->galerie('Galerie encore vide', 9506);
        $this->article($vide, 'Brouillon seulement', 9506, ['Photo'], publie: false);

        $crawler = $client->request('GET', $this->urls()->categoryPath($vide));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testTextCategoryUsesItsOwnTextAsDescription(): void
    {
        $client = static::createClient();
        $cv = (new Categorie())
            ->setTitre('CV référencement test')
            ->setType(Categorie::TYPE_TEXTE)
            ->setContenu('<h1>Formation</h1><p>Diplômée des Beaux-Arts, Caroline expose ses peintures et gravures depuis plus de vingt ans dans toute la région.</p>')
            ->setPosition(9507);
        $this->persist($cv);

        $crawler = $client->request('GET', $this->urls()->categoryPath($cv));

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Formation Diplômée des Beaux-Arts', $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertStringNotContainsString('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('WebPage', $this->node($this->graph($crawler), 'WebPage')['@type']);
    }

    public function testArticlePageIsCompleteForGoogleAndGoogleImages(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Peinture test', 9508);
        $article = $this->article(
            $galerie,
            'La paire de chaussures',
            9508,
            ['Les chaussures vues de face', 'Les chaussures vues de dos'],
            description: '<div>Acrylique sur toile, 2023. Une paire de chaussures usées, peinte à la lumière du matin.</div>',
        );

        $crawler = $client->request('GET', $this->urls()->articlePath($article));

        self::assertResponseIsSuccessful();
        self::assertSame('La paire de chaussures – Peinture test | Caroline Marcellier', trim($crawler->filter('title')->text()));
        self::assertSame(
            'Acrylique sur toile, 2023. Une paire de chaussures usées, peinte à la lumière du matin.',
            $crawler->filter('meta[name="description"]')->attr('content'),
        );

        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('La paire de chaussures', trim($crawler->filter('h1.article_subtitle')->text()));

        // Lien vers la catégorie, avec ses mots-clés dans le texte du lien
        $retour = $crawler->filter('nav.breadcrumb a');
        self::assertSame($this->urls()->categoryPath($galerie), $retour->attr('href'));
        self::assertStringContainsString('Peinture test', $retour->text());

        $images = $crawler->filter('.gallery img');
        self::assertCount(2, $images);
        self::assertSame('Les chaussures vues de face', $images->eq(0)->attr('alt'));
        self::assertSame('high', $images->eq(0)->attr('fetchpriority'), "La première photo est chargée en priorité");
        self::assertSame('lazy', $images->eq(1)->attr('loading'), 'Les suivantes attendent qu\'on défile');

        $graph = $this->graph($crawler);
        $page = $this->node($graph, 'ImageGallery');
        self::assertSame('2024-05-01T10:00:00+00:00', $page['datePublished']);
        self::assertCount(2, $page['image']);

        $image = $page['image'][0];
        self::assertSame('ImageObject', $image['@type']);
        self::assertStringStartsWith(self::SITE . '/uploads/attachments/seo-test-', $image['contentUrl']);
        self::assertSame('Les chaussures vues de face', $image['caption']);
        self::assertSame('Caroline Marcellier', $image['creator']['name'], "Google Images affiche l'auteur de la photo");
        self::assertSame('© Caroline Marcellier', $image['copyrightNotice']);

        $fil = $this->node($graph, 'BreadcrumbList')['itemListElement'];
        self::assertSame(['Accueil', 'Peinture test', 'La paire de chaussures'], array_column($fil, 'name'));
        self::assertSame(self::SITE . $this->urls()->articlePath($article), $fil[2]['item']);
    }

    public function testArticleWithoutDescriptionGetsAUsefulOne(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Gravure test', 9509);
        $article = $this->article($galerie, 'Vase sans texte', 9509, ['Vase']);

        $crawler = $client->request('GET', $this->urls()->articlePath($article));

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Vase sans texte – Gravure test, par Caroline Marcellier, artiste dans la Loire (42).',
            $crawler->filter('meta[name="description"]')->attr('content'),
        );
    }

    public function testUnknownAndUnpublishedArticlesAreRealNotFoundPages(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Brouillons test', 9510);
        $brouillon = $this->article($galerie, 'Pas encore prêt', 9510, ['Photo'], publie: false);

        $client->request('GET', $this->urls()->articlePath($brouillon));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, "Un brouillon répond « introuvable », pas une page vide « OK »");

        $client->request('GET', '/article/2000000000');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/category/2000000000');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOldAndWrongAddressesRedirectToTheOfficialOne(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Adresses test', 9511);
        $article = $this->article($galerie, 'Adresse officielle', 9511, ['Photo']);

        $officielArticle = $this->urls()->articlePath($article);
        $officielCategorie = $this->urls()->categoryPath($galerie);
        self::assertSame('/article/' . $article->getId() . '/adresse-officielle', $officielArticle);

        // L'adresse courte, celle que les liens déjà partagés utilisent
        $client->request('GET', '/article/' . $article->getId());
        self::assertResponseRedirects($officielArticle, Response::HTTP_MOVED_PERMANENTLY);

        $client->request('GET', '/category/' . $galerie->getId());
        self::assertResponseRedirects($officielCategorie, Response::HTTP_MOVED_PERMANENTLY);

        // Un titre qui n'est pas le bon (ou plus le bon)
        $client->request('GET', '/article/' . $article->getId() . '/un-ancien-titre');
        self::assertResponseRedirects($officielArticle, Response::HTTP_MOVED_PERMANENTLY);

        // Les paramètres de suivi des campagnes ne se perdent pas
        $client->request('GET', '/article/' . $article->getId() . '?utm_source=newsletter');
        self::assertResponseRedirects($officielArticle . '?utm_source=newsletter', Response::HTTP_MOVED_PERMANENTLY);

        // L'adresse officielle, elle, répond directement (pas de boucle)
        $client->request('GET', $officielArticle);
        self::assertResponseIsSuccessful();
        $client->request('GET', $officielCategorie);
        self::assertResponseIsSuccessful();

        // L'ancienne page /category n'affichait que le menu
        $client->request('GET', '/category');
        self::assertResponseRedirects('/', Response::HTTP_MOVED_PERMANENTLY);
    }

    public function testRenamingAnArticleKeepsItsOldAddressWorking(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Renommer test', 9512);
        $article = $this->article($galerie, 'Premier titre', 9512, ['Photo']);
        $ancienne = $this->urls()->articlePath($article);

        $client->request('GET', $ancienne);
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $managed = $em->find(Article::class, $article->getId());
        $managed->setTitre('Nouveau titre plus précis');
        $em->flush();

        $nouvelle = '/article/' . $article->getId() . '/nouveau-titre-plus-precis';
        $client->request('GET', $ancienne);
        self::assertResponseRedirects($nouvelle, Response::HTTP_MOVED_PERMANENTLY);

        $client->request('GET', $nouvelle);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouveau titre plus précis');
    }

    public function testMenuLinksUseTheOfficialAddresses(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Menu référencement test', 9513);
        $this->article($galerie, 'Une œuvre', 9513, ['Photo']);

        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('#main-menu a[href="' . $this->urls()->categoryPath($galerie) . '"]'));
        self::assertCount(0, $crawler->filter('#main-menu a[href="/category/' . $galerie->getId() . '"]'), "Pas de lien vers l'ancienne adresse courte");
    }

    public function testContactAndLegalPages(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/contact');
        self::assertResponseIsSuccessful();
        self::assertSame('Contact | Caroline Marcellier', trim($crawler->filter('title')->text()));
        self::assertSame(self::SITE . '/contact', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertStringNotContainsString('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(1, $crawler->filter('h1'), 'Le titre du formulaire reste le seul h1');
        self::assertSame('ContactPage', $this->node($this->graph($crawler), 'ContactPage')['@type']);

        // Les mentions légales doivent exister, pas être recommandées par Google
        $crawler = $client->request('GET', '/legal');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testLoginAndAdminAreNeverIndexed(): void
    {
        $client = static::createClient();

        // En-tête de réponse (la page de connexion n'a pas de <head> où mettre une balise)
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));

        // L'admin renvoie vers la connexion : l'en-tête est déjà sur cette redirection
        $client->request('GET', '/mon_petit_site');
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testJsonLdCannotBeBrokenByATitle(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Titre piégé test', 9514);
        $article = $this->article($galerie, '</script><script>alert("x")</script> & "guillemets"', 9514, ['Légende </script>']);

        $crawler = $client->request('GET', $this->urls()->articlePath($article));
        $html = $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<script>alert("x")', $html);
        self::assertSame('</script><script>alert("x")</script> & "guillemets"', $this->node($this->graph($crawler), 'ImageGallery')['name']);
    }

    public function testSitemapListsEveryPublicPageAndPhoto(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Sitemap test', 9515);
        $publie = $this->article($galerie, 'Œuvre dans le plan', 9515, ['Photo une', 'Photo deux']);
        $brouillon = $this->article($galerie, 'Brouillon secret', 9516, ['Photo cachée'], publie: false);
        $vide = $this->galerie('Galerie sans œuvre test', 9516);
        $cv = (new Categorie())->setTitre('CV sitemap test')->setType(Categorie::TYPE_TEXTE)->setContenu('<p>Mon parcours.</p>')->setPosition(9517);
        $cvVide = (new Categorie())->setTitre('CV vide sitemap test')->setType(Categorie::TYPE_TEXTE)->setPosition(9518);
        $this->persist($cv, $cvVide);

        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('application/xml', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=3600', $client->getResponse()->headers->get('Cache-Control'));

        $xml = simplexml_load_string($client->getResponse()->getContent());
        self::assertNotFalse($xml, 'Le sitemap est du XML valide');
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->registerXPathNamespace('i', 'http://www.google.com/schemas/sitemap-image/1.1');

        $pages = array_map('strval', $xml->xpath('//s:url/s:loc'));

        self::assertContains(self::SITE . '/', $pages);
        self::assertContains(self::SITE . '/contact', $pages);
        self::assertContains(self::SITE . $this->urls()->categoryPath($galerie), $pages);
        self::assertContains(self::SITE . $this->urls()->articlePath($publie), $pages);
        self::assertContains(self::SITE . $this->urls()->categoryPath($cv), $pages);

        // Ni brouillon, ni page vide, ni mentions légales
        self::assertNotContains(self::SITE . $this->urls()->articlePath($brouillon), $pages);
        self::assertNotContains(self::SITE . $this->urls()->categoryPath($vide), $pages);
        self::assertNotContains(self::SITE . $this->urls()->categoryPath($cvVide), $pages);
        self::assertNotContains(self::SITE . '/legal', $pages);
        self::assertSame(count($pages), count(array_unique($pages)), 'Chaque page une seule fois');

        // Les photos de l'œuvre, avec leur légende
        $loc = self::SITE . $this->urls()->articlePath($publie);
        $images = $xml->xpath(sprintf('//s:url[s:loc="%s"]/i:image', $loc));
        self::assertCount(2, $images);
        $premiere = $images[0]->children('http://www.google.com/schemas/sitemap-image/1.1');
        self::assertStringStartsWith(self::SITE . '/uploads/attachments/seo-test-', (string) $premiere->loc);
        self::assertSame('Photo une', (string) $premiere->title);

        // Une date de dernière modification au format attendu
        $lastmod = (string) $xml->xpath(sprintf('//s:url[s:loc="%s"]/s:lastmod', $loc))[0];
        self::assertMatchesRegularExpression('/^2024-05-01T10:00:00[+-]\d\d:\d\d$/', $lastmod);
    }

    /**
     * @return array<int, array{agents: list<string>, rules: list<string>}> Les groupes de règles de robots.txt
     */
    private function robotsGroups(string $robots): array
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\R/', $robots) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));

            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                if (!$lastWasAgent) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = $m[1];
                $lastWasAgent = true;
            } elseif (preg_match('/^(Allow|Disallow):\s*(.*)$/i', $line, $m) && null !== $current) {
                $groups[$current]['rules'][] = $m[1] . ': ' . $m[2];
                $lastWasAgent = false;
            }
        }

        return $groups;
    }

    public function testRobotsTxtWelcomesSearchEnginesAndRefusesAiTrainingCrawlers(): void
    {
        $robots = file_get_contents(dirname(__DIR__, 2) . '/public/robots.txt');
        $groups = $this->robotsGroups($robots);

        self::assertStringContainsString('Sitemap: ' . self::SITE . '/sitemap.xml', $robots);
        self::assertStringNotContainsString('mon_petit_site', $robots, "robots.txt est public : l'adresse de l'admin n'y figure pas");

        // Tout le monde est le bienvenu (y compris dans /uploads, d'où viennent les photos)...
        $everyone = array_values(array_filter($groups, fn (array $g) => in_array('*', $g['agents'], true)));
        self::assertCount(1, $everyone);
        self::assertSame(['Allow: /'], $everyone[0]['rules']);

        // ... sauf les robots qui entraînent des IA
        $refused = [];
        foreach ($groups as $group) {
            if (['Disallow: /'] === $group['rules']) {
                $refused = array_merge($refused, array_map('strtolower', $group['agents']));
            }
        }

        foreach (['GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Meta-ExternalAgent', 'ImagesiftBot', 'img2dataset'] as $bot) {
            self::assertContains(strtolower($bot), $refused, sprintf('%s doit être refusé', $bot));
        }

        // Google, Bing et les assistants qui recommandent Caroline (recherche) ne sont jamais refusés
        foreach (['Googlebot', 'Googlebot-Image', 'Bingbot', 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Claude-SearchBot', 'Claude-User'] as $bot) {
            self::assertNotContains(strtolower($bot), $refused, sprintf('%s ne doit pas être refusé', $bot));
        }
    }

    public function testPagesAskAiCrawlersNotToTrainOnThem(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $robots = $crawler->filter('meta[name="robots"]')->each(fn (Crawler $meta) => $meta->attr('content'));

        self::assertContains('noai, noimageai', $robots);
        // Les consignes pour Google restent dans leur propre balise, intactes
        self::assertContains('index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1', $robots);
    }
}
