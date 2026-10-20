// The menu editor of the admin (MENU-2, «Дизайн» -> «Менюта»). An Alpine component that holds the menu as a FLAT list with a depth, drags it with SortableJS
// (vertical = order, horizontal offset at the drop = depth, 30px a level, a block moves with its sub-items), edits it in cards and saves it in ONE Livewire call.
// Loaded by the page itself (@vite in its view) and by nothing else: no other admin page and never the storefront.
import Sortable from 'sortablejs';
import * as core from './menu-editor-core.js';

const SAVED_FIELDS = ['type', 'label', 'url', 'target_id', 'submenu', 'html_block_id', 'mega_columns', 'badge_text', 'badge_color', 'new_tab', 'visible', 'depth'];

let counter = 0;

function nextUid() {
    counter += 1;

    return 'n' + counter;
}

function blank(type, extra) {
    return Object.assign({
        uid: nextUid(), type, depth: 0, label: null, url: null, target_id: null, submenu: 'none', html_block_id: null, mega_columns: null, badge_text: null, badge_color: null,
        new_tab: false, visible: true, target_name: null, target_url: null, target_status: 'ok',
    }, extra || {});
}

function menuEditor(state) {
    return {
        t: state.i18n,
        menus: state.menus,
        menuId: state.menu ? state.menu.id : null,
        key: state.menu ? state.menu.key : '',
        isProtected: state.menu ? state.menu.protected : false,
        name: state.menu ? state.menu.name : '',
        items: [],
        selectedLocations: [],
        holders: state.holders,
        locationList: state.locations,
        categories: state.categories,
        pages: state.pages,
        blocks: state.blocks,
        presets: state.presets,
        limits: state.limits,
        blocksUrl: state.urls.blocks,
        baseline: '',
        errors: { items: {}, menu: {} },
        open: {},
        flash: {},
        announcement: '',
        busy: false,
        message: '',
        newMenuName: '',
        creating: false,
        panel: 'categories',
        categorySearch: '',
        tickedCategories: {},
        withChildren: false,
        pageTab: 'recent',
        pageSearch: '',
        tickedPages: {},
        productQuery: '',
        productResults: [],
        productsBusy: false,
        tickedProducts: {},
        linkUrl: '',
        linkText: '',
        addTarget: '',
        megaWarnings: {},
        dragStartX: 0,
        lastX: 0,
        sortable: null,
        guards: [],

        init() {
            this.load(state.menu);
            this.$watch('productQuery', (value) => this.searchProducts(value));

            const pointer = (event) => {
                this.lastX = event.clientX;
                this.previewDepth();
            };
            const leave = (event) => {
                if (this.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            const navigate = (event) => {
                if (this.dirty && !window.confirm(this.t.leave)) {
                    event.preventDefault();
                }
            };

            document.addEventListener('pointermove', pointer);
            document.addEventListener('dragover', pointer);
            window.addEventListener('beforeunload', leave);
            document.addEventListener('livewire:navigate', navigate);
            this.guards = [() => document.removeEventListener('pointermove', pointer), () => document.removeEventListener('dragover', pointer), () => window.removeEventListener('beforeunload', leave), () => document.removeEventListener('livewire:navigate', navigate)];

            this.sortable = Sortable.create(this.$refs.list, {
                handle: '.me-handle',
                draggable: '.me-card',
                animation: 120,
                // The fallback drag draws a CLONE of the row on the body; it carries the row's Alpine bindings but not its x-for scope, so Alpine must leave it alone.
                forceFallback: true,
                fallbackOnBody: true,
                ghostClass: 'me-ghost',
                onStart: (event) => {
                    document.querySelectorAll('body > .sortable-fallback').forEach((clone) => clone.setAttribute('x-ignore', ''));
                    this.dragStartX = this.lastX;
                    this.dragFrom = event.oldDraggableIndex;
                },
                onEnd: (event) => this.dropped(event),
            });
        },

        destroy() {
            this.guards.forEach((undo) => undo());

            if (this.sortable) {
                this.sortable.destroy();
            }
        },

        // ---- state -----------------------------------------------------------------------------------------------------------------

        load(menu) {
            if (!menu) {
                this.menuId = null;
                this.items = [];
                this.baseline = '';

                return;
            }

            this.menuId = menu.id;
            this.key = menu.key;
            this.isProtected = menu.protected;
            this.name = menu.name;
            this.items = core.reconcile(menu.items.map((item) => Object.assign({ mega_columns: null }, item, { uid: nextUid() })));
            this.megaWarnings = {};
            this.addTarget = '';
            this.selectedLocations = menu.locations.slice();
            this.errors = { items: {}, menu: {} };
            this.open = {};
            this.baseline = this.snapshot();
        },

        snapshot() {
            return JSON.stringify([this.name, this.selectedLocations.slice().sort(), this.items.map((item) => SAVED_FIELDS.map((field) => item[field]))]);
        },

        get dirty() {
            return this.menuId !== null && this.snapshot() !== this.baseline;
        },

        get crowded() {
            return core.crowded(this.items);
        },

        get totalWarning() {
            return this.items.length > this.limits.total;
        },

        // ---- the menu list ----------------------------------------------------------------------------------------------------------

        async switchMenu(id) {
            if (id === this.menuId || (this.dirty && !window.confirm(this.t.leave))) {
                this.$refs.select.value = this.menuId;

                return;
            }

            const result = await this.$wire.loadMenu(id);

            if (result.ok) {
                this.holders = result.holders;
                this.load(result.menu);
            } else {
                this.message = result.message;
            }
        },

        async createMenu() {
            if (this.dirty && !window.confirm(this.t.leave)) {
                return;
            }

            const result = await this.$wire.createMenu(this.newMenuName);

            if (result.ok) {
                this.menus = result.menus;
                this.holders = result.holders;
                this.load(result.menu);
                this.creating = false;
                this.newMenuName = '';
                this.message = '';
            } else {
                this.message = result.message;
            }
        },

        async deleteMenu() {
            if (!window.confirm(this.t.delete_confirm)) {
                return;
            }

            const result = await this.$wire.deleteMenu(this.menuId);

            if (result.ok) {
                this.menus = result.menus;
                this.holders = result.holders;
                this.load(result.menu);
                this.message = '';
            } else {
                this.message = result.message;
            }
        },

        // ---- saving -----------------------------------------------------------------------------------------------------------------

        async save() {
            this.busy = true;
            this.message = '';
            const rows = this.items.map((item) => {
                const row = {};
                SAVED_FIELDS.forEach((field) => {
                    row[field] = item[field];
                });

                return row;
            });
            const result = await this.$wire.saveMenu(this.menuId, this.name, rows, this.selectedLocations);
            this.busy = false;

            if (result.ok) {
                this.menus = result.menus;
                this.holders = result.holders;
                this.load(result.menu);
                this.announce(this.t.saved);

                return;
            }

            this.errors = { items: {}, menu: result.menu || {} };
            let first = null;

            Object.keys(result.items || {}).forEach((index) => {
                const item = this.items[Number(index)];

                if (item) {
                    this.errors.items[item.uid] = result.items[index];
                    this.open[item.uid] = true;
                    first = first || item.uid;
                }
            });

            this.message = result.message || this.t.invalid;
            this.announce(this.message);
            this.$nextTick(() => {
                const target = first ? document.getElementById('me-' + first) : this.$refs.top;

                if (target) {
                    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            });
        },

        itemErrors(item) {
            return Object.values(this.errors.items[item.uid] || {});
        },

        fieldError(item, field) {
            return (this.errors.items[item.uid] || {})[field] || '';
        },

        // ---- the add-panels ---------------------------------------------------------------------------------------------------------

        togglePanel(name) {
            this.panel = this.panel === name ? '' : name;
        },

        get shownCategories() {
            const needle = this.categorySearch.trim().toLowerCase();

            return needle === '' ? this.categories : this.categories.filter((category) => category.path.toLowerCase().includes(needle));
        },

        get shownPages() {
            const needle = this.pageSearch.trim().toLowerCase();

            if (this.pageTab === 'recent') {
                return this.pages.slice(0, 10);
            }

            if (this.pageTab === 'search') {
                return needle === '' ? [] : this.pages.filter((page) => page.title.toLowerCase().includes(needle));
            }

            return this.pages.slice().sort((a, b) => a.title.localeCompare(b.title));
        },

        async searchProducts(value) {
            const query = value.trim();

            if (query === '') {
                this.productResults = [];

                return;
            }

            this.productsBusy = true;
            const results = await this.$wire.searchProducts(query);

            // an answer to an older question is dropped
            if (query === this.productQuery.trim()) {
                this.productResults = results;
            }

            this.productsBusy = false;
        },

        add(added) {
            if (!added.length) {
                return;
            }

            const fresh = added.map((item) => Object.assign(blank(item.type, item), { uid: nextUid() }));
            this.items = core.append(this.items, fresh, this.addTarget || null);
            this.flashItems(fresh.map((item) => item.uid));
            this.announce(this.t.added.replace(':count', String(fresh.length)));
        },

        addCategories() {
            const ids = this.categories.filter((category) => this.tickedCategories[category.id]).map((category) => category.id);
            const picked = new Set(ids);
            const added = [];

            // a ticked category whose parent is ticked too (and "with sub-categories" is on) is already part of the parent's branch
            ids.forEach((id) => {
                const category = this.categories.find((c) => c.id === id);

                if (!(this.withChildren && category.parent !== null && this.ancestorPicked(category, picked))) {
                    added.push(...core.categoryBranch(this.categories, id, this.withChildren));
                }
            });
            this.add(added);
            this.tickedCategories = {};
        },

        ancestorPicked(category, picked) {
            let parent = category.parent;
            let guard = 0;

            while (parent !== null && guard++ < 10) {
                if (picked.has(parent)) {
                    return true;
                }

                parent = (this.categories.find((c) => c.id === parent) || {}).parent ?? null;
            }

            return false;
        },

        addPages() {
            this.add(this.pages.filter((page) => this.tickedPages[page.id]).map((page) => ({ type: 'page', target_id: page.id, target_name: page.title, target_url: page.url })));
            this.tickedPages = {};
        },

        addProducts() {
            this.add(this.productResults.filter((product) => this.tickedProducts[product.id]).map((product) => ({ type: 'product', target_id: product.id, target_name: product.name, target_url: product.url })));
            this.tickedProducts = {};
        },

        addLink() {
            const url = this.linkUrl.trim();
            const text = this.linkText.trim();

            if (text === '' || url === '') {
                this.message = this.t.link_needed;

                return;
            }

            this.message = '';
            this.add([{ type: 'link', url, label: text, target_url: url }]);
            this.linkUrl = '';
            this.linkText = '';
        },

        flashItems(uids) {
            uids.forEach((uid) => {
                this.flash[uid] = true;
            });
            this.$nextTick(() => {
                const el = document.getElementById('me-' + uids[0]);

                if (el) {
                    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            });
            setTimeout(() => uids.forEach((uid) => delete this.flash[uid]), 2200);
        },

        // ---- the cards ----------------------------------------------------------------------------------------------------------------

        title(item, index) {
            return item.type === 'column' ? (item.label && item.label.trim()) || this.columnName(index) : core.displayName(item);
        },

        isPlaceholderTitle(item) {
            return item.type === 'column' ? !(item.label && item.label.trim()) : !(item.label && item.label.trim());
        },

        columnName(index) {
            return this.t.column + ' ' + core.columnNumber(this.items, index);
        },

        inColumn(index) {
            return core.inColumn(this.items, index);
        },

        columnEmpty(index) {
            return !this.items[index + 1] || this.items[index + 1].depth < 2;
        },

        /** the places new items can go besides the end of the menu */
        get targets() {
            const list = core.columnTargets(this.items, this.t.column);

            if (this.addTarget !== '' && !list.some((target) => target.uid === this.addTarget)) {
                this.addTarget = '';
            }

            return list;
        },

        toggle(item) {
            this.open[item.uid] = !this.open[item.uid];
        },

        typeLabel(item) {
            return this.t.types[item.type];
        },

        submenuLabel(item) {
            if (item.depth !== 0 || item.submenu === 'none') {
                return '';
            }

            return item.submenu === 'mega' ? this.t.submenu.mega + ' · ' + this.t.columns_count.replace(':count', String(item.mega_columns || 3)) : this.t.submenu[item.submenu];
        },

        // ---- the columns of a mega item ---------------------------------------------------------------------------------------------------

        changeColumns(item, value) {
            const index = this.items.findIndex((row) => row.uid === item.uid);
            const report = {};
            this.items = core.setMegaColumns(this.items, index, Number(value), report);
            const moved = report.moved && report.moved[item.uid];

            if (moved && moved.links > 0) {
                this.megaWarnings[item.uid] = this.t.columns_lowered.replace(':links', String(moved.links)).replace(':columns', String(moved.columns)).replace(':into', String(moved.into));
                this.announce(this.megaWarnings[item.uid]);
            } else {
                delete this.megaWarnings[item.uid];
            }
        },

        changeSubmenu(item, event) {
            const value = event.target.value;
            const index = this.items.findIndex((row) => row.uid === item.uid);

            if (item.submenu === 'mega' && value !== 'mega' && core.linkCount(this.items, index) > 0 && !window.confirm(this.t.mega_dissolve_confirm)) {
                event.target.value = item.submenu;

                return;
            }

            this.items = core.setSubmenu(this.items, index, value);
            delete this.megaWarnings[item.uid];
        },

        splitEvenly(item) {
            const index = this.items.findIndex((row) => row.uid === item.uid);
            this.items = core.splitEvenly(this.items, index);
            delete this.megaWarnings[item.uid];
            this.announce(this.t.split_done.replace(':sizes', core.columnSizes(this.items, index).join(', ')));
        },

        linksIn(item) {
            return core.linkCount(this.items, this.items.findIndex((row) => row.uid === item.uid));
        },

        moveToColumn(index, direction) {
            this.apply(core.toColumn(this.items, index, direction), this.items[index].uid);
        },

        statusLabel(item) {
            return item.target_status === 'hidden' ? this.t.hidden : item.target_status === 'deleted' ? this.t.deleted : '';
        },

        hasTarget(item) {
            return ['category', 'page', 'product'].includes(item.type);
        },

        original(item) {
            return this.t.types[item.type] + ' ' + (item.target_name || '') + (item.target_url ? ' → ' + item.target_url : '');
        },

        setBadgeColor(item, value) {
            item.badge_color = value === '' ? null : value;
        },

        badgeIsPreset(item) {
            return item.badge_color === null || this.presets.includes(item.badge_color);
        },

        badgeCss(item) {
            const color = item.badge_color;

            if (!item.badge_text) {
                return '';
            }

            return color && color.startsWith('#') ? 'background:' + color : '';
        },

        remove(index) {
            const sub = core.blockEnd(this.items, index) - index - 1;

            if (sub > 0 && !window.confirm(this.t.remove_confirm.replace(':count', String(sub)))) {
                return;
            }

            this.items = core.removeBlock(this.items, index);
        },

        // ---- moving ---------------------------------------------------------------------------------------------------------------------

        apply(items, uid) {
            this.items = items;
            const index = this.items.findIndex((item) => item.uid === uid);

            if (index >= 0) {
                const where = core.position(this.items, index);
                this.announce(this.t.moved.replace(':position', String(where.position)).replace(':total', String(where.total)).replace(':level', String(where.level)));
            }
        },

        moveUp(index) {
            this.apply(core.moveUp(this.items, index), this.items[index].uid);
        },

        moveDown(index) {
            this.apply(core.moveDown(this.items, index), this.items[index].uid);
        },

        indent(index) {
            this.apply(core.indent(this.items, index), this.items[index].uid);
        },

        outdent(index) {
            this.apply(core.outdent(this.items, index), this.items[index].uid);
        },

        canIndent(index) {
            return core.canIndent(this.items, index);
        },

        canOutdent(index) {
            return core.canOutdent(this.items, index);
        },

        previewDepth() {
            const ghost = this.$refs.list ? this.$refs.list.querySelector('.me-ghost') : null;

            if (!ghost || this.dragFrom === undefined) {
                return;
            }

            const from = this.items[this.dragFrom];
            const wanted = core.clampDepth(core.depthFromDrag(from.depth, this.lastX - this.dragStartX), core.MAX_DEPTH - 1, 0);
            ghost.style.marginLeft = wanted * core.STEP + 'px';
        },

        dropped(event) {
            const from = event.oldDraggableIndex;
            const to = event.newDraggableIndex;
            const uid = this.items[from].uid;
            const dx = this.lastX - this.dragStartX;

            // Put the row back where it was: the state, not the DOM, decides the new order (Alpine re-renders the keyed list from it).
            event.item.remove();
            const rest = Array.from(event.from.querySelectorAll(':scope > .me-card'));
            event.from.insertBefore(event.item, rest[from] || null);
            this.dragFrom = undefined;

            if (to === from && Math.abs(dx) < core.STEP / 2) {
                return;
            }

            const result = core.moveBlock(this.items, from, to, core.depthFromDrag(this.items[from].depth, dx));

            if (result === this.items) {
                this.message = this.items[from].type === 'column' ? this.t.column_refused : '';

                return;
            }

            this.message = '';
            this.apply(result, uid);
        },

        announce(text) {
            this.announcement = '';
            this.$nextTick(() => {
                this.announcement = text;
            });
        },

        // ---- locations --------------------------------------------------------------------------------------------------------------------

        locationNote(location) {
            const holder = this.holders[location];

            if (!holder || holder.id === this.menuId || !this.selectedLocations.includes(location)) {
                return '';
            }

            return this.t.moves_from.replace(':menu', holder.name);
        },
    };
}

const register = () => window.Alpine.data('menuEditor', menuEditor);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
