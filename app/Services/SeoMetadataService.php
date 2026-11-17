<?php

namespace App\Services;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Item;
use App\Models\SeoMetaEntity;
use App\Models\SeoTemplateGlobal;
use App\Models\State;
use Illuminate\Support\Str;

class SeoMetadataService
{
    /**
     * Default global templates across page types, locales, and variants (WP4)
     */
    public static function defaultTemplates(): array
    {
        return [
            // === COUNTRY PAGE ===
            [
                'page_type' => 'country',
                'locale' => 'fr',
                'variant' => 'default',
                'meta_title' => 'Petites annonces au {country} | Jumli',
                'meta_description' => 'Découvrez des milliers de petites annonces au {country} sur Jumli : immobilier, voitures, téléphones, emplois et plus. Achetez et vendez facilement.',
                'h1' => 'Petites annonces au {country}',
                'intro_text' => 'Bienvenue sur Jumli {country}, la plateforme leader de petites annonces gratuites.',
            ],
            [
                'page_type' => 'country',
                'locale' => 'fr',
                'variant' => 'empty',
                'meta_title' => 'Petites annonces au {country} | Jumli',
                'meta_description' => 'Trouvez et publiez des petites annonces au {country} sur Jumli.',
                'h1' => 'Petites annonces au {country}',
                'intro_text' => 'Publiez la première annonce au {country} sur Jumli dès aujourd’hui.',
            ],
            [
                'page_type' => 'country',
                'locale' => 'en',
                'variant' => 'default',
                'meta_title' => 'Classified Ads in {country} | Jumli',
                'meta_description' => 'Browse thousands of classified ads in {country} on Jumli: real estate, cars, phones, jobs, and more. Buy and sell with ease.',
                'h1' => 'Classified Ads in {country}',
                'intro_text' => 'Welcome to Jumli {country}, your trusted local marketplace.',
            ],
            [
                'page_type' => 'country',
                'locale' => 'en',
                'variant' => 'empty',
                'meta_title' => 'Classified Ads in {country} | Jumli',
                'meta_description' => 'Find and post classified ads in {country} on Jumli.',
                'h1' => 'Classified Ads in {country}',
                'intro_text' => 'Post the first ad in {country} on Jumli today.',
            ],

            // === LOCATION PAGE ===
            [
                'page_type' => 'location',
                'locale' => 'fr',
                'variant' => 'default',
                'meta_title' => 'Petites annonces à {location}, {country} | Jumli',
                'meta_description' => 'Découvrez toutes les annonces à {location}, {country} sur Jumli. Achetez et vendez près de chez vous en toute sécurité.',
                'h1' => 'Petites annonces à {location}',
                'intro_text' => 'Toutes les petites annonces récentes à {location}, {country}.',
            ],
            [
                'page_type' => 'location',
                'locale' => 'fr',
                'variant' => 'empty',
                'meta_title' => 'Petites annonces à {location}, {country} | Jumli',
                'meta_description' => 'Trouvez des annonces à {location}, {country} sur Jumli. Publiez une annonce gratuitement.',
                'h1' => 'Petites annonces à {location}',
                'intro_text' => 'Soyez le premier à publier une annonce à {location}, {country}.',
            ],
            [
                'page_type' => 'location',
                'locale' => 'en',
                'variant' => 'default',
                'meta_title' => 'Classified Ads in {location}, {country} | Jumli',
                'meta_description' => 'Discover all classified ads in {location}, {country} on Jumli. Buy and sell locally with ease.',
                'h1' => 'Classified Ads in {location}',
                'intro_text' => 'All recent listings in {location}, {country}.',
            ],
            [
                'page_type' => 'location',
                'locale' => 'en',
                'variant' => 'empty',
                'meta_title' => 'Classified Ads in {location}, {country} | Jumli',
                'meta_description' => 'Find listings in {location}, {country} on Jumli. Post your ad for free.',
                'h1' => 'Classified Ads in {location}',
                'intro_text' => 'Be the first to post a listing in {location}, {country}.',
            ],

            // === CATEGORY PAGE ===
            [
                'page_type' => 'category',
                'locale' => 'fr',
                'variant' => 'default',
                'meta_title' => '{category} au {country} ({listing_count} annonces) | Jumli',
                'meta_description' => 'Trouvez les meilleures offres de {category} au {country}. Annonces vérifiées et prix attractifs sur Jumli.',
                'h1' => '{category} au {country}',
                'intro_text' => 'Explorez notre sélection d’annonces pour {category} au {country}.',
            ],
            [
                'page_type' => 'category',
                'locale' => 'fr',
                'variant' => 'empty',
                'meta_title' => '{category} au {country} | Jumli',
                'meta_description' => 'Toutes les annonces de {category} au {country} sur Jumli. Publiez votre annonce gratuitement.',
                'h1' => '{category} au {country}',
                'intro_text' => 'Aucune annonce disponible actuellement pour {category} au {country}. Déposez la vôtre !',
            ],
            [
                'page_type' => 'category',
                'locale' => 'en',
                'variant' => 'default',
                'meta_title' => '{category} in {country} ({listing_count} listings) | Jumli',
                'meta_description' => 'Find the best deals on {category} in {country}. Verified listings and great prices on Jumli.',
                'h1' => '{category} in {country}',
                'intro_text' => 'Explore our top listings for {category} in {country}.',
            ],
            [
                'page_type' => 'category',
                'locale' => 'en',
                'variant' => 'empty',
                'meta_title' => '{category} in {country} | Jumli',
                'meta_description' => 'All {category} listings in {country} on Jumli. Post your ad for free.',
                'h1' => '{category} in {country}',
                'intro_text' => 'No listings currently available for {category} in {country}. Post yours today!',
            ],

            // === COMBINATION (CITY + CATEGORY) PAGE ===
            [
                'page_type' => 'combination',
                'locale' => 'fr',
                'variant' => 'default',
                'meta_title' => '{category} à {location}, {country} ({listing_count} annonces) | Jumli',
                'meta_description' => 'Découvrez les offres de {category} à {location}, {country}. Annonces vérifiées à partir de {min_price} {currency}.',
                'h1' => '{category} à {location}',
                'intro_text' => 'Trouvez facilement {category} à {location}, {country}.',
            ],
            [
                'page_type' => 'combination',
                'locale' => 'fr',
                'variant' => 'empty',
                'meta_title' => '{category} à {location}, {country} | Jumli',
                'meta_description' => 'Trouvez et publiez des annonces de {category} à {location}, {country} sur Jumli.',
                'h1' => '{category} à {location}',
                'intro_text' => 'Aucune annonce de {category} à {location} pour le moment. Soyez le premier à publier !',
            ],
            [
                'page_type' => 'combination',
                'locale' => 'en',
                'variant' => 'default',
                'meta_title' => '{category} in {location}, {country} ({listing_count} listings) | Jumli',
                'meta_description' => 'Find {category} in {location}, {country}. Verified listings starting from {min_price} {currency}.',
                'h1' => '{category} in {location}',
                'intro_text' => 'Find quality {category} in {location}, {country}.',
            ],
            [
                'page_type' => 'combination',
                'locale' => 'en',
                'variant' => 'empty',
                'meta_title' => '{category} in {location}, {country} | Jumli',
                'meta_description' => 'Find and post {category} ads in {location}, {country} on Jumli.',
                'h1' => '{category} in {location}',
                'intro_text' => 'No {category} listings in {location} right now. Post your ad today!',
            ],

            // === AD PAGE ===
            [
                'page_type' => 'ad',
                'locale' => 'fr',
                'variant' => 'default',
                'meta_title' => '{ad_title} — {location}, {country} | Jumli',
                'meta_description' => '{ad_description}',
                'h1' => '{ad_title}',
                'intro_text' => null,
            ],
            [
                'page_type' => 'ad',
                'locale' => 'en',
                'variant' => 'default',
                'meta_title' => '{ad_title} — {location}, {country} | Jumli',
                'meta_description' => '{ad_description}',
                'h1' => '{ad_title}',
                'intro_text' => null,
            ],
        ];
    }

    /**
     * Seed or update all default global templates
     */
    public static function seedDefaultTemplates(): void
    {
        foreach (self::defaultTemplates() as $template) {
            SeoTemplateGlobal::updateOrCreate(
                [
                    'page_type' => $template['page_type'],
                    'locale' => $template['locale'],
                    'variant' => $template['variant'],
                ],
                $template
            );
        }
    }

    /**
     * Resolve metadata for a page using the 3-tier cascade resolver with field-by-field fallback.
     *
     * @param string $pageType 'country', 'location', 'category', 'combination', 'ad'
     * @param array $context [country, location, category, category_chain, ad, listing_count, min_price, currency]
     * @param string $locale 'fr' or 'en'
     * @param int $listingCount count of active ads
     * @param int $page current pagination page
     * @return array [meta_title, meta_description, h1, intro_text, variant, page]
     */
    public static function resolve(
        string $pageType,
        array $context = [],
        string $locale = 'fr',
        int $listingCount = 1,
        int $page = 1
    ): array {
        // Ensure baseline templates exist
        if (SeoTemplateGlobal::count() === 0) {
            self::seedDefaultTemplates();
        }

        $variant = ($listingCount > 0) ? 'default' : 'empty';

        // 1. Fetch Tier 2 Overrides (Category / Location)
        $categoryOverride = null;
        if (!empty($context['category']['id'])) {
            $categoryOverride = SeoMetaEntity::forEntity('category', $context['category']['id'], $locale, $variant)->first();
            if (!$categoryOverride && $variant === 'empty') {
                $categoryOverride = SeoMetaEntity::forEntity('category', $context['category']['id'], $locale, 'default')->first();
            }
        }

        $locationOverride = null;
        if (!empty($context['location']['id'])) {
            $locType = (!empty($context['location']['level']) && $context['location']['level'] == 1) ? 'state' : 'city';
            $locationOverride = SeoMetaEntity::forEntity($locType, $context['location']['id'], $locale, $variant)->first();
            if (!$locationOverride && $variant === 'empty') {
                $locationOverride = SeoMetaEntity::forEntity($locType, $context['location']['id'], $locale, 'default')->first();
            }
        }

        // 2. Fetch Tier 1 Global Template
        $globalTemplate = SeoTemplateGlobal::forPage($pageType, $locale, $variant)->first();
        if (!$globalTemplate && $variant === 'empty') {
            $globalTemplate = SeoTemplateGlobal::forPage($pageType, $locale, 'default')->first();
        }

        // 3. Resolve Field-by-Field: Category Override -> Location Override -> Global Template
        $fields = ['meta_title', 'meta_description', 'h1', 'intro_text'];
        $resolved = [];

        foreach ($fields as $field) {
            $val = null;
            if ($categoryOverride && filled($categoryOverride->{$field})) {
                $val = $categoryOverride->{$field};
            } elseif ($locationOverride && filled($locationOverride->{$field})) {
                $val = $locationOverride->{$field};
            } elseif ($globalTemplate && filled($globalTemplate->{$field})) {
                $val = $globalTemplate->{$field};
            }

            $resolved[$field] = $val;
        }

        // 4. Substitute Dynamic Variables
        $replacements = self::buildReplacements($context, $listingCount, $page);

        foreach ($fields as $field) {
            if ($resolved[$field]) {
                $resolved[$field] = self::substituteVariables($resolved[$field], $replacements);
            }
        }

        // 5. Pagination Safety: Append " — Page {page}" for page >= 2
        if ($page > 1 && filled($resolved['meta_title'])) {
            $pageSuffix = ($locale === 'en') ? " — Page {$page}" : " — Page {$page}";
            $resolved['meta_title'] .= $pageSuffix;
        }

        $resolved['variant'] = $variant;
        $resolved['page'] = $page;

        return $resolved;
    }

    /**
     * Build placeholder replacements map from page context
     */
    protected static function buildReplacements(array $context, int $listingCount, int $page): array
    {
        $countryName = $context['country']['name'] ?? '';
        $locationName = $context['location']['name'] ?? '';
        $categoryName = $context['category']['name'] ?? '';

        $categoryPath = '';
        if (!empty($context['category_chain']) && is_array($context['category_chain'])) {
            $categoryPath = implode(' › ', array_column($context['category_chain'], 'name'));
        } elseif ($categoryName) {
            $categoryPath = $categoryName;
        }

        $currency = $context['currency'] ?? $context['country']['currency'] ?? '';
        $minPrice = isset($context['min_price']) && $context['min_price'] > 0
            ? number_format($context['min_price'])
            : '';

        $adTitle = $context['ad']['name'] ?? $context['ad_title'] ?? '';
        $adDescription = $context['ad']['description'] ?? $context['ad_description'] ?? '';
        if ($adDescription) {
            $adDescription = Str::limit(strip_tags($adDescription), 160);
        }

        return [
            '{country}' => $countryName,
            '{location}' => $locationName,
            '{city}' => $locationName,
            '{category}' => $categoryName,
            '{category_path}' => $categoryPath,
            '{listing_count}' => $listingCount > 0 ? (string)$listingCount : '',
            '{min_price}' => $minPrice,
            '{currency}' => $currency,
            '{ad_title}' => $adTitle,
            '{ad_description}' => $adDescription,
            '{page}' => (string)$page,
        ];
    }

    /**
     * Perform string placeholder substitution and clean empty brackets
     */
    protected static function substituteVariables(?string $template, array $replacements): ?string
    {
        if (!$template) {
            return null;
        }

        $output = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Remove empty residual variables e.g. "{foo}"
        $output = preg_replace('/\{[a-zA-Z0-9_-]+\}/', '', $output);

        // Clean double spaces or dangling hyphens caused by empty variables
        $output = preg_replace('/\s+/', ' ', $output);
        $output = trim(str_replace(['( )', '()', ' - |', ' | |'], ['', '', '|', '|'], $output));

        return $output;
    }
}
