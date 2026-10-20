{{-- «Менюта» (MENU-2): the WordPress-style editor. The whole structure lives in the Alpine component `menuEditor` (resources/js/admin-menu-editor.js, loaded by THIS page only);
     wire:ignore keeps Livewire from morphing the DOM Alpine owns. Saving is one call to Menus::saveMenu(). --}}
@php
    $editor = $this->editorState();
@endphp
<x-filament-panels::page>
    @vite('resources/js/admin-menu-editor.js')

    <style>
        .me { display: grid; grid-template-columns: minmax(0, 20rem) minmax(0, 1fr); gap: 1.25rem; align-items: start; }
        @media (max-width: 900px) { .me { grid-template-columns: minmax(0, 1fr); } }
        .me-box { border: 1px solid rgba(128, 128, 128, 0.3); border-radius: 0.5rem; background: rgba(128, 128, 128, 0.05); }
        .me-box > h2 { margin: 0; padding: 0.75rem 1rem; font-weight: 600; border-bottom: 1px solid rgba(128, 128, 128, 0.3); }
        .me-body { padding: 0.75rem 1rem; }
        .me-top { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
        .me input[type='text'], .me input[type='search'], .me select { width: 100%; padding: 0.4rem 0.6rem; border: 1px solid rgba(128, 128, 128, 0.5); border-radius: 0.375rem; background: transparent; color: inherit; }
        .me select { width: auto; min-width: 12rem; }
        .me-btn { padding: 0.4rem 0.9rem; border: 1px solid rgba(128, 128, 128, 0.5); border-radius: 0.375rem; background: transparent; color: inherit; cursor: pointer; }
        .me-btn:hover { background: rgba(128, 128, 128, 0.12); }
        .me-btn[disabled] { opacity: 0.4; cursor: default; }
        .me-primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .me-primary:hover { background: #1d4ed8; }
        .me-danger { color: #dc2626; border-color: #dc2626; }
        .me-link { background: none; border: 0; padding: 0; color: #2563eb; cursor: pointer; text-decoration: underline; }
        .me-panel-head { display: flex; width: 100%; justify-content: space-between; padding: 0.6rem 1rem; background: none; border: 0; border-top: 1px solid rgba(128, 128, 128, 0.3); color: inherit; font-weight: 600; cursor: pointer; text-align: left; }
        .me-list-scroll { max-height: 15rem; overflow-y: auto; margin: 0.5rem 0; }
        .me-check { display: flex; gap: 0.4rem; align-items: baseline; padding: 0.15rem 0; }
        .me-tabs { display: flex; gap: 0.75rem; margin-bottom: 0.4rem; }
        .me-tab { background: none; border: 0; padding: 0 0 0.15rem; color: inherit; cursor: pointer; border-bottom: 2px solid transparent; }
        .me-tab[aria-selected='true'] { border-bottom-color: #2563eb; font-weight: 600; }
        .me-field { margin: 0.5rem 0; }
        .me-field > label { display: block; font-size: 0.8rem; margin-bottom: 0.15rem; opacity: 0.8; }
        .me-list { list-style: none; margin: 0.75rem 0; padding: 0; min-height: 2rem; }
        .me-card { margin-bottom: 0.4rem; border: 1px solid rgba(128, 128, 128, 0.4); border-radius: 0.375rem; background: rgba(255, 255, 255, 0.04); transition: background 0.4s; }
        .me-card.me-flash { background: rgba(37, 99, 235, 0.25); }
        .me-card.me-error { border-color: #dc2626; }
        .me-ghost { opacity: 0.45; }
        .me-line { display: flex; align-items: center; gap: 0.5rem; padding: 0.45rem 0.6rem; }
        .me-handle { cursor: grab; opacity: 0.6; user-select: none; }
        .me-title { flex: 1; min-width: 0; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .me-title.me-placeholder { font-weight: 400; opacity: 0.6; }
        .me-meta { opacity: 0.65; font-size: 0.8rem; white-space: nowrap; }
        .me-warn { color: #dc2626; font-size: 0.8rem; }
        .me-badge { display: inline-block; padding: 0 0.45rem; border-radius: 9999px; background: #dc2626; color: #fff; font-size: 0.7rem; }
        .me-card-body { padding: 0.25rem 0.75rem 0.75rem; border-top: 1px solid rgba(128, 128, 128, 0.3); }
        .me-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.6rem; }
        .me-err { color: #dc2626; font-size: 0.8rem; margin-top: 0.15rem; }
        .me-notice { padding: 0.5rem 0.75rem; margin: 0.5rem 0; border-radius: 0.375rem; background: rgba(245, 158, 11, 0.15); }
        .me-bad { background: rgba(220, 38, 38, 0.12); }
        .me-dot { display: inline-block; width: 0.55rem; height: 0.55rem; margin-left: 0.4rem; border-radius: 9999px; background: #f59e0b; }
        .me-locations { display: flex; flex-wrap: wrap; gap: 1rem; margin: 0.5rem 0; }
        .me-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; }
        .me-two { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        .me-col-head { border-style: dashed; border-width: 2px; background: rgba(37, 99, 235, 0.06); }
        .me-col-head .me-title { font-weight: 600; }
        .me-col-head input[type='text'] { margin-top: 0.15rem; }
        .me-in-col { border-left: 3px dashed rgba(37, 99, 235, 0.45); }
        .me-colbtns { display: inline-flex; gap: 0.25rem; }
        .me-colbtns .me-btn { padding: 0.1rem 0.5rem; }
        .me-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    </style>

    <div wire:ignore class="me-root" x-data="menuEditor(@js($editor))" x-ref="top">
        <p class="me-sr" role="status" aria-live="polite" x-text="announcement"></p>

        <div class="me-top">
            <label for="me-select" class="me-sr">{{ __('menus.editor.select') }}</label>
            <strong>{{ __('menus.editor.select') }}:</strong>
            <select id="me-select" x-ref="select" x-on:change="switchMenu(Number($event.target.value))">
                <template x-for="menu in menus" :key="menu.id">
                    <option :value="menu.id" :selected="menu.id === menuId" x-text="menu.name"></option>
                </template>
            </select>
            <button type="button" class="me-btn" x-on:click="creating = !creating">{{ __('menus.editor.create') }}</button>
            <template x-if="creating">
                <span class="me-top" style="margin: 0">
                    <input type="text" x-model="newMenuName" maxlength="100" placeholder="{{ __('menus.editor.create_name') }}" x-on:keydown.enter.prevent="createMenu()" style="width: 14rem">
                    <button type="button" class="me-btn me-primary" x-on:click="createMenu()">{{ __('menus.editor.create_do') }}</button>
                </span>
            </template>
            <span style="margin-left: auto">{!! $this->helpLink() !!}</span>
        </div>

        <div class="me-notice me-bad" x-show="message" x-text="message" role="alert"></div>

        <template x-if="menuId === null">
            <p>{{ __('menus.editor.no_menu') }}</p>
        </template>

        <div class="me" x-show="menuId !== null">
            {{-- ---- the add-panels (left) ---- --}}
            <div class="me-box">
                <h2>{{ __('menus.editor.add_title') }}</h2>

                <button type="button" class="me-panel-head" x-on:click="togglePanel('categories')" :aria-expanded="panel === 'categories'">{{ __('menus.editor.panel_categories') }} <span x-text="panel === 'categories' ? '▾' : '▸'"></span></button>
                <div class="me-body" x-show="panel === 'categories'">
                    <input type="search" x-model="categorySearch" placeholder="{{ __('menus.editor.search') }}" aria-label="{{ __('menus.editor.search') }}">
                    <div class="me-list-scroll">
                        <template x-for="category in shownCategories" :key="category.id">
                            <label class="me-check" :style="{ paddingLeft: (categorySearch.trim() === '' ? category.depth * 16 : 0) + 'px' }">
                                <input type="checkbox" x-model="tickedCategories[category.id]">
                                <span x-text="categorySearch.trim() === '' ? category.name : category.path"></span>
                            </label>
                        </template>
                        <p x-show="shownCategories.length === 0" class="me-meta">{{ __('menus.editor.nothing_found') }}</p>
                    </div>
                    <label class="me-check"><input type="checkbox" x-model="withChildren"> {{ __('menus.editor.with_children') }}</label>
                    <button type="button" class="me-btn" x-on:click="addCategories()">{{ __('menus.editor.add') }}</button>
                </div>

                <button type="button" class="me-panel-head" x-on:click="togglePanel('pages')" :aria-expanded="panel === 'pages'">{{ __('menus.editor.panel_pages') }} <span x-text="panel === 'pages' ? '▾' : '▸'"></span></button>
                <div class="me-body" x-show="panel === 'pages'">
                    <div class="me-tabs" role="tablist">
                        <button type="button" class="me-tab" role="tab" :aria-selected="pageTab === 'recent'" x-on:click="pageTab = 'recent'">{{ __('menus.editor.tab_recent') }}</button>
                        <button type="button" class="me-tab" role="tab" :aria-selected="pageTab === 'all'" x-on:click="pageTab = 'all'">{{ __('menus.editor.tab_all') }}</button>
                        <button type="button" class="me-tab" role="tab" :aria-selected="pageTab === 'search'" x-on:click="pageTab = 'search'">{{ __('menus.editor.tab_search') }}</button>
                    </div>
                    <input type="search" x-show="pageTab === 'search'" x-model="pageSearch" placeholder="{{ __('menus.editor.search') }}" aria-label="{{ __('menus.editor.search') }}">
                    <div class="me-list-scroll">
                        <template x-for="page in shownPages" :key="page.id">
                            <label class="me-check"><input type="checkbox" x-model="tickedPages[page.id]"> <span x-text="page.title"></span></label>
                        </template>
                        <p x-show="shownPages.length === 0" class="me-meta">{{ __('menus.editor.nothing_found') }}</p>
                    </div>
                    <button type="button" class="me-btn" x-on:click="addPages()">{{ __('menus.editor.add') }}</button>
                </div>

                <button type="button" class="me-panel-head" x-on:click="togglePanel('products')" :aria-expanded="panel === 'products'">{{ __('menus.editor.panel_products') }} <span x-text="panel === 'products' ? '▾' : '▸'"></span></button>
                <div class="me-body" x-show="panel === 'products'">
                    <input type="search" x-model="productQuery" placeholder="{{ __('menus.editor.search_products') }}" aria-label="{{ __('menus.editor.search_products') }}">
                    <div class="me-list-scroll">
                        <template x-for="product in productResults" :key="product.id">
                            <label class="me-check"><input type="checkbox" x-model="tickedProducts[product.id]"> <span x-text="product.name + (product.sku ? ' (' + product.sku + ')' : '')"></span></label>
                        </template>
                        <p x-show="productQuery.trim() !== '' && !productsBusy && productResults.length === 0" class="me-meta">{{ __('menus.editor.nothing_found') }}</p>
                    </div>
                    <button type="button" class="me-btn" x-on:click="addProducts()">{{ __('menus.editor.add') }}</button>
                </div>

                <button type="button" class="me-panel-head" x-on:click="togglePanel('link')" :aria-expanded="panel === 'link'">{{ __('menus.editor.panel_link') }} <span x-text="panel === 'link' ? '▾' : '▸'"></span></button>
                <div class="me-body" x-show="panel === 'link'">
                    <div class="me-field"><label for="me-link-url">{{ __('menus.editor.link_url') }}</label><input id="me-link-url" type="text" x-model="linkUrl" placeholder="/ или https://" maxlength="500"></div>
                    <div class="me-field"><label for="me-link-text">{{ __('menus.editor.link_text') }}</label><input id="me-link-text" type="text" x-model="linkText" maxlength="120"></div>
                    <button type="button" class="me-btn" x-on:click="addLink()">{{ __('menus.editor.add') }}</button>
                </div>
            </div>

            {{-- ---- the structure (right) ---- --}}
            <div class="me-box">
                <h2>{{ __('menus.editor.structure') }}<span class="me-dot" x-show="dirty" title="{{ __('menus.editor.unsaved') }}"></span></h2>
                <div class="me-body">
                    <div class="me-field">
                        <label for="me-name">{{ __('menus.editor.name') }}</label>
                        <input id="me-name" type="text" x-model="name" maxlength="100">
                        <div class="me-err" x-show="errors.menu.name" x-text="errors.menu.name"></div>
                    </div>

                    <div class="me-notice" x-show="crowded">{{ __('menus.warning_crowded') }}</div>
                    <div class="me-err" x-show="errors.menu.items" x-text="errors.menu.items"></div>
                    <p class="me-meta" x-show="items.length === 0">{{ __('menus.editor.empty') }}</p>

                    <div class="me-field" x-show="targets.length > 0">
                        <label for="me-target">{{ __('menus.editor.add_target') }}</label>
                        <select id="me-target" x-model="addTarget">
                            <option value="">{{ __('menus.editor.add_target_end') }}</option>
                            <template x-for="target in targets" :key="target.uid">
                                <option :value="target.uid" x-text="target.text"></option>
                            </template>
                        </select>
                    </div>

                    <ul class="me-list" x-ref="list">
                        <template x-for="(item, index) in items" :key="item.uid">
                            <li class="me-card" :id="'me-' + item.uid" :class="{ 'me-flash': flash[item.uid], 'me-error': itemErrors(item).length > 0, 'me-col-head': item.type === 'column', 'me-in-col': inColumn(index) }" :style="{ marginLeft: (item.depth * 30) + 'px' }">
                                <div class="me-line">
                                    <span class="me-handle" aria-hidden="true" title="{{ __('menus.editor.drag') }}">⋮⋮</span>
                                    <button type="button" class="me-title me-link" style="text-decoration: none; color: inherit; text-align: left" :class="{ 'me-placeholder': isPlaceholderTitle(item) }" x-on:click="toggle(item)" :aria-expanded="!!open[item.uid]" x-text="title(item, index) || t.untitled"></button>
                                    <span class="me-badge" x-show="item.badge_text" :style="badgeCss(item)" x-text="item.badge_text"></span>
                                    <span class="me-meta" x-text="typeLabel(item) + (submenuLabel(item) ? ' · ' + submenuLabel(item) : '') + (item.depth > 0 && item.type !== 'column' && !inColumn(index) ? ' · ' + t.sub_item : '')"></span>
                                    <span class="me-warn" x-show="statusLabel(item)" x-text="statusLabel(item)"></span>
                                    <template x-if="item.type === 'column'">
                                        <span class="me-colbtns">
                                            <button type="button" class="me-btn" x-on:click="moveUp(index)" :aria-label="t.col_left" title="{{ __('menus.editor.col_left') }}">←</button>
                                            <button type="button" class="me-btn" x-on:click="moveDown(index)" :aria-label="t.col_right" title="{{ __('menus.editor.col_right') }}">→</button>
                                        </span>
                                    </template>
                                    <button type="button" class="me-link" x-show="item.type !== 'column'" x-on:click="toggle(item)" :aria-label="t.open" x-text="open[item.uid] ? '▴' : '▾'"></button>
                                </div>

                                <div class="me-colhead" x-show="item.type === 'column'" style="padding: 0 0.75rem 0.5rem">
                                    <input type="text" x-model="item.label" maxlength="60" :aria-label="t.column_heading" :placeholder="t.column_heading">
                                    <div class="me-err" x-show="fieldError(item, 'label')" x-text="fieldError(item, 'label')"></div>
                                    <p class="me-meta" x-show="columnEmpty(index)" x-text="t.column_empty"></p>
                                </div>
                                <div class="me-err" style="padding: 0 0.75rem" x-show="itemErrors(item).length > 0 && !open[item.uid] && item.type !== 'column'" x-text="itemErrors(item)[0]"></div>
                                <div class="me-err" style="padding: 0 0.75rem" x-show="item.type === 'column' && fieldError(item, '_item')" x-text="fieldError(item, '_item')"></div>

                                <div class="me-card-body" x-show="open[item.uid] && item.type !== 'column'">
                                    <div class="me-field">
                                        <label :for="'lab-' + item.uid">{{ __('menus.editor.f_label') }}</label>
                                        <input :id="'lab-' + item.uid" type="text" x-model="item.label" maxlength="120" :placeholder="item.target_name || ''">
                                        <div class="me-err" x-show="fieldError(item, 'label') && item.type !== 'column'" x-text="fieldError(item, 'label')"></div>
                                    </div>

                                    <template x-if="item.type === 'link'">
                                        <div class="me-field">
                                            <label :for="'url-' + item.uid">{{ __('menus.editor.f_url') }}</label>
                                            <input :id="'url-' + item.uid" type="text" x-model="item.url" maxlength="500" placeholder="/ или https://">
                                            <div class="me-err" x-show="fieldError(item, 'url')" x-text="fieldError(item, 'url')"></div>
                                        </div>
                                    </template>
                                    <div class="me-err" x-show="fieldError(item, 'target_id')" x-text="fieldError(item, 'target_id')"></div>

                                    <label class="me-check"><input type="checkbox" x-model="item.new_tab"> {{ __('menus.editor.f_new_tab') }}</label>

                                    <div class="me-two">
                                        <div class="me-field">
                                            <label :for="'bt-' + item.uid">{{ __('menus.editor.f_badge') }}</label>
                                            <input :id="'bt-' + item.uid" type="text" x-model="item.badge_text" maxlength="16" placeholder="Sale">
                                            <div class="me-err" x-show="fieldError(item, 'badge_text')" x-text="fieldError(item, 'badge_text')"></div>
                                        </div>
                                        <div class="me-field" x-show="item.badge_text">
                                            <label :for="'bc-' + item.uid">{{ __('menus.editor.f_badge_color') }}</label>
                                            <select :id="'bc-' + item.uid" x-on:change="setBadgeColor(item, $event.target.value === 'custom' ? '#dc2626' : $event.target.value)">
                                                <option value="" :selected="item.badge_color === null">{{ __('menus.editor.color_default') }}</option>
                                                <template x-for="preset in presets" :key="preset">
                                                    <option :value="preset" :selected="item.badge_color === preset" x-text="t.colors[preset]"></option>
                                                </template>
                                                <option value="custom" :selected="!badgeIsPreset(item)">{{ __('menus.editor.color_custom') }}</option>
                                            </select>
                                            <input type="color" x-show="!badgeIsPreset(item)" :value="item.badge_color" x-on:input="item.badge_color = $event.target.value" aria-label="{{ __('menus.editor.f_badge_color') }}">
                                            <div class="me-err" x-show="fieldError(item, 'badge_color')" x-text="fieldError(item, 'badge_color')"></div>
                                        </div>
                                    </div>

                                    <template x-if="item.depth === 0">
                                        <div class="me-field">
                                            <label :for="'sm-' + item.uid">{{ __('menus.editor.f_submenu') }}</label>
                                            <select :id="'sm-' + item.uid" x-on:change="changeSubmenu(item, $event)">
                                                <option value="none" :selected="item.submenu === 'none'">{{ __('menus.editor.submenu_none') }}</option>
                                                <option value="dropdown" :selected="item.submenu === 'dropdown'">{{ __('menus.editor.submenu_dropdown') }}</option>
                                                <option value="mega" :selected="item.submenu === 'mega'">{{ __('menus.editor.submenu_mega') }}</option>
                                            </select>
                                            <div class="me-err" x-show="fieldError(item, 'submenu')" x-text="fieldError(item, 'submenu')"></div>
                                        </div>
                                    </template>
                                    <template x-if="item.depth === 0 && item.submenu === 'mega'">
                                        <div>
                                            <div class="me-two">
                                                <div class="me-field">
                                                    <label :for="'mc-' + item.uid">{{ __('menus.editor.f_columns') }}</label>
                                                    <select :id="'mc-' + item.uid" x-on:change="changeColumns(item, $event.target.value)">
                                                        <template x-for="n in [1, 2, 3, 4]" :key="n">
                                                            <option :value="n" :selected="item.mega_columns === n" x-text="n"></option>
                                                        </template>
                                                    </select>
                                                    <div class="me-err" x-show="fieldError(item, 'mega_columns')" x-text="fieldError(item, 'mega_columns')"></div>
                                                </div>
                                                <div class="me-field">
                                                    <label :for="'hb-' + item.uid">{{ __('menus.editor.f_block') }}</label>
                                                    <select :id="'hb-' + item.uid" x-on:change="item.html_block_id = $event.target.value === '' ? null : Number($event.target.value)">
                                                        <option value="" :selected="item.html_block_id === null">{{ __('menus.editor.no_block') }}</option>
                                                        <template x-for="block in blocks" :key="block.id">
                                                            <option :value="block.id" :selected="item.html_block_id === block.id" x-text="block.title"></option>
                                                        </template>
                                                    </select>
                                                    <a :href="blocksUrl" target="_blank" rel="noopener">{{ __('menus.editor.new_block') }}</a>
                                                    <div class="me-err" x-show="fieldError(item, 'html_block_id')" x-text="fieldError(item, 'html_block_id')"></div>
                                                </div>
                                            </div>
                                            <button type="button" class="me-btn" x-on:click="splitEvenly(item)" :disabled="linksIn(item) === 0">{{ __('menus.editor.split_even') }}</button>
                                            <div class="me-notice" x-show="megaWarnings[item.uid]" x-text="megaWarnings[item.uid]" role="status"></div>
                                        </div>
                                    </template>

                                    <label class="me-check"><input type="checkbox" x-model="item.visible"> {{ __('menus.editor.f_visible') }}</label>

                                    <p class="me-meta" x-show="hasTarget(item)">
                                        {{ __('menus.editor.original') }}: <span x-text="t.types[item.type] + ' ' + (item.target_name || '')"></span>
                                        <a x-show="item.target_url" :href="item.target_url" target="_blank" rel="noopener" x-text="'→ ' + item.target_url"></a>
                                    </p>

                                    <div class="me-err" x-show="fieldError(item, '_item')" x-text="fieldError(item, '_item')"></div>

                                    <div class="me-actions">
                                        <button type="button" class="me-btn" x-on:click="moveUp(index)">{{ __('menus.editor.move_up') }}</button>
                                        <button type="button" class="me-btn" x-on:click="moveDown(index)">{{ __('menus.editor.move_down') }}</button>
                                        <button type="button" class="me-btn" x-show="inColumn(index)" x-on:click="moveToColumn(index, -1)">{{ __('menus.editor.to_prev_column') }}</button>
                                        <button type="button" class="me-btn" x-show="inColumn(index)" x-on:click="moveToColumn(index, 1)">{{ __('menus.editor.to_next_column') }}</button>
                                        <button type="button" class="me-btn" x-on:click="indent(index)" :disabled="!canIndent(index)">{{ __('menus.editor.indent') }}</button>
                                        <button type="button" class="me-btn" x-on:click="outdent(index)" :disabled="!canOutdent(index)">{{ __('menus.editor.outdent') }}</button>
                                        <button type="button" class="me-btn me-danger" x-on:click="remove(index)">{{ __('menus.editor.remove') }}</button>
                                    </div>
                                </div>
                            </li>
                        </template>
                    </ul>

                    <fieldset class="me-field">
                        <legend><strong>{{ __('menus.editor.locations') }}</strong></legend>
                        <div class="me-locations">
                            <template x-for="location in locationList" :key="location.key">
                                <div>
                                    <label class="me-check"><input type="checkbox" :value="location.key" x-model="selectedLocations" :id="'loc-' + location.key"> <span x-text="location.label"></span></label>
                                    <div class="me-meta" x-text="locationNote(location.key)"></div>
                                </div>
                            </template>
                        </div>
                        <div class="me-err" x-show="errors.menu.locations" x-text="errors.menu.locations"></div>
                    </fieldset>

                    <div class="me-footer">
                        <button type="button" class="me-btn me-primary" x-on:click="save()" :disabled="busy"><span>{{ __('menus.editor.save') }}</span><span class="me-dot" x-show="dirty"></span></button>
                        <button type="button" class="me-btn me-danger" x-show="!isProtected" x-on:click="deleteMenu()">{{ __('menus.editor.delete') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
