<?php

namespace App\Services;

/**
 * A hash of EVERYTHING the merchant decided for one cancel/return operation, so
 * that a repeated operation key can be told apart as "the same submission again"
 * (same hash: replay) or "a different decision under a reused key" (refused).
 * Shipping-domain-design.md §7.2.3, owner decision R1a-4.
 *
 * It covers: the action (cancel or return), the lines and quantities and the
 * restock choice of each, the per-line restock overrides of a cancel, the entered
 * goods amounts, the shipping refund, the deduction and its reason, the channel,
 * and the free-text reason of the operation. Every map is sorted first, so the
 * order a form happened to submit things in cannot change the hash.
 */
final class RefundOperationFingerprint
{
    /**
     * @param  'cancel'|'return'  $action
     * @param  array<int, array{originatingSaleLineId: string, quantityReturned: int, restock: bool}>  $lines  return only
     * @param  array<string, bool>  $restockOverrides  cancel only
     */
    public static function of(string $action, array $lines, array $restockOverrides, ?RefundRequest $request, ?string $reason): string
    {
        $returnLines = [];

        foreach ($lines as $line) {
            $returnLines[(string) $line['originatingSaleLineId']] = [(int) $line['quantityReturned'], (bool) $line['restock']];
        }

        ksort($returnLines);

        $overrides = [];

        foreach ($restockOverrides as $lineId => $restock) {
            $overrides[(string) $lineId] = (bool) $restock;
        }

        ksort($overrides);

        $entered = [];

        foreach ($request?->enteredGoodsByLine ?? [] as $lineId => $amount) {
            $entered[(string) $lineId] = $amount->minorValue();
        }

        ksort($entered);

        $contents = [
            'action' => $action,
            'lines' => $returnLines,
            'restockOverrides' => $overrides,
            'entered' => $entered,
            'shipping' => $request?->shipping?->minorValue() ?? 0,
            'deduction' => $request?->deduction?->minorValue() ?? 0,
            'deductionReason' => $request?->deductionReason !== null ? trim($request->deductionReason) : null,
            'channel' => $request?->channel?->value,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ];

        // Only when given, so the hash of an operation without one is what it always was.
        if ($request?->announcedReturnAt !== null) {
            $contents['announcedReturnAt'] = $request->announcedReturnAt->format('Y-m-d H:i:s');
        }

        return hash('sha256', json_encode($contents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** The fingerprint of "record this money-only refund" — everything the merchant entered. */
    public static function forMoneyOnly(MoneyOnlyRefundRequest $request): string
    {
        return hash('sha256', json_encode([
            'action' => 'money_only_refund',
            'shipping' => $request->shipping->minorValue(),
            'adjustment' => $request->adjustment->minorValue(),
            'deduction' => $request->deduction?->minorValue() ?? 0,
            'channel' => $request->channel->value,
            'reason' => self::text($request->reason),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** The fingerprint of "mark this OWED refund paid out" — everything the merchant entered. */
    public static function forPayout(string $refundId, \DateTimeImmutable $paidOutAt, ?string $reference, ?string $note): string
    {
        return hash('sha256', json_encode([
            'action' => 'refund_paid_out',
            'refund' => $refundId,
            'paidOutAt' => $paidOutAt->format('Y-m-d H:i:s'),
            'reference' => self::text($reference),
            'note' => self::text($note),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** The fingerprint of "cancel this OWED refund". */
    public static function forCancellation(string $refundId, string $reason): string
    {
        return hash('sha256', json_encode([
            'action' => 'refund_cancelled',
            'refund' => $refundId,
            'reason' => self::text($reason),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function text(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
