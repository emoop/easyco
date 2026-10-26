<?php

namespace Tests\Feature\Storefront;

use App\Storefront\ReadModels\ImageSet;
use App\Storefront\ReadModels\ProductCard;
use Tests\TestCase;

/**
 * PC-2 (markup): every card has its product id; a card with a photo carries ONE empty, hidden size list after its photo (the browser fills it); a card without a
 * photo carries none. No style= attribute anywhere in a card.
 */
class CardSizesMarkupTest extends TestCase
{
    private function card(?ImageSet $image, string $id = '5'): ProductCard
    {
        return new ProductCard($id, 'dress', '/product/dress', 'Dress', null, $image, null, [], true);
    }

    private function image(): ImageSet
    {
        return new ImageSet('https://cdn.test/storage/p/5-medium.webp', 'Dress', 900, 675, [['url' => 'https://cdn.test/storage/p/5-medium.webp', 'width' => 900]], '100vw');
    }

    private function render(ProductCard $card): string
    {
        return view('storefront.partials.card', ['card' => $card, 'eager' => false])->render();
    }

    public function test_a_card_carries_its_product_id(): void
    {
        $this->assertStringContainsString('<article class="sf-card" data-product-id="5">', $this->render($this->card($this->image())));
    }

    public function test_a_card_with_a_photo_has_one_empty_hidden_size_list_after_the_photo(): void
    {
        $html = $this->render($this->card($this->image()));

        $this->assertSame(1, substr_count($html, '<ul class="sf-card-sizes" hidden aria-hidden="true"></ul>'));
        $this->assertLessThan(strpos($html, 'class="sf-card-sizes"'), strpos($html, 'class="sf-image"'), 'the list comes after the photo');
    }

    public function test_a_card_without_a_photo_has_no_size_list(): void
    {
        $html = $this->render($this->card(null));

        $this->assertStringNotContainsString('sf-card-sizes', $html);
        $this->assertStringContainsString('data-product-id="5"', $html);
    }

    public function test_no_style_attribute_appears_in_a_card(): void
    {
        $this->assertStringNotContainsString('style=', $this->render($this->card($this->image())));
        $this->assertStringNotContainsString('style=', $this->render($this->card(null)));
    }
}
