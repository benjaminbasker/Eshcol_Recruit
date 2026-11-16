<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/** A sending failure; permanent ones (bad address, bad key) are not retried. */
final class MailError extends \RuntimeException
{
    /** @param bool $keyProblem Brevo refused the API key (wrong, switched off, or the server's IP not authorised): pause, don't fail */
    /** @param bool $unsure Brevo may have taken it (the answer was lost): never resend automatically */
    public function __construct(string $message, public readonly bool $permanent = false, public readonly bool $keyProblem = false, public readonly bool $unsure = false)
    {
        parent::__construct($message);
    }
}
