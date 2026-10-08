<?php

namespace EasyCo\Shipping\Enums;

/**
 * How a method delivers (shipping stage 5f): to the customer's address, to a courier office, to a parcel locker, or
 * something else. A DISPLAY and grouping fact only — it never changes a price, a rule or whether a pickup point is
 * required. A method without one (NULL) is simply not typed.
 */
enum ShippingDeliveryType: string
{
    case ADDRESS = 'address';
    case OFFICE = 'office';
    case LOCKER = 'locker';
    case OTHER = 'other';
}
