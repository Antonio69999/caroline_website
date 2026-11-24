<?php

namespace App\Tests\Twig;

use App\Seo\SeoPage;
use App\Twig\SeoExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Les données structurées (JSON-LD) ne servent qu'à Google : quoi qu'il arrive à leur écriture,
 * elles ne doivent jamais empêcher la page de s'afficher pour les visiteurs.
 */
class SeoExtensionTest extends KernelTestCase
{
    public function testAnInvalidCharacterInATitleDoesNotBreakThePage(): void
    {
        // « café » écrit en Latin-1 : l'octet \xE9 n'est pas de l'UTF-8 valide
        $html = $this->extension()->jsonLd($this->page([['@type' => 'WebPage', 'name' => "Un caf\xE9, 2019"]]));

        self::assertStringStartsWith('<script type="application/ld+json">', $html);
        self::assertStringContainsString('Un caf', $html);
        // Le résultat est du JSON valide
        self::assertIsArray(json_decode($this->contenu($html), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testDataThatCannotBeEncodedGivesNothingInsteadOfAnError(): void
    {
        // Un nombre « pas un nombre » n'a pas d'écriture en JSON
        $html = $this->extension()->jsonLd($this->page([['@type' => 'WebPage', 'note' => \NAN]]));

        self::assertSame('', $html);
    }

    public function testNormalDataIsWrittenAsBefore(): void
    {
        $html = $this->extension()->jsonLd($this->page([['@type' => 'WebPage', 'name' => 'Flux Libre, 2019 </script>']]));
        $json = json_decode($this->contenu($html), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('https://schema.org', $json['@context']);
        self::assertSame('Flux Libre, 2019 </script>', $json['@graph'][0]['name']);
        // Un « </script> » écrit dans un titre ne ferme pas la balise avant l'heure
        self::assertSame(1, substr_count($html, '</script>'));
    }

    public function testNoGraphWritesNothing(): void
    {
        self::assertSame('', $this->extension()->jsonLd($this->page([])));
    }

    private function extension(): SeoExtension
    {
        static::bootKernel();

        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        return $twig->getExtension(SeoExtension::class);
    }

    /** @param list<array<string, mixed>> $graph */
    private function page(array $graph): SeoPage
    {
        return new SeoPage('Titre', 'Description', 'https://carottecake.com/article/33/flux-libre-2019', 'https://carottecake.com/image.jpg', 'Image', 'index, follow', $graph);
    }

    private function contenu(string $html): string
    {
        return html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
