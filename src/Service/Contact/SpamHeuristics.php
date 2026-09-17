<?php

namespace App\Service\Contact;

/**
 * Lightweight, dependency-free signals used to flag likely bot submissions
 * on the public contact form, without hard-blocking real visitors.
 */
final class SpamHeuristics
{
    public function __construct(
        private readonly int $maxLinks = 3,
        private readonly int $minSecondsOnForm = 2,
    ) {
    }

    public function isHoneypotFilled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * @param int|null $renderedAt Unix timestamp of when the form was first displayed
     *                             (server-side, from the session). Null means the signal
     *                             is unavailable (e.g. cookies blocked) and must not be
     *                             held against the visitor.
     */
    public function isSubmittedTooFast(?int $renderedAt): bool
    {
        if ($renderedAt === null) {
            return false;
        }

        return (time() - $renderedAt) < $this->minSecondsOnForm;
    }

    public function countLinks(string $text): int
    {
        return preg_match_all('#\bhttps?://\S+|\bwww\.\S+#i', $text) ?: 0;
    }

    public function hasTooManyLinks(string $text): bool
    {
        return $this->countLinks($text) > $this->maxLinks;
    }
}
