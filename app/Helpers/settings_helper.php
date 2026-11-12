<?php

/*
 * NOT USED — this file is never loaded (no composer.json "autoload.files" entry,
 * no require/include anywhere) and neither function below is called.
 * Locale is set by LanguageManager / ApiLocalizationMiddleware instead.
 */

use App\Models\Language;
use Illuminate\Support\Facades\Session;

function current_language()
{
    // if (Session::get('language') == 'en' || Session::get('language') == 'fr') {
    //     $lang = Session::get('language');
    //     Session::put('language', $lang);
    //     Session::put('locale', $lang);
    //     app()->setLocale(Session::get('locale'));
    // } else {
    //     $lang = 'en';
    //     Session::put('language', $lang);
    //     Session::put('locale', $lang);
    //     app()->setLocale(Session::get('locale'));
    // }
    $lang = Session::get('locale');
    app()->setLocale($lang);
}

function get_language()
{
    return Language::get();
}
