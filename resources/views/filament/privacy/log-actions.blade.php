@php($lines = \App\Privacy\ForgetLogPresenter::actionLines($getRecord()))
<ul style="list-style: none; margin: 0; padding: 0; font-size: .8125rem; max-width: 15rem; white-space: normal">
    @forelse ($lines as $line)
        <li>{{ $line }}</li>
    @empty
        <li style="opacity: .7">{{ __('privacy_admin.log.actions_none') }}</li>
    @endforelse
</ul>
