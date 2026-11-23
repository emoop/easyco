<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyConfirmationRequester;
use App\Storefront\Reader\CatalogReader;
use EasyCo\Account\Security\SignedLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ACC-4d (acc-4-gdpr-design.md §6, D2): the identity-confirmation page of a privacy request. `GET|POST /account/privacy/confirm?id=&expires=&signature=` (the link of the
 * `privacy.confirm` mail, SignedLink purpose `privacy-confirm`).
 *
 *  - GET writes NOTHING (mail scanners and prefetchers open links): it shows the one-button page for a request still `awaiting_confirmation`.
 *  - POST sets `confirmed` + `confirmed_at` ONLY while the request is still `awaiting_confirmation` (one conditional UPDATE: two POSTs at once confirm once); a second POST, or a
 *    request already `confirmed`, shows the same «confirmed» page and changes nothing. Idempotent. Nothing is exported or erased by it.
 *  - A bad, tampered, wrong-purpose or expired link, an unknown request, and a request that is `expired`, `processing`, `done` or `failed`: ONE neutral page and one status (404),
 *    on GET and on POST alike; nothing is written and nothing tells which of these it was.
 *
 * POST PROTECTION: the storefront has no session and no CSRF token (routes/storefront.php); the signed link IS the capability. A forged cross-site POST needs the signature, which
 * only the person who got the mail has, and the effect is one narrow, idempotent flag. A per-IP limiter (`privacy-confirm`) guards the POST. The page shows no email and no other
 * personal data. Headers (no-store, private; noindex; no-referrer) come from AccountPageHeaders on the route; the pipeline sets no cookie.
 */
final class PrivacyConfirmController extends Controller
{
    public function show(Request $request, CatalogReader $reader): Response
    {
        $row = $this->request($request);

        if ($row === null) {
            return $this->page('invalid', null, $reader);
        }

        return $row->status === PrivacyRequestModel::STATUS_AWAITING_CONFIRMATION
            ? $this->page('form', $row->kind, $reader)
            : $this->page('done', null, $reader);
    }

    public function confirm(Request $request, CatalogReader $reader): Response
    {
        $row = $this->request($request);

        if ($row === null) {
            return $this->page('invalid', null, $reader);
        }

        // the ONE write: only a request that still waits
        PrivacyRequestModel::query()
            ->whereKey($row->getKey())
            ->where('status', PrivacyRequestModel::STATUS_AWAITING_CONFIRMATION)
            ->update(['status' => PrivacyRequestModel::STATUS_CONFIRMED, 'confirmed_at' => now()]);

        return $this->page('done', null, $reader);
    }

    /** The request of a valid link that may still be answered with the form or the thanks (`awaiting_confirmation` or `confirmed`), else null. */
    private function request(Request $request): ?PrivacyRequestModel
    {
        $id = $request->query('id');
        $expires = $request->query('expires');
        $signature = $request->query('signature');

        // a parameter sent as an array (`expires[]=1`) is not a string: neutral page, never an error
        if (! is_string($id) || ! is_string($expires) || ! is_string($signature) || preg_match('/^[0-9]{1,18}$/', $id) !== 1 || ! SignedLink::valid(PrivacyConfirmationRequester::PURPOSE, $id, $expires, $signature)) {
            return null;
        }

        $row = PrivacyRequestModel::query()->find((int) $id);

        if ($row === null || ! in_array($row->status, [PrivacyRequestModel::STATUS_AWAITING_CONFIRMATION, PrivacyRequestModel::STATUS_CONFIRMED], true)) {
            return null;
        }

        return $row;
    }

    private function page(string $state, ?string $kind, CatalogReader $reader): Response
    {
        $heading = (string) __('privacy_confirm.'.match ($state) {
            'form' => 'title',
            'done' => 'done_title',
            default => 'invalid_title',
        });

        // the same neutral page and the same status for every kind of invalid link
        return response()->view('storefront.privacy.confirm', [
            'state' => $state,
            'kind' => $kind,
            'heading' => $heading,
            'categories' => $reader->categoryTree(),
            'seo' => ['title' => $heading.' — '.config('app.name'), 'description' => '', 'canonical' => null],
        ], $state === 'invalid' ? 404 : 200);
    }
}
