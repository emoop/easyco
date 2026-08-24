<?php

namespace EasyCo\Extensibility;

/**
 * A WordPress-style action/filter hook registry — pure PHP, zero framework
 * dependency of any kind. This is the extensibility mechanism other EasyCo
 * domain packages hang extension points on: "when X happens, let anything
 * that cares run" (actions), and "let anything that cares adjust this
 * value" (filters) — without those packages ever knowing who's listening,
 * or depending on Laravel to make it work.
 *
 * Deliberately not a singleton/static class itself — that's what the
 * Laravel-facing Hook facade (EasyCo\Extensibility\Hook) is for. A domain
 * package that wants to be hookable takes a HookRegistry instance directly
 * (constructor injection, or whatever container it's wired into), never
 * this class's own static state — that's exactly what keeps this package's
 * core logic framework-agnostic.
 */
final class HookRegistry
{
    /** @var array<string, array<int, callable[]>> hook => priority => callbacks (insertion order within a priority) */
    private array $actions = [];

    /** @var array<string, array<int, callable[]>> hook => priority => callbacks (insertion order within a priority) */
    private array $filters = [];

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->actions[$hook][$priority][] = $callback;
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][$priority][] = $callback;
    }

    /**
     * Calls every registered action callback for $hook, lowest priority
     * first (matching WordPress convention — priority 10 runs before 20),
     * and in registration order among callbacks sharing the same priority.
     * Every callback receives the same $args.
     *
     * If any callback throws, the exception propagates immediately and any
     * remaining callbacks for this call do not run — this method never
     * catches, logs, or swallows an exception from a listener.
     */
    public function doAction(string $hook, mixed ...$args): void
    {
        foreach ($this->orderedCallbacks($this->actions, $hook) as $callback) {
            $callback(...$args);
        }
    }

    /**
     * Calls every registered filter callback for $hook, lowest priority
     * first, threading each callback's return value into the next callback
     * as $value. $context is passed unchanged to every callback — it is
     * never part of the chain, only $value is. Returns $value unchanged if
     * no filters are registered for $hook.
     *
     * If any callback throws, the exception propagates immediately; $value
     * is never silently rolled back to whatever it was before the throwing
     * callback ran — there is no fallback behavior here.
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$context): mixed
    {
        foreach ($this->orderedCallbacks($this->filters, $hook) as $callback) {
            $value = $callback($value, ...$context);
        }

        return $value;
    }

    public function hasListeners(string $hook): bool
    {
        return ! empty($this->actions[$hook]) || ! empty($this->filters[$hook]);
    }

    /**
     * Removes all listeners (both actions and filters) for $hook, or every
     * hook if $hook is null. Exists specifically for test isolation: tests
     * that register hooks against a shared registry can reset it between
     * cases without needing a fresh instance.
     */
    public function clear(?string $hook = null): void
    {
        if ($hook === null) {
            $this->actions = [];
            $this->filters = [];

            return;
        }

        unset($this->actions[$hook], $this->filters[$hook]);
    }

    /**
     * Flattens one hook's priority => callbacks map into a single ordered
     * list: priorities ascending (lower runs first), callbacks within a
     * priority in the order they were registered.
     *
     * @param array<string, array<int, callable[]>> $registry
     * @return list<callable>
     */
    private function orderedCallbacks(array $registry, string $hook): array
    {
        if (! isset($registry[$hook])) {
            return [];
        }

        $byPriority = $registry[$hook];
        ksort($byPriority, SORT_NUMERIC);

        $ordered = [];
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as $callback) {
                $ordered[] = $callback;
            }
        }

        return $ordered;
    }
}
