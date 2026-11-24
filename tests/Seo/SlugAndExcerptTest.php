<?php

namespace App\Tests\Seo;

use App\Seo\SeoBuilder;
use App\Seo\Slug;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les deux briques de texte du référencement : le titre qui devient un morceau d'adresse,
 * et le texte de l'admin qui devient la description affichée par Google.
 */
class SlugAndExcerptTest extends KernelTestCase
{
    /** @return iterable<string, array{0: ?string, 1: string}> */
    public static function titles(): iterable
    {
        yield 'accents et espaces' => ['Gravure sur verre', 'gravure-sur-verre'];
        yield 'majuscules et accents' => ['Été à la Borie', 'ete-a-la-borie'];
        yield 'ligature' => ['Cœur de bœuf', 'coeur-de-boeuf'];
        yield 'apostrophe et ponctuation' => ["L'atelier : peinture, gravure !", 'l-atelier-peinture-gravure'];
        yield 'chiffres' => ['La paire de chaussures, 2023', 'la-paire-de-chaussures-2023'];
        yield 'rien d\'exploitable' => ['???', ''];
        yield 'vide' => ['', ''];
        yield 'nul' => [null, ''];
    }

    /**
     * @dataProvider titles
     */
    public function testSlugOfATitle(?string $titre, string $attendu): void
    {
        self::assertSame($attendu, (new Slug())->from($titre));
    }

    public function testSlugIsAlwaysSafeForTheRoute(): void
    {
        $slug = new Slug();
        $bizarres = [
            '<script>alert(1)</script>', '../../etc/passwd', "Titre\nsur\r\ndeux lignes", 'ÀÉÎÕÜ ÿ ñ ç', '書道 — calligraphie',
            str_repeat('mot très long ', 30), '---', ' - tirets - autour - ', '100 % pur', 'a/b?c=d&e#f',
        ];

        foreach ($bizarres as $titre) {
            $resultat = $slug->from($titre);

            // C'est exactement l'exigence de la route : sinon la redirection vers l'adresse officielle bouclerait
            self::assertMatchesRegularExpression('/^(?:[a-z0-9-]+)?$/', $resultat, sprintf('Slug invalide pour « %s »', $titre));
            self::assertLessThanOrEqual(60, strlen($resultat));
            self::assertStringStartsNotWith('-', $resultat);
            self::assertStringEndsNotWith('-', $resultat);
        }
    }

    public function testLongSlugIsCutBetweenTwoWords(): void
    {
        $slug = (new Slug())->from('Une très longue description de tableau qui dépasse largement la taille raisonnable pour une adresse');

        self::assertLessThanOrEqual(60, strlen($slug));
        self::assertSame('une-tres-longue-description-de-tableau-qui-depasse', $slug, 'Coupé entre deux mots, jamais au milieu de « largement »');
        self::assertSame($slug, (new Slug())->from($slug), 'Un slug déjà propre ne change plus');
    }

    public function testPlainTextKeepsWordsApartAndDecodesEntities(): void
    {
        $seo = self::getContainer()->get(SeoBuilder::class);

        self::assertSame(
            "L'huile sur toile & le fusain",
            $seo->plainText("<div>L&#039;huile sur toile</div><div>&amp; le&nbsp;fusain</div>"),
        );
        self::assertSame('un deux trois', $seo->plainText('<p>un</p><p>deux</p><ul><li>trois</li></ul>'));
        self::assertSame('', $seo->plainText('<p> </p><br>'));
        self::assertSame('', $seo->plainText(null));
    }

    public function testExcerptIsCutBetweenWordsAndKeepsShortTextsUntouched(): void
    {
        $seo = self::getContainer()->get(SeoBuilder::class);

        self::assertSame('Un texte court.', $seo->excerpt('<p>Un texte court.</p>'));

        $long = '<p>' . str_repeat('Peinture à l\'huile sur toile, ', 20) . '</p>';
        $extrait = $seo->excerpt($long);

        self::assertLessThanOrEqual(155, mb_strlen($extrait));
        self::assertStringEndsWith('…', $extrait);
        self::assertStringNotContainsString(',…', $extrait, 'On ne finit pas sur une virgule');
        self::assertMatchesRegularExpression('/(toile|huile|Peinture)…$/u', $extrait, 'On coupe après un mot entier');
    }

    /**
     * Le vrai cas de l'article « Flux Libre » (500 en production) : « à » écrit en deux morceaux
     * (« a » + accent combinant, octets 61 CC 80, typique d'un texte collé depuis un Mac), pile à l'endroit
     * de la coupe. Le résumé devenait de l'UTF-8 invalide et l'encodage JSON de la page échouait.
     */
    public function testExcerptNeverBreaksACharacterWhenTheCutFallsOnAnAccent(): void
    {
        $seo = self::getContainer()->get(SeoBuilder::class);
        // « à » en deux morceaux (le cas réel), et « À » en un seul morceau dont le dernier octet est lui aussi 80
        // (celui-là n'est pas recomposé : il vérifie à lui seul que la coupure ne scie plus un caractère)
        foreach (["a\u{0300}", 'À'] as $mot) {
            // On fait tomber le mot à toutes les positions possibles autour de la coupe (155 caractères)
            for ($decalage = 140; $decalage <= 165; ++$decalage) {
                $texte = str_repeat('x', $decalage) . ' ' . $mot . ' la perche télescopique. ' . str_repeat('suite du texte ', 20);
                $extrait = $seo->excerpt($texte);
                $contexte = sprintf('« %s » avec un décalage de %d', bin2hex($mot), $decalage);

                self::assertTrue(mb_check_encoding($extrait, 'UTF-8'), 'UTF-8 invalide : ' . $contexte);
                self::assertNotFalse(json_encode($extrait, \JSON_THROW_ON_ERROR), 'Doit pouvoir s\'écrire en JSON : ' . $contexte);
                self::assertStringNotContainsString("\u{FFFD}", $extrait, $contexte);
            }
        }
    }

    public function testExcerptRecomposesDecomposedAccentsAndDoesNotEndOnDashes(): void
    {
        $seo = self::getContainer()->get(SeoBuilder::class);

        // Pas de coupe : un « à » décomposé est simplement recomposé (un seul caractère)
        self::assertSame("à la perche", $seo->excerpt("a\u{0300} la perche"));

        // Un tiret ou un point à la coupe est retiré, accents et tirets longs compris
        $texte = str_repeat('mot ', 36) . 'fin – — - . , ;' . str_repeat(' suite', 20);
        $extrait = $seo->excerpt($texte);

        self::assertStringEndsWith('…', $extrait);
        self::assertDoesNotMatchRegularExpression('/[ ,;:.\-–—]…$/u', $extrait);
        self::assertTrue(mb_check_encoding($extrait, 'UTF-8'));
    }
}
