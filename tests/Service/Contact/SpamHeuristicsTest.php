<?php

namespace App\Tests\Service\Contact;

use App\Service\Contact\SpamHeuristics;
use PHPUnit\Framework\TestCase;

class SpamHeuristicsTest extends TestCase
{
    private SpamHeuristics $heuristics;

    protected function setUp(): void
    {
        $this->heuristics = new SpamHeuristics(maxLinks: 3, minSecondsOnForm: 2);
    }

    public function testEmptyHoneypotIsNotFlagged(): void
    {
        self::assertFalse($this->heuristics->isHoneypotFilled(null));
        self::assertFalse($this->heuristics->isHoneypotFilled(''));
        self::assertFalse($this->heuristics->isHoneypotFilled('   '));
    }

    public function testFilledHoneypotIsFlagged(): void
    {
        self::assertTrue($this->heuristics->isHoneypotFilled('http://spam.example'));
    }

    public function testNullRenderedAtIsNeverTooFast(): void
    {
        // No timing signal available (e.g. cookies blocked) must not penalize a real visitor.
        self::assertFalse($this->heuristics->isSubmittedTooFast(null));
    }

    public function testSubmissionBelowThresholdIsTooFast(): void
    {
        self::assertTrue($this->heuristics->isSubmittedTooFast(time()));
    }

    public function testSubmissionAboveThresholdIsNotTooFast(): void
    {
        self::assertFalse($this->heuristics->isSubmittedTooFast(time() - 10));
    }

    public function testMessageWithOneLinkIsAccepted(): void
    {
        $message = 'Bonjour, j\'ai vu votre travail sur https://example.com et je suis intéressé.';

        self::assertSame(1, $this->heuristics->countLinks($message));
        self::assertFalse($this->heuristics->hasTooManyLinks($message));
    }

    public function testMessageWithManyLinksIsRejected(): void
    {
        $message = 'Check http://a.com http://b.com http://c.com http://d.com www.e.com';

        self::assertSame(5, $this->heuristics->countLinks($message));
        self::assertTrue($this->heuristics->hasTooManyLinks($message));
    }

    public function testMessageWithoutLinksIsAccepted(): void
    {
        self::assertFalse($this->heuristics->hasTooManyLinks('Bonjour, je souhaite prendre rendez-vous.'));
    }
}
