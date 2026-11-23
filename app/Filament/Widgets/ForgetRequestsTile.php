<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Pages\PrivacyLog;
use App\Privacy\ForgetPending;
use EasyCo\Staff\Enums\Permission;
use Filament\Widgets\Widget;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md D6): the «Табло» tile «Заявка за забравяне»: the erase requests whose clear view is still on and that still wait for manual steps (ForgetPending), the person whose
 * clear view ends soonest, and a link to the log. Shown ONLY to PRIVACY_MANAGE and ONLY when the count is above zero: `canView()` is evaluated on the server for every dashboard render.
 */
class ForgetRequestsTile extends Widget
{
    use AuthorizesViaStaffPermission;

    protected string $view = 'filament.widgets.forget-requests-tile';

    protected static ?int $sort = 5;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return static::staffCanForAction(Permission::PRIVACY_MANAGE) && ForgetPending::summary() !== null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $summary = ForgetPending::summary();

        return ['summary' => $summary ?? ['count' => 0, 'email' => null, 'days' => 0], 'logUrl' => PrivacyLog::getUrl()];
    }
}
