<?php

namespace EasyCo\Shipping\Carrier;

/** What a courier point is for the customer (courier-integration-design.md §3.1): a staffed office or a parcel locker. */
enum PickupPointKind: string
{
    case OFFICE = 'office';
    case LOCKER = 'locker';
}
