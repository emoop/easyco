{{-- Footer block `contacts` (FT-1): phone (a tel: link), email (a mailto: link), address and opening hours as plain lines (escaped; line breaks kept). --}}
<div class="sf-ft-block sf-ft-contacts">
    @if ($settings['phone'] !== '')
        @php($dial = preg_replace('/[^0-9+]/', '', $settings['phone']))
        <p class="sf-ft-line-item">@if ($dial !== '')<a href="tel:{{ $dial }}">{{ $settings['phone'] }}</a>@else{{ $settings['phone'] }}@endif</p>
    @endif
    @if ($settings['email'] !== '')
        <p class="sf-ft-line-item"><a href="mailto:{{ $settings['email'] }}">{{ $settings['email'] }}</a></p>
    @endif
    @if ($settings['address'] !== '')
        <p class="sf-ft-line-item">{!! nl2br(e($settings['address'])) !!}</p>
    @endif
    @if ($settings['hours'] !== '')
        <p class="sf-ft-line-item">{!! nl2br(e($settings['hours'])) !!}</p>
    @endif
</div>
