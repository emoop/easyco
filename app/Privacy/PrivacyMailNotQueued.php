<?php

namespace App\Privacy;

use App\Privacy\Persistence\PrivacyRequestModel;
use RuntimeException;

/**
 * ACC-4e-1: the confirmation row was written but its mail could not be queued (the queue is down). Carries the row: it stays `awaiting_confirmation`, and «Изпрати отново» can try again.
 * The admin must be told; it is never reported as sent.
 */
final class PrivacyMailNotQueued extends RuntimeException
{
    public function __construct(public readonly PrivacyRequestModel $request)
    {
        parent::__construct('The confirmation mail could not be queued.');
    }
}
