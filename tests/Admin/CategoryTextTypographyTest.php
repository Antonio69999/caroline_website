<?php

namespace App\Tests\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Les polices et les titres de l'éditeur de texte sont répartis entre un script
 * (admin_category_rich_text.js : la liste des polices proposées) et une feuille de style
 * (category_text.css : leurs classes, leurs fichiers, les tailles de titres).
 *
 * Rien ne relie les deux à l'exécution : si on ajoute une police dans le script sans sa classe
 * dans la feuille, ou si un fichier de police disparaît, le menu propose un choix qui ne fait rien
 * (ou, pire, qui s'affiche dans la police par défaut). Ce test le voit avant la mise en ligne.
 */
class CategoryTextTypographyTest extends TestCase
{
    private static string $publicDir;
    private static string $css;
    private static string $script;

    public static function setUpBeforeClass(): void
    {
        self::$publicDir = dirname(__DIR__, 2) . '/public';
        self::$css = (string) file_get_contents(self::$publicDir . '/asset/css/category_text.css');
        self::$script = (string) file_get_contents(self::$publicDir . '/asset/js/admin_category_rich_text.js');
    }

    /** @return list<string> identifiants des polices proposées par le menu de l'éditeur */
    private function fontsOfTheEditor(): array
    {
        preg_match('/const FONTS = \[(.*?)\n  \];/s', self::$script, $block);
        self::assertNotEmpty($block, 'La liste FONTS est introuvable dans admin_category_rich_text.js');

        preg_match_all("/value: '([a-z]+)'/", $block[1], $values);

        return $values[1];
    }

    public function testTheEditorOffersAtLeastTheSiteFontAndAFewMore(): void
    {
        self::assertContains('alice', $this->fontsOfTheEditor(), 'Alice est la police des titres du site');
        self::assertGreaterThanOrEqual(3, count($this->fontsOfTheEditor()));
    }

    public function testEveryFontOfTheMenuHasItsClassOnTheSiteAndInTheEditor(): void
    {
        foreach ($this->fontsOfTheEditor() as $font) {
            self::assertStringContainsString(".category-text .ql-font-$font", self::$css, "Le site n'a pas de style pour la police « $font »");
            self::assertStringContainsString(".category-rich-editor .ql-snow .ql-editor .ql-font-$font", self::$css, "L'éditeur n'a pas de style pour la police « $font »");
        }
    }

    public function testEveryFontFileReferencedByTheStylesheetExists(): void
    {
        preg_match_all('#url\("(/asset/fonts/[^"?]+)(?:\?[^"]*)?"\)#', self::$css, $files);

        self::assertNotEmpty($files[1]);
        foreach ($files[1] as $file) {
            self::assertFileExists(self::$publicDir . $file, "Fichier de police manquant : $file");
        }
    }

    public function testSixHeadingLevelsHaveASizeOnTheSiteAndInTheEditor(): void
    {
        foreach (range(1, 6) as $level) {
            // La taille se lit dans un bloc dont le sélecteur nomme le titre ET l'éditeur : c'est ce qui
            // garantit que le site et l'éditeur affichent la même chose
            $found = preg_match(
                sprintf('/\.category-text h%1$d,\s*\.category-rich-editor \.ql-snow \.ql-editor h%1$d\s*\{[^}]*font-size:\s*([\d.]+)rem/', $level),
                self::$css,
                $matches,
            );
            self::assertSame(1, $found, "Le titre $level n'a pas de taille commune au site et à l'éditeur");
            self::assertGreaterThan(0.5, (float) $matches[1]);
        }
    }

    public function testHeadingSizesAreFixedNotFluid(): void
    {
        // « Titre 2 · 20 px » n'est vrai partout que si la taille ne varie pas avec l'écran
        self::assertDoesNotMatchRegularExpression('/font-size:\s*(clamp|calc|min|max)\(/', self::$css);
    }

    public function testHeadingsGoFromBiggestToSmallest(): void
    {
        $sizes = [];
        foreach (range(1, 6) as $level) {
            preg_match(sprintf('/\.category-text h%1$d,\s*\.category-rich-editor \.ql-snow \.ql-editor h%1$d\s*\{[^}]*font-size:\s*([\d.]+)rem/', $level), self::$css, $m);
            $sizes[$level] = (float) $m[1];
        }

        $sorted = $sizes;
        rsort($sorted);
        self::assertSame(array_values($sorted), array_values($sizes), 'Un titre de niveau plus élevé doit être plus petit (ou égal)');
        self::assertGreaterThan($sizes[6], $sizes[1]);
    }

    public function testTheEditorOffersSixHeadingLevels(): void
    {
        self::assertStringContainsString('const HEADING_LEVELS = [1, 2, 3, 4, 5, 6];', self::$script);
    }

    public function testTheOldSiteOnlyH1RuleIsGone(): void
    {
        // Deux définitions du titre 1 (ici et dans categories.css) se contrediraient en silence
        $categories = (string) file_get_contents(self::$publicDir . '/asset/css/categories.css');
        self::assertStringNotContainsString('.category-text h1', $categories);
    }
}
