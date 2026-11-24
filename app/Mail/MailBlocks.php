<?php

namespace App\Mail;

/**
 * The HTML blocks a template places with a block token (mail-design.md §5.2): built by CODE from values
 * escaped here, never from merchant markup. The merchant chooses where a block goes, never what is inside.
 *
 * Plain markup only (the final sanitizer allows no `style`): a table with a few layout attributes.
 * Every label is translated into the mail's locale explicitly — a queue worker has no request locale.
 */
final class MailBlocks
{
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function t(string $key, string $locale, array $replace = []): string
    {
        return (string) __($key, $replace, $locale);
    }

    /**
     * ACC-1: a link as a paragraph plus the address in plain text under it (a mail client that shows no links, or the dev shop on http, still gets it).
     * The final sanitizer keeps only https: / mailto: hrefs.
     */
    public static function link(string $url, string $label, string $locale): string
    {
        return '<p><a href="'.self::e($url).'">'.self::e($label).'</a></p><p>'.self::e(self::t('mail.account.copy_link', $locale)).' '.self::e($url).'</p>';
    }

    /**
     * @param  list<array{name: string, sku: ?string, attributes: string, quantity: int, unit: ?string, total: string, cell?: string}>  $lines
     * @param  ?string  $headCell  MAIL-2: when set, the table has a FIRST column for the product picture. `$headCell` and each line's `cell` are the MARKERS of that column's cells: the sanitiser would drop
     *                             an `img` and the cell's width, so the finished cells are put in AFTER it (TemplateRenderer, `$inline`). Null = no picture column: the table is exactly what it always was.
     */
    public static function orderLines(array $lines, string $locale, ?string $headCell = null): string
    {
        $rows = '';

        foreach ($lines as $line) {
            $detail = [];

            if ($line['sku'] !== null && $line['sku'] !== '') {
                $detail[] = self::e(self::t('mail.order.lines.sku', $locale).': '.$line['sku']);
            }

            if ($line['attributes'] !== '') {
                $detail[] = self::e($line['attributes']);
            }

            $rows .= '<tr>'.($headCell === null ? '' : '<td valign="top">'.($line['cell'] ?? '').'</td>').'<td>'.self::e($line['name']).($detail === [] ? '' : '<br>'.implode('<br>', $detail)).'</td>'
                .'<td align="right">'.$line['quantity'].'</td>'
                .'<td align="right">'.self::e($line['unit'] ?? '—').'</td>'
                .'<td align="right">'.self::e($line['total']).'</td></tr>';
        }

        return '<table width="100%" border="0" cellpadding="6" cellspacing="0" role="presentation"><thead><tr>'
            .($headCell === null ? '' : '<th valign="top">'.$headCell.'</th>')
            .'<th align="left">'.self::e(self::t('mail.order.lines.product', $locale)).'</th>'
            .'<th align="right">'.self::e(self::t('mail.order.lines.quantity', $locale)).'</th>'
            .'<th align="right">'.self::e(self::t('mail.order.lines.unit_price', $locale)).'</th>'
            .'<th align="right">'.self::e(self::t('mail.order.lines.line_total', $locale)).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    /**
     * MAIL-2: one cell of the picture column, finished (it is put in AFTER the sanitiser, see orderLines()): table-based, an absolute URL, `width` and `height` attributes (the SHOWN size), the
     * escaped `alt`, `border="0"` and `display:block;border:0;`. No picture = an empty cell of the same width, so the rows stay aligned. `$tag` is `td`, or `th` for the heading.
     *
     * @param  ?array{url: string, width: int, height: int}  $thumb
     */
    public static function thumbCell(?array $thumb, string $alt, string $tag = 'td'): string
    {
        $inner = $thumb === null || $tag === 'th'
            ? ''
            : '<img src="'.self::e($thumb['url']).'" width="'.$thumb['width'].'" height="'.$thumb['height'].'" alt="'.self::e($alt).'" border="0" style="display:block;border:0;">';

        return '<'.$tag.' width="72" valign="top" style="width:72px;">'.$inner.'</'.$tag.'>';
    }

    /**
     * Label/value rows, right-aligned values. $rows are [label, value] pairs of plain text.
     *
     * @param  list<array{0: string, 1: string}>  $rows
     */
    public static function pairs(array $rows, ?string $heading = null): string
    {
        $out = $heading === null ? '' : '<p><strong>'.self::e($heading).'</strong></p>';
        $out .= '<table width="100%" border="0" cellpadding="4" cellspacing="0" role="presentation"><tbody>';

        foreach ($rows as [$label, $value]) {
            $out .= '<tr><td valign="top">'.self::e($label).'</td><td align="right">'.nl2br(self::e($value), false).'</td></tr>';
        }

        return $out.'</tbody></table>';
    }

    /** Escaped multi-line plain text as paragraphs (a blank line starts a new paragraph). */
    public static function paragraphs(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $out = '';

        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $paragraph) {
            $out .= '<p>'.nl2br(self::e(trim($paragraph)), false).'</p>';
        }

        return $out;
    }
}
