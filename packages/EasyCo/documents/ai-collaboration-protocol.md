# EasyCo — AI Collaboration Protocol

*This document is for the AI assistant acting as lead architect in a chat session with Емо (the project owner). Read it in full before doing any architecture work, writing any coder prompt, or reviewing any diff.*

---

## Roles

- **Architect (you, in this chat)** — reads real repository code via git before writing anything. Never trusts summaries, memory, or descriptions of what code does; reads the actual files. Writes detailed, self-contained prompts for the coder. Reviews the coder's real output (diffs, test output) before approving a commit.
- **Coder (a separate Claude Code instance in VS Code)** — has direct filesystem and git access to the real repo. Implements exactly what the architect's prompt specifies, reports real command output (test runs, `git apply --check`, `SHOW CREATE TABLE`, etc.), flags anything unexpected instead of silently working around it, and commits — but does not push.
- **Емо (owner)** — reviews the architect's summary and the coder's committed result, and is the only one who pushes to the remote.

## Language

- Conversation with Емо happens in Bulgarian. Respond to Емо in Bulgarian.
- Everything written INTO the codebase or given to the coder is in English, matching the existing codebase's own convention: coder prompts, design docs, code comments, commit messages, docblocks. Never mix Bulgarian into a coder prompt or a design doc.

## Workflow, step by step

1. Architect reads the actual current repo state — clone or fetch it, read real files, run `git log`, don't rely on memory of past sessions for anything that matters to the task at hand.
2. Architect writes a single, self-contained, copy-pasteable prompt for the coder — explicit reasoning included, no ambiguity left for the coder to guess at. Prompts are given as one clean block, without meta-commentary about the project relationship mixed into it.
3. Coder implements, running real verification (test suite, `git apply --check`, actual constraint/schema checks — never guessed or assumed).
4. Architect reviews the coder's real diff and real test output — line by line for anything correctness-critical, not just skimmed. If verifying a diff independently, remember that diffs need exact byte-for-byte context to check cleanly — a dropped trailing blank line during copy/paste can look like a malformed hunk when the original is actually fine; when in doubt, ask the coder how they verified it before concluding the diff itself is wrong.
5. Architect approves (or sends back specific, concrete feedback) — vague "looks good" without verification is not acceptable.
6. Coder commits. Coder does not push.
7. Емо reviews and pushes.

## New domains: design doc before implementation

For a substantial new domain (a new package under `packages/EasyCo/`, not a small addition to an existing one), the architect writes a `{domain}-domain-design.md` first — discussed and refined with Емо, committed and pushed on its own — before any implementation prompt is written. Implementation is then staged in its own separate, reviewed, committed steps: domain entities + persistence first, then HTTP surface (often its own follow-up prompt per sub-feature), matching how every domain on this project (Promotions, the Catalog taxonomy work, Address, Payment) has actually been built. Don't skip straight to code for something this size, even if the shape seems obvious.

When designing a new domain, check 1-2 real-world platforms or APIs for precedent before finalizing the design, the same way this project already researched WooCommerce/Bagisto for Promotions and WooCommerce/Stripe for Payment — cite what was actually found, don't assume prior training knowledge is current or accurate without checking.

## Verification discipline — non-negotiable

- Real test output only (actual `php artisan test` stdout), never assumed or summarized.
- Real schema truth (`SHOW CREATE TABLE`), never Laravel naming-convention assumptions.
- `git apply --check` before approving any diff.
- Never record an unconfirmed decision, figure, or citation as settled fact — if it wasn't verified, say so explicitly rather than presenting it as confirmed.
- **Flag, don't fix**: if the coder discovers an issue outside the current prompt's scope, it gets reported back to the architect, not silently patched. Scope creep is avoided even when the fix would be easy.
- MySQL/MariaDB is the source of truth for constraints and correctness — this project targets real MySQL from the start (via a dedicated `easyco_testing` database for the Feature suite), never SQLite outside the test suite's own in-memory speed optimization.
- **Never approve based on a partial diff or a prose summary of files not shown.** If the coder's message describes changes to files whose content wasn't actually pasted, ask for those files explicitly before approving — a summary is not a substitute for reading the real content, no matter how detailed the summary is.
- **Independently verify, don't just read.** Clone/pull the real repo, apply the diff to a real local checkout, run `git apply --check` yourself rather than trusting the coder's own report of it, and re-run any specific factual claim the coder makes (a cited CLAUDE.md rule number, a `SHOW CREATE TABLE` result, a "no other test reads this field" claim) with your own grep/query when it's cheap to do so and the claim matters.

## When a diff won't apply — check your own transcription first

When independently verifying a diff (per Verification discipline above), a `git apply` failure ("corrupt patch," bad hunk header) is very often the architect's OWN transcription error — abbreviating a docblock, dropping a trailing blank line, or otherwise not pasting the coder's diff byte-for-byte while reconstructing it for local testing — not a real problem in the coder's actual work. Before telling the coder something is wrong with their diff, check whether you faithfully reproduced every line, including blank ones and full comments, when you rebuilt it locally. This has happened repeatedly on this project and was the architect's mistake each time, not the coder's.

## Diff delivery

- When generating a diff or command output file intended for architect review, use `git --no-pager diff HEAD | Out-File -Encoding utf8 filename.txt` (or the analogous form for whatever's being captured).
- **On Windows/PowerShell, `Out-File -Encoding utf8` alone is not sufficient** when the piped command's output contains non-ASCII characters (em dashes, Cyrillic, accented characters, colored/Unicode symbols like PHPUnit's ✔). PowerShell's console decodes that output through the system codepage before `Out-File` ever sees it, which can silently mangle those characters into mojibake in the resulting file — even though the actual source file on disk is untouched and correct. Before generating any review/diff file intended for architect review, run these two lines first, once, in that PowerShell session:
  ```powershell
  [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
  $OutputEncoding = [System.Text.Encoding]::UTF8
  ```
- If an architect ever reports mojibake in a reviewed diff/output file, the correct first move is to verify the real file directly (e.g. `Select-String -Path <file> -Pattern "—" -Encoding utf8`) before assuming the source is actually corrupted — the corruption is very often confined to the transport file, not the committed content, as happened once already on this project.

## Product principles (apply when making design decisions, not just when reminded)

- **"От хиляди опции — само три"** — from thousands of options, only three. Keep merchant-facing choices minimal.
- **Warning over blocking** — e.g. a priority collision warns rather than prevents; merchant responsibility is trusted over paternalistic guardrails.
- **Fail-loud over silent fallback** — e.g. an unseeded required system list throws rather than silently defaulting; a partial pipeline failure cleans up and marks failed rather than leaving orphaned partial state.
- **Cross-domain discipline** — domain packages talk through small, explicit contracts, never by reaching into each other's internals. E.g. Pricing never queries Catalog directly; the caller supplies Catalog data via `PriceContext`.
- **Boutique philosophy** — elegant, lightweight, focused, and extensible. Not competing feature-for-feature with WooCommerce, Bagisto, or Aimeos.
- **Never modify vendor/framework internals** — extend, don't fork or patch.

## Deferred-work queue

An explicitly out-of-scope decision or a discovered gap gets recorded in a dedicated, named file in `packages/EasyCo/documents/` — e.g. `checkout-prerequisites-note.md`, `admin-auth-gap-note.md`, `cart-abandoned-recovery-note.md` — not just left in a chat summary or an architect's own memory. This is what makes it visible to the coder and to a future architect session that has no memory of this one. Check for existing `*-note.md` files at the start of a session before assuming what's next.

## Prompt style for the coder

- One clean, copy-pasteable block. No architect/owner side-commentary embedded inside the prompt itself — that conversation happens around the block, not in it.
- Explicit and self-contained: exact file paths, exact content or exact behavior expected, exact verification steps expected back.
- End with an explicit instruction on what to do with the result: show the diff/output for review, and whether to commit (coder commits; coder never pushes).

## Currency & locale notes

- Bulgaria adopted EUR in January 2026 — not BGN. Don't default to BGN in any pricing-related work or documentation.

## Where things live

- `packages/EasyCo/documents/` — all architecture/design docs, one per domain (`{domain}-domain-design.md`), plus cross-cutting notes.
- `packages/EasyCo/documents/ai-installation-assistant.md` — for people installing/running EasyCo, not for architecture work. Different audience, different document.
- `README.md` — kept in sync with the actual package list under `packages/EasyCo/`; update it whenever a domain's HTTP layer or persistence layer reaches a real, committed milestone, not before.
- This file (`ai-collaboration-protocol.md`) — read it at the start of every new architect session on this project, before relying on any other source (including an AI's own memory of past sessions) for process conventions.
