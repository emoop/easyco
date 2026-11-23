{{-- CC-1: the VISIBLE warning (not an info icon): the banner is on, but no menu a guest sees holds the «Настройки за бисквитки» item, so the fixed fallback strip is used. --}}
<div class="cc-warning-strip" role="alert">
    <style>
        .cc-warning-strip { margin-bottom: 1rem; padding: .6rem .85rem; border: 1px solid #f59e0b; border-radius: .5rem; background: #fffbeb; color: #78350f; font-size: .875rem; }
        :is(.dark) .cc-warning-strip { background: rgba(245, 158, 11, .12); color: #fcd34d; }
    </style>
    {{ __('cookies_admin.warning_fallback_strip') }}
</div>
