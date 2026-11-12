{{-- Sidebar → Package Management → Ad Packages (AdPackageController::index). --}}
@extends('packages.partials.list', [
    'title' => __('Ad Packages'),
    'createLabel' => __('Create Ad Package'),
    'exportName' => 'ad-package-list',
    'showVideoColumn' => true,
])
