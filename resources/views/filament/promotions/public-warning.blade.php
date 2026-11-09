{{-- P5b: the amber warning of a PUBLIC promo code that has no condition at all. Visible text on purpose (admin-ui-standard.md §1: a warning the merchant must always see stays visible). Shown and hidden live by the form;
     it never blocks saving. The text is escaped. --}}
<div role="alert" data-promotion-public-warning style="border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:0.5rem;padding:0.5rem 0.75rem;font-size:0.875rem;line-height:1.25rem;">
    {{ __('promotions.public_warning') }}
</div>
