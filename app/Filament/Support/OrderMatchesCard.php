<?php

namespace App\Filament\Support;

use App\Filament\Resources\OrderResource;
use App\Services\OrderMatchReader;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * The HTML of the «Съвпадения» card (cod-protection-design.md section 5), built from OrderMatchReader's answer. EVERY part is escaped here (the
 * entry prints HTML so the order numbers can be links): the customer's name is typed text, so it goes through e(); nothing else is customer input.
 *
 * A strong match (phone, email, address/office) is plain; a weak one (the name only) is greyed and labelled «възможно съвпадение»; cancelled and
 * refunded orders stand out; the red line appears only for an identifier this order SHARES with another and that is actively blocked.
 */
final class OrderMatchesCard
{
    private const RED = 'color: rgb(185, 28, 28); font-weight: 600';

    private const GREY = 'opacity: 0.55';

    public static function html(OrderModel $record): string
    {
        $matches = app(OrderMatchReader::class)->forOrder($record);

        if ($matches['orders'] === []) {
            return '<p>'.e(__('blocks.matches.none')).'</p>';
        }

        $html = '';

        // The red line first: it is the part a person must not miss.
        foreach ($matches['blocked'] as $block) {
            $until = $block['until'];
            $text = $until === null
                ? __('blocks.matches.blocked_'.$block['type'].'_forever')
                : __('blocks.matches.blocked_'.$block['type'], ['date' => Carbon::instance($until)->timezone(FilamentTimezone::get())->format('M j, Y')]);
            $html .= '<p style="'.self::RED.'">'.e($text).'</p>';
        }

        // The summary counts the STRONG matches only; with none (a name-only possibility) there is nothing to summarise.
        if ($matches['strong'] > 0) {
            $html .= '<p style="font-weight: 600">'.e(trans_choice('blocks.matches.summary', $matches['strong'], ['count' => $matches['strong'], 'refused' => $matches['refused']])).'</p>';
        }

        if ($matches['weak'] > 0) {
            $html .= '<p style="'.self::GREY.'">'.e(trans_choice('blocks.matches.weak_summary', $matches['weak'], ['count' => $matches['weak']])).'</p>';
        }

        $html .= '<ul style="list-style: none; padding: 0; margin: 0.25rem 0 0">';

        foreach ($matches['orders'] as $match) {
            $status = Lang::has('orders.status_options.'.$match['status']) ? __('orders.status_options.'.$match['status']) : $match['status'];
            $stands = in_array($match['status'], ['cancelled', 'refunded'], true);
            $keys = implode(', ', array_map(static fn (string $key): string => __('blocks.matches.key_'.($key === 'name' ? 'name' : $key)), $match['keys']));
            $date = Carbon::instance($match['placed_at'])->timezone(FilamentTimezone::get())->format('M j, Y');
            $link = '<a class="fi-link" href="'.e(OrderResource::getUrl('view', ['record' => $match['id']])).'">'.e(__('blocks.matches.order', ['id' => $match['id']])).'</a>';

            $html .= '<li style="margin-bottom: 0.35rem;'.($match['weak'] ? ' '.self::GREY.';' : '').'">'
                .$link.' · '.e($date).' · '.e($match['name']).' · '
                .'<span'.($stands ? ' style="'.self::RED.'"' : '').'>'.e($status).'</span>'
                .'<br><span style="font-size: 0.75rem">'.e($keys).'</span>'
                .'</li>';
        }

        return $html.'</ul><p style="font-size: 0.75rem; opacity: 0.65">'.e(__('blocks.matches.more_than', ['limit' => OrderMatchReader::LIMIT])).'</p>';
    }
}
