<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Matching\BulgarianSettlementNameNormalizer;
use EasyCo\Shipping\Matching\NeutralSettlementNameNormalizer;
use PHPUnit\Framework\TestCase;

final class SettlementNameNormalizerTest extends TestCase
{
    private NeutralSettlementNameNormalizer $neutral;

    private BulgarianSettlementNameNormalizer $bg;

    protected function setUp(): void
    {
        $this->neutral = new NeutralSettlementNameNormalizer();
        $this->bg = new BulgarianSettlementNameNormalizer();
    }

    // --- the neutral rules ---------------------------------------------------------------------

    public function test_the_neutral_normalizer_folds_case(): void
    {
        $this->assertSame('софия', $this->neutral->normalize('СОФИЯ'));
        $this->assertSame('strasse', $this->neutral->normalize('Straße'), 'case FOLDING, not only lower-casing');
    }

    public function test_a_decomposed_letter_equals_the_composed_one(): void
    {
        $nfd = "Бели\u{0438}\u{0306}"; // "Бели" + и + combining breve
        $nfc = "Бели\u{0439}";         // "Бели" + й

        $this->assertNotSame($nfc, $nfd);
        $this->assertSame($this->neutral->normalize($nfc), $this->neutral->normalize($nfd));
        $this->assertSame($this->bg->normalize($nfc), $this->bg->normalize($nfd));
    }

    public function test_the_result_is_in_nfc_even_when_case_folding_decomposes_a_letter(): void
    {
        // U+0390 (Greek small iota with dialytika and tonos) FOLDS to iota + U+0308 + U+0301:
        // folding alone leaves a decomposed sequence, so NFC must run again after it.
        $folded = mb_convert_case("\u{0390}", MB_CASE_FOLD, 'UTF-8');
        $this->assertFalse(\Normalizer::isNormalized($folded, \Normalizer::FORM_C), 'precondition: folding really decomposes this letter');

        foreach ([$this->neutral, $this->bg] as $normalizer) {
            $result = $normalizer->normalize("\u{0390}");

            $this->assertTrue(\Normalizer::isNormalized($result, \Normalizer::FORM_C));
            $this->assertSame("\u{0390}", $result);
            $this->assertSame($result, $normalizer->normalize("\u{03B9}\u{0308}\u{0301}"), 'composed and decomposed spellings are one name');
            $this->assertSame($result, $normalizer->normalize("\u{03AA}\u{0301}"), 'the capital form too');
        }
    }

    public function test_extra_spaces_and_non_breaking_spaces_collapse_to_one_space(): void
    {
        $this->assertSame('нова загора', $this->neutral->normalize("  Нова   Загора  "));
        $this->assertSame('нова загора', $this->neutral->normalize("Нова\u{00A0}\u{00A0}Загора"));
        $this->assertSame('нова загора', $this->neutral->normalize("Нова\t\n\u{2003}\u{202F}Загора\u{3000}"));
    }

    public function test_dash_variants_are_all_the_plain_hyphen(): void
    {
        $plain = $this->neutral->normalize('Стара-Загора');

        foreach (["\u{2010}", "\u{2011}", "\u{2013}", "\u{2014}"] as $dash) {
            $this->assertSame($plain, $this->neutral->normalize("Стара{$dash}Загора"), 'U+'.dechex(mb_ord($dash)));
        }

        $this->assertSame('стара-загора', $plain);
    }

    public function test_the_neutral_normalizer_does_not_strip_a_settlement_prefix(): void
    {
        $this->assertSame('гр. софия', $this->neutral->normalize('гр. София'));
        $this->assertNotSame($this->neutral->normalize('София'), $this->neutral->normalize('гр. София'));
    }

    public function test_there_is_no_transliteration(): void
    {
        $this->assertNotSame($this->neutral->normalize('Sofia'), $this->neutral->normalize('София'));
        $this->assertNotSame($this->bg->normalize('Sofia'), $this->bg->normalize('София'));
    }

    public function test_a_blank_name_normalizes_to_the_empty_string(): void
    {
        $this->assertSame('', $this->neutral->normalize(''));
        $this->assertSame('', $this->neutral->normalize(" \u{00A0}\t "));
        $this->assertSame('', $this->bg->normalize('   '));
    }

    public function test_invalid_utf8_normalizes_to_the_empty_string_instead_of_throwing(): void
    {
        $this->assertSame('', $this->neutral->normalize("Sofia\xC3\x28"));
        $this->assertSame('', $this->bg->normalize("\xFF\xFE"));
    }

    // --- the Bulgarian rules -------------------------------------------------------------------

    public function test_under_bg_the_town_prefixes_and_the_bare_name_are_equal(): void
    {
        $expected = 'софия';

        foreach (['гр. София', 'ГР. СОФИЯ', 'град София', 'София', 'гр.София', 'гр София', '  Гр.   София '] as $input) {
            $this->assertSame($expected, $this->bg->normalize($input), $input);
        }
    }

    public function test_under_bg_the_village_prefixes_are_stripped(): void
    {
        foreach (['с. Драгалевци', 'с.Драгалевци', 'с Драгалевци', 'село Драгалевци', 'СЕЛО ДРАГАЛЕВЦИ'] as $input) {
            $this->assertSame('драгалевци', $this->bg->normalize($input), $input);
        }
    }

    public function test_under_bg_a_district_prefix_is_kept(): void
    {
        $this->assertSame('кв. лозенец', $this->bg->normalize('кв. Лозенец'));
        $this->assertSame('ж.к. младост', $this->bg->normalize('ж.к. Младост'));
        $this->assertNotSame($this->bg->normalize('Лозенец'), $this->bg->normalize('кв. Лозенец'));
    }

    public function test_under_bg_only_one_prefix_is_stripped_and_a_word_that_merely_starts_with_one_is_untouched(): void
    {
        $this->assertSame('с. драгалевци', $this->bg->normalize('гр. с. Драгалевци'));
        $this->assertSame('градец', $this->bg->normalize('Градец'));
        $this->assertSame('сопот', $this->bg->normalize('Сопот'));
        $this->assertSame('селце', $this->bg->normalize('Селце'));
        $this->assertSame('с', $this->bg->normalize('с'), 'a bare "с" is a name, not a prefix');
    }

    public function test_under_bg_dash_and_space_rules_still_apply_after_the_prefix(): void
    {
        $this->assertSame('стара-загора', $this->bg->normalize("гр.\u{00A0}Стара\u{2013}Загора"));
    }
}
