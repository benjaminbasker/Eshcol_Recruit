<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/** A sending failure; permanent ones (bad address, bad key) are not retried. */
final class MailError extends \RuntimeException
{
    /** @param bool $keyProblem Brevo refused the API key (wrong, switched off, or the server's IP not authorised): pause, don't fail */
    public function __construct(string $message, public readonly bool $permanent = false, public readonly bool $keyProblem = false)
    {
        parent::__construct($message);
    }
}
