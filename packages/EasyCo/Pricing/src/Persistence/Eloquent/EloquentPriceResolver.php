<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use DateTimeImmutable;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\FixedItemsPriceLookup;
use RuntimeException;

/**
 * Real PriceResolver implementation running §4.6's algorithm against the
 * PriceList/PriceListScope/PriceListItem tables — see
 * pricing-persistence-domain-design.md §4.3-§4.6. Replaces
 * InMemoryPriceResolver (not yet swapped in PricingServiceProvider — that
 * binding change is a separate, later step, §8 item 2e).
 *
 * All of the actual algorithm now lives in PriceListResolutionEngine
 * (this class's own persistence-layer sibling, shared with
 * EloquentPriceRangeResolver — see that engine's own docblock). This
 * class is a thin single-target adapter over it: byte-for-byte the same
 * observable behaviour as before this split (same RuntimeException/
 * PriceNotConfiguredException cases, same quote values) — verified by
 * EloquentPriceResolverTest, unmodified.
 *
 * CONSTRUCTOR SIGNATURE DELIBERATELY UNCHANGED, THE ENGINE IS BUILT
 * INTERNALLY: EloquentPriceResolverTest.php's own resolver() helper
 * constructs `new EloquentPriceResolver(...)` directly with these exact
 * three arguments (not via the container) — a real, confirmed
 * constraint, not an assumption: changing this constructor's signature
 * to take PriceListResolutionEngine directly broke that test with a
 * TypeError when tried. Since that test file is required to pass
 * UNMODIFIED, the engine is instead constructed once, internally, from
 * these same three collaborators — functionally identical to injecting
 * it, with the one added guarantee that container resolution
 * (app(PriceResolver::class)) and this test's direct construction both
 * keep working unchanged.
 */
final class EloquentPriceResolver implements PriceResolver
{
    private const REGULAR_PRICES_LIST_NAME = 'Regular Prices';

    private readonly PriceListResolutionEngine $engine;

    public function __construct(
        private readonly PriceListRepository $priceListRepository,
        PriceListScopeRepository $priceListScopeRepository,
        FixedItemsPriceLookup $fixedItemsPriceLookup,
    ) {
        $this->engine = new PriceListResolutionEngine(
            $priceListRepository,
            $priceListScopeRepository,
            $fixedItemsPriceLookup,
        );
    }

    public function resolve(PriceContext $context): PriceQuote
    {
        $at = $context->at ?? new DateTimeImmutable();

        $regularList = $this->priceListRepository->findSystemListByName(self::REGULAR_PRICES_LIST_NAME);

        if ($regularList === null) {
            throw new RuntimeException(
                'The reserved "Regular Prices" system PriceList has not been seeded — see '.
                'pricing-persistence-domain-design.md §4.5 / §8 item 3.'
            );
        }

        $candidates = $this->engine->preloadForBatch($at);
        $winningList = $this->engine->findWinningList($candidates, $context);

        $quote = $this->engine->buildQuote($regularList, $winningList, $context);

        if ($quote === null) {
            throw PriceNotConfiguredException::forPriceableId($context->priceableId);
        }

        return $quote;
    }
}
