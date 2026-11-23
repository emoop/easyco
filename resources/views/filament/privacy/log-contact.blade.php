@php($email = \App\Privacy\ForgetLogPresenter::email($getRecord()))
@php($phone = \App\Privacy\ForgetLogPresenter::phone($getRecord()))
<div style="font-size: .875rem">
    @if ($email !== null || $phone !== null)
        @if ($email !== null)<div title="{{ $email }}">{{ \Illuminate\Support\Str::limit($email, 28) }}</div>@endif
        @if ($phone !== null)<div>{{ $phone }}</div>@endif
    @else
        <span style="opacity: .7">{{ __('privacy_admin.log.hash_only') }}</span>
    @endif
</div>
