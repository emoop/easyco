# EasyCo

**A lightweight, modular, API-first e-commerce platform built from the ground up.**

EasyCo is a new e-commerce platform designed with a focus on performance, modularity, extensibility, and long-term maintainability.

The project is inspired by ideas and lessons from established e-commerce platforms such as Bagisto and Medusa, but it is **not a fork, wrapper, or continuation of either platform**.

## Documentation

Documentation is maintained in two languages:

- 🇬🇧 English — [`docs/en/`](docs/en/)
- 🇧🇬 Bulgarian — [`docs/bg/`](docs/bg/)

The English documentation is the primary public/GitHub-facing version. The Bulgarian documentation is maintained in parallel for the project team.

### Architecture

- [Architecture & Product Foundation — English](docs/en/architecture-and-product-foundation.md)
- [Архитектура и продуктова основа — Български](docs/bg/architecture-and-product-foundation.md)

## Architecture Direction

EasyCo starts as a **modular monolith** with clearly separated business domains.

The initial architectural direction includes:

- Core
- Catalog
- Pricing
- Customers
- Cart
- Checkout
- Orders
- Inventory
- Promotions
- Payments
- Shipping
- Tax
- Search
- Content

The architecture is designed so that individual domains can be extracted into separate services later if a real business or technical requirement justifies it.

> **Modular monolith first, distributed architecture only when justified.**

## Principles

EasyCo follows several core principles:

- Small core, many capabilities
- Domain-oriented architecture
- Loose coupling
- API-first
- Performance by design
- Explicit contracts
- Extensibility without core modification
- Simplicity over unnecessary abstraction
- Documentation evolving together with the implementation

## Project Status

The project is currently in the **architecture and domain-design phase**.

The first major domain planned for detailed design is **Pricing**.

## Repository Structure

```text
easyco/
├── docs/
│   ├── en/
│   └── bg/
├── src/
├── tests/
├── README.md
└── ...
```

The exact source structure will be defined as implementation begins.

## Documentation Policy

Every major architectural or domain document should, whenever practical, have:

1. an English version for GitHub and external contributors;
2. a Bulgarian version for internal/project use.

The two versions should describe the same architecture and remain synchronized.

## License

License information will be added when the project's licensing decision is finalized.
