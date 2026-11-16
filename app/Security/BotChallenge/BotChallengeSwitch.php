<?php

namespace App\Security\BotChallenge;

/** P8d: pushes the stored bot-challenge mode to the edge (the cache layer). The Varnish implementation arrives in P8c; until then NullBotChallengeSwitch. */
interface BotChallengeSwitch
{
    /** @param  string  $mode  one of BotChallenge::MODES */
    public function push(string $mode): void;
}
