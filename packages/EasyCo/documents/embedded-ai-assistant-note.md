# Embedded AI Assistant — Setup/Config Helper (local note, later priority)

Idea: a small AI assistant embedded in EasyCo itself, helping merchants
with installation, configuration, and explaining platform features with
simple examples ("Леснотийка" philosophy - make it easy, don't make the
merchant hunt for how to do something).

Technically feasible via API calls to an LLM (Claude), passing current
store-configuration context. NOT designed yet - this note exists so the
idea and its constraints aren't lost before it's prioritized.

Key architectural constraint, non-negotiable when this is designed: the
assistant must NEVER write directly to the database or bypass the
domain layer - any configuration action it suggests must go through the
same domain methods (Product::createSimple(), PriceList domain methods,
etc.) a human using the admin UI would use, never raw Eloquent/SQL.
This is the exact same discipline already flagged for Filament/admin
tooling in performance-and-channel-strategy.md - an AI assistant is not
exempt from it. Default posture: the assistant suggests, the merchant
confirms - never autonomous action without confirmation, matching every
other "no silent action" pattern already established in this project.

Cost management options to consider when this is designed (not decided
here):
- Use a smaller/cheaper model (e.g. Claude Haiku 4.5) for simple
  explanatory Q&A rather than a larger model by default.
- Let the merchant supply their own API key (opt-in), same pattern as
  the Releva.ai integration discussion - shifts cost to whoever wants
  the feature rather than baking it into every EasyCo install by
  default.
- Static documentation/FAQ content covers most common
  installation/configuration questions cheaply; AI escalation reserved
  for adaptive, context-specific help (e.g. explaining a specific
  error given the merchant's actual current configuration).

Dependency note: this only makes sense once the features it would
explain are themselves stable (PriceLists, Media pipeline, Cart, etc.)
- explicitly a LATER priority, not blocking anything currently in the
queue, and not blocked by anything either - just sequenced after the
core domains it would need to explain actually exist.
