<?php

namespace App\Privacy;

/** Where a privacy request came from (privacy_requests.source). */
enum PrivacySource: string
{
    case STOREFRONT = 'storefront';
    case ACCOUNT = 'account';
    case ADMIN = 'admin';
}
