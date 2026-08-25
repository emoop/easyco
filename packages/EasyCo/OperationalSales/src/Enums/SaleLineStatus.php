<?php

namespace EasyCo\OperationalSales\Enums;

enum SaleLineStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
