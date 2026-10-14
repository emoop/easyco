<?php

namespace App\Enums;

/**
 * What a cash-on-delivery block is placed on (cod-protection-design.md section 3.1, stage C2-B): an IP address, a phone or an email.
 * Stored as a plain string (`order_blocks.type`), guarded by the CHECK `oblk_type_check`. Labels: lang `orders.block_type_options.*`.
 */
enum OrderBlockType: string
{
    case IP = 'ip';
    case PHONE = 'phone';
    case EMAIL = 'email';
}
