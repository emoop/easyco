# Commerce Knowledge Layer — Concept Notes

**Status:** v1.0 — planning notes only; nothing in this document is implemented, and no domain has been designed. It exists to record an idea and its guardrails before they're lost, the same way `performance-and-channel-strategy.md` records constraints before its work starts.
**Builds on:** the Catalog/Pricing isolation boundary already established and proven in this codebase (`catalog-domain-design.md`, `pricing-domain-design.md`) and the provenance discipline already implemented for `attribute_signature` (`catalog-domain-design.md` §3.1/§7).

---

## 1. Where this idea comes from

It originates from a conversation about SEO's shift away from keyword density toward structured, useful context for AI-driven discovery — ChatGPT, AI shopping agents, and similar tools increasingly answer "does this fit small?" or "what is this made of?" by reasoning over structured product context rather than crawling and ranking pages the way a traditional search engine does.

The real merchant workflow this project is built around already produces exactly that kind of context — informally, and today it is simply lost:

```
photograph new stock
    → Instagram post / video
    → customer questions in Messenger / comments
    → merchant's answer
    → (nothing captured; the knowledge evaporates)
```

The core idea: a future "Commerce Knowledge" capability that captures product-related information beyond price/stock/images — facts, measurements, fit notes, merchant observations, customer Q&A, reviews, brand/collection context — and feeds it back into product/brand/collection pages, AI agent discoverability, and SEO *organically*, rather than requiring anyone to write separate "SEO content" by hand.

---

## 2. Architectural boundary — the main risk this note exists to flag

**Knowledge must not become a property baked directly into the `Product` aggregate.** This would repeat, precisely, the mistake already avoided with Pricing: `Product` knows nothing about price — it exposes `priceableId()` and Pricing resolves against that id from the outside, as a separate package with its own persistence. A future Knowledge domain must follow the identical pattern:

- Reference `Product` / `Variation` / `Brand` / `Collection` by id, from the outside.
- Never be modeled as fields, properties, or value objects living on `Product` itself.
- Live in its own package, with its own repository/contract shape, resolvable the same way `PriceResolver` is resolvable — not reachable by `Product` calling out to it.

This is stated explicitly here as a guardrail for whoever eventually designs the real domain, precisely because "just add a `notes` field to the product" or "just add a `knowledge` relation" will be the path of least resistance in the moment, and it is the wrong one.

---

## 3. The provenance model

This is the concept's strongest, most concrete part. Every piece of knowledge must be tagged with its provenance category, and these categories must never be silently merged:

| Category | Example |
|---|---|
| **FACT** | a measured dimension |
| **MERCHANT OBSERVATION** | "runs slightly small at shoulders" |
| **CUSTOMER GENERATED** | a review, a customer's question |
| **AI GENERATED** | any inference or summary an AI produces |

This mirrors a separation this codebase has already established independently: `attribute_assignments` (source of truth, explicitly asserted) vs. `attribute_signature` (a derived projection computed *from* that source of truth, never itself a source of truth — `catalog-domain-design.md` §3.1). The same underlying principle applies here — **never conflate a fact with an inference from that fact** — arrived at independently for a different domain, which is a reasonable signal that it's a real principle for this project rather than a coincidence specific to signatures.

Provenance tagging is not a nice-to-have metadata field; it is the mechanism that keeps an AI-generated summary from silently being presented, or silently feeding a customer-facing agent, as if it were a measured fact.

---

## 4. Unresolved, not yet solved — the capture workflow

**The hard problem here is not the schema. It is the capture workflow**, and this note does not solve it:

- Manual merchant entry (someone sitting down and typing structured fit notes) doesn't scale past a handful of products — it's exactly the kind of separate "SEO content" work this idea is trying to avoid recreating under a different name.
- AI-extraction from Messenger/Instagram chat history — having an AI read a comment thread and produce a structured fit-note or measurement record — reintroduces exactly the hallucination risk the provenance model in §3 exists to prevent. An AI summarizing a merchant's answer and an AI inventing a plausible-sounding answer look identical in the output; only the extraction process determines which one actually happened.

This is a closed loop as of this note: the provenance model assumes trustworthy input, but the only workflow that scales to real merchant volume is AI extraction, which is the one input source the provenance model exists to be skeptical of. Nobody has designed a way out of this yet. Flagging it prominently here so it isn't quietly assumed solved by whoever picks this up next.

---

## 5. Legal/compliance flag

If AI-generated or AI-extracted "FACT" fields ever feed a customer-facing AI agent's direct answers, an incorrect "fact" is a real **EU consumer-protection / misleading-advertising exposure** — not merely a UX quality issue. A shopping agent stating a wrong measurement or a wrong material as though it were verified is a materially different risk than a bad search-ranking result.

`performance-and-channel-strategy.md` §3 already establishes the precedent for how this project handles similar AI-content risk (the GDPR consent-gate requirement for tracking). The same posture applies here: this is not a detail to leave implicit and hope the eventual implementation gets right — it needs an explicit disclosure/verification gate wherever AI-generated or AI-extracted content reaches a customer-facing surface, designed at the same time as the domain itself, not bolted on afterward.

---

## 6. Explicitly not in scope right now

No domain design, no schema, no code exists for any of this. It sits in the deferred queue behind:

1. Real Pricing persistence (`vertical-slice-notes.md` §5).
2. Real SKU/barcode generation (`catalog-domain-design.md` §6).

This note exists only so the idea and its guardrails (§2's isolation boundary, §3's provenance model, §4's unsolved capture-workflow problem, §5's compliance flag) aren't lost before it's actually prioritized.
