<?php

namespace App\Storefront\Menu;

/**
 * When the compiled menus are rebuilt (D3). Saving a menu compiles NOW (`now()`, inside the save, so a too-large menu is refused). Everything else
 * (a page published or renamed, a category created / renamed / moved / deleted, a product that changes what a category shows, a menu picture that
 * becomes ready or is deleted) only calls `request()`: the rebuild is DEBOUNCED, so several changes in one request compile once, at the end of the
 * request (`terminating`), after a queue job (see MenuRecompileListeners), or earlier when the storefront reads the appearance
 * (StorefrontAppearance flushes a pending request first, so a change is never read stale in the same process).
 *
 * A singleton: the flag lives for the process. `runs` counts the compiles (the debounce test reads it).
 */
final class MenuRecompiler
{
    public int $runs = 0;

    private bool $pending = false;

    private bool $hooked = false;

    /** @var array<string, bool> item types a menu holds (looked up once, forgotten after a rebuild) */
    private array $holds = [];

    /**
     * Ask for a rebuild at the end of the request / job. `$type` says what changed (`category`: a category or the visibility of a product; `page`; `image`): when no
     * menu holds an item of that type nothing could change, so nothing is asked (a shop without menu categories pays nothing when a product is saved).
     */
    public function request(?string $type = null): void
    {
        if ($type !== null && ! ($this->holds[$type] ??= \Illuminate\Support\Facades\DB::table('menu_items')->where('type', $type)->exists())) {
            return;
        }

        $this->pending = true;

        if (! $this->hooked) {
            $this->hooked = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    public function hasPending(): bool
    {
        return $this->pending;
    }

    /** Runs the rebuild when one was asked for. */
    public function flush(): void
    {
        $this->hooked = false;

        if ($this->pending) {
            $this->now();
        }
    }

    /** Rebuilds and stores the compiled menus right now. @throws MenuCompileException */
    public function now(): void
    {
        $this->pending = false;
        $this->holds = [];
        $this->runs++;
        app(MenuCompiler::class)->compileAndStore();
    }
}
