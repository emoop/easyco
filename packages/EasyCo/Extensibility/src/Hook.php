<?php

namespace EasyCo\Extensibility;

/**
 * The ergonomic, Laravel-facing entry point to the hook system — meant to
 * be called from app/ code (controllers, listeners, service providers),
 * never from pure domain packages (Catalog, Pricing, ...), which must stay
 * framework-agnostic. A domain package that wants to be hookable should
 * depend on a HookRegistry instance directly, not this facade.
 *
 * This is the ONLY class in this package that touches Laravel: every
 * method resolves EasyCo\Extensibility\HookRegistry as a singleton from
 * the container via the global app() helper, then delegates.
 */
final class Hook
{
    public static function action(string $name, callable $callback, int $priority = 10): void
    {
        app(HookRegistry::class)->addAction($name, $callback, $priority);
    }

    public static function filter(string $name, callable $callback, int $priority = 10): void
    {
        app(HookRegistry::class)->addFilter($name, $callback, $priority);
    }

    public static function fire(string $name, mixed ...$args): void
    {
        app(HookRegistry::class)->doAction($name, ...$args);
    }

    public static function apply(string $name, mixed $value, mixed ...$context): mixed
    {
        return app(HookRegistry::class)->applyFilters($name, $value, ...$context);
    }
}
