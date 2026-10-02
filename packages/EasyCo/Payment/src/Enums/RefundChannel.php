<?php

namespace EasyCo\Payment\Enums;

/**
 * How a refund is paid back: cash from the register, or through the bank
 * (shipping-domain-design.md §7.2.8). The channel decides which permission
 * records and pays it out.
 *
 * Until the refund dialog lets the merchant choose (R3), the channel is DERIVED
 * from the payment method: cash on delivery pays back in cash, everything else
 * through the bank — the same split the permission has always followed.
 */
enum RefundChannel: string
{
    case CASH = 'cash';
    case BANK = 'bank';

    public static function defaultForMethod(string $paymentMethod): self
    {
        return $paymentMethod === 'cash_on_delivery' ? self::CASH : self::BANK;
    }
}
