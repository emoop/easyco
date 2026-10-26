<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\Design\DesignScreen;
use Illuminate\Http\RedirectResponse;

/**
 * DS-3: the old admin pages «Хедър» (`/admin/header-settings`) and «Футър» (`/admin/footer`) are gone; their urls open the «Дизайн» screen on the same zone (302, never permanent). The route sits behind the
 * panel's login; a staff member without `content_manage` gets the 403 the old pages gave (the permission of the screen itself).
 */
final class DesignRedirectController
{
    public function header(): RedirectResponse
    {
        return $this->to('header');
    }

    public function footer(): RedirectResponse
    {
        return $this->to('footer');
    }

    private function to(string $zone): RedirectResponse
    {
        abort_unless(DesignScreen::canAccess(), 403);

        return redirect()->to(DesignScreen::getUrl(['zone' => $zone]));
    }
}
