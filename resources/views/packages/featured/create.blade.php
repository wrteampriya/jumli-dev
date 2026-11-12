{{-- Featured Packages → Create (FeaturedPackageController::create, side drawer). No video ads or category scope: featured packages are global. --}}
@extends('packages.partials.form-page', [
    'title' => __('Create Featured Package'),
    'limitsTitle' => __('Featured Limits'),
    'itemLimitLabel' => __('Number of Featured Ads'),
    'durationTypeLabel' => __('Featured Ads Duration Type'),
    'showVideoSection' => false,
])
