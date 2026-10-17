<?php
return [
    'name' => 'KrushiWorld KrushiPulse - Agricultural Market Intelligence',
    'version' => '2.1.0',
    'timezone' => 'Asia/Kolkata',
    'site' => [
        // Set APMC_PUBLIC_ORIGIN in production, e.g. https://your-domain.example
        'origin' => rtrim(getenv('APMC_PUBLIC_ORIGIN') ?: '', '/'),
        'publisher_name' => 'KrushiWorld',
        'default_locale' => 'mr_IN',
        'alternate_locale' => 'en_IN',
    ],
    'seo' => [
        'index_public' => true,
        'index_admin' => false,
        'dataset_license' => '',
    ],
    'sponsor' => [
        // Sponsor branding is intentionally separate from market data / SEO entities.
        // The logo should be a local WebP path under /public for a tighter CSP.
        'enabled' => filter_var(getenv('APMC_SPONSOR_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
        'name' => trim((string)(getenv('APMC_SPONSOR_NAME') ?: '')),
        'url' => trim((string)(getenv('APMC_SPONSOR_URL') ?: '')),
        'logo' => trim((string)(getenv('APMC_SPONSOR_LOGO') ?: '')),
    ],
    'co_sponsor' => [
        'enabled' => filter_var(getenv('APMC_COSPONSOR_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
        'name' => trim((string)(getenv('APMC_COSPONSOR_NAME') ?: '')),
        'url' => trim((string)(getenv('APMC_COSPONSOR_URL') ?: '')),
        'logo' => trim((string)(getenv('APMC_COSPONSOR_LOGO') ?: '')),
    ],
    'python_binary' => getenv('APMC_PYTHON') ?: 'python3',
    'source_base' => 'https://apmcpune.in',
    'admin_token' => getenv('APMC_ADMIN_TOKEN') ?: '',
    'admin' => [
        // Keep false in production. Query-string tokens leak into history/logs/referrers.
        'allow_legacy_query_token' => false,
    ],
    'refresh' => [
        'overnight_seconds' => 3600,
        'morning_seconds' => 900,
        'active_seconds' => 300,
        'evening_seconds' => 600,
        'source_timeout_seconds' => 15,
        // Browser/page fallback activates only after server cron misses enough time.
        'server_heartbeat_grace_seconds' => 900,
    ],
];
