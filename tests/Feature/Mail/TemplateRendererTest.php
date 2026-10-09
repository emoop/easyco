<?php

namespace Tests\Feature\Mail;

use App\Mail\MailTemplates;
use App\Mail\RenderedMailable;
use App\Mail\MailerSettings;
use App\Mail\TemplateOverrides;
use App\Mail\TemplateRenderer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The renderer end to end: merchant text (an override) goes through the closed variable parser, the Markdown
 * converter and the final sanitizer. Stored XSS and header injection must not survive any of it.
 */
class TemplateRendererTest extends TestCase
{
    private function definition()
    {
        return app(MailTemplates::class)->get(MailTemplates::ORDER_CONFIRMATION);
    }

    private function renderer(?array $override = null): TemplateRenderer
    {
        $overrides = new class($override) extends TemplateOverrides
        {
            public function __construct(private readonly ?array $override)
            {
            }

            public function find(string $key, string $locale): ?array
            {
                return $this->override;
            }
        };

        return new TemplateRenderer($overrides);
    }

    /** @return array<string, string> */
    private function scalars(array $overrides = []): array
    {
        return $overrides + [
            'shop_name' => 'Raf', 'customer_name' => 'Ivan', 'order_number' => '42', 'order_date' => '2026-10-09 10:00',
            'order_total' => '20.00 €', 'payment_method_label' => 'Cash on delivery', 'support_email' => 'info@example.com',
        ];
    }

    private function blocks(): array
    {
        return ['order_lines' => '<table><tbody><tr><td>LINES</td></tr></tbody></table>', 'order_totals' => '<p>TOTALS</p>', 'delivery_summary' => '<p>DELIVERY</p>', 'payment_instructions' => ''];
    }

    public function test_the_shipped_defaults_render_in_both_locales_with_blocks_in_place(): void
    {
        foreach (['bg' => 'Вашата поръчка 42', 'en' => 'Your order 42'] as $locale => $subject) {
            $mail = $this->renderer()->render($this->definition(), $locale, $this->scalars(), $this->blocks());

            $this->assertStringStartsWith($subject, $mail->subject);
            $this->assertStringContainsString('LINES', $mail->html);
            $this->assertStringContainsString('TOTALS', $mail->html);
            $this->assertStringContainsString('DELIVERY', $mail->html);
            $this->assertStringContainsString('lang="'.$locale.'"', $mail->html);
            $this->assertStringNotContainsString('{{', $mail->html);
            $this->assertStringNotContainsString('{{', $mail->text);
            $this->assertStringContainsString('LINES', $mail->text);
        }
    }

    public function test_a_stored_xss_payload_in_a_merchant_template_is_neutralised(): void
    {
        $body = implode("\n", [
            '**bold** and [a good link](https://ok.example/page)',
            '',
            '[bad](javascript:alert(1)) [bad2](JAVASCRIPT:alert(1)) [bad3](&#106;avascript:alert(1)) [bad4](data:text/html;base64,PHNjcmlwdD4=)',
            '',
            '<script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)" onclick="alert(4)">raw</a>',
            '',
            '<p style="background:url(javascript:alert(5))">styled</p> <iframe src="https://evil.example"></iframe>',
            '',
            '<svg onload=alert(6)></svg> &lt;script&gt;alert(7)&lt;/script&gt; <b onmouseover=alert(8)>bold</b>',
            '',
            '{{ order_lines }}',
        ]);

        $mail = $this->renderer(['subject' => 'Order {{ order_number }}', 'body' => $body])->render($this->definition(), 'en', $this->scalars(), $this->blocks());

        // Only the merchant-controlled part is judged; the shell around it is written by code (it has the one inline style).
        $this->assertSame(1, preg_match('#<div style="max-width:640px;margin:0 auto;">(.*)</div></body></html>#s', $mail->html, $inner));
        $html = strtolower($inner[1]);

        foreach (['<script', 'javascript:', 'onerror', 'onclick', 'onload', 'onmouseover', 'style=', '<iframe', '<img', '<svg', 'data:text'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "'{$needle}' survived");
        }
        $this->assertStringContainsString('<strong>bold</strong>', $mail->html);
        $this->assertStringContainsString('href="https://ok.example/page"', $mail->html);
        $this->assertStringContainsString('LINES', $mail->html);
    }

    public function test_a_hostile_value_is_escaped_in_the_body_and_cleaned_in_the_subject(): void
    {
        $hostile = "Evil\r\nBcc: spy@example.com <script>alert(1)</script> &amp; \"x\"";
        $mail = $this->renderer(['subject' => 'Hello {{ customer_name }}', 'body' => 'Dear {{ customer_name }}'])
            ->render($this->definition(), 'en', $this->scalars(['customer_name' => $hostile]), $this->blocks());

        $this->assertStringNotContainsString("\r", $mail->subject);
        $this->assertStringNotContainsString("\n", $mail->subject);
        $this->assertStringNotContainsString('<script', $mail->html);
        $this->assertStringContainsString('&lt;script&gt;', $mail->html);
        $this->assertStringContainsString('&amp;amp;', $mail->html); // an ampersand typed by a person stays text
    }

    public function test_a_hostile_subject_never_becomes_a_second_header(): void
    {
        $mail = $this->renderer(['subject' => 'Hi {{ customer_name }}', 'body' => 'x'])->render(
            $this->definition(),
            'en',
            $this->scalars(['customer_name' => "A\r\nBcc: spy@example.com\0\u{202E}"]),
            $this->blocks(),
        );

        $sender = new MailerSettings('array', "Shop\r\nBcc: spy2@example.com", 'Shop', null);
        Mail::mailer('array')->to('buyer@example.com')->send(new RenderedMailable($mail, new MailerSettings('array', 'shop@example.com', "Shop\r\nBcc: spy@example.com", null)));

        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertNull($email->getHeaders()->get('Bcc'));
        $this->assertCount(1, iterator_to_array($email->getHeaders()->all('Subject')));
        $this->assertStringNotContainsString("\n", $email->getHeaders()->get('Subject')->getBodyAsString());
        $this->assertCount(1, $email->getTo());
    }

    public function test_unknown_malformed_and_misplaced_variables_are_refused(): void
    {
        $definition = $this->definition();
        $renderer = $this->renderer();

        $this->assertSame([], $renderer->problems($definition, 'Hi {{ customer_name }}', "Text {{ order_total }}\n\n{{ order_lines }}\n\nEnd"));
        $this->assertNotSame([], $renderer->problems($definition, 'Hi {{ nope }}', 'x'), 'unknown variable in the subject');
        $this->assertNotSame([], $renderer->problems($definition, 'Hi', 'x {{ nope }}'), 'unknown variable in the body');
        $this->assertNotSame([], $renderer->problems($definition, 'Hi {{ order_lines }}', 'x'), 'a block in the subject');
        $this->assertNotSame([], $renderer->problems($definition, 'Hi', 'x {{ order_lines }} y'), 'a block inside a sentence');
        $this->assertNotSame([], $renderer->problems($definition, 'Hi', "x\n{{ order_lines }}\ny"), 'a block without blank lines around it');
        $this->assertNotSame([], $renderer->problems($definition, 'Hi', '{{ order_lines }} {{ order_totals }}'), 'two blocks on one line');
        $this->assertNotSame([], $renderer->problems($definition, 'Hi', 'x {{ customer_name'), 'an unclosed token');
        $this->assertNotSame([], $renderer->problems($definition, 'Hi', 'x {{ customer-name }}'), 'a token with an illegal character');
        $this->assertNotSame([], $renderer->problems($definition, "Hi\0", 'x'), 'a control character');
        $this->assertNotSame([], $renderer->problems($definition, 'Hi', '{{ foo.bar() }}'), 'code-like content is not a variable');
    }

    public function test_blade_and_php_in_a_merchant_template_are_never_executed(): void
    {
        $mail = $this->renderer(['subject' => 'Hi', 'body' => "@php echo 'EXECUTED'; @endphp {{ \$x }} <?php echo 'EXECUTED2'; ?> {!! 'EXECUTED3' !!}"])
            ->render($this->definition(), 'en', $this->scalars(), $this->blocks());

        // The unusable override falls back to the shipped default; nothing was executed on the way.
        $this->assertStringNotContainsString('EXECUTED', $mail->html);
        $this->assertStringContainsString('Your order', $mail->subject);
    }

    public function test_an_over_long_template_is_refused_and_falls_back(): void
    {
        $mail = $this->renderer(['subject' => 'Hi', 'body' => str_repeat('a', TemplateRenderer::MAX_BODY + 1)])
            ->render($this->definition(), 'en', $this->scalars(), $this->blocks());

        $this->assertStringContainsString('Your order', $mail->subject);
    }
}
