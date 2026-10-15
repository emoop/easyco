<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Stage C1e (checkout visual pass): the markup the floating labels need (a label BEFORE its control inside `.sf-float`, a one-space placeholder),
 * the fields that are NOT floating, the postal code next to the place, the express divider, and the stylesheet's rules as static checks (how it LOOKS
 * is the owner's to see in a browser; what is pinned is that the rules exist, are scoped where they must be, and degrade to "label above").
 */
class StorefrontCheckoutFloatingFieldsTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
        app(SiteSettingsRepository::class)->set('site.locale', 'en');
    }

    private function dom(?string $html = null): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.($html ?? $this->get('/checkout')->assertOk()->getContent()));
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css');
    }

    /** The text between the braces of the first block that starts at `$header`. */
    private function block(string $css, string $header): string
    {
        $start = strpos($css, $header);
        $this->assertNotFalse($start, "{$header} exists");
        $open = strpos($css, '{', $start);
        $depth = 0;

        for ($i = $open, $n = strlen($css); $i < $n; $i++) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);

            if ($depth === 0) {
                return substr($css, $open + 1, $i - $open - 1);
            }
        }

        $this->fail("{$header} is never closed");
    }

    private function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    // ---- the markup ---------------------------------------------------------------------------------------------------------------------

    public function test_every_floating_text_field_has_its_label_before_the_control_and_a_one_space_placeholder(): void
    {
        $xpath = $this->dom();
        $fields = $xpath->query('//*['.$this->hasClass('sf-float').' and not('.$this->hasClass('sf-float-up').')]');

        $this->assertGreaterThanOrEqual(15, $fields->length, 'the contact, delivery and invoice fields');

        foreach ($fields as $field) {
            $order = [];
            foreach ($field->childNodes as $child) {
                if ($child instanceof \DOMElement && in_array($child->tagName, ['label', 'input'], true)) {
                    $order[] = $child->tagName;
                }
            }

            $this->assertSame(['label', 'input'], $order, 'the label precedes its control in the DOM (reading order)');
            $input = $xpath->query('./input', $field)->item(0);
            $this->assertContains($input->getAttribute('type'), ['text', 'email', 'tel']);
            $this->assertSame(' ', $input->getAttribute('placeholder'), $input->getAttribute('name').' carries placeholder=" "');
            $this->assertSame($input->getAttribute('id'), $xpath->query('./label', $field)->item(0)->getAttribute('for'));
        }
    }

    public function test_every_text_email_and_tel_input_floats_except_the_invoice_email(): void
    {
        $xpath = $this->dom();
        $loose = [];

        foreach ($xpath->query('//form//input[@type="text" or @type="email" or @type="tel"]') as $input) {
            if ($xpath->query('ancestor::*['.$this->hasClass('sf-float').']', $input)->length === 0) {
                $loose[] = $input->getAttribute('name');
            }
        }

        $this->assertSame(['invoice_email'], $loose, 'its placeholder is the order email (the script writes it): it keeps label-above and a real placeholder');
        $this->assertSame('', $xpath->query('//input[@name="invoice_email"]')->item(0)->getAttribute('placeholder'), 'no floating placeholder on it');
    }

    public function test_the_note_textarea_checkboxes_radios_and_payment_cards_are_not_floating(): void
    {
        $xpath = $this->dom();

        $this->assertSame(0, $xpath->query('//*['.$this->hasClass('sf-float').']//textarea')->length);
        $this->assertSame(0, $xpath->query('//*['.$this->hasClass('sf-float').']//input[@type="checkbox" or @type="radio"]')->length);
        $this->assertSame(0, $xpath->query('//*['.$this->hasClass('sf-note-field').' and '.$this->hasClass('sf-float').']')->length);
        $this->assertSame(0, $xpath->query('//*['.$this->hasClass('sf-check').' and '.$this->hasClass('sf-float').']')->length);
        $this->assertSame(0, $xpath->query('//*['.$this->hasClass('sf-pay-card').' and '.$this->hasClass('sf-float').']')->length);
        $this->assertSame('Order note', trim($xpath->query('//label[@for="sf-note"]')->item(0)->textContent), 'the note keeps its label above');
        $this->assertNotSame('', $xpath->query('//textarea[@id="sf-note"]')->item(0)->getAttribute('placeholder'), 'and its real placeholder');
    }

    public function test_the_selects_are_floating_fields_with_the_label_always_up(): void
    {
        $xpath = $this->dom();

        foreach (['sf-country', 'sf-invoice-country', 'sf-delivery-destination'] as $id) {
            $select = $xpath->query('//select[@id="'.$id.'"]')->item(0);
            $this->assertNotNull($select, $id);
            $parent = $select->parentNode;
            $this->assertStringContainsString('sf-float', $parent->getAttribute('class'), $id);
            $this->assertStringContainsString('sf-float-up', $parent->getAttribute('class'), "{$id}: a select always shows a value, so its label is always up");
            $this->assertSame('label', $xpath->query('./*[1]', $parent)->item(0)->tagName, 'the label comes first');
        }
    }

    public function test_the_required_asterisk_stays_inside_the_label(): void
    {
        $xpath = $this->dom();

        foreach (['sf-email', 'sf-first-name', 'sf-phone', 'sf-country', 'sf-city'] as $id) {
            $this->assertSame(1, $xpath->query('//label[@for="'.$id.'"]/span['.$this->hasClass('sf-required').']')->length, $id);
        }
    }

    // ---- G4: the postal code ------------------------------------------------------------------------------------------------------------

    public function test_the_postal_code_is_on_the_row_of_the_place_and_out_of_the_street_block(): void
    {
        $xpath = $this->dom();
        $row = $xpath->query('//input[@id="sf-city"]/ancestor::*['.$this->hasClass('sf-field-row').'][1]')->item(0);

        $this->assertNotNull($row, 'the place is in a two-field row');
        $this->assertSame(['sf-city', 'sf-postal-code'], array_map(fn (\DOMElement $i) => $i->getAttribute('id'), iterator_to_array($xpath->query('.//input', $row))));
        $this->assertStringContainsString('sf-place-row', $row->getAttribute('class'));
        $this->assertSame(0, $xpath->query('//*[@data-sf-address-fields]//input[@name="postal_code"]')->length, 'no longer in the street-address block');
        $this->assertSame(1, $xpath->query('//*[@data-sf-postal-field]//input[@name="postal_code"]')->length, 'the script toggles this holder');
        $this->assertSame('Postal code (optional)', trim($xpath->query('//label[@for="sf-postal-code"]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//p[@id="sf-err-postal_code" and @data-sf-error-for="postal_code"]')->length, 'the same error holder');
        $this->assertSame(0, $xpath->query('//*[@data-sf-postal-field and @hidden]')->length, 'visible until the script hides it for a pick-up kind');
    }

    public function test_the_postal_label_is_translated(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $this->assertSame('Пощенски код (по желание)', trim($this->dom()->query('//label[@for="sf-postal-code"]')->item(0)->textContent));
    }

    public function test_the_invoice_city_and_postal_code_share_a_row(): void
    {
        $xpath = $this->dom();
        $row = $xpath->query('//input[@id="sf-invoice-city"]/ancestor::*['.$this->hasClass('sf-field-row').'][1]')->item(0);

        $this->assertNotNull($row);
        $this->assertSame(['sf-invoice-city', 'sf-invoice-postal-code'], array_map(fn (\DOMElement $i) => $i->getAttribute('id'), iterator_to_array($xpath->query('.//input', $row))));
        $this->assertSame(1, $xpath->query('//p[@id="sf-err-invoice_city"]')->length);
        $this->assertSame(1, $xpath->query('//p[@id="sf-err-invoice_postal_code"]')->length);
    }

    // ---- G5: the express divider --------------------------------------------------------------------------------------------------------

    public function test_the_divider_is_absent_with_an_empty_express_slot_and_follows_a_filled_one(): void
    {
        $this->assertStringNotContainsString('sf-express-divider', $this->get('/checkout')->getContent(), 'nothing while the slot is empty');

        $dir = sys_get_temp_dir().'/sf-divider-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/wallet.blade.php', '<div class="wallet-test">PAYPAL BUTTON</div>');
        View::addNamespace('dividerext', $dir);

        try {
            Hook::filter('storefront.checkout.express', fn (array $partials): array => [...$partials, 'dividerext::wallet']);
            $html = $this->get('/checkout')->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<\/section>\s*<p class="sf-express-divider" role="separator"><span>OR<\/span><\/p>\s*<p class="sf-required-note">/', $html, 'right after the slot, before the Contact card');
            $this->assertLessThan(strpos($html, 'id="sf-email"'), strpos($html, 'sf-express-divider'));

            app(SiteSettingsRepository::class)->set('site.locale', 'bg');
            $this->assertStringContainsString('<span>ИЛИ</span>', $this->get('/checkout')->getContent());
        } finally {
            @unlink($dir.'/wallet.blade.php');
            @rmdir($dir);
        }
    }

    // ---- the stylesheet -----------------------------------------------------------------------------------------------------------------

    public function test_the_float_rules_live_inside_the_has_support_query_and_nothing_floats_outside_it(): void
    {
        $css = $this->css();
        $supports = $this->block($css, '@supports selector(:has(*))');
        $outside = str_replace($supports, '', $css);

        foreach (['.sf-float > label', ':placeholder-shown', ':autofill', ':-webkit-autofill', '.sf-float-up > label', 'pointer-events: none;', 'position: absolute;', 'transition: transform'] as $needle) {
            $this->assertStringContainsString($needle, $supports, "{$needle} is part of the enhancement");
        }

        $this->assertStringNotContainsString(':placeholder-shown', $outside, 'without :has() nothing floats: the label stays above');
        // (S3-V2: the checkout summary's fade overlay also has `pointer-events: none`; it is not a floating label, so only the label's own rules are checked.)
        $this->assertDoesNotMatchRegularExpression('/\.sf-float[^{]*\{[^}]*position: absolute/', $outside);
    }

    public function test_a_short_transition_that_reduced_motion_removes(): void
    {
        $supports = $this->block($this->css(), '@supports selector(:has(*))');

        $this->assertMatchesRegularExpression('/transition: transform (\d+)ms ease, color \1ms ease;/', $supports);
        preg_match('/transition: transform (\d+)ms/', $supports, $ms);
        $this->assertLessThanOrEqual(150, (int) $ms[1], 'at most 150ms');

        $reduced = $this->block($supports, '@media (prefers-reduced-motion: reduce)');
        $this->assertMatchesRegularExpression('/\.sf-float > label,\s*\.sf-promo-compact-form > \.sf-promo-compact-label \{\s*transition: none;/', $reduced);
    }

    public function test_the_error_colour_is_keyed_on_aria_invalid(): void
    {
        $css = $this->css();
        $supports = $this->block($css, '@supports selector(:has(*))');

        $this->assertMatchesRegularExpression("/\\.sf-float:has\\(> \\[aria-invalid='true'\\]\\) > label \\{\\s*color: #b91c1c;/", $supports, 'the label');
        $this->assertStringContainsString(".sf-field input[aria-invalid='true'],\n    .sf-field select[aria-invalid='true'] {", $css, 'the border (and the field background)');
        $this->assertStringContainsString("[aria-invalid='true']:focus-visible", $css, 'an invalid field keeps the error colour while focused');
    }

    public function test_the_controls_are_52px_with_one_focus_style(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression("/\\.sf-field input\\[type='tel'\\],\\s*\\.sf-field select,\\s*\\.sf-kind-select \\{\\s*@apply h-\\[3\\.25rem\\] w-full rounded-lg border border-neutral-300/", $css, 'inputs and selects: 52px, 8px radius, a 1px neutral border');
        $this->assertStringContainsString('@apply h-[3.25rem] min-w-0 flex-1', $css, 'the promo field');
        $this->assertStringContainsString('@apply h-[3.25rem] shrink-0', $css, 'and its Apply button');
        $this->assertStringContainsString('min-h-[3.25rem] w-full', $css, 'the Place-order button');

        $focus = $this->block($css, ".sf-checkout-form input[type='text']:focus-visible");
        $this->assertStringContainsString('outline: 2px solid #0369a1;', $focus);
        $this->assertStringContainsString('outline-offset: 0;', $focus);
        $this->assertStringContainsString('border-color: #0369a1;', $focus);
    }

    public function test_the_sections_are_cards_with_their_legend_inside(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('@apply min-w-0 rounded-xl border border-neutral-200 bg-white p-5 sm:p-6;', $css);
        $this->assertStringContainsString('@apply float-left mb-3 w-full p-0 text-lg font-semibold text-neutral-900;', $css, 'a floated legend sits inside the card, not on its border');
        $this->assertMatchesRegularExpression('/\.sf-legend \+ \* \{\s*clear: both;/', $css);
        $this->assertStringContainsString('.sf-place-row:has(> [hidden]) {', $css, 'the place takes the whole row when the postal code is hidden');
    }
}
