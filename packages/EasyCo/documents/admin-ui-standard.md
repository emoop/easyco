# EasyCo — admin form standard (the owner's rule, 2026-11-03)

The merchant must orient himself easily: the same things look and behave the same everywhere. Every prompt that touches an admin form MUST follow this standard and the coder must check it before reporting.

1. **Explanations are hidden.** An explanation of a field is never a paragraph under the field. It is the info icon next to the label with the text in a tooltip (hover, keyboard focus, tap): `App\Filament\Design\InfoHint::make($text, $field)` via `->afterLabel(...)` (HINT-1). The text stays in the page, linked by `aria-describedby`. Only a warning the merchant must always see (a password rule, a legal text, a destructive-action warning) stays visible, and the coder lists such cases instead of deciding alone.
2. **Fields are two per row.** Short fields (selects, numbers, short text, colour pickers, toggles) sit TWO per row in a compact grid (two columns from the medium width, one column on a phone). Always full width: titles / headings, long text and text areas, links and URLs, pickers of pictures and blocks, repeaters and lists, preview areas.
3. **Compact look.** The same compact size as the «Дизайн» screen: smaller fonts and controls than Filament's defaults, sections tight, no empty space. A group of related fields (for example «Показвай, когато…» or a style panel) is one section with its fields in that two-column grid.
4. **Same order, same names, same components.** Reuse an existing component or section instead of building a look-alike; the same setting has the same label and position everywhere (size, alignment, colour, border, radius, height). A new field type is a last resort (stop and ask).
5. **Live preview** next to the form wherever the result is visual.
6. **Texts** in bg and en, sentence case, labels without a final period.
7. **Check in the browser** at 1280px and 360px (screenshots read by the coder): no helper paragraphs under fields, two per row where allowed, one column on a phone, no horizontal scroll.
