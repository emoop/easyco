<?php

namespace App\Mail;

use InvalidArgumentException;

/**
 * The code registry of mail templates (mail-design.md §5.1): each key with its closed variable list,
 * its sender identity and its category. Adding a key is a code change; merchants edit text, never structure.
 *
 * M1 registers ONE key, `order.confirmation`.
 *
 *  - SCALARS are plain-text values; they are HTML-escaped when inserted into the body and made header-safe
 *    when inserted into the subject.
 *  - BLOCKS are HTML built by code (MailBlocks) from escaped values; a block token must stand alone on its own line.
 */
final class MailTemplates
{
    public const ORDER_CONFIRMATION = 'order.confirmation';

    /** @var array<string, array{category: string, sender: string, queue: string, scalars: list<string>, blocks: list<string>}> */
    private const DEFINITIONS = [
        self::ORDER_CONFIRMATION => [
            'category' => 'transactional',
            'sender' => 'transactional',
            'queue' => 'mail-transactional',
            'scalars' => ['shop_name', 'customer_name', 'order_number', 'order_date', 'order_total', 'payment_method_label', 'support_email'],
            'blocks' => ['order_lines', 'order_totals', 'delivery_summary', 'payment_instructions'],
        ],
    ];

    public function has(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    /** @throws InvalidArgumentException An unknown key is a programming error, never customer input. */
    public function get(string $key): MailTemplateDefinition
    {
        $definition = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException('Unknown mail template key.');

        return new MailTemplateDefinition($key, $definition['category'], $definition['sender'], $definition['queue'], $definition['scalars'], $definition['blocks']);
    }
}
