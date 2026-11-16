<?php

namespace App\Console\Commands;

use App\Security\BotChallenge\BotChallenge;
use App\Security\BotChallenge\BotChallengeSwitch;
use Illuminate\Console\Command;

/** P8d: `php artisan security:bot-challenge {off|filters|misses}`: stores the mode (the same setting as the admin field) and pushes it to the edge at once. */
class SecurityBotChallenge extends Command
{
    protected $signature = 'security:bot-challenge {mode : off | filters | misses}';

    protected $description = 'Set the bot-challenge mode (off, filters or misses) and push it to the cache layer';

    public function handle(BotChallenge $challenge, BotChallengeSwitch $switch): int
    {
        $mode = (string) $this->argument('mode');

        if (! BotChallenge::valid($mode)) {
            $this->error('The mode must be one of: '.implode(', ', BotChallenge::MODES).'.');

            return self::INVALID;
        }

        $challenge->set($mode);
        $switch->push($mode);
        $this->info("Bot challenge: {$mode}.");

        return self::SUCCESS;
    }
}
