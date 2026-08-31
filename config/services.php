<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pricing' => [
        // The store's default currency, used only where a Money-adjacent
        // computation needs *some* currency before any real amount has
        // established one (see EasyCo\Pricing\DefaultCurrency). Override
        // via .env, not by editing this hardcoded value, if the store's
        // currency ever changes — see EasyCo\Pricing\DefaultCurrency's
        // docblock for why this must stay configurable rather than
        // hardcoded in code.
        'default_currency' => env('PRICING_DEFAULT_CURRENCY', 'EUR'),
    ],

    'catalog' => [
        // The first value the auto-generated Product::baseSku() sequence
        // issues (see database/migrations/..._create_catalog_sku_sequence_table.php
        // and App\Providers\CatalogSkuGeneratorServiceProvider). Read at
        // migration time to seed the sequence, and available at runtime
        // for reference — the sequence table itself, not this config
        // value, is the actual source of truth once seeded.
        'base_sku_sequence_start' => env('PRODUCT_SKU_SEQUENCE_START', 100000),
    ],

    'media' => [
        // Max photos allowed per Product/Variation before ProductMediaCountGuard/
        // VariationMediaCountGuard reject a further attach — see
        // media-domain-design.md §6 and EasyCo\Media\ProductMedia/VariationMedia's
        // own docblocks. Configurable, never hardcoded in the guard classes
        // themselves (they take a plain int; only MediaServiceProvider reads
        // config()).
        'max_photos_per_product' => env('MEDIA_MAX_PHOTOS_PER_PRODUCT', 10),
        'max_photos_per_variation' => env('MEDIA_MAX_PHOTOS_PER_VARIATION', 3),

        // Which Laravel Filesystem disk LaravelMediaStorageAdapter writes to
        // when no explicit disk is given — see media-domain-design.md §5.
        // 'public' is a safe, harmless default (unlike DefaultCurrency's
        // fail-loud posture, §5 explicitly notes a storage disk carries no
        // silently-wrong risk), overridable via .env.
        'default_disk' => env('MEDIA_DEFAULT_DISK', 'public'),

        // Variant tiers for the image processing pipeline — see
        // media-domain-design.md §3.2. thumbnail/medium/large use scale()
        // (fit inside the max bound, aspect ratio preserved, never crops —
        // a single number per tier is enough); admin_grid is the only
        // cover() tier (fixed 42x42, cropping permitted — a small admin
        // product-table thumbnail, not customer-facing imagery). Every
        // number here is configurable, never hardcoded in the processor.
        'image_variants' => [
            'thumbnail' => ['method' => 'scale', 'max' => 400, 'quality' => 80],
            'medium' => ['method' => 'scale', 'max' => 900, 'quality' => 82],
            'large' => ['method' => 'scale', 'max' => 1600, 'quality' => 85],
            'admin_grid' => ['method' => 'cover', 'width' => 42, 'height' => 42, 'quality' => 80],
        ],

        // Upload-time validation limits for POST /api/media — see
        // media-domain-design.md §6. Separate caps for images vs. video
        // (video files are naturally much larger); the minimum image
        // dimension is checked at upload time, not in the processing job,
        // so a too-small image is rejected while the merchant is still in
        // the upload flow (§6's own reasoning). All configurable, never
        // hardcoded in MediaController.
        //
        // max_video_size_kb default: the domain owner's own real usage is
        // short, pre-processed product clips (§4) — 100MB matches Etsy's
        // proven real-world listing-video cap (100MB, 5-15 second clips),
        // a much closer comparison than Shopify's 1GB, since Shopify
        // serves through its own CDN with transcoding and EasyCo is
        // self-hosted with no CDN by default. Still fully
        // env-overridable per merchant — this is only the default.
        'max_image_size_kb' => env('MEDIA_MAX_IMAGE_SIZE_KB', 10240),
        'max_video_size_kb' => env('MEDIA_MAX_VIDEO_SIZE_KB', 102400),
        'min_image_dimension_px' => env('MEDIA_MIN_IMAGE_DIMENSION_PX', 600),
    ],

];
