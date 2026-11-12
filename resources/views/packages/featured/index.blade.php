{{-- Sidebar → Package Management → Featured Packages (FeaturedPackageController::index). --}}
@extends('packages.partials.list', [
    'title' => __('Featured Packages'),
    'createLabel' => __('Create Featured Package'),
    'exportName' => 'featured-package-list',
    'showVideoColumn' => false,
])
