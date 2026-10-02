<?php

namespace Tests\Feature;

use App\Services\SettlementNormalizerResolver;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Shipping\Contracts\SettlementNameNormalizer;
use EasyCo\Shipping\Matching\BulgarianSettlementNameNormalizer;
use EasyCo\Shipping\Matching\NeutralSettlementNameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shipping stage 3a: the normalizer is chosen by the STORE locale, through named container bindings. */
class SettlementNormalizerResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): SettlementNormalizerResolver
    {
        return app(SettlementNormalizerResolver::class);
    }

    public function test_bg_and_its_regional_forms_get_the_bulgarian_normalizer(): void
    {
        foreach (['bg', 'BG', 'bg_BG', 'bg-BG', ' bg '] as $locale) {
            $this->assertInstanceOf(BulgarianSettlementNameNormalizer::class, $this->resolver()->forLocale($locale), $locale);
        }
    }

    public function test_an_unknown_or_empty_locale_resolves_to_the_neutral_normalizer_never_a_refusal(): void
    {
        foreach (['en', 'xx', 'zz_ZZ', '', 'not a locale!', '../etc', 'de_DE'] as $locale) {
            $normalizer = $this->resolver()->forLocale($locale);

            $this->assertSame(NeutralSettlementNameNormalizer::class, $normalizer::class, var_export($locale, true));
        }
    }

    public function test_the_store_locale_decides(): void
    {
        $settings = app(SiteSettingsRepository::class);

        $settings->set('site.locale', 'bg');
        $this->assertInstanceOf(BulgarianSettlementNameNormalizer::class, app(SettlementNormalizerResolver::class)->forCurrentLocale());

        $settings->set('site.locale', 'en');
        $this->assertSame(NeutralSettlementNameNormalizer::class, app(SettlementNormalizerResolver::class)->forCurrentLocale()::class);
    }

    public function test_an_extension_adds_a_locale_by_binding_a_name_without_editing_core(): void
    {
        $extension = new class implements SettlementNameNormalizer
        {
            public function normalize(string $name): string
            {
                return 'ext:'.$name;
            }
        };

        $this->app->bind('shipping.settlement_normalizer.xx', fn () => $extension);
        $this->app->bind('shipping.settlement_normalizer.pt_br', fn () => new class implements SettlementNameNormalizer
        {
            public function normalize(string $name): string
            {
                return 'pt-br';
            }
        });

        $this->assertSame($extension, $this->resolver()->forLocale('xx'));
        $this->assertSame($extension, $this->resolver()->forLocale('xx_YY'), 'the language is tried after the whole locale');
        $this->assertSame('pt-br', $this->resolver()->forLocale('pt-BR')->normalize('x'), 'the whole locale wins over its language');
        $this->assertSame(NeutralSettlementNameNormalizer::class, $this->resolver()->forLocale('pt_PT')::class);
    }
}
