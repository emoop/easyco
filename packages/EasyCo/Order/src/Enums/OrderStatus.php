<?php

namespace EasyCo\Order\Enums;

/**
 * Order lifecycle status — see checkout-domain-design.md §3. No
 * transition methods exist anywhere in this package: nothing in this
 * pass changes an Order's status once placed — that is deliberately
 * future admin-UI work (design doc §3/§10), not guessed at here.
 */
enum OrderStatus: string
{
    case PLACED = 'placed';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
}
