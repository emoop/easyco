<?php

namespace App\Filament\Design\Zones;

/**
 * A tree zone whose rows and columns can be added, removed and moved (the footer, DS-2b). The screen calls restructure() with the action and the id it works on; the zone returns the new
 * tree or throws a DomainException with the message the screen shows (a limit, a column that still holds blocks and was not confirmed).
 */
interface RestructuresTree
{
    public const ACTIONS = ['add_row', 'remove_row', 'add_column', 'remove_column', 'move_column_left', 'move_column_right'];

    /**
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     *
     * @throws \DomainException
     */
    public function restructure(array $tree, string $action, string $id, bool $confirmed): array;
}
