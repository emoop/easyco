// The rules of the menu editor (MENU-2, MENU-3) as plain functions on a FLAT list of items, each with a `depth` (0-2). No DOM, no Alpine: the editor component
// (admin-menu-editor.js) calls these, and tests/Feature/Admin/Js/menu-editor-core.mjs runs them under node. The server checks everything again
// (App\Content\Menus\MenuFlat + MenuValidator); these rules only keep the list valid while a person drags and clicks.
//
// The shape of a mega menu in the flat list (MENU-3): a top-level item with `submenu: 'mega'` and `mega_columns: N` (1-4) is followed by EXACTLY N `column` entries at depth 1
// (a column's `label` is its optional heading), each followed by its links at depth 2. `reconcile()` brings any list to that shape after every change.

export const MAX_DEPTH = 2;
export const STEP = 30; // px of horizontal drag per level
export const MAX_COLUMNS = 4;
export const DEFAULT_COLUMNS = 3;

let uidCounter = 0;

/** A fresh uid for an item the rules create themselves (columns). */
export function newUid() {
    uidCounter += 1;

    return 'k' + uidCounter;
}

export function blankColumn(label = null) {
    return {
        uid: newUid(), type: 'column', depth: 1, label, url: null, target_id: null, submenu: 'none', html_block_id: null, mega_columns: null, badge_text: null, badge_color: null,
        new_tab: false, visible: true, target_name: null, target_url: null, target_status: 'ok',
    };
}

/** The list with a valid shape of depths: the first item at depth 0, every item at most one level deeper than the one before it, never deeper than MAX_DEPTH. */
export function normalize(items) {
    const out = [];

    items.forEach((item, i) => {
        const depth = i === 0 ? 0 : Math.max(0, Math.min(item.depth, out[i - 1].depth + 1, MAX_DEPTH));
        out.push({ ...item, depth });
    });

    // A top-level item that has sub-items needs a submenu to show them: "nothing" becomes a dropdown.
    out.forEach((item, i) => {
        if (item.depth === 0 && item.submenu === 'none' && out[i + 1] && out[i + 1].depth > 0) {
            item.submenu = 'dropdown';
        }
    });

    return out;
}

/** The index after the item and all its sub-items. */
export function blockEnd(items, index) {
    let end = index + 1;

    while (end < items.length && items[end].depth > items[index].depth) {
        end++;
    }

    return end;
}

function relativeHeight(items, index) {
    let max = 0;

    for (let i = index + 1; i < blockEnd(items, index); i++) {
        max = Math.max(max, items[i].depth - items[index].depth);
    }

    return max;
}

/** The index of the item this one hangs under (the nearest item before it that is one level shallower), or -1. */
export function parentOf(items, index) {
    for (let i = index - 1; i >= 0; i--) {
        if (items[i].depth === items[index].depth - 1) {
            return i;
        }

        if (items[i].depth < items[index].depth - 1) {
            return -1;
        }
    }

    return -1;
}

const clampColumns = (value) => {
    const n = Number(value);

    return Number.isInteger(n) && n >= 1 && n <= MAX_COLUMNS ? n : DEFAULT_COLUMNS;
};

/**
 * Brings the list to the valid shape of menus: depths, and above all the mega menus (exactly N columns after a mega item, every link in a column, a column nowhere else).
 * `report`, when given, collects what the change had to move: `report.moved[uid of the mega item] = { columns, links }` when columns were merged into the last one.
 */
export function reconcile(items, report = null) {
    const list = normalize(items);
    const out = [];
    let i = 0;

    while (i < list.length) {
        const end = blockEnd(list, i);
        out.push(...fixBlock(list.slice(i, end), report));
        i = end;
    }

    return normalize(out);
}

function plainTop(item) {
    return { ...item, submenu: item.depth === 0 ? item.submenu : 'none', mega_columns: item.submenu === 'mega' && item.depth === 0 ? item.mega_columns : null, html_block_id: item.submenu === 'mega' && item.depth === 0 ? item.html_block_id : null };
}

function fixBlock(block, report) {
    let top = block[0];
    let rest = block.slice(1);

    // A column that is not under a mega item (it was dragged out, or its mega item changed): dissolved, its links take its place.
    if (top.type === 'column') {
        return reconcile(rest.map((item) => ({ ...item, depth: Math.max(0, item.depth - 2) })), report);
    }

    if (top.submenu !== 'mega') {
        const flat = [];
        let inColumn = false;

        rest.forEach((item) => {
            if (item.type === 'column') {
                inColumn = true;

                return;
            }

            if (item.depth === 1) {
                inColumn = false;
            }

            flat.push({ ...plainTop(item), depth: inColumn && item.depth === 2 ? 1 : item.depth, ...(item.depth > 0 ? { submenu: 'none', mega_columns: null, html_block_id: null } : {}) });
        });

        return [{ ...plainTop(top), mega_columns: null, html_block_id: null }, ...flat];
    }

    const wanted = clampColumns(top.mega_columns);
    const groups = [];
    let pending = [];

    rest.forEach((item) => {
        if (item.type === 'column') {
            groups.push({ column: { ...item, depth: 1, submenu: 'none', mega_columns: null, html_block_id: null, badge_text: null, badge_color: null }, links: [] });

            return;
        }

        const link = { ...item, depth: 2, submenu: 'none', mega_columns: null, html_block_id: null };

        if (groups.length === 0) {
            pending.push(link);
        } else {
            groups[groups.length - 1].links.push(link);
        }
    });

    if (groups.length === 0) {
        groups.push({ column: blankColumn(), links: [] });
    }

    // links that came before the first column (a link dropped right under the mega item) join the start of the first column
    groups[0].links = [...pending, ...groups[0].links];
    pending = [];

    while (groups.length < wanted) {
        groups.push({ column: blankColumn(), links: [] });
    }

    if (groups.length > wanted) {
        const extra = groups.splice(wanted);
        const moved = extra.reduce((sum, group) => sum + group.links.length, 0);
        groups[groups.length - 1].links.push(...extra.flatMap((group) => group.links));

        if (report) {
            report.moved = report.moved || {};
            report.moved[top.uid] = { columns: extra.length, links: moved, into: groups.length };
        }
    }

    top = { ...top, mega_columns: wanted, submenu: 'mega' };

    return [top, ...groups.flatMap((group) => [group.column, ...group.links])];
}

/** The depth a drop at horizontal offset `dx` asks for, clamped to what the previous item allows (and to what the block's own sub-items leave room for). */
export function clampDepth(wanted, previousDepth, height = 0) {
    const room = MAX_DEPTH - height;

    return Math.max(0, Math.min(wanted, previousDepth === null ? 0 : previousDepth + 1, room));
}

export function depthFromDrag(startDepth, dx) {
    return startDepth + Math.round(dx / STEP);
}

/** The index of the top-level item that holds this one (itself when it is top-level). */
function topOf(items, index) {
    let i = index;

    while (i > 0 && items[i].depth > 0) {
        i--;
    }

    return i;
}

/**
 * Moves the item at `from` (with its sub-items) to where a drag put it. `to` is the index the dragged row has among the rows in the list AS IT WAS during the drag
 * (the sub-items are still in their old places while the row is dragged), `wantedDepth` the depth the horizontal offset asked for.
 *
 * Returns the same array object when the move is refused (a column dragged out of its mega item).
 */
export function moveBlock(items, from, to, wantedDepth, report = null) {
    const end = blockEnd(items, from);
    const block = items.slice(from, end);
    const height = relativeHeight(items, from);
    // The sub-items of the block that sit before the drop point leave the list too, so the drop point moves up by their number.
    const subIndexes = new Set(Array.from({ length: end - from - 1 }, (_, k) => from + 1 + k));
    let before = 0;

    items.filter((_, i) => i !== from).forEach((_, i) => {
        const original = i < from ? i : i + 1;

        if (i < to && subIndexes.has(original)) {
            before++;
        }
    });

    const rest = items.filter((_, i) => i < from || i >= end);
    let at = Math.max(0, Math.min(to - before, rest.length));

    if (block[0].type === 'column') {
        return moveColumnTo(items, rest, block, from, at, report);
    }

    let depth = clampDepth(wantedDepth, at === 0 ? null : rest[at - 1].depth, height);

    // A top-level block is never dropped INTO another block (that would hand the rest of it to the dropped item): it goes to the nearest top-level boundary.
    if (depth === 0 && at < rest.length && rest[at].depth > 0) {
        let up = at;

        while (up > 0 && rest[up].depth > 0) {
            up--;
        }

        let down = at;

        while (down < rest.length && rest[down].depth > 0) {
            down++;
        }

        at = at - up <= down - at ? up : down;
        depth = 0;
    }

    const shift = depth - block[0].depth;
    const moved = block.map((item) => ({ ...item, depth: item.depth + shift }));

    return reconcile([...rest.slice(0, at), ...moved, ...rest.slice(at)], report);
}

/** A column moves only among the columns of its own mega item: the drop snaps to the nearest column boundary of that item, anywhere else it is refused. */
function moveColumnTo(items, rest, block, from, at, report) {
    const mega = topOf(items, from);
    const megaEndOld = blockEnd(items, mega);
    const megaStart = mega; // the same index in `rest` (nothing before it moved)
    const megaEnd = megaEndOld - block.length;
    const boundaries = [];

    for (let i = megaStart + 1; i < megaEnd; i++) {
        if (rest[i].type === 'column') {
            boundaries.push(i);
        }
    }

    boundaries.push(megaEnd);

    if (at < megaStart + 1 || at > megaEnd) {
        return items;
    }

    const snapped = boundaries.reduce((best, b) => (Math.abs(b - at) < Math.abs(best - at) ? b : best), boundaries[0]);

    return reconcile([...rest.slice(0, snapped), ...block.map((item) => ({ ...item })), ...rest.slice(snapped)], report);
}

/** The block moves above the previous sibling (the nearest item before it at the same depth, before any shallower item). A link at the top of a column goes to the end of the previous column. */
export function moveUp(items, index) {
    const depth = items[index].depth;
    let sibling = -1;

    for (let i = index - 1; i >= 0; i--) {
        if (items[i].depth < depth) {
            break;
        }

        if (items[i].depth === depth) {
            sibling = i;
            break;
        }
    }

    if (sibling === -1) {
        return items[index].type !== 'column' && inColumn(items, index) ? toColumn(items, index, -1) : items;
    }

    const end = blockEnd(items, index);

    return reconcile([...items.slice(0, sibling), ...items.slice(index, end), ...items.slice(sibling, index), ...items.slice(end)]);
}

/** The block moves below the next sibling and that sibling's sub-items. A link at the bottom of a column goes to the start of the next column. */
export function moveDown(items, index) {
    const end = blockEnd(items, index);

    if (end >= items.length || items[end].depth !== items[index].depth) {
        return items[index].type !== 'column' && inColumn(items, index) ? toColumn(items, index, 1) : items;
    }

    const next = blockEnd(items, end);

    return reconcile([...items.slice(0, index), ...items.slice(end, next), ...items.slice(index, end), ...items.slice(next)]);
}

/** True for a link that sits in a column of a mega item. */
export function inColumn(items, index) {
    return items[index].depth === 2 && items[index].type !== 'column' && parentOf(items, index) >= 0 && items[parentOf(items, index)].type === 'column';
}

/** The link moves into the previous (-1) or next (+1) column of the same mega item: to the END of the previous one, to the START of the next one. Unchanged when there is none. */
export function toColumn(items, index, direction) {
    if (!inColumn(items, index)) {
        return items;
    }

    const column = parentOf(items, index);
    const mega = parentOf(items, column);
    const megaEnd = blockEnd(items, mega);
    const columns = [];

    for (let i = mega + 1; i < megaEnd; i++) {
        if (items[i].type === 'column') {
            columns.push(i);
        }
    }

    const at = columns.indexOf(column) + direction;

    if (at < 0 || at >= columns.length) {
        return items;
    }

    const link = items[index];
    const without = items.filter((_, i) => i !== index);
    const target = columns[at] > index ? columns[at] - 1 : columns[at];
    const insertAt = direction < 0 ? blockEnd(without, target) : target + 1;

    return reconcile([...without.slice(0, insertAt), link, ...without.slice(insertAt)]);
}

/** «Под предишния»: one level deeper, allowed while the item before it is at the same depth or deeper and the block still fits. */
export function indent(items, index) {
    if (index === 0 || !canIndent(items, index)) {
        return items;
    }

    const end = blockEnd(items, index);

    return reconcile(items.map((item, i) => (i >= index && i < end ? { ...item, depth: item.depth + 1 } : item)));
}

export function canIndent(items, index) {
    return index > 0 && items[index].type !== 'column' && items[index].depth + 1 <= items[index - 1].depth + 1 && items[index].depth + relativeHeight(items, index) + 1 <= MAX_DEPTH;
}

/** «Извади от подменюто»: one level up. A link in a column stays in it (the way out of a mega item is to drag it). */
export function outdent(items, index) {
    if (items[index].depth === 0 || !canOutdent(items, index)) {
        return items;
    }

    const end = blockEnd(items, index);

    return reconcile(items.map((item, i) => (i >= index && i < end ? { ...item, depth: item.depth - 1 } : item)));
}

export function canOutdent(items, index) {
    return items[index].depth > 0 && items[index].type !== 'column' && !inColumn(items, index);
}

/** Removes the item and its sub-items. A column cannot be removed (a mega item holds exactly as many as it says). */
export function removeBlock(items, index) {
    if (items[index].type === 'column') {
        return items;
    }

    return reconcile([...items.slice(0, index), ...items.slice(blockEnd(items, index))]);
}

/**
 * Appends new items (each with a depth RELATIVE to the first one). By default at the end of the menu, at the top level; with `columnUid` at the end of that column of a mega item,
 * where every link sits at depth 2.
 */
export function append(items, added, columnUid = null) {
    if (columnUid) {
        const column = items.findIndex((item) => item.uid === columnUid && item.type === 'column');

        if (column >= 0) {
            const at = blockEnd(items, column);

            return reconcile([...items.slice(0, at), ...added.map((item) => ({ ...item, depth: 2 })), ...items.slice(at)]);
        }
    }

    return reconcile([...items, ...added.map((item) => ({ ...item, depth: item.depth || 0 }))]);
}

/** The places new items can go besides the end of the menu: every column of every mega item, named «Дрехи › Колона 2» (or by its heading). */
export function columnTargets(items, columnWord = 'Колона') {
    const targets = [];
    let mega = null;
    let number = 0;

    items.forEach((item) => {
        if (item.depth === 0) {
            mega = item.submenu === 'mega' ? item : null;
            number = 0;
        } else if (item.type === 'column' && mega) {
            number += 1;
            targets.push({ uid: item.uid, text: displayName(mega) + ' › ' + columnWord + ' ' + number + (item.label ? ' (' + item.label + ')' : '') });
        }
    });

    return targets;
}

/** The number of a column among the columns of its mega item (1-4). */
export function columnNumber(items, index) {
    let number = 0;

    for (let i = index; i >= 0 && items[i].depth >= 1; i--) {
        if (items[i].depth === 1 && items[i].type === 'column') {
            number++;
        }
    }

    return number;
}

/** A mega item's number of columns changes. Fewer: the links of the removed columns go to the END of the last one (see `report`); more: empty columns are added. */
export function setMegaColumns(items, index, count, report = null) {
    return reconcile(items.map((item, i) => (i === index ? { ...item, submenu: 'mega', mega_columns: clampColumns(count) } : item)), report);
}

/** The submenu of a top-level item changes. To `mega`: columns appear (existing sub-items join the first one); away from it: the columns are dissolved, their links become sub-items in order. */
export function setSubmenu(items, index, submenu) {
    return reconcile(items.map((item, i) => (i === index ? { ...item, submenu, mega_columns: submenu === 'mega' ? clampColumns(item.mega_columns) : null, html_block_id: submenu === 'mega' ? item.html_block_id : null } : item)));
}

/** The number of links a mega item holds. */
export function linkCount(items, index) {
    return items.slice(index + 1, blockEnd(items, index)).filter((item) => item.type !== 'column').length;
}

/** «Раздели равномерно»: all the links of the mega item, in their order, spread over its columns as evenly as possible (the first columns take the remainder: 10 over 4 = 3, 3, 2, 2). */
export function splitEvenly(items, index) {
    const list = items; // the editor's list is always reconciled
    const end = blockEnd(list, index);
    const columns = [];
    const links = [];

    for (let i = index + 1; i < end; i++) {
        if (list[i].type === 'column') {
            columns.push(list[i]);
        } else {
            links.push(list[i]);
        }
    }

    if (columns.length === 0) {
        return list;
    }

    const base = Math.floor(links.length / columns.length);
    const remainder = links.length % columns.length;
    const block = [];
    let used = 0;

    columns.forEach((column, position) => {
        const size = base + (position < remainder ? 1 : 0);
        block.push(column, ...links.slice(used, used + size));
        used += size;
    });

    return [...list.slice(0, index + 1), ...block, ...list.slice(end)];
}

/** The number of links in each column of a mega item, in order. */
export function columnSizes(items, index) {
    const sizes = [];
    const end = blockEnd(items, index);

    for (let i = index + 1; i < end; i++) {
        if (items[i].type === 'column') {
            sizes.push(0);
        } else if (sizes.length) {
            sizes[sizes.length - 1]++;
        }
    }

    return sizes;
}

/**
 * The branch of the category tree to add: the category and, when asked, its sub-categories nested under it (kept at most MAX_DEPTH deep: deeper ones sit at the last level).
 * `categories` is the flat tree of the page (id, name, depth, parent, url). A parent with children becomes a dropdown.
 */
export function categoryBranch(categories, id, withChildren) {
    const root = categories.find((c) => c.id === id);

    if (!root) {
        return [];
    }

    const out = [];
    const walk = (category, depth) => {
        out.push({ type: 'category', target_id: category.id, target_name: category.name, target_url: category.url, target_status: 'ok', depth: Math.min(depth, MAX_DEPTH) });

        if (withChildren) {
            categories.filter((c) => c.parent === category.id).forEach((child) => walk(child, depth + 1));
        }
    };

    walk(root, 0);

    return out;
}

/** The warning for a crowded bar: more than 7 top-level items, or a top-level label over 20 characters. */
export function crowded(items) {
    const top = items.filter((item) => item.depth === 0);

    return top.length > 7 || top.some((item) => (item.label || item.target_name || '').length > 20);
}

/** The text of an item's collapsed line: its own label, else the name of its target. */
export function displayName(item) {
    return (item.label && item.label.trim()) || item.target_name || '';
}

export function position(items, index) {
    return { position: index + 1, total: items.length, level: items[index].depth + 1 };
}
