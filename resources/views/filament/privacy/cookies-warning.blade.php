{{-- CC-1: the VISIBLE warning (not an info icon): no published privacy policy page is picked, so the banner has no link to it. --}}
<div class="cc-warning" role="alert">
    <style>
        .cc-warning { margin-bottom: 1rem; padding: .6rem .85rem; border: 1px solid #f59e0b; border-radius: .5rem; background: #fffbeb; color: #78350f; font-size: .875rem; }
        :is(.dark) .cc-warning { background: rgba(245, 158, 11, .12); color: #fcd34d; }
    </style>
    {{ __('cookies_admin.warning_no_page') }}
</div>
