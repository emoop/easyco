<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only. ViewRecord::authorizeAccess() already correctly calls
 * abort_unless(canView($record), 403) by default — see RoleResource\
 * Pages\ViewRole's identical note — no override needed here. Duplicate is
 * here too, per admin-panel-design.md §13.2's own "row action on the list,
 * header action on View" spec.
 *
 * THE TWO DELETE-SLOT ACTIONS (catalog-domain-design.md §3.19.8 B) are
 * mutually exclusive by construction and both come from ProductResource, so
 * this page and the list row mount the exact same objects:
 *
 *  - `deleteProductAction()` — only for an ARCHIVED product, with
 *    PRODUCT_DELETE. The record it works on is this page's own `$record`
 *    (there is no id parameter anywhere), and its own closure re-checks both
 *    facts server-side.
 *  - `archiveFirstAction()` — the opposite case: a product that is not
 *    archived yet, where the delete does not exist to press. It states G-D3's
 *    two-step rule and offers the real first step (the product's Edit page,
 *    which is where Status is set to Archived), rather than a dead button.
 *
 * There is deliberately still NO product delete ACTION on the Edit pages:
 * §3.19.8 B places it on View and the list, and EditProduct/
 * EditVariableProduct already carry their own save-oriented actions.
 */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProductResource::historyAction(),
            ProductResource::duplicateAction(),
            ProductResource::deleteProductAction(),
            ProductResource::archiveFirstAction(),
        ];
    }
}
