<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Matching\BulgarianSettlementNameNormalizer;
use EasyCo\Shipping\Matching\NeutralSettlementNameNormalizer;
use EasyCo\Shipping\Matching\ZoneDestination;
use EasyCo\Shipping\Matching\ZoneMatcher;
use EasyCo\Shipping\Matching\ZoneMatchResult;
use EasyCo\Shipping\ShippingZone;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ZoneMatcherTest extends TestCase
{
    private ZoneMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ZoneMatcher();
    }

    /** @param list<string> $countries */
    private function zone(string $id, int $sortOrder, array $countries = ['BG'], ?array $names = null, ?array $postcodes = null): ShippingZone
    {
        return ShippingZone::reconstituteFromStorage($id, "zone-{$id}", $sortOrder, $countries, $names, $postcodes);
    }

    private function matchedId(array $zones, ZoneDestination $destination, $normalizer = null): ?string
    {
        $result = $this->matcher->match($zones, $destination, $normalizer ?? new NeutralSettlementNameNormalizer());

        return $result->isMatched() ? $result->zone()->id() : null;
    }

    // --- order and refusal -----------------------------------------------------------------------

    public function test_of_two_matching_zones_the_lower_sort_order_wins(): void
    {
        $broad = $this->zone('1', 10);
        $narrow = $this->zone('2', 5, names: ['София']);

        $this->assertSame('2', $this->matchedId([$broad, $narrow], new ZoneDestination('BG', 'София')));
        $this->assertSame('2', $this->matchedId([$narrow, $broad], new ZoneDestination('BG', 'София')), 'input order does not matter');
        $this->assertSame('1', $this->matchedId([$broad, $narrow], new ZoneDestination('BG', 'Пловдив')), 'falls through to the broad zone');
    }

    public function test_with_equal_sort_order_the_lower_id_wins(): void
    {
        $this->assertSame('2', $this->matchedId([$this->zone('10', 1), $this->zone('2', 1)], new ZoneDestination('BG')), 'ids compare as numbers: 2 before 10');
        $this->assertSame('2', $this->matchedId([$this->zone('2', 1), $this->zone('10', 1)], new ZoneDestination('BG')));
    }

    public function test_no_match_is_an_explicit_refusal_not_null_or_an_empty_list(): void
    {
        $result = $this->matcher->match([$this->zone('1', 1, ['BG'])], new ZoneDestination('GR'), new NeutralSettlementNameNormalizer());

        $this->assertFalse($result->isMatched());
        $this->assertSame('no_zone_for_destination', $result->refusalReason());
        $this->assertSame(ZoneMatchResult::NO_ZONE_FOR_DESTINATION, $result->refusalReason());
    }

    public function test_no_zones_at_all_is_the_same_refusal(): void
    {
        $result = $this->matcher->match([], new ZoneDestination('BG'), new NeutralSettlementNameNormalizer());

        $this->assertFalse($result->isMatched());
        $this->assertSame('no_zone_for_destination', $result->refusalReason());
    }

    public function test_asking_a_refusal_for_its_zone_fails_loudly(): void
    {
        $this->expectException(LogicException::class);

        $this->matcher->match([], new ZoneDestination('BG'), new NeutralSettlementNameNormalizer())->zone();
    }

    public function test_a_matched_result_has_no_refusal_reason(): void
    {
        $result = $this->matcher->match([$this->zone('1', 1)], new ZoneDestination('BG'), new NeutralSettlementNameNormalizer());

        $this->assertTrue($result->isMatched());
        $this->assertNull($result->refusalReason());
    }

    // --- settlement ------------------------------------------------------------------------------

    public function test_a_pickup_point_matches_by_its_settlement(): void
    {
        $zones = [$this->zone('1', 1, names: ['Варна']), $this->zone('2', 2)];

        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', 'Варна', isPickupPoint: true)));
        $this->assertSame('2', $this->matchedId($zones, new ZoneDestination('BG', 'Бургас', isPickupPoint: true)));
    }

    public function test_a_street_address_matches_by_its_city(): void
    {
        $zones = [$this->zone('1', 1, names: ['Пловдив']), $this->zone('2', 2, ['GR'])];

        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', 'Пловдив', '4000')));
        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'Варна', '9000')));
    }

    public function test_a_blank_or_missing_settlement_matches_no_name(): void
    {
        $zones = [$this->zone('1', 1, names: ['София'])];

        foreach ([null, '', '   ', "\u{00A0}"] as $settlement) {
            $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', $settlement)), var_export($settlement, true));
        }
    }

    public function test_a_blank_zone_name_can_never_be_hit_by_a_blank_settlement(): void
    {
        // Even a (hypothetical) name that normalizes to nothing must not match a blank settlement.
        $zones = [$this->zone('1', 1, names: ['гр.'])];

        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', ''), new BulgarianSettlementNameNormalizer()));
    }

    public function test_matching_is_equality_after_normalization_never_a_substring_or_prefix(): void
    {
        $zones = [$this->zone('1', 1, names: ['София'])];

        foreach (['Софийска област', 'Нова София', 'Соф', 'София-град', 'София 2'] as $settlement) {
            $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', $settlement)), $settlement);
        }

        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', '  СОФИЯ ')));
    }

    public function test_sofia_in_latin_does_not_match_the_cyrillic_name(): void
    {
        $zones = [$this->zone('1', 1, names: ['София'])];

        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'Sofia')));
        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'Sofia'), new BulgarianSettlementNameNormalizer()));
    }

    public function test_under_the_bulgarian_normalizer_the_prefixed_spellings_match_the_stored_name(): void
    {
        $zones = [$this->zone('1', 1, names: ['гр. София'])];
        $bg = new BulgarianSettlementNameNormalizer();

        foreach (['София', 'ГР. СОФИЯ', 'град София', 'гр.София'] as $city) {
            $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', $city), $bg), $city);
        }

        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'кв. Лозенец'), $bg));
    }

    public function test_under_the_bulgarian_normalizer_a_district_does_not_match_the_town(): void
    {
        $zones = [$this->zone('1', 1, names: ['Лозенец'])];

        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'кв. Лозенец'), new BulgarianSettlementNameNormalizer()));
    }

    public function test_under_the_neutral_normalizer_the_prefix_is_not_stripped(): void
    {
        $zones = [$this->zone('1', 1, names: ['София'])];

        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'гр. София'), new NeutralSettlementNameNormalizer()));
    }

    public function test_a_decomposed_city_matches_a_composed_stored_name(): void
    {
        $zones = [$this->zone('1', 1, names: ["Бели\u{0439}"])];

        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', "Бели\u{0438}\u{0306}")));
    }

    // --- postcode --------------------------------------------------------------------------------

    public function test_a_postcode_matches_exactly_after_normalization(): void
    {
        $zones = [$this->zone('1', 1, ['GB'], postcodes: ['1000', 'SW1A1AA'])];

        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('GB', null, ' 1000 ')));
        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('GB', null, 'sw1a 1aa')));
        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('GB', null, "SW1A\u{00A0}1AA")));
        $this->assertNull($this->matchedId($zones, new ZoneDestination('GB', null, '10001')), 'no prefix match');
        $this->assertNull($this->matchedId($zones, new ZoneDestination('GB', null, '100')), 'no substring match');
        $this->assertNull($this->matchedId($zones, new ZoneDestination('GB', null, null)));
        $this->assertNull($this->matchedId($zones, new ZoneDestination('GB', null, '  ')));
    }

    public function test_a_zone_built_from_an_unnormalized_postcode_matches_the_same_postcode_typed_any_way(): void
    {
        $zones = [ShippingZone::reconstituteFromStorage('1', 'uk', 1, ['GB'], null, ['SW1A1AA'])];
        $built = ShippingZone::create('uk', 1, ['GB'], null, [' sw1a 1aa ']);

        $this->assertSame(['SW1A1AA'], $built->postcodes());
        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('GB', null, 'Sw1a 1Aa')));
    }

    public function test_a_postcodes_only_zone_never_matches_a_pickup_point(): void
    {
        $zones = [$this->zone('1', 1, postcodes: ['1000'])];

        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'София', null, isPickupPoint: true)));
        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'София', '1000', isPickupPoint: true)), 'even if a postcode is passed along: a pickup point has none');
        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', 'София', '1000')), 'the same zone matches a street address');
    }

    // --- country and the OR --------------------------------------------------------------------------

    public function test_a_country_mismatch_never_matches_even_if_the_name_does(): void
    {
        $zones = [
            $this->zone('1', 1, ['GR'], names: ['София']),
            $this->zone('2', 2, ['GR'], postcodes: ['1000']),
            $this->zone('3', 3, ['GR']),
        ];

        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'София', '1000')));
    }

    public function test_a_zone_with_both_lists_matches_either_one(): void
    {
        $zones = [$this->zone('1', 1, names: ['Пловдив'], postcodes: ['1000'])];

        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', 'Пловдив', '9999')), 'by name');
        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', 'Варна', '1000')), 'by postcode');
        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', 'Пловдив', '1000')), 'by both');
        $this->assertNull($this->matchedId($zones, new ZoneDestination('BG', 'Варна', '9999')), 'by neither');
    }

    public function test_a_zone_covering_several_countries_matches_any_of_them(): void
    {
        $zones = [$this->zone('1', 1, ['BG', 'RO', 'GR'])];

        foreach (['BG', 'RO', 'GR'] as $country) {
            $this->assertSame('1', $this->matchedId($zones, new ZoneDestination($country)));
        }

        $this->assertNull($this->matchedId($zones, new ZoneDestination('DE')));
    }

    public function test_a_zone_without_narrowing_matches_any_settlement_and_postcode_in_its_country(): void
    {
        $zones = [$this->zone('1', 1)];

        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG')));
        $this->assertSame('1', $this->matchedId($zones, new ZoneDestination('BG', 'Anywhere', '0000', isPickupPoint: true)));
    }

    // --- the destination -----------------------------------------------------------------------------

    public function test_the_destination_country_must_be_an_uppercase_alpha_2_code(): void
    {
        foreach (['bg', 'BGR', 'B', '', 'B1'] as $bad) {
            try {
                new ZoneDestination($bad);
                $this->fail("'{$bad}' must be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
