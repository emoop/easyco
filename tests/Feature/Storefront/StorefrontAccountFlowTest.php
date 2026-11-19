<?php

namespace Tests\Feature\Storefront;

use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/**
 * ACC-3b-2: the account area's script (storefront-account.js: delete / make default on the cards, the address form, the password and the email form) on the REAL
 * rendered pages, driven in the fake DOM (tests/Feature/Storefront/Js/account-flow.mjs). No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontAccountFlowTest extends TestCase
{
    use DrivesAccountApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
    }

    /** @return array<string, array{0: string, 1: string}> scenario => [scenario, page] */
    public static function scenarios(): array
    {
        $pages = [
            'delete_first_click_asks_and_sends_nothing' => 'book',
            'delete_cancel_restores_the_buttons' => 'book',
            'delete_yes_sends_ONE_delete_and_goes_to_the_notice' => 'book',
            'make_default_sends_ONE_post_and_goes_to_the_notice' => 'book',
            'a_429_on_delete_shows_try_again_in_minutes_and_unlocks_the_card' => 'book',
            'form_422_puts_each_error_under_its_field_and_keeps_the_typed_values' => 'new',
            'form_success_goes_to_the_saved_notice_and_never_double_submits' => 'new',
            'edit_form_puts_to_the_address' => 'edit',
            'form_follows_the_region_rule_of_the_country' => 'new',
            'form_429_shows_try_again_in_minutes' => 'new',
            'password_mismatch_is_caught_before_sending' => 'details',
            'password_422_shows_the_error_under_the_field' => 'details',
            'password_success_stays_signed_in_and_goes_to_the_details_notice' => 'details',
            'password_401_means_the_session_is_gone_and_goes_to_the_login' => 'details',
            'email_success_goes_to_the_email_sent_notice' => 'details',
            'email_422_wrong_current_password_shows_under_the_field' => 'details',
        ];

        $out = [];

        foreach ($pages as $scenario => $page) {
            $out[$scenario] = [$scenario, $page];
        }

        return $out;
    }

    private function street(string $accountId, string $line1): string
    {
        $address = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientFirstName: 'Ivan',
            recipientLastName: 'Petrov',
            phone: '+359888123456',
            accountId: $accountId,
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: $line1,
        );
        app(AddressRepository::class)->save($address);

        return (string) $address->id();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_the_script_flow(string $scenario, string $page): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $account = $this->verifiedAccount('me@example.com');
        $this->actingAs($account, 'customer');
        $first = $this->street((string) $account->getKey(), 'First Street 1');
        $second = $this->street((string) $account->getKey(), 'Second Street 2');
        app(AddressRepository::class)->setDefault($first, (string) $account->getKey());

        $url = match ($page) {
            'book' => '/account/addresses',
            'new' => '/account/addresses/new',
            'edit' => '/account/addresses/'.$second.'/edit',
            default => '/account/details',
        };

        $file = tempnam(sys_get_temp_dir(), 'ckacc').'.html';
        file_put_contents($file, $this->get($url)->assertOk()->getContent());

        try {
            $output = [];
            exec('node '.escapeshellarg(__DIR__.'/Js/account-flow.mjs').' '.escapeshellarg($file).' '.escapeshellarg($scenario).' 2>&1', $output, $code);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('OK '.$scenario, implode("\n", $output));
    }
}
