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
     * @param  list<array{name: string, sku: ?string, attributes: string, quantity: int, unit: ?string, total: string}>  $lines
     */
    public static function orderLines(array $lines, string $locale): string
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

            $rows .= '<tr><td>'.self::e($line['name']).($detail === [] ? '' : '<br>'.implode('<br>', $detail)).'</td>'
                .'<td align="right">'.$line['quantity'].'</td>'
                .'<td align="right">'.self::e($line['unit'] ?? '—').'</td>'
                .'<td align="right">'.self::e($line['total']).'</td></tr>';
        }

        return '<table width="100%" border="0" cellpadding="6" cellspacing="0" role="presentation"><thead><tr>'
            .'<th align="left">'.self::e(self::t('mail.order.lines.product', $locale)).'</th>'
            .'<th align="right">'.self::e(self::t('mail.order.lines.quantity', $locale)).'</th>'
            .'<th align="right">'.self::e(self::t('mail.order.lines.unit_price', $locale)).'</th>'
            .'<th align="right">'.self::e(self::t('mail.order.lines.line_total', $locale)).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>';
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
