<?php

namespace App\Privacy;

use App\Privacy\Persistence\PrivacyRequestModel;
use RuntimeException;

/**
 * ACC-4e-1: a confirmation request for this email and kind is already waiting (`awaiting_confirmation`, younger than PrivacyConfirmationRequester::VALID_DAYS). Carries that row, so the
 * admin dialog can say «Вече е пратено на …» and offer «Изпрати отново» (PrivacyConfirmationRequester::resend) instead of making a second row.
 */
final class PrivacyRequestAlreadyOpen extends RuntimeException
{
    public function __construct(public readonly PrivacyRequestModel $request)
    {
        parent::__construct('A confirmation request for this email and kind is already open.');
    }
}
