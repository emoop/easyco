<?php

namespace App\Mail;

use League\CommonMark\CommonMarkConverter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a template + values into a finished message (mail-design.md §5.4). The merchant's text is NEVER
 * compiled as Blade or PHP and never reaches a template engine that can call code: variables are
 * substituted by a tiny closed parser that recognises only `{{ name }}` against the template's own list.
 *
 * Order of operations (each step is a barrier; the last one is the safety net):
 *   1. VALIDATE  — only listed variables, well-formed tokens, blocks alone on their own line between blank lines.
 *   2. MARKDOWN  — league/commonmark with html_input=strip and allow_unsafe_links=false, tokens still in place.
 *   3. SUBSTITUTE — scalars HTML-escaped; blocks are HTML built by code from escaped values.
 *   4. SANITIZE  — the final allow-list (HtmlSanitizer): nothing outside it can leave, whatever steps 1-3 did.
 *
 * A stored (merchant) template that cannot be rendered for ANY reason falls back to the shipped default, and
 * the problem is logged: a mail is never lost to a bad edit. A broken SHIPPED default is a programming
 * error and throws.
 */
final class TemplateRenderer
{
    public const MAX_SUBJECT = 180;

    public const MAX_BODY = 20000;

    private const TOKEN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    public function __construct(
        private readonly TemplateOverrides $overrides,
    ) {
    }

    /**
     * @param  array<string, string>  $scalars  plain-text values
     * @param  array<string, string>  $blocks  block name => HTML built by code (empty string = block omitted)
     *
     * @throws TemplateException The shipped default itself cannot be rendered.
     */
    public function render(MailTemplateDefinition $definition, string $locale, array $scalars, array $blocks): RenderedMail
    {
        $override = $this->overrides->find($definition->key, $locale);

        if ($override !== null) {
            try {
                return $this->renderSource($definition, $override['subject'], $override['body'], $locale, $scalars, $blocks);
            } catch (Throwable $e) {
                Log::warning('mail.template_override_failed', [
                    'template' => $definition->key,
                    'locale' => $locale,
                    'exception' => $e::class,
                    'reason' => $e instanceof TemplateException ? $e->getMessage() : null,
                ]);
            }
        }

        [$subject, $body] = $this->shipped($definition->key, $locale);

        return $this->renderSource($definition, $subject, $body, $locale, $scalars, $blocks);
    }

    /**
     * Save-time validation for the future editor (M4), exposed now because it is the same function the
     * renderer runs: returns the problems found, empty when the template is acceptable.
     *
     * @return list<string>
     */
    public function problems(MailTemplateDefinition $definition, string $subject, string $body): array
    {
        $problems = [];

        foreach ([[$subject, false], [$body, true]] as [$source, $blocksAllowed]) {
            try {
                $this->validate($definition, $source, $blocksAllowed);
            } catch (TemplateException $e) {
                $problems[] = $e->getMessage();
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, string>  $scalars
     * @param  array<string, string>  $blocks
     */
    private function renderSource(MailTemplateDefinition $definition, string $subject, string $body, string $locale, array $scalars, array $blocks): RenderedMail
    {
        if (mb_strlen($subject) > self::MAX_SUBJECT || mb_strlen($body) > self::MAX_BODY) {
            throw new TemplateException('Template is too long.');
        }

        $this->validate($definition, $subject, false);
        $this->validate($definition, $body, true);

        $renderedSubject = (string) preg_replace_callback(self::TOKEN, static fn (array $m): string => MailHeader::clean($scalars[$m[1]] ?? '', self::MAX_SUBJECT), $subject);
        $renderedSubject = MailHeader::clean($renderedSubject, self::MAX_SUBJECT);

        if ($renderedSubject === '') {
            throw new TemplateException('Subject is empty.');
        }

        $html = $this->markdown()->convert($body)->getContent();

        foreach ($definition->blocks as $name) {
            $pattern = '#<p>\s*\{\{\s*'.preg_quote($name, '#').'\s*\}\}\s*</p>#';
            $html = (string) preg_replace_callback($pattern, static fn (): string => $blocks[$name] ?? '', $html);
        }

        $html = (string) preg_replace_callback(
            self::TOKEN,
            static fn (array $m): string => htmlspecialchars($scalars[$m[1]] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $html,
        );

        $clean = HtmlSanitizer::clean($html, (string) config('app.url'));

        return new RenderedMail($renderedSubject, $this->wrap($clean, $renderedSubject, $locale), HtmlToText::convert($clean));
    }

    /** @throws TemplateException */
    private function validate(MailTemplateDefinition $definition, string $source, bool $blocksAllowed): void
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $source) === 1) {
            throw new TemplateException('Template contains control characters.');
        }

        if (preg_match_all(self::TOKEN, $source, $matches) > 0) {
            foreach ($matches[1] as $name) {
                if (in_array($name, $definition->scalars, true)) {
                    continue;
                }

                if (in_array($name, $definition->blocks, true)) {
                    if (! $blocksAllowed) {
                        throw new TemplateException('A block variable cannot be used here.');
                    }

                    continue;
                }

                throw new TemplateException('Unknown variable.');
            }
        }

        $leftover = (string) preg_replace(self::TOKEN, '', $source);

        if (str_contains($leftover, '{{') || str_contains($leftover, '}}')) {
            throw new TemplateException('Malformed variable.');
        }

        // A block must stand alone on its own line, with a blank line (or the start/end) on each side.
        $lines = preg_split('/\R/', $source) ?: [];

        foreach ($lines as $i => $line) {
            preg_match_all(self::TOKEN, $line, $found);

            if (array_intersect($found[1], $definition->blocks) === []) {
                continue;
            }

            $alone = count($found[1]) === 1 && trim((string) preg_replace(self::TOKEN, '', $line)) === '';
            $before = $i === 0 || trim($lines[$i - 1]) === '';
            $after = $i === count($lines) - 1 || trim($lines[$i + 1]) === '';

            if (! $alone || ! $before || ! $after) {
                throw new TemplateException('A block variable must stand alone between blank lines.');
            }
        }
    }

    private function markdown(): CommonMarkConverter
    {
        return new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br />\n"],
        ]);
    }

    /** The trusted shell around the sanitised body; the only inline CSS in a mail, written by code. */
    private function wrap(string $body, string $subject, string $locale): string
    {
        $title = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lang = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$title.'</title></head>'
            .'<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222222;">'
            .'<div style="max-width:640px;margin:0 auto;">'.$body.'</div></body></html>';
    }

    /**
     * The shipped default: resources/mail/{locale}/{key}.md — `subject: ...` on the first line, a blank line, the body.
     *
     * @return array{0: string, 1: string}
     *
     * @throws TemplateException
     */
    private function shipped(string $key, string $locale): array
    {
        foreach ([$locale, (string) config('app.fallback_locale', 'en'), 'en'] as $candidate) {
            if (preg_match('/^[a-z]{2}$/', $candidate) !== 1 || preg_match('/^[a-z_.]+$/', $key) !== 1) {
                continue;
            }

            $path = resource_path('mail/'.$candidate.'/'.$key.'.md');

            if (! is_file($path)) {
                continue;
            }

            $content = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));

            if (preg_match('/\Asubject:[ \t]*([^\n]+)\n+(.*)\z/s', $content, $match) !== 1) {
                throw new TemplateException('Shipped template has no subject line.');
            }

            return [trim($match[1]), trim($match[2])];
        }

        throw new TemplateException('Shipped template is missing.');
    }
}
