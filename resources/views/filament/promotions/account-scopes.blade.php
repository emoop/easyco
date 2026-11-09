{{-- P5c: the customer restrictions of a promotion (ACCOUNT scopes, set through the API only), as one read-only line with an info icon. Nothing here is a field: it is never submitted. $count, $hint (an InfoHint). --}}
<div data-promotion-account-scopes class="text-sm" style="display:flex;align-items:center;gap:0.375rem;">
    <span>{{ trans_choice('promotions.account_scopes', $count, ['count' => $count]) }}</span>
    {{ $hint }}
</div>
