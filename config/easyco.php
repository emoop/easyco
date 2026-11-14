<?php

/**
 * Operator switches for TEST / STAGING deployments (deploy-docker.md). EVERY switch is OFF by default, so a Laragon install, the test
 * suite and a production install behave exactly as before. They are environment configuration, not Site Settings: a merchant never sees them.
 */
return [

    // `EASYCO_NOINDEX=true`: every response carries `X-Robots-Tag: noindex, nofollow` and /robots.txt disallows everything
    // (App\Http\Middleware\NoIndexResponses, routes/web.php).
    'noindex' => filter_var(env('EASYCO_NOINDEX', false), FILTER_VALIDATE_BOOLEAN),

    // HTTP basic auth for the WHOLE site, active only when BOTH values are non-empty (App\Http\Middleware\SiteBasicAuth). `/up` stays open.
    'basic_auth' => [
        'user' => (string) env('EASYCO_BASIC_AUTH_USER', ''),
        'password' => (string) env('EASYCO_BASIC_AUTH_PASSWORD', ''),
    ],

    // `EASYCO_MAIL_SANDBOX=true`: EVERY outgoing mail goes to this SMTP server (Mailpit), whatever transport the admin chose
    // (App\Mail\MailConfigurator::apply — the one place).
    'mail_sandbox' => [
        'enabled' => filter_var(env('EASYCO_MAIL_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),
        'host' => (string) env('EASYCO_MAIL_SANDBOX_HOST', 'mailpit'),
        'port' => (int) env('EASYCO_MAIL_SANDBOX_PORT', 1025),
    ],
];
