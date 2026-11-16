<?php

namespace App\Security\BotChallenge;

use Illuminate\Support\Facades\Log;

/** P8d: no edge yet: does nothing (logs at debug). */
final class NullBotChallengeSwitch implements BotChallengeSwitch
{
    public function push(string $mode): void
    {
        Log::debug('Bot-challenge switch (null): nothing is pushed.', ['mode' => $mode]);
    }
}
