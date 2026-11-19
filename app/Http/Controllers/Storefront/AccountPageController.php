<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Storefront\Account\SafeRedirect;
use App\Storefront\Reader\CatalogReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * ACC-3a: the storefront account pages. Server SHELLS driven by storefront-account.js on the EXISTING stateful account API (the same session / CSRF flow as
 * the cart: GET /sanctum/csrf-cookie, then X-XSRF-TOKEN). Every page answers `private, no-store` + noindex (App\Storefront\Account\AccountPageHeaders) and
 * loads nothing from another host.
 *
 * login / register / forgot / reset / verify / emailConfirm are COOKIE-FREE like the rest of the storefront (routes/storefront.php). The token pages carry
 * their secrets only in the url: the HTML never contains them, the script reads them from the address bar and sends them in the POST body.
 *
 * home() (`/account`) is the ONE account page with a session (it has to know who is signed in to redirect a guest): never cacheable, and the stale-session
 * soft check runs first, so a stale login is a guest here too.
 *
 * VIEWS receive: `categories` (the menu), `seo` {title, description, canonical: null}, and per page:
 *   login: `redirect` (a SafeRedirect path), `notice` (?string); home: `email`.
 */
final class AccountPageController extends Controller
{
    public function login(Request $request, CatalogReader $reader): Response
    {
        $notice = $request->query('notice') === 'password-reset' ? (string) __('account_pages.notice_password_reset') : null;

        return $this->page('login', 'login_title', $reader, ['redirect' => SafeRedirect::path($request->query('redirect')), 'notice' => $notice]);
    }

    public function register(CatalogReader $reader): Response
    {
        return $this->page('register', 'register_title', $reader);
    }

    public function forgot(CatalogReader $reader): Response
    {
        return $this->page('forgot', 'forgot_title', $reader);
    }

    public function reset(CatalogReader $reader): Response
    {
        return $this->page('reset', 'reset_title', $reader);
    }

    public function verify(CatalogReader $reader): Response
    {
        return $this->page('verify', 'verify_title', $reader);
    }

    public function emailConfirm(CatalogReader $reader): Response
    {
        return $this->page('email-confirm', 'confirm_title', $reader);
    }

    /** A guest goes to the login with this page as the way back; a signed-in customer sees the ACC-3a placeholder (ACC-3b replaces the body). */
    public function home(CatalogReader $reader): Response|RedirectResponse
    {
        $account = Auth::guard('customer')->user();

        if ($account === null) {
            return redirect('/account/login?redirect=/account');
        }

        return $this->page('home', 'title', $reader, ['email' => (string) $account->email]);
    }

    /** @param  array<string, mixed>  $data */
    private function page(string $view, string $titleKey, CatalogReader $reader, array $data = []): Response
    {
        return response()->view('storefront.account.'.$view, $data + [
            'categories' => $reader->categoryTree(),
            'seo' => [
                'title' => __('account_pages.'.$titleKey).' — '.config('app.name'),
                'description' => '',
                'canonical' => null,
            ],
        ]);
    }
}
