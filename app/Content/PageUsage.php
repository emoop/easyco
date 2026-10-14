<?php

namespace App\Content;

use App\Content\Models\PageModel;
use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * WHY A PAGE MAY NOT BE DELETED (pages-design.md section 2): it is the page the checkout settings point at for the terms or the
 * privacy policy, or an order's `terms_version` (`p{id}r{n}`) names one of its revisions: the text the customer accepted. The
 * answer is a sentence in the store's language, or null when nothing uses the page.
 */
final class PageUsage
{
    public function __construct(private readonly SiteSettingsRepository $settings)
    {
    }

    public function reason(PageModel $page): ?string
    {
        $id = (string) $page->id;

        if ($this->settings->get(CheckoutFormSettings::TERMS_PAGE_ID_KEY) === $id) {
            return __('pages.in_use.terms_page');
        }

        if ($this->settings->get(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY) === $id) {
            return __('pages.in_use.privacy_page');
        }

        if (DB::table('orders')->where('terms_version', 'like', 'p'.$id.'r%')->exists()) {
            return __('pages.in_use.accepted_by_order');
        }

        return null;
    }
}
