<?php

namespace Database\Seeders;

use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Database\Seeder;

/**
 * The two EMPTY drafts of pages-design.md section 8: «Общи условия» (`obshti-usloviya`) and «Политика за поверителност»
 * (`politika-za-poveritelnost`), so the merchant sees where those texts go. Empty body, status draft, not in the footer, never published
 * by default. The platform ships NO legal text.
 *
 * CREATED ONCE: a marker setting (`content.drafts_seeded`) records that this ran, so a draft the merchant renamed or archived is not
 * created again by the next `db:seed`; and a page whose slug already exists is left alone. Safe to run on an existing install:
 * `php artisan db:seed --class=ContentDraftsSeeder`.
 */
class ContentDraftsSeeder extends Seeder
{
    public const MARKER_KEY = 'content.drafts_seeded';

    public const DRAFTS = [
        'obshti-usloviya' => 'pages.drafts.terms_title',
        'politika-za-poveritelnost' => 'pages.drafts.privacy_title',
    ];

    public function run(): void
    {
        $settings = app(SiteSettingsRepository::class);

        if ($settings->get(self::MARKER_KEY) === '1') {
            return;
        }

        $writer = app(PageWriter::class);

        foreach (self::DRAFTS as $slug => $titleKey) {
            if (PageModel::query()->where('slug', $slug)->exists()) {
                continue;
            }

            $writer->create([
                'title' => (string) __($titleKey, [], app(\App\Settings\StoreLocale::class)->current()),
                'slug' => $slug,
                'body' => '',
                'status' => PageModel::DRAFT,
                'in_footer' => false,
            ]);
        }

        $settings->set(self::MARKER_KEY, '1');
    }
}
