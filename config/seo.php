<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reserved Root Slugs
    |--------------------------------------------------------------------------
    | Slugs that can never be used as a country slug.
    */
    'reserved_root_slugs' => [
        'ads', 'blogs', 'about-us', 'contact-us', 'categories', 'faqs',
        'landing', 'privacy-policy', 'refund-policy', 'subscription',
        'terms-and-condition', 'en', 'fr', 'api', 'admin', 'sitemap',
        'sitemap.xml', 'robots.txt', 'assets', '_next', 'seller', 'chat',
        'my-ads', 'profile', 'login', 'register', 'home', 'clear', 'migrate',
        'storage', 'install', 'installed', 'dummy-data', 'error-logs',
        'file-manager', 'import', 'seeder', 'run-scheduler', 'restore-access',
        'system', 'attribution-analytics', 'page', 'password', 'email',
        'roles', 'staff', 'currency', 'custom-fields', 'package', 'slider',
        'notification', 'report-reasons', 'verification-requests', 'watermark-settings',
        'web-settings', 'link-preview', 'item', 'items', 'category'
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved Second Slugs
    |--------------------------------------------------------------------------
    | Slugs that can never be used as a location or category slug.
    */
    'reserved_second_slugs' => [
        'annonce',
        'listing',
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO Redirects Feature Flag
    |--------------------------------------------------------------------------
    | Toggle dynamic SEO redirect resolver on/off safely.
    */
    'redirects_enabled' => env('SEO_REDIRECTS_ENABLED', true),
];
