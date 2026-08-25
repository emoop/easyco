<?php

namespace EasyCo\OperationalSales\Enums;

enum InstallmentPlanStatus: string
{
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
