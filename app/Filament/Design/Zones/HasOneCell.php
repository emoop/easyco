<?php

namespace App\Filament\Design\Zones;

/**
 * A tree zone that is ONE flat list (SC-1: the product page's right column): its tree has one row of one cell, and the screen hides that structure. The cell is selected from the start (and after
 * every reset), so the palette always knows where a new component goes.
 */
interface HasOneCell
{
    /** The id of the one cell. */
    public function onlyCell(): string;
}
