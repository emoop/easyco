<?php

namespace Tests\Feature\Mail;

use App\Mail\MailTemplates;
use App\Mail\MailThumbnails;
use App\Mail\OrderConfirmationContent;
use App\Mail\SendMailJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionClass;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * MAIL-2: the product pictures of the order-confirmation mail: which picture, the JPEG made from the original, the markup, the failures (never a lost mail) and where the work runs.
 */
class MailThumbnailsTest extends TestCase
{
    use PlacesOrdersForMail;
    use RefreshDatabase;

    private const URL = 'http://localhost:8000/storage/mail-thumbs/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacesOrders();
        FlakyTransport::reset();
        Storage::fake('public');
    }

    // ---- fixtures ---------------------------------------------------------------------------------------------------------------------

    private function png(int $w, int $h, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 200, 30, 30, $transparent ? 127 : 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function jpeg(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 90, 200));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** a READY image of the library with its original stored; returns the id */
    private function asset(int $id, string $bytes, string $mime = 'image/png', int $w = 600, int $h = 800): int
    {
        Storage::disk('public')->put("uploads/{$id}.png", $bytes);
        DB::table('catalog_media')->insert([
            'id' => $id, 'type' => 'image', 'disk' => 'public', 'path' => "uploads/{$id}.png", 'mime' => $mime, 'size_bytes' => strlen($bytes), 'width' => $w, 'height' => $h,
            'processing_status' => 'ready', 'variants' => json_encode([['tier' => 'thumbnail', 'width' => 400, 'height' => 300, 'quality' => 80, 'path' => "uploads/{$id}-thumbnail.webp"]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function onProduct(string $productId, int $mediaId): void
    {
        DB::table('catalog_product_media')->insert(['product_id' => $productId, 'media_id' => $mediaId, 'sort_order' => 0]);
    }

    private function onVariation(string $variationId, int $mediaId): void
    {
        DB::table('catalog_variation_media')->insert(['variation_id' => $variationId, 'media_id' => $mediaId, 'sort_order' => 0]);
    }

    /**
     * Places ONE order with a line for each given item (from mailVariation()). The mail is queued (the sync queue would run it inside the request); runs the job when `$send`.
     *
     * @param  list<array{productId: string, variationId: string, sku: string}>  $items
     */
    private function place(array $items, bool $send = true): string
    {
        Queue::fake();

        foreach ($items as $item) {
            $this->mailCartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $item['variationId'], 'quantity' => 1])->assertStatus(201)->json('cart_id');
        }

        $response = $this->postJson('/api/checkout', [
            'cart_id' => $this->mailCartId, 'email' => 'buyer@example.com', 'recipient_first_name' => 'Guest', 'recipient_last_name' => 'Buyer', 'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery', 'delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1', ...$this->shippingPayload(),
        ])->assertStatus(201);

        $send && $this->sendQueuedMail();

        return (string) $response->json('order.id');
    }

    private function sendQueuedMail(): void
    {
        $id = (int) DB::table('mail_log')->orderByDesc('id')->value('id');
        app()->call([new SendMailJob($id), 'handle']);
    }

    private function html(int $index = 0): string
    {
        $email = array_values(Mail::mailer('array')->getSymfonyTransport()->messages()->all())[$index]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return (string) $email->getHtmlBody();
    }

    private function text(int $index = 0): string
    {
        return (string) array_values(Mail::mailer('array')->getSymfonyTransport()->messages()->all())[$index]->getOriginalMessage()->getTextBody();
    }

    /** @return list<string> */
    private function thumbFiles(): array
    {
        return Storage::disk('public')->files('mail-thumbs');
    }

    private function item(string $name = 'Blue Shirt'): array
    {
        return $this->mailVariation($name, '10.00', 20);
    }

    // ---- which picture ----------------------------------------------------------------------------------------------------------------

    public function test_a_line_uses_its_variant_picture_else_the_product_picture_and_a_line_with_none_prints_an_aligned_empty_cell(): void
    {
        $variant = $this->item('Variant Tee');
        $product = $this->item('Product Tee');
        $bare = $this->item('Bare Tee');
        $this->onVariation($variant['variationId'], $this->asset(10, $this->png(300, 400)));
        $this->onProduct($variant['productId'], $this->asset(11, $this->png(300, 400)));   // the variant's own picture must win over this
        $this->onProduct($product['productId'], $this->asset(12, $this->png(300, 400)));

        $this->place([$variant, $product, $bare]);

        $html = $this->html();
        $this->assertSame(2, substr_count($html, '<img '));
        $names = array_map('basename', $this->thumbFiles());
        $this->assertCount(2, $names);
        $this->assertTrue(str_starts_with($names[0], '10-') || str_starts_with($names[1], '10-'), 'the variant picture (10) is used');
        $this->assertTrue(str_starts_with($names[0], '12-') || str_starts_with($names[1], '12-'), 'the product picture (12) is used');
        $this->assertStringNotContainsString(self::URL.'11-', $html, 'the product picture of the variant that has its own is not used');

        // three lines: three picture cells (one empty) and the heading cell: the rows stay aligned
        $this->assertSame(4, preg_match_all('#<t[dh] width="72"#', $html), 'the heading cell and one cell per line');
        $this->assertSame(1, substr_count($html, '<td width="72" valign="top" style="width:72px;"></td>'), 'the line with no picture');
        $this->assertCount(1, DB::table('mail_log')->where('status', 'sent')->get());
    }

    public function test_an_order_with_no_picture_at_all_has_no_picture_column_and_is_what_it_always_was(): void
    {
        $this->place([$this->item('Plain Tee')]);

        $html = $this->html();
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('width="72"', $html);
        $this->assertSame([], $this->thumbFiles());
    }

    // ---- the thumbnail ----------------------------------------------------------------------------------------------------------------

    public function test_the_thumbnail_is_a_jpeg_of_at_most_144_px_with_the_proportions_kept(): void
    {
        $made = app(MailThumbnails::class)->generate($this->png(600, 800));

        $this->assertSame("\xFF\xD8\xFF", substr($made['bytes'], 0, 3));
        $this->assertSame([144, 192], [$made['width'], $made['height']]);
        $size = getimagesizefromstring($made['bytes']);
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame([144, 192], [$size[0], $size[1]]);

        $wide = app(MailThumbnails::class)->generate($this->png(1000, 500));
        $this->assertSame([144, 72], [$wide['width'], $wide['height']]);

        $tall = app(MailThumbnails::class)->generate($this->png(300, 1200));
        $this->assertSame([48, 192], [$tall['width'], $tall['height']], 'at most 192 high');
    }

    public function test_a_small_source_is_never_upscaled(): void
    {
        $made = app(MailThumbnails::class)->generate($this->png(100, 60));

        $this->assertSame([100, 60], [$made['width'], $made['height']]);
        $this->assertSame([50, 30], array_values(MailThumbnails::shown($made['width'], $made['height'])));
    }

    public function test_transparency_is_flattened_onto_white(): void
    {
        $made = app(MailThumbnails::class)->generate($this->png(200, 200, transparent: true));

        $image = imagecreatefromstring($made['bytes']);
        $rgb = imagecolorsforindex($image, imagecolorat($image, 10, 10));
        $this->assertGreaterThan(245, $rgb['red']);
        $this->assertGreaterThan(245, $rgb['green']);
        $this->assertGreaterThan(245, $rgb['blue']);
    }

    public function test_a_picture_is_made_once_and_reused_by_the_next_mail_and_a_replaced_original_gets_a_new_file(): void
    {
        $item = $this->item('Once Tee');
        $this->onProduct($item['productId'], $this->asset(20, $this->png(300, 400)));

        $this->place([$item]);
        $files = $this->thumbFiles();
        $this->assertCount(1, $files);

        // swap the stored file for a recognisable one: a second mail must reuse it, not write again
        Storage::disk('public')->put($files[0], $this->jpeg(40, 20));
        $this->place([$item]);
        $this->assertSame($files, $this->thumbFiles());
        $this->assertStringContainsString('width="20" height="10"', $this->html(1), 'the file already there was reused');

        // the original is replaced (another file, another size): a new file
        Storage::disk('public')->put('uploads/20b.png', $this->png(310, 410));
        DB::table('catalog_media')->where('id', 20)->update(['path' => 'uploads/20b.png', 'size_bytes' => 999, 'width' => 310, 'height' => 410]);
        $this->place([$item]);
        $this->assertCount(2, $this->thumbFiles());
    }

    // ---- the markup -------------------------------------------------------------------------------------------------------------------

    public function test_the_markup_is_email_safe_and_the_plain_text_part_is_unchanged(): void
    {
        $item = $this->item('Tee "Q" & <b>Co</b>');
        $this->onProduct($item['productId'], $this->asset(30, $this->png(600, 800)));
        $this->place([$item]);

        $html = $this->html();
        preg_match('#<img [^>]*>#', $html, $m);
        $img = $m[0] ?? '';
        $this->assertMatchesRegularExpression('#src="'.preg_quote(self::URL, '#').'30-[0-9a-f]{12}\.jpg"#', $img, 'an absolute URL on the shop\'s domain');
        $this->assertStringContainsString('width="72" height="96"', $img);
        $this->assertStringContainsString('border="0"', $img);
        $this->assertStringContainsString('style="display:block;border:0;"', $img);
        $this->assertStringContainsString('alt="Tee &quot;Q&quot; &amp; &lt;b&gt;Co&lt;/b&gt;"', $img);
        $this->assertStringNotContainsString('<b>Co', $html);
        $this->assertStringNotContainsString('?', substr($img, 0, (int) strpos($img, 'width=')), 'no query string');

        foreach (['.webp', '.svg', 'srcset', 'background', 'data:', '<picture', 'loading='] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, $forbidden);
        }

        // the plain text is what the same mail has without the picture
        $text = $this->text();
        $this->assertDoesNotMatchRegularExpression('/mt[0-9a-f]{16}[hr]/', $text . $html);
        DB::table('catalog_product_media')->delete();
        $this->place([$item]);
        $number = static fn (string $t): string => (string) preg_replace('/Order number: \d+/', 'Order number: N', $t);
        $this->assertSame($number($this->text(1)), $number($text));
        $this->assertStringNotContainsString('<img', $this->html(1));
    }

    // ---- a problem with a picture never stops a mail ---------------------------------------------------------------------------------

    /** @return array<string, array{0: callable(self): int}> */
    public static function badPictures(): array
    {
        return [
            'a missing original' => [static function (self $t): int {
                $id = $t->asset(40, $t->png(300, 400));
                Storage::disk('public')->delete('uploads/40.png');

                return $id;
            }],
            'an unreadable picture' => [static fn (self $t): int => $t->asset(40, 'this is not an image')],
            'a type GD does not read' => [static fn (self $t): int => $t->asset(40, 'BM'.str_repeat("\0", 60))],
            'a huge source' => [static function (self $t): int {
                // a PNG header that claims 5000 x 5000 (25 MP): refused from the header, nothing is decoded
                $ihdr = pack('NN', 5000, 5000)."\x08\x06\x00\x00\x00";
                $chunk = pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

                return $t->asset(40, "\x89PNG\r\n\x1a\n".$chunk);
            }],
        ];
    }

    /** @dataProvider badPictures */
    #[\PHPUnit\Framework\Attributes\DataProvider('badPictures')]
    public function test_a_bad_picture_logs_one_warning_and_the_mail_is_still_sent_without_it(callable $make): void
    {
        $item = $this->item('Bad Tee');
        $this->onProduct($item['productId'], $make($this));
        Log::spy();

        $this->place([$item]);

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages()->all(), 'the mail is sent');
        $this->assertStringContainsString('Bad Tee', $this->html());
        $this->assertStringNotContainsString('<img', $this->html());
        $this->assertSame([], $this->thumbFiles());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'mail.thumbnail_failed' && ($context['product_id'] ?? null) === $item['productId'])->once();
    }

    public function test_one_bad_picture_does_not_remove_the_others(): void
    {
        $good = $this->item('Good Tee');
        $bad = $this->item('Bad Tee');
        $this->onProduct($good['productId'], $this->asset(50, $this->png(300, 400)));
        $this->onProduct($bad['productId'], $this->asset(51, 'garbage'));

        $this->place([$good, $bad]);

        $html = $this->html();
        $this->assertSame(1, substr_count($html, '<img '));
        $this->assertSame(1, substr_count($html, '<td width="72" valign="top" style="width:72px;"></td>'));
    }

    // ---- where it runs ----------------------------------------------------------------------------------------------------------------

    public function test_nothing_is_generated_in_the_checkout_request_only_when_the_queued_mail_is_built(): void
    {
        $item = $this->item('Queued Tee');
        $this->onProduct($item['productId'], $this->asset(60, $this->png(300, 400)));

        $this->place([$item], send: false);

        Queue::assertPushed(SendMailJob::class);
        $this->assertSame([], $this->thumbFiles(), 'the checkout request made no thumbnail');

        $this->sendQueuedMail();
        $this->assertCount(1, $this->thumbFiles(), 'the job made it');
    }

    public function test_the_class_is_used_by_the_mail_content_builder_and_by_nothing_in_the_checkout_path(): void
    {
        $users = [];

        foreach (['app', 'packages/EasyCo'] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) && str_contains((string) file_get_contents($file->getPathname()), 'MailThumbnails')) {
                    $users[] = $file->getBasename();
                }
            }
        }

        sort($users);
        $this->assertSame(['MailThumbnails.php', 'OrderConfirmationContent.php'], $users);
    }

    public function test_the_order_mail_is_the_only_template_that_prints_the_items_block(): void
    {
        $definitions = (new ReflectionClass(MailTemplates::class))->getReflectionConstant('DEFINITIONS')->getValue();
        $with = array_keys(array_filter($definitions, static fn (array $d): bool => in_array('order_lines', $d['blocks'], true)));

        $this->assertSame([MailTemplates::ORDER_CONFIRMATION], $with);
    }

    public function test_a_resend_builds_the_same_mail_and_reuses_the_file(): void
    {
        $item = $this->item('Resend Tee');
        $this->onProduct($item['productId'], $this->asset(70, $this->png(300, 400)));
        $this->place([$item]);
        $files = $this->thumbFiles();

        $row = DB::table('mail_log')->orderByDesc('id')->first();
        $built = app(OrderConfirmationContent::class)->build($row);

        $this->assertSame($files, $this->thumbFiles());
        $this->assertStringContainsString('<img ', $built->html);
    }
}
