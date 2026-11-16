<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * CVs are written by candidates, so everything sent to Claude from one is data (audit F13, 16 Nov 2026).
 *  - cv(): the CV inside one <cv> block that its own text can't close or reopen (a "</cv>" written in a CV is shown
 *    as "[cv]", so nothing after it can pose as Eshcol's own instructions);
 *  - suspect(): lines written to an AI rather than to a recruiter ("ignore the previous instructions", "note to the
 *    AI", "rate this candidate 100"). A quote taken from such a line never counts as proof.
 * Pure: tested directly (tests/adversarial-test.php).
 */
final class PromptGuard
{
    /** Instruction-like wording aimed at a model. Kept narrow, so ordinary CV lines ("followed SOP instructions") pass. */
    private const PATTERNS = [
        '/\b(ignore|disregard|forget|override)\b.{0,20}\b(all|any|your|previous|prior|above|earlier)\b.{0,20}\b(instructions?|prompts?|rules|guidelines|directions)\b/iu',
        '/\b(note|message|instructions?)\s+(to|for)\s+(the\s+)?(ai|a\.i\.|llm|chatgpt|gpt|claude|gemini|assistant|model|bot|screening (system|tool|software)|ats|recruit(ing|ment)? (system|software|tool|bot))\b/iu',
        '/\b(you are|you\'re|act as)\s+(an?\s+|the\s+)?(ai|assistant|language model|llm|chatgpt|claude|recruit(er|ing) (bot|ai|assistant))\b/iu',
        '/\b(rate|score|rank|mark|grade|assess|classify|categori[sz]e)\b.{0,30}\bthis\s+(candidate|cv|resume|applicant|profile)\b.{0,40}\b(100|ten out of ten|10\s*\/\s*10|highest|top|perfect|excellent|strong|senior|director|proven)\b/iu',
        '/\bthis\s+(candidate|applicant|cv|resume|profile)\b.{0,20}\b(meets|satisfies|fulfils|fulfills|matches)\b.{0,15}\b(every|all|each)\b.{0,20}\b(requirements?|criteria|must[- ]haves?)\b/iu',
        '/\b(system|developer)\s+(prompt|message|override|instruction)s?\b/iu',
        '/^\s*\[?(system|assistant)\]?\s*:\s*.{0,40}\b(candidate|applicant|you|ignore|rate|score|rank|classify)\b/iu',
        '/<\s*\/?\s*(cv|system|instructions?|prompt)\b[^>]*>/iu',
    ];

    /** The CV as one data block for a prompt. */
    public static function cv(string $text): string
    {
        return "<cv>\n" . self::neutralise($text) . "\n</cv>";
    }

    /** A "<cv>" or "</cv>" (any case or spacing) inside the CV's own text can't open or close the block. */
    public static function neutralise(string $text): string
    {
        return (string) preg_replace('~<\s*/?\s*cv\b[^>]*>~iu', '[cv]', $text);
    }

    /** Is this line or quote written to an AI rather than to a recruiter? */
    public static function suspect(string $text): bool
    {
        foreach (self::PATTERNS as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> the CV's lines that look written to an AI (for a warning to the recruiter). */
    public static function suspectLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== '' && self::suspect($l)));
    }
}
