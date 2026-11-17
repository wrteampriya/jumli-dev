<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reserved Root Slugs
    |--------------------------------------------------------------------------
    | Slugs that can never be used as a country slug.
    */
    'reserved_root_slugs' => [
        // Frontend Website Routes
        'about-us',
        'ad-details',
        'ad-listing',
        'ads',
        'blogs',
        'cache-clear',
        'categories',
        'contact-us',
        'edit-listing',
        'faqs',
        'landing',
        'my-listing',
        'privacy-policy',
        'reel',
        'refund-policy',
        'seller',
        'subscription',
        'terms-and-condition',
        'user-verification',
        'chat',
        'favorites',
        'job-applications',
        'my-ads',
        'notifications',
        'profile',
        'refer-and-earn',
        'reviews',
        'transactions',
        'user-subscription',
        'main',
        'sitemap',
        'sitemap.xml',
        'robots.txt',
        'api',
        'assets',
        'en',
        'fr',
        '_next',

        // Module Slugs
        'classified',

        // Admin & Backend System Routes
        'admin',
        'login',
        'register',
        'home',
        'clear',
        'migrate',
        'storage',
        'install',
        'installed',
        'dummy-data',
        'error-logs',
        'file-manager',
        'import',
        'seeder',
        'run-scheduler',
        'restore-access',
        'system',
        'attribution-analytics',
        'page',
        'password',
        'email',
        'roles',
        'staff',
        'currency',
        'custom-fields',
        'package',
        'slider',
        'notification',
        'report-reasons',
        'verification-requests',
        'watermark-settings',
        'web-settings',
        'link-preview',
        'item',
        'items',
        'category'
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
