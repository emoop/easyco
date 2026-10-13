<?php

namespace EasyCo\Payment\Registry;

/**
 * How the customer pays once the order is placed (checkout-form-v2-design.md section 8.2). OFFLINE: nothing happens on the page
 * (cash on delivery, bank transfer). REDIRECT and EMBEDDED belong to the first online provider's design (section 8.4): C1d-B
 * only names them, so a descriptor can say what it will be.
 */
enum PaymentFlow: string
{
    case OFFLINE = 'offline';
    case REDIRECT = 'redirect';
    case EMBEDDED = 'embedded';
}
