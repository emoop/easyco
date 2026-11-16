<?php

namespace App\Console\Commands;

use App\Security\BotChallenge\BotChallenge;
use App\Security\BotChallenge\BotChallengeSwitch;
use Illuminate\Console\Command;

/** P8d: every minute (routes/console.php): pushes the STORED mode to the edge, which forgets it on a restart. Writes nothing. */
class SecurityPushBotChallenge extends Command
{
    protected $signature = 'security:push-bot-challenge';

    protected $description = 'Push the stored bot-challenge mode to the cache layer';

    public function handle(BotChallenge $challenge, BotChallengeSwitch $switch): int
    {
        $switch->push($challenge->current());

        return self::SUCCESS;
    }
}
