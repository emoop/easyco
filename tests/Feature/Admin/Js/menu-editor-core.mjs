// The rules of the menu editor (resources/js/menu-editor-core.js, MENU-2 and MENU-3) under node, no browser: moves of a flat list with depths and the columns of mega items.
//   node menu-editor-core.mjs   -> prints "OK <n>" when every assertion holds. Run by tests/Feature/Admin/MenuEditorCoreJsTest.php.
import assert from 'node:assert/strict';
import * as core from '../../../../resources/js/menu-editor-core.js';

let n = 0;
const test = (name, fn) => {
    try {
        fn();
        n++;
    } catch (error) {
        console.error('FAILED: ' + name + '\n' + error.stack);
        process.exit(1);
    }
};
const item = (label, depth, extra = {}) => ({ uid: label, type: 'link', label, depth, submenu: 'none', mega_columns: null, html_block_id: null, ...extra });
const col = (label, extra = {}) => item(label, 1, { type: 'column', ...extra });
const mega = (label, columns, extra = {}) => item(label, 0, { submenu: 'mega', mega_columns: columns, ...extra });
const list = (...rows) => rows.map(([label, depth, extra]) => item(label, depth, extra));
const show = (items) => items.map((i) => (i.type === 'column' ? '[' + i.label + ']' : i.label) + ':' + i.depth).join(' ');
const links = (items) => items.filter((i) => i.type !== 'column' && i.depth > 0).map((i) => i.label).join(',');
const sizes = (items, at = 0) => core.columnSizes(items, at).join(',');

// ---- the rules of MENU-2 (depth, blocks, moves) ---------------------------------------------------------------------------------------------

test('normalize: the first item is at depth 0, a jump is cut to one level, never deeper than 2', () => {
    assert.equal(show(core.normalize(list(['a', 2], ['b', 2], ['c', 5], ['d', 0]))), 'a:0 b:1 c:2 d:0');
});

test('normalize: a top-level item with sub-items and no submenu becomes a dropdown', () => {
    const out = core.normalize(list(['a', 0], ['b', 1], ['c', 0]));
    assert.deepEqual(out.map((i) => i.submenu), ['dropdown', 'none', 'none']);
});

test('blockEnd: an item and all its deeper followers', () => {
    const items = list(['a', 0], ['b', 1], ['c', 2], ['d', 1], ['e', 0]);
    assert.equal(core.blockEnd(items, 0), 4);
    assert.equal(core.blockEnd(items, 1), 3);
    assert.equal(core.blockEnd(items, 4), 5);
});

test('moveBlock: a drag down carries the sub-items along', () => {
    const items = list(['a', 0], ['a1', 1], ['b', 0], ['c', 0]);
    assert.equal(show(core.moveBlock(items, 0, 3, 0)), 'b:0 c:0 a:0 a1:1');
});

test('moveBlock: dropping inside its own sub-items puts it back', () => {
    const items = list(['a', 0], ['a1', 1], ['a2', 1], ['b', 0]);
    assert.equal(show(core.moveBlock(items, 0, 1, 0)), 'a:0 a1:1 a2:1 b:0');
});

test('moveBlock: the depth is the offset, clamped to previous + 1 and to 2', () => {
    const items = list(['a', 0], ['b', 0], ['c', 0]);
    assert.equal(show(core.moveBlock(items, 2, 2, 2)), 'a:0 b:0 c:1', 'two levels asked, one allowed under b');
    const deep = list(['a', 0], ['b', 1], ['c', 0]);
    assert.equal(show(core.moveBlock(deep, 2, 2, 9)), 'a:0 b:1 c:2', 'nine asked, two is the last level');
    assert.equal(show(core.moveBlock(deep, 2, 2, -3)), 'a:0 b:1 c:0', 'left of zero is zero');
});

test('moveBlock: a block with its own sub-items keeps its shape and fits in 3 levels', () => {
    const items = list(['a', 0], ['b', 0], ['b1', 1], ['b2', 2]);
    assert.equal(show(core.moveBlock(items, 1, 1, 1)), 'a:0 b:0 b1:1 b2:2');
});

test('the first item can never be indented by a drop', () => {
    assert.equal(show(core.moveBlock(list(['a', 0], ['b', 0]), 1, 0, 2)), 'b:0 a:0');
});

test('a top-level block dropped into the middle of another block goes to the nearest top-level boundary', () => {
    const items = list(['a', 0], ['a1', 1], ['a2', 1], ['a3', 1], ['b', 0], ['z', 0]);
    assert.equal(show(core.moveBlock(items, 5, 3, 0)), 'a:0 a1:1 a2:1 a3:1 z:0 b:0', 'nearer the end of a: after it');
    assert.equal(show(core.moveBlock(items, 5, 1, 0)), 'z:0 a:0 a1:1 a2:1 a3:1 b:0', 'nearer the start of a: before it');
});

test('moveUp and moveDown move a block past its sibling block', () => {
    const items = list(['a', 0], ['a1', 1], ['b', 0], ['b1', 1], ['c', 0]);
    assert.equal(show(core.moveUp(items, 2)), 'b:0 b1:1 a:0 a1:1 c:0');
    assert.equal(show(core.moveDown(items, 0)), 'b:0 b1:1 a:0 a1:1 c:0');
    assert.equal(core.moveUp(items, 0), items, 'the first has nothing above');
    assert.equal(core.moveDown(items, 4), items, 'the last has nothing below');
    assert.equal(core.moveUp(items, 1), items, 'a first child does not jump out of its parent');
});

test('indent: under the previous item, one level, never past 2 nor deeper than previous + 1', () => {
    const items = list(['a', 0], ['b', 0], ['b1', 1]);
    assert.equal(show(core.indent(items, 1)), 'a:0 b:1 b1:2', 'b and its sub-item move together');
    assert.equal(core.indent(items, 0), items, 'the first cannot');
    assert.equal(core.canIndent(list(['a', 0], ['b', 1], ['c', 2]), 2), false, 'already at the last level');
    assert.equal(core.canIndent(list(['a', 0], ['b', 1], ['b1', 2], ['c', 0], ['c1', 1], ['c2', 2]), 3), false, 'a block of 3 levels cannot go one deeper');
});

test('outdent: one level up, the block with it', () => {
    const items = list(['a', 0], ['b', 1], ['b1', 2]);
    assert.equal(show(core.outdent(items, 1)), 'a:0 b:0 b1:1');
    assert.equal(core.outdent(items, 0), items);
    assert.equal(core.canOutdent(items, 0), false);
});

test('removeBlock removes the item and its sub-items', () => {
    assert.equal(show(core.removeBlock(list(['a', 0], ['b', 0], ['b1', 1], ['c', 0]), 1)), 'a:0 c:0');
});

test('append puts new items at the end, at the top, nested ones keep their relative depth', () => {
    assert.equal(show(core.append(list(['a', 0]), [item('x', 0), item('x1', 1)])), 'a:0 x:0 x1:1');
});

test('categoryBranch: the category alone, or with its sub-categories nested (at most 3 levels)', () => {
    const categories = [
        { id: 1, name: 'Clothes', parent: null, url: '/c' },
        { id: 2, name: 'Dresses', parent: 1, url: '/c/d' },
        { id: 3, name: 'Party', parent: 2, url: '/c/d/p' },
        { id: 4, name: 'Mini', parent: 3, url: '/c/d/p/m' },
        { id: 5, name: 'Shoes', parent: null, url: '/s' },
    ];
    assert.deepEqual(core.categoryBranch(categories, 1, false).map((i) => i.target_name), ['Clothes']);
    assert.deepEqual(core.categoryBranch(categories, 1, true).map((i) => i.target_name + ':' + i.depth), ['Clothes:0', 'Dresses:1', 'Party:2', 'Mini:2']);
    assert.deepEqual(core.categoryBranch(categories, 99, true), []);
});

test('crowded: more than 7 top-level items or a top-level label over 20 characters', () => {
    assert.equal(core.crowded(Array.from({ length: 7 }, (_, i) => item('i' + i, 0))), false);
    assert.equal(core.crowded(Array.from({ length: 8 }, (_, i) => item('i' + i, 0))), true);
    assert.equal(core.crowded([item('x'.repeat(21), 0)]), true);
    assert.equal(core.crowded([item('x'.repeat(21), 1)]), false, 'only the top level counts');
});

test('depthFromDrag: 30px is one level', () => {
    assert.equal(core.depthFromDrag(0, 29), 1);
    assert.equal(core.depthFromDrag(0, 14), 0);
    assert.equal(core.depthFromDrag(2, -61), 0);
});

// ---- MENU-3: the columns of a mega item ---------------------------------------------------------------------------------------------------------

const twelve = () => Array.from({ length: 12 }, (_, i) => item('l' + (i + 1), 2));

test('reconcile: a mega item holds exactly N columns: missing ones are added empty (select 4 -> 4 boxes)', () => {
    const out = core.reconcile([mega('Dresses', 4)]);
    assert.equal(out.filter((i) => i.type === 'column').length, 4);
    assert.equal(out.length, 5);
    const more = core.setMegaColumns(core.reconcile([mega('Dresses', 2), col('A'), item('a1', 2), col('B'), item('b1', 2)]), 0, 4);
    assert.equal(more.filter((i) => i.type === 'column').length, 4);
    assert.equal(links(more), 'a1,b1', 'raising the number loses nothing');
    assert.equal(sizes(more), '1,1,0,0');
});

test('reconcile: the number is 1 to 4, anything else is the default 3', () => {
    assert.equal(core.reconcile([mega('M', 9)]).filter((i) => i.type === 'column').length, 3);
    assert.equal(core.reconcile([mega('M', null)]).filter((i) => i.type === 'column').length, 3);
    assert.equal(core.reconcile([mega('M', 1)]).filter((i) => i.type === 'column').length, 1);
});

test('lowering N moves the links of the removed columns to the end of the last column, in order, and reports it', () => {
    const items = core.reconcile([mega('M', 4), col('A'), item('a1', 2), item('a2', 2), col('B'), item('b1', 2), col('C'), item('c1', 2), item('c2', 2), col('D'), item('d1', 2)]);
    const report = {};
    const out = core.setMegaColumns(items, 0, 2, report);
    assert.equal(out.filter((i) => i.type === 'column').length, 2);
    assert.equal(sizes(out), '2,4', 'column B takes the links of C and D after its own');
    assert.equal(links(out), 'a1,a2,b1,c1,c2,d1', 'nothing lost, same order');
    assert.deepEqual(report.moved[out[0].uid], { columns: 2, links: 3, into: 2 }, 'the warning has its numbers');
    assert.equal(core.setMegaColumns(items, 0, 3, {}).filter((i) => i.type === 'column').length, 3);
});

test('lowering with nothing in the removed columns reports nothing moved', () => {
    const items = core.reconcile([mega('M', 3), col('A'), item('a1', 2)]);
    const report = {};
    core.setMegaColumns(items, 0, 1, report);
    assert.deepEqual(report.moved[items[0].uid], { columns: 2, links: 0, into: 1 });
});

test('splitEvenly: 12 links over 4 columns = 3, 3, 3, 3 in their order', () => {
    const items = core.reconcile([mega('M', 4), col('A'), ...twelve()]);
    const out = core.splitEvenly(items, 0);
    assert.equal(sizes(out), '3,3,3,3');
    assert.equal(links(out), 'l1,l2,l3,l4,l5,l6,l7,l8,l9,l10,l11,l12');
    assert.equal(out.filter((i) => i.type === 'column').length, 4);
    assert.ok(out.filter((i) => i.type !== 'column' && i.depth > 0).every((i) => i.depth === 2));
});

test('splitEvenly: 10 over 4 = 3, 3, 2, 2 (the first columns take the remainder), the headings stay with their columns', () => {
    const ten = Array.from({ length: 10 }, (_, i) => item('x' + (i + 1), 2));
    const items = core.reconcile([mega('M', 4), col('One'), ...ten]);
    items.find((i) => i.label === 'One').label = 'Heading one';
    const out = core.splitEvenly(items, 0);
    assert.equal(sizes(out), '3,3,2,2');
    assert.equal(links(out), 'x1,x2,x3,x4,x5,x6,x7,x8,x9,x10');
    assert.equal(out.find((i) => i.type === 'column').label, 'Heading one');
});

test('splitEvenly: fewer links than columns leaves the last ones empty; one column takes everything', () => {
    assert.equal(sizes(core.splitEvenly(core.reconcile([mega('M', 4), col('A'), item('a', 2), item('b', 2)]), 0)), '1,1,0,0');
    assert.equal(sizes(core.splitEvenly(core.reconcile([mega('M', 1), col('A'), item('a', 2), item('b', 2)]), 0)), '2');
});

test('splitEvenly works on the mega item it is asked for, in a list with other items', () => {
    const items = core.reconcile([item('first', 0), mega('M', 2), col('A'), item('a', 2), item('b', 2), item('c', 2), item('after', 0)]);
    const out = core.splitEvenly(items, 1);
    assert.equal(show(out), 'first:0 M:0 [A]:1 a:2 b:2 [' + out[5].label + ']:1 c:2 after:0');
});

test('Мега -> Падащо dissolves the columns: their links become the items of the dropdown, in order', () => {
    const items = core.reconcile([mega('M', 3), col('A'), item('a1', 2), item('a2', 2), col('B'), item('b1', 2), col('C')]);
    const out = core.setSubmenu(items, 0, 'dropdown');
    assert.equal(show(out), 'M:0 a1:1 a2:1 b1:1');
    assert.equal(out[0].submenu, 'dropdown');
    assert.equal(out[0].mega_columns, null);
    assert.equal(out.some((i) => i.type === 'column'), false);
    assert.equal(core.setSubmenu(items, 0, 'none')[0].submenu, 'dropdown', 'a menu with items under it is a dropdown, not "none"');
});

test('Падащо -> Мега: the existing sub-items join the first column', () => {
    const out = core.setSubmenu(core.reconcile(list(['M', 0, { submenu: 'dropdown' }], ['a', 1], ['b', 1])), 0, 'mega');
    assert.equal(out[0].mega_columns, 3);
    assert.equal(sizes(out), '2,0,0');
    assert.equal(links(out), 'a,b');
});

test('a link cannot land at depth 1 under a mega item: it goes into a column', () => {
    const items = core.reconcile([mega('M', 2), col('A'), item('a1', 2), col('B'), item('b1', 2), item('x', 0)]);
    // x dragged right under the mega item (before column A), asking for depth 1
    const first = core.moveBlock(items, 5, 1, 1);
    assert.equal(show(first), 'M:0 [A]:1 x:2 a1:2 [B]:1 b1:2', 'it joined the first column');
    // x dropped between the columns asking for depth 1: it joins the column above
    const between = core.moveBlock(items, 5, 3, 1);
    assert.equal(sizes(between), '2,1');
    assert.ok(between.every((i) => i.type === 'column' ? i.depth === 1 : (i.depth === 2 || i.depth === 0)));
    assert.equal(links(core.reconcile([mega('M', 1), item('p', 1), item('q', 1)])), 'p,q', 'links made sub-items of a mega item are put into its column');
});

test('a link dropped onto a column box (depth 2 after the column header) lands in that column', () => {
    const items = core.reconcile([mega('M', 3), col('A'), item('a1', 2), col('B'), col('C'), item('c1', 2), item('x', 0)]);
    const out = core.moveBlock(items, 6, 4, 2); // between the header of B and the header of C
    assert.equal(sizes(out), '1,1,1');
    assert.equal(out[4].label, 'x');
});

test('a column is only dragged among the columns of its own mega item', () => {
    const items = core.reconcile([item('first', 0), mega('M', 3), col('A'), item('a1', 2), col('B'), item('b1', 2), col('C'), item('c1', 2), item('after', 0)]);
    const shape = (list) => show(list).replace(/\[[^\]]*\]/g, '[]');
    // column A (index 2) dropped after column B's block (index 5 among the rows during the drag)
    const swapped = core.moveBlock(items, 2, 5, 1);
    assert.equal(shape(swapped), 'first:0 M:0 []:1 b1:2 []:1 a1:2 []:1 c1:2 after:0');
    assert.equal(swapped[2].label, 'B');
    assert.equal(swapped[4].label, 'A');
    assert.equal(core.moveBlock(items, 2, 0, 1), items, 'dropped above its mega item: refused');
    assert.equal(core.moveBlock(items, 2, 8, 1), items, 'dropped below its mega item: refused');
    assert.equal(core.moveUp(items, 2), items, 'the first column has nothing to its left');
    const right = core.moveDown(items, 2);
    assert.equal(shape(right), 'first:0 M:0 []:1 b1:2 []:1 a1:2 []:1 c1:2 after:0', 'the column moves right');
    assert.equal(core.moveUp(items, 4)[2].label, 'B', 'and left');
});

test('a column cannot be removed, indented or taken out of its mega item', () => {
    const items = core.reconcile([mega('M', 2), col('A'), item('a1', 2), col('B')]);
    assert.equal(core.removeBlock(items, 1), items);
    assert.equal(core.canIndent(items, 3), false);
    assert.equal(core.canOutdent(items, 1), false);
    assert.equal(core.canOutdent(items, 2), false, 'a link in a column stays in it');
});

test('a column that ends up outside a mega item is dissolved (its links stay)', () => {
    const out = core.reconcile([item('a', 0), col('stray'), item('s1', 2)]);
    assert.equal(out.some((i) => i.type === 'column'), false);
    assert.equal(show(out), 'a:0 s1:1');
});

test('the move buttons move a link to the previous or next column', () => {
    const items = core.reconcile([mega('M', 3), col('A'), item('a1', 2), item('a2', 2), col('B'), item('b1', 2), col('C')]);
    // up on the first link of column B goes to the END of column A; down on the last link of A goes to the START of B
    const up = core.moveUp(items, 5);
    assert.equal(sizes(up), '3,0,0');
    assert.equal(links(up), 'a1,a2,b1');
    const down = core.moveDown(items, 3);
    assert.equal(sizes(down), '1,2,0');
    assert.equal(links(down), 'a1,a2,b1', 'order kept: a1, then a2 and b1 in column B');
    assert.equal(down[3].label, 'B');
    assert.equal(sizes(core.moveDown(items, 5)), '2,0,1', 'b1 is the last link of B: down means the start of C');
    assert.equal(sizes(core.toColumn(items, 5, 1)), '2,0,1');
    assert.equal(core.toColumn(items, 3, -1), items, 'there is no column left of the first');
    assert.equal(sizes(core.toColumn(items, 3, 1)), '1,2,0');
    // inside a column the buttons swap neighbours as before
    assert.equal(core.moveUp(items, 2), items, 'a1 is first in the first column: there is no column to its left, unchanged');
    assert.equal(links(core.moveUp(items, 3)), 'a2,a1,b1', 'a2 swaps with the link above it');
});

test('the places a new item can go: every column, named by its mega item and number', () => {
    const items = core.reconcile([item('x', 0), mega('Dresses', 2), col('A'), item('a1', 2), col('B')]);
    items.find((i) => i.label === 'B').label = 'Shoes';
    const targets = core.columnTargets(items, 'Column');
    assert.deepEqual(targets.map((t) => t.text), ['Dresses › Column 1 (A)', 'Dresses › Column 2 (Shoes)']);
    const out = core.append(items, [item('n1', 0), item('n2', 1)], targets[1].uid);
    assert.equal(sizes(out, 1), '1,2', 'both go to the end of that column, flattened to depth 2');
    assert.ok(out.filter((i) => i.label === 'n1' || i.label === 'n2').every((i) => i.depth === 2));
});

test('columnNumber and linkCount', () => {
    const items = core.reconcile([mega('M', 3), col('A'), item('a1', 2), col('B'), item('b1', 2), item('b2', 2)]);
    assert.equal(core.columnNumber(items, 3), 2);
    assert.equal(core.columnNumber(items, 5), 2);
    assert.equal(core.linkCount(items, 0), 3);
});

test('a block with a mega item moves as a whole (columns and links with it)', () => {
    const items = core.reconcile([mega('M', 2), col('A'), item('a1', 2), col('B'), item('b1', 2), item('z', 0)]);
    const out = core.moveBlock(items, 5, 0, 0);
    assert.equal(show(out).replace(/\[[^\]]*\]/g, '[]'), 'z:0 M:0 []:1 a1:2 []:1 b1:2');
    const down = core.moveDown(out, 0);
    assert.equal(show(down).replace(/\[[^\]]*\]/g, '[]'), 'M:0 []:1 a1:2 []:1 b1:2 z:0');
});

console.log('OK ' + n);
