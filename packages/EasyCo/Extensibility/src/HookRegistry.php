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
    /**
     * @param \Psr\Log\LoggerInterface|null $logger where a failed action listener is reported (Laravel's container autowires its logger);
     *                                               without one the failure goes to error_log(). PSR-3 only: this package stays framework-agnostic.
     */
    public function __construct(private readonly ?\Psr\Log\LoggerInterface $logger = null)
    {
    }

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
     * CONTAINMENT (actions only): every listener runs inside its own try/catch. A listener that throws is logged at `error` level
     * (hook name, a description of the listener, exception class, message, file:line — NEVER the hook arguments, they can hold customer
     * or order data) and the next listener still runs; the caller never sees the exception. An action means "something happened,
     * react": the fact is already committed, so a failing reaction must not break or undo it. Same rule for every hook, in every
     * environment. Order and priority are unchanged.
     */
    public function doAction(string $hook, mixed ...$args): void
    {
        foreach ($this->orderedCallbacks($this->actions, $hook) as $callback) {
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                $this->reportFailure($hook, $callback, $e);
            }
        }
    }

    /**
     * Calls every registered filter callback for $hook, lowest priority
     * first, threading each callback's return value into the next callback
     * as $value. $context is passed unchanged to every callback — it is
     * never part of the chain, only $value is. Returns $value unchanged if
     * no filters are registered for $hook.
     *
     * FILTERS ARE NOT CONTAINED, on purpose: a filter produces a value the core
     * write depends on (the built-in slug / SKU generators are themselves
     * filters), so a failing filter must fail that write loudly and roll it back.
     * If any callback throws, the exception propagates immediately; $value is
     * never silently rolled back to what it was before the throwing callback —
     * there is no fallback behavior here.
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

    /** Logs a failed action listener. Context deliberately holds no hook arguments. */
    private function reportFailure(string $hook, callable $callback, \Throwable $e): void
    {
        $context = [
            'hook' => $hook,
            'listener' => self::describe($callback),
            'kind' => 'action',
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
        ];

        try {
            if ($this->logger !== null) {
                $this->logger->error('Hook listener failed; the core write stands.', $context);

                return;
            }

            error_log('Hook listener failed; the core write stands. '.json_encode($context));
        } catch (\Throwable) {
            // reporting must never break the caller either
        }
    }

    private static function describe(callable $callback): string
    {
        try {
            if ($callback instanceof \Closure) {
                $r = new \ReflectionFunction($callback);

                return 'Closure@'.$r->getFileName().':'.$r->getStartLine();
            }

            if (is_array($callback)) {
                return (is_object($callback[0]) ? $callback[0]::class : (string) $callback[0]).'::'.$callback[1];
            }

            if (is_object($callback)) {
                return $callback::class;
            }

            return (string) $callback;
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
