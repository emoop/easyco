@php($record = $getRecord())
@php($ticked = \App\Privacy\ForgetLogPresenter::ticked($record))
<div style="font-size: .8125rem">
    @forelse ($this->steps() as $step)
        <label style="display: flex; gap: .4rem; align-items: start; margin: .15rem 0; cursor: pointer">
            <input type="checkbox" @checked(array_key_exists($step['key'], $ticked)) wire:click="toggleStep({{ (int) $record->getKey() }}, @js($step['key']), {{ array_key_exists($step['key'], $ticked) ? 'false' : 'true' }})" />
            <span>{{ $step['label'] }}</span>
        </label>
    @empty
        <span style="opacity: .7">{{ __('privacy_admin.log.steps_none') }}</span>
    @endforelse
</div>
