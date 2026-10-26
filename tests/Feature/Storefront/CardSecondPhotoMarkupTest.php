<?php

namespace Tests\Feature\Storefront;

use App\Storefront\ReadModels\ImageSet;
use App\Storefront\ReadModels\ProductCard;
use Tests\TestCase;

/**
 * PC-1 (markup): a card with a second photo carries ONE hover image after the main one — with no src, no srcset and no
 * loading attribute (the browser must fetch nothing on its own), its URLs in data- attributes, an empty alt and the
 * rendition's size. A card with ONE photo or none renders byte-identical to the markup before PC-1 (the fixtures were
 * taken from the view before the change). The name is escaped and no style= attribute appears.
 */
class CardSecondPhotoMarkupTest extends TestCase
{
    private function image(string $id, string $alt = 'Summer dress'): ImageSet
    {
        return new ImageSet(
            "https://cdn.test/storage/p/{$id}-medium.webp", $alt, 900, 675,
            [['url' => "https://cdn.test/storage/p/{$id}-thumbnail.webp", 'width' => 400], ['url' => "https://cdn.test/storage/p/{$id}-medium.webp", 'width' => 900]],
            '(min-width: 1024px) 25vw, (min-width: 640px) 50vw, 100vw',
        );
    }

    private function card(?ImageSet $image, ?ImageSet $second = null, string $name = 'Summer dress'): ProductCard
    {
        return new ProductCard('1', 'summer-dress', '/product/summer-dress', $name, null, $image, null, [], true, $second);
    }

    private function render(ProductCard $card): string
    {
        return view('storefront.partials.card', ['card' => $card, 'eager' => false])->render();
    }

    /** The hover image's opening tag, or null. */
    private function hoverTag(string $html): ?string
    {
        return preg_match('/<img class="sf-card-image-hover"[^>]*>/', $html, $m) ? $m[0] : null;
    }

    public function test_a_card_with_one_photo_renders_byte_identical_to_the_markup_before_pc1(): void
    {
        $html = $this->render($this->card($this->image('1')));

        $this->assertSame(file_get_contents(base_path('tests/Fixtures/storefront/card_one_photo.html')), $html);
        $this->assertNull($this->hoverTag($html));
        $this->assertStringNotContainsString('sf-card-image-hover', $html, 'no empty wrapper, no extra class');
    }

    public function test_a_card_with_no_photo_renders_byte_identical_to_the_markup_before_pc1(): void
    {
        $html = $this->render(new ProductCard('3', 'none', '/product/none', 'No photo', null, null, null, [], true));

        $this->assertSame(file_get_contents(base_path('tests/Fixtures/storefront/card_no_photo.html')), $html);
    }

    public function test_a_card_with_two_photos_has_exactly_one_hover_image_after_the_main_one_with_no_src_and_no_loading(): void
    {
        $html = $this->render($this->card($this->image('1'), $this->image('2')));

        $this->assertSame(1, substr_count($html, 'class="sf-card-image-hover"'));

        $tag = $this->hoverTag($html);
        $this->assertNotNull($tag);
        $this->assertLessThan(strpos($html, 'class="sf-card-image-hover"'), strpos($html, 'class="sf-image"'), 'the hover image comes after the main image');

        $this->assertStringContainsString('alt=""', $tag, 'decorative: the first image carries the name');
        $this->assertStringContainsString('hidden', $tag);
        $this->assertStringContainsString('decoding="async"', $tag);
        $this->assertStringContainsString('width="900"', $tag);
        $this->assertStringContainsString('height="675"', $tag);
        $this->assertStringContainsString('data-src="https://cdn.test/storage/p/2-medium.webp"', $tag);
        $this->assertStringContainsString('data-srcset="https://cdn.test/storage/p/2-thumbnail.webp 400w, https://cdn.test/storage/p/2-medium.webp 900w"', $tag);
        $this->assertStringContainsString('data-sizes="(min-width: 1024px) 25vw, (min-width: 640px) 50vw, 100vw"', $tag);

        $this->assertDoesNotMatchRegularExpression('/\s(src|srcset|loading)=/', $tag, 'the browser fetches nothing on its own');
    }

    public function test_the_name_is_escaped_and_no_style_attribute_appears(): void
    {
        $html = $this->render($this->card($this->image('1'), $this->image('2'), '<script>alert(1)</script>'));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('style=', $html);
    }
}
