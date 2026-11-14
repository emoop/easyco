{{-- MEAS-4: the measurements grid of the product tab «Размери» (app/Filament/Resources/ProductResource/Measurements/MeasurementGrid.php). The state is [row key][measure id] => text, part of the product form (one save).
     Kind `single`: a plain vertical list (name, input, unit). Kind `by_size`: a table with a sticky first column inside its OWN scroll box (the page never scrolls sideways).
     Keyboard: Tab / Shift+Tab follow the native order; Enter moves DOWN the same column (Shift+Enter up) and never submits the form. On blur the number is shown the way it will be stored and read back
     (95.5 -> 95,5 in bg). Every input has an accessible name «<size> — <measure>». The look is public/css/admin/measurement-grid.css. --}}
@php
    $statePath = $getStatePath();
    $grid = $getGrid();
    $mark = $getDecimalMark();
    $disabled = $isDisabled();
    $unitLabel = fn (string $unit): string => __('measurements.units.'.$unit);
    $archivedNote = __('measurements.product_tab.archived_column');
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($grid !== null)
        <div
            class="meas"
            x-data="{
                mark: '{{ $mark }}',
                norm(el) {
                    const m = /^(\d{1,6})(?:[.,](\d{1,2}))?$/.exec(el.value.trim());
                    if (! m) { return; }
                    let out = m[1];
                    if (m[2] !== undefined) {
                        const f = m[2].replace(/0+$/, '');
                        if (f !== '') { out += this.mark + f; }
                    }
                    if (out !== el.value) { el.value = out; el.dispatchEvent(new Event('input', { bubbles: true })); }
                },
                // a cell that is being edited is no longer the cell the server refused: its error goes away at once (the next save validates again)
                clearError(el) {
                    if (! el.hasAttribute('aria-invalid')) { return; }
                    el.classList.remove('meas-input-error');
                    el.removeAttribute('aria-invalid');
                    el.parentElement.querySelector('.meas-error')?.remove();
                },
                move(e, dir) {
                    const el = e.target;
                    if (! el.matches('[data-meas-cell]')) { return; }
                    e.preventDefault();
                    const next = this.$root.querySelector(`[data-meas-cell][data-col='${el.dataset.col}'][data-row='${Number(el.dataset.row) + dir}']`);
                    if (next) { next.focus(); next.select(); }
                },
            }"
            x-on:keydown.enter="move($event, $event.shiftKey ? -1 : 1)"
            data-meas-kind="{{ $grid['kind'] }}"
        >
            @if (! $grid['single'] && $grid['fallback'] && $grid['axis_name'] !== null)
                <p class="meas-note meas-fallback" role="note">
                    {{ __('measurements.product_tab.fallback_before', ['axis' => $grid['axis_name']]) }}
                    <a href="{{ \App\Filament\Resources\AttributeDefinitionResource::getUrl('edit', ['record' => $grid['axis_definition_id']]) }}">{{ __('measurements.product_tab.fallback_link') }}</a>
                </p>
            @endif

            @if ($grid['measures'] === [])
                <p class="meas-note">{{ __('measurements.product_tab.no_measures') }}</p>
            @elseif ($grid['rows'] === [])
                <p class="meas-note">{{ __('measurements.product_tab.no_rows') }}</p>
            @elseif ($grid['single'])
                <div class="meas-single">
                    @foreach ($grid['measures'] as $mi => $measure)
                        @php
                            $key = $statePath.'.'.\EasyCo\Catalog\Contracts\MeasurementValueRepository::PRODUCT_ROW.'.'.$measure['id'];
                            $error = $errors->first($key);
                            $inputId = 'meas-'.$mi.'-'.md5($statePath);
                        @endphp
                        <div class="meas-single-row @if ($measure['archived']) meas-archived-col @endif">
                            <label for="{{ $inputId }}" class="meas-single-label">
                                {{ $measure['name'] }}
                                @if ($measure['archived'])
                                    <span class="meas-archived">{{ $archivedNote }}</span>
                                @endif
                            </label>
                            <input
                                type="text" inputmode="decimal" autocomplete="off"
                                id="{{ $inputId }}"
                                class="meas-input @if ($error !== '') meas-input-error @endif"
                                data-meas-cell data-col="0" data-row="{{ $mi }}"
                                aria-label="{{ $measure['name'] }}"
                                @if ($error !== '') aria-invalid="true" aria-describedby="{{ $inputId }}-err" @endif
                                wire:model="{{ $key }}"
                                x-on:blur="norm($el)"
                                x-on:input="clearError($el)"
                                @disabled($disabled)
                            >
                            <span class="meas-unit">{{ $unitLabel($measure['unit']) }}</span>
                            @if ($error !== '')
                                <span class="meas-error" id="{{ $inputId }}-err" role="alert">{{ $error }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="meas-scroll">
                    <table class="meas-table">
                        <thead>
                            <tr>
                                <th scope="col" class="meas-sticky meas-corner">{{ $grid['axis_name'] }}</th>
                                @foreach ($grid['measures'] as $measure)
                                    <th scope="col" class="@if ($measure['archived']) meas-archived-col @endif">
                                        {{ $measure['name'] }}, {{ $unitLabel($measure['unit']) }}
                                        @if ($measure['archived'])
                                            <span class="meas-archived">{{ $archivedNote }}</span>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grid['rows'] as $ri => $row)
                                <tr>
                                    <th scope="row" class="meas-sticky">{{ $row['label'] }}</th>
                                    @foreach ($grid['measures'] as $ci => $measure)
                                        @php
                                            $key = $statePath.'.'.$row['key'].'.'.$measure['id'];
                                            $error = $errors->first($key);
                                            $inputId = 'meas-'.$ri.'-'.$ci.'-'.md5($statePath);
                                        @endphp
                                        <td class="@if ($measure['archived']) meas-archived-col @endif">
                                            <input
                                                type="text" inputmode="decimal" autocomplete="off"
                                                id="{{ $inputId }}"
                                                class="meas-input @if ($error !== '') meas-input-error @endif"
                                                data-meas-cell data-col="{{ $ci }}" data-row="{{ $ri }}"
                                                aria-label="{{ $row['label'] }} — {{ $measure['name'] }}"
                                                @if ($error !== '') aria-invalid="true" aria-describedby="{{ $inputId }}-err" @endif
                                                wire:model="{{ $key }}"
                                                x-on:blur="norm($el)"
                                                x-on:input="clearError($el)"
                                                @disabled($disabled)
                                            >
                                            @if ($error !== '')
                                                <span class="meas-error" id="{{ $inputId }}-err" role="alert">{{ $error }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($grid['hidden'] > 0)
                <p class="meas-hidden">{{ trans_choice('measurements.product_tab.hidden_values', $grid['hidden'], ['count' => $grid['hidden']]) }}</p>
            @endif
        </div>
    @endif
</x-dynamic-component>
