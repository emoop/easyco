# EasyCo — admin form standard (the owner's rule, 2026-11-03; point 2 amended 2026-11-04)

The merchant must orient himself easily: the same things look and behave the same everywhere. Every prompt that touches an admin form MUST follow this standard and the coder must check it before reporting.

1. **Explanations are hidden.** An explanation of a field is never a paragraph under the field. It is the info icon next to the label with the text in a tooltip (hover, keyboard focus, tap): `App\Filament\Design\InfoHint::make($text, $field)` via `->afterLabel(...)` (HINT-1). The text stays in the page, linked by `aria-describedby`. Only a warning the merchant must always see (a password rule, a legal text, a destructive-action warning) stays visible, and the coder lists such cases instead of deciding alone.
2. **Field layout follows the real width of the container, not a fixed rule.**
   - In a WIDE container (a full-width admin page or a wide form: roughly 40rem and more) short fields (selects, numbers, short text, colour pickers, toggles) sit TWO per row in a compact grid; on a phone one column.
   - In a NARROW container (the side panels of the «Дизайн» screen, e.g. the home zone panel, which takes about a quarter of the screen) fields sit ONE per row, because two per row makes labels overlap and controls shift (the owner saw this in the real admin).
   - Always full width: titles / headings, long text and text areas, links and URLs, pickers of pictures and blocks, repeaters and lists, preview areas.
   - The coder decides by MEASURING the container in the browser and says in the report which rule applied. If labels overlap or controls shift at any width the standard is wrong for that place: fix the layout, do not defend the rule.
3. **Compact look.** The same compact size as the «Дизайн» screen: smaller fonts and controls than Filament's defaults, sections tight, no empty space. A group of related fields (for example «Показвай, когато…» or a style panel) is one section with its fields in the grid allowed by point 2.
4. **Same order, same names, same components.** Reuse an existing component or section instead of building a look-alike; the same setting has the same label and position everywhere (size, alignment, colour, border, radius, height). A new field type is a last resort (stop and ask).
5. **Live preview** next to the form wherever the result is visual.
6. **Texts** in bg and en, sentence case, labels without a final period.
7. **Check in the browser** at 1280px and 360px (screenshots read by the coder), INCLUDING the narrow panels at their real width: no helper paragraphs under fields, no overlapping labels, no shifting controls, one column on a phone, no horizontal scroll.
8. **Documents cannot catch everything.** The owner's eye on the real screen decides; an exception he asks for is valid and is recorded here (2026-11-04: the home zone panel is one field per row, including the Hero slider; the same later for the carousel, tiles and grids if they look cramped).
