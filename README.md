<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

# EasyCo

EasyCo is a modular Laravel commerce platform. Rather than one monolithic application, each business domain — Catalog, Pricing, and (planned) Inventory, Orders, Cart — lives in its own independently developed, independently testable package under `packages/EasyCo/`, communicating through small, explicit contracts rather than shared internal state.

## Architecture note

This project was originally scaffolded on top of [Bagisto](https://bagisto.com/). It was rebuilt from a clean Laravel installation instead: integrating our domain packages deeply enough into Bagisto would have required either modifying Bagisto's core directly or duplicating large portions of its admin Blade views into our own packages. Both options work against this project's core philosophy — well-isolated, independently testable domain packages with a small, explicit public surface — so the Bagisto scaffold was set aside in favor of building that architecture directly on a plain Laravel app.

## Packages

| Package | Description | Design doc |
|---|---|---|
| [`packages/EasyCo/Pricing`](packages/EasyCo/Pricing) | Currency-aware, tax-aware `Price` value object and price-resolution contracts, shared across Catalog, Cart, and Orders. | [pricing-domain-design.md](packages/EasyCo/documents/pricing-domain-design.md) |
| [`packages/EasyCo/Catalog`](packages/EasyCo/Catalog) | The Product/Variation aggregate — attributes, variation axes, media and size-guide references — shared across Web, POS, Social, and AI channels. | [catalog-domain-design.md](packages/EasyCo/documents/catalog-domain-design.md) |
| [`packages/EasyCo/Extensibility`](packages/EasyCo/Extensibility) | A WordPress-style hooks system (actions/filters). Foundational and framework-agnostic — no dependency on Catalog, Pricing, or Laravel in its core logic; consumed only by the `app/` layer, never by domain packages directly. | [extensibility-design-and-hooks.md](packages/EasyCo/documents/extensibility-design-and-hooks.md) |
| [`packages/EasyCo/OperationalSales`](packages/EasyCo/OperationalSales) | The record-keeping side of a sale — `Client`, `Transaction`, immutable `SaleLine`, `InstallmentPlan`. Domain layer and persistence layer (migrations, Eloquent repositories) both implemented. | [operational-sales-domain-design.md](packages/EasyCo/documents/operational-sales-domain-design.md) |

See [vertical-slice-notes.md](packages/EasyCo/documents/vertical-slice-notes.md) for how Catalog and Pricing are wired together end-to-end today, and what's still temporary.

## Setup

MySQL/MariaDB is required — this project doesn't run against SQLite outside its own automated test suite (which uses an in-memory database purely for speed). Create an empty database on your server first; `migrate` creates the tables, not the database itself.

```bash
composer install

cp .env.example .env
php artisan key:generate
# .env.example defaults to DB_CONNECTION=sqlite — change it to mysql and fill in
# DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD

php artisan migrate

npm install && npm run build
# compiles frontend assets — required for the default homepage to load (uses Vite)
```
