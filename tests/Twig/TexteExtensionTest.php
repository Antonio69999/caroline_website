<?php

namespace App\Tests\Twig;

use App\Twig\TexteExtension;
use PHPUnit\Framework\TestCase;

/**
 * Le texte libre d'un article ou d'une catégorie avant affichage : on y retire les espaces de fin
 * de ligne (qui décalent un texte centré), et on décide si le texte est « long » (alignement à gauche).
 */
class TexteExtensionTest extends TestCase
{
    private TexteExtension $texte;

    protected function setUp(): void
    {
        $this->texte = new TexteExtension();
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function nettoyages(): iterable
    {
        yield 'espace insécable avant un retour à la ligne' => ['<div>Ligne un&nbsp;<br>Ligne deux</div>', '<div>Ligne un<br>Ligne deux</div>'];
        yield 'espace insécable en fin de bloc' => ['<div>Ligne un&nbsp;</div>', '<div>Ligne un</div>'];
        yield 'plusieurs espaces de fin (Trix alterne insécable et normale)' => ["<div>Fin&nbsp; &nbsp;<br>Suite</div>", '<div>Fin<br>Suite</div>'];
        yield 'espace insécable écrite en caractère' => ["<div>Fin\u{00A0}<br>Suite</div>", '<div>Fin<br>Suite</div>'];
        yield 'écrite en nombre' => ['<div>Fin&#160;<br>Suite</div>', '<div>Fin<br>Suite</div>'];
        yield 'avant la fermeture d\'un texte en gras' => ['<div><strong>Gras&nbsp;</strong><br>Suite</div>', '<div><strong>Gras</strong><br>Suite</div>'];
        yield 'fin de texte' => ['Dernière ligne&nbsp;', 'Dernière ligne'];

        // Ce qui doit rester tel quel
        yield 'espace voulue avant une ponctuation' => ['<div>psst&nbsp;! Le&nbsp;fusain</div>', '<div>psst&nbsp;! Le&nbsp;fusain</div>'];
        yield 'espaces entre deux mots' => ['<div>un deux  trois</div>', '<div>un deux  trois</div>'];
        yield 'ligne blanche faite d\'une seule espace insécable' => ['<div>Un</div><div>&nbsp;</div><div>Deux</div>', '<div>Un</div><div>&nbsp;</div><div>Deux</div>'];
        yield 'ligne blanche classique de Trix' => ['<div>Un</div><div><br></div><div>Deux</div>', '<div>Un</div><div><br></div><div>Deux</div>'];
        yield 'sans rien à nettoyer' => ['<div>Huile sur toile, 2023</div>', '<div>Huile sur toile, 2023</div>'];
        yield 'vide' => ['', ''];
    }

    /**
     * @dataProvider nettoyages
     */
    public function testTrailingSpacesAreRemovedButNothingElse(string $html, string $attendu): void
    {
        self::assertSame($attendu, $this->texte->nettoyer($html));
    }

    public function testNullIsAnEmptyText(): void
    {
        self::assertSame('', $this->texte->nettoyer(null));
        self::assertFalse($this->texte->estLong(null));
    }

    public function testBadlyEncodedTextIsShownAsIsRatherThanLost(): void
    {
        // Un octet invalide en UTF-8 : preg_replace échoue ; mieux vaut le texte brut que rien
        $abime = "<div>Fin\xFF&nbsp;<br>Suite</div>";

        self::assertSame($abime, $this->texte->nettoyer($abime));
    }

    public function testAShortCaptionIsNotLong(): void
    {
        self::assertFalse($this->texte->estLong('<div>Huile sur toile, 80 × 60 cm, 2023</div>'));
    }

    public function testALongTextIsLong(): void
    {
        self::assertTrue($this->texte->estLong('<div>' . str_repeat('Un mot de plus. ', 30) . '</div>'));
    }

    public function testTheLimitIsExactlyTheThreshold(): void
    {
        $limite = TexteExtension::SEUIL_TEXTE_LONG;

        self::assertFalse($this->texte->estLong('<div>' . str_repeat('a', $limite) . '</div>'));
        self::assertTrue($this->texte->estLong('<div>' . str_repeat('a', $limite + 1) . '</div>'));
    }

    public function testTagsAndEntitiesDoNotCountAsText(): void
    {
        // Beaucoup de balises et d'entités, mais très peu de texte lu : reste court
        $html = str_repeat('<strong class="un-nom-de-classe-tres-long"><em>a</em></strong>&nbsp;', 40);

        self::assertFalse($this->texte->estLong($html));
    }

    public function testLineBreaksCountAsASpace(): void
    {
        // « un<br>deux » se lit « un deux » : 3 + 1 + 4 caractères, pas 7
        $limite = TexteExtension::SEUIL_TEXTE_LONG;
        $mots = str_repeat('a<br>', (int) ($limite / 2));          // « a a a … » : limite - 1 ou limite caractères

        self::assertFalse($this->texte->estLong('<div>' . $mots . '</div>'));
        self::assertTrue($this->texte->estLong('<div>' . $mots . $mots . '</div>'));
    }

    public function testRunsOfSpacesCountOnce(): void
    {
        $limite = TexteExtension::SEUIL_TEXTE_LONG;

        self::assertFalse($this->texte->estLong('<div>' . str_repeat('a' . str_repeat('&nbsp; ', 6), (int) ($limite / 2)) . '</div>'));
    }

    public function testOnlyWhitespaceIsNotLong(): void
    {
        self::assertFalse($this->texte->estLong("   \n  "));
        self::assertFalse($this->texte->estLong('<div><br></div>'));
    }
}
