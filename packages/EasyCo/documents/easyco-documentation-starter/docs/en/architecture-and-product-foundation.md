# EasyCo Platform — Architecture & Product Foundation

**Status:** Draft  
**Version:** 0.1  
**Project:** EasyCo  
**Purpose:** Foundational architecture and product specification

---

## 1. Introduction

EasyCo is a new, proprietary e-commerce platform built from the ground up.

The platform is not a continuation of Bagisto and must not be architecturally coupled to it. Bagisto, Medusa, and other existing solutions may be used as sources of ideas, patterns, and good practices, but the final architecture must be designed around EasyCo's own requirements.

The primary goal is to build a platform that is:

- lightweight;
- fast;
- modular;
- easy to extend;
- easy to maintain;
- well structured;
- suitable for both small and large stores;
- API-first;
- ready for headless architectures;
- rich in capabilities without unnecessary complexity in the core.

EasyCo should be a platform where complexity is introduced only when it is actually needed.

---

## 2. Core Philosophy

### 2.1. Small Core, Many Capabilities

The core should contain only functionality that is fundamental to the platform.

Additional capabilities should be implemented as clearly separated modules.

The goal is to avoid an architecture where every installation carries a large amount of code and dependencies regardless of which features it actually uses.

### 2.2. Simplicity Over Abstraction

We should not create an abstraction simply because it is technically possible.

Every abstraction must solve a real problem.

We prefer:

- clear models;
- clear boundaries;
- clear dependencies;
- predictable behavior;
- minimal magic.

The code should be understandable to someone who did not participate in the original implementation.

### 2.3. Performance by Design

Performance must not be an optimization phase at the end of the project.

It must be an architectural requirement from the beginning.

This means:

- minimizing database queries;
- carefully designed indexes;
- avoiding N+1 queries;
- caching where it provides real value;
- using lazy loading only when appropriate;
- controlling payload size;
- minimizing unnecessary processing;
- allowing horizontal scaling where required.

---

## 3. What EasyCo Is NOT

EasyCo will not be:

- a Bagisto fork;
- a wrapper around another e-commerce platform;
- a monolith where all functionality is inseparably coupled;
- a system built around a specific frontend;
- a system dependent on a single payment provider;
- a system where every feature requires core changes;
- an architecture where the database schema dictates the entire application design.

---

## 4. Core Architectural Principles

### 4.1. Domain-Oriented Architecture

The system should be divided by business domains rather than only by technical concerns.

Examples include:

- Catalog
- Pricing
- Cart
- Checkout
- Orders
- Customers
- Inventory
- Promotions
- Payments
- Shipping
- Tax
- Content
- Search

Each domain must have clearly defined responsibilities.

### 4.2. Loose Coupling

Modules must communicate through clear contracts.

One module must not depend on the internal implementation details of another module.

For example, Pricing should not need to know how Order stores its data.

Pricing should expose its result through a defined contract/service/API that Order can consume.

### 4.3. API-First

The API is a first-class part of the platform.

Frontend applications should not have special status compared with other clients.

The same business logic should be usable by:

- Storefront;
- Admin;
- Mobile applications;
- External integrations;
- Marketplace integrations;
- Custom applications.

---

## 5. Core and Modules

EasyCo's architecture must clearly distinguish between:

### Core

Core contains the fundamental platform mechanisms:

- application lifecycle;
- configuration;
- dependency management;
- authentication/authorization infrastructure;
- events;
- jobs;
- caching;
- persistence infrastructure;
- API infrastructure;
- logging;
- validation;
- module loading.

### Modules

Business functionality is implemented as modules.

Example:

```text
EasyCo
├── Core
├── Catalog
├── Pricing
├── Customers
├── Cart
├── Checkout
├── Orders
├── Inventory
├── Promotions
├── Payments
├── Shipping
├── Tax
├── Search
└── Content
```

This list is not final.

Modules will be defined progressively as the corresponding domains are designed.

---

## 6. Target Architecture

The target architecture should support the following:

```text
                    ┌───────────────────┐
                    │     Clients       │
                    │                   │
                    │ Storefront        │
                    │ Admin             │
                    │ Mobile            │
                    │ External Apps     │
                    └─────────┬─────────┘
                              │
                              ▼
                    ┌───────────────────┐
                    │       API         │
                    └─────────┬─────────┘
                              │
                              ▼
              ┌──────────────────────────────┐
              │        Application           │
              │                              │
              │ Catalog   Pricing   Cart     │
              │ Checkout  Orders    Customers│
              │ Inventory Promotions Payments│
              └──────────────┬───────────────┘
                             │
                             ▼
                    ┌───────────────────┐
                    │ Infrastructure    │
                    │                   │
                    │ Database          │
                    │ Cache             │
                    │ Queue             │
                    │ Search            │
                    │ Storage           │
                    └───────────────────┘
```

This does not imply that EasyCo must use microservices.

The initial implementation should prefer a **modular monolith** unless a concrete problem justifies another approach.

---

## 7. Modular Monolith as the Starting Architecture

EasyCo starts as a modular monolith.

This provides:

- simpler development;
- less infrastructure overhead;
- easier local development;
- transactions across domains when necessary;
- easier debugging;
- lower operational complexity.

At the same time, module boundaries must be clear enough that an individual domain can be extracted into a separate service in the future if that becomes necessary.

Therefore:

**Modular monolith first, distributed architecture only when justified.**

---

## 8. Database Principles

The database model must be designed around business domains.

We must not copy the database structure of Bagisto or another platform.

Special attention must be given to:

- normalization;
- indexing;
- foreign keys;
- unique constraints;
- soft deletion where appropriate;
- audit information;
- versioning;
- transactions;
- concurrency;
- performance at large catalog sizes.

The database schema must be a result of domain design, not its replacement.

---

## 9. Extensibility

EasyCo must be extensible without modifying core code.

Extensions should be possible through:

- modules;
- events;
- listeners;
- services;
- interfaces/contracts;
- adapters;
- plugins;
- configuration;
- API extensions.

Example:

```text
Core
  │
  ├── Event
  │       │
  │       └── Listener
  │
  └── Contract
          │
          └── Implementation
```

The goal is to allow third-party developers to add functionality without forking the platform.

---

## 10. Architectural Rules

From this point forward, every new feature should be evaluated against the following questions:

1. Which domain does it belong to?
2. Does it really belong in Core?
3. Can it be implemented as a module?
4. What are its dependencies?
5. How does it communicate with other domains?
6. What is its public contract?
7. Does it have database impact?
8. Does it have performance impact?
9. Can the functionality be replaced or extended?
10. How will it behave at large data volumes?

---

## 11. Development Approach

We should not design the entire platform down to the last detail before writing the first line of code.

Documentation should evolve together with the architecture.

The approach is:

```text
Architecture
      ↓
Domain
      ↓
Contracts
      ↓
Data model
      ↓
Implementation
      ↓
Tests
      ↓
Documentation refinement
```

Each major domain will receive its own document when its design begins.

---

## 12. Initial Architectural Domains

The following documents should be covered at minimum:

1. **Core Architecture**
2. **Catalog**
3. **Pricing**
4. **Customers**
5. **Cart**
6. **Checkout**
7. **Orders**
8. **Inventory**
9. **Promotions**
10. **Payments**
11. **Shipping**
12. **Tax**
13. **Search**
14. **Content**
15. **Authentication & Authorization**
16. **API Architecture**
17. **Events & Jobs**
18. **Caching**
19. **Storage**
20. **Observability**

The order is not final and will change according to dependencies between domains.

---

## 13. First Real Priority

After the foundational architecture document, the next domain to design is **Pricing**.

The reason is that the pricing model will be fundamental to a large part of the rest of the system.

It should support future scenarios such as:

- base price;
- sale price;
- customer-specific pricing;
- customer group pricing;
- quantity pricing;
- tier pricing;
- currency;
- tax-inclusive/exclusive pricing;
- catalog price rules;
- promotional adjustments;
- discounts;
- price lists;
- scheduled prices.

Pricing must not be just a `price` field on Product.

It must be a standalone domain with a clear contract.

---

## 14. Next Step

After this foundational document, detailed design begins for:

**Pricing Domain**

First we will define:

- what a Price is;
- what a Price List is;
- how the final price is determined;
- how different price sources are combined;
- how rules work;
- how customer/context-specific prices work;
- how currency and tax are handled;
- the database model;
- service contracts;
- API contracts;
- caching strategy;
- testing strategy;
- how Pricing communicates with Catalog, Cart, Checkout, and Orders.

This will be our first true **domain design** and will serve as a model for designing the remaining modules.
