<?php

namespace DDWPTweaks\SEO;

defined('ABSPATH') || exit;

/**
 * The schema.org types the editor knows about: their properties (for the
 * Visual mode property picker and required-property warnings) and presets.
 *
 * Unknown types still work: the editor accepts any @type and property name.
 */
class Schema_Types
{
    /** Properties every Thing can carry. */
    private const THING = ['name', 'description', 'url', 'image', 'sameAs', 'alternateName', 'identifier'];

    private const BUSINESS = [
        'required'    => ['name', 'address'],
        'recommended' => ['telephone', 'url', 'image', 'geo', 'openingHoursSpecification', 'priceRange'],
        'other'       => ['email', 'areaServed', 'parentOrganization', 'aggregateRating', 'review', 'logo', 'hasMap', 'paymentAccepted', 'currenciesAccepted', 'department', 'knowsAbout', 'slogan'],
    ];

    private const ARTICLE = [
        'required'    => ['headline', 'image', 'datePublished'],
        'recommended' => ['author', 'dateModified', 'publisher', 'description', 'mainEntityOfPage'],
        'other'       => ['articleSection', 'keywords', 'wordCount', 'inLanguage', 'about'],
    ];

    public static function all(): array
    {
        $types = [
            'Article'      => ['label' => 'Article'] + self::ARTICLE,
            'BlogPosting'  => ['label' => 'Blog post'] + self::ARTICLE,
            'NewsArticle'  => ['label' => 'News article'] + self::ARTICLE,
            'WebPage'      => [
                'label'       => 'Web page',
                'required'    => ['name'],
                'recommended' => ['url', 'description', 'datePublished', 'dateModified'],
                'other'       => ['breadcrumb', 'primaryImageOfPage', 'about', 'mainEntity', 'isPartOf'],
            ],
            'Organization' => [
                'label'       => 'Organization',
                'required'    => ['name'],
                'recommended' => ['url', 'logo', 'sameAs', 'telephone', 'email', 'address'],
                'other'       => ['founder', 'foundingDate', 'parentOrganization', 'subOrganization', 'areaServed', 'contactPoint', 'slogan'],
            ],
            'MedicalOrganization' => ['label' => 'Medical organization', 'parent' => 'Organization'],
            'LocalBusiness'       => ['label' => 'Local business'] + self::BUSINESS,
            'MedicalClinic'       => ['label' => 'Medical clinic', 'parent' => 'LocalBusiness', 'extra' => ['availableService', 'medicalSpecialty', 'healthPlanNetworkId']],
            'Dentist'             => ['label' => 'Dentist', 'parent' => 'LocalBusiness', 'extra' => ['availableService', 'medicalSpecialty']],
            'Physician'           => ['label' => 'Physician practice', 'parent' => 'LocalBusiness', 'extra' => ['availableService', 'medicalSpecialty']],
            'ProfessionalService' => ['label' => 'Professional service', 'parent' => 'LocalBusiness'],
            'LegalService'        => ['label' => 'Legal service', 'parent' => 'LocalBusiness'],
            'HomeAndConstructionBusiness' => ['label' => 'Trades / construction', 'parent' => 'LocalBusiness'],
            'AutomotiveBusiness'  => ['label' => 'Automotive business', 'parent' => 'LocalBusiness'],
            'Restaurant'          => ['label' => 'Restaurant', 'parent' => 'LocalBusiness', 'extra' => ['servesCuisine', 'menu', 'acceptsReservations']],
            'Store'               => ['label' => 'Store', 'parent' => 'LocalBusiness'],
            'Person'       => [
                'label'       => 'Person',
                'required'    => ['name'],
                'recommended' => ['url', 'image', 'jobTitle', 'worksFor'],
                'other'       => ['email', 'telephone', 'description', 'knowsAbout', 'alumniOf', 'honorificPrefix', 'honorificSuffix', 'affiliation'],
            ],
            'Service'      => [
                'label'       => 'Service',
                'required'    => ['name'],
                'recommended' => ['serviceType', 'description', 'provider', 'areaServed', 'url'],
                'other'       => ['offers', 'category', 'audience', 'hasOfferCatalog', 'termsOfService'],
            ],
            'Recipe'       => [
                'label'       => 'Recipe',
                'required'    => ['name', 'image'],
                'recommended' => ['author', 'datePublished', 'description', 'prepTime', 'cookTime', 'totalTime', 'recipeYield', 'recipeCategory', 'recipeCuisine', 'keywords', 'recipeIngredient', 'recipeInstructions', 'nutrition', 'aggregateRating', 'video'],
                'other'       => ['suitableForDiet', 'publisher', 'cookingMethod'],
            ],
            'Event'        => [
                'label'       => 'Event',
                'required'    => ['name', 'startDate', 'location'],
                'recommended' => ['endDate', 'eventStatus', 'eventAttendanceMode', 'image', 'description', 'offers', 'organizer', 'performer'],
                'other'       => ['doorTime', 'maximumAttendeeCapacity', 'inLanguage'],
            ],
            'FAQPage'      => [
                'label'       => 'FAQ page',
                'required'    => ['mainEntity'],
                'recommended' => [],
                'other'       => [],
            ],
            'Product'      => [
                'label'       => 'Product',
                'required'    => ['name'],
                'recommended' => ['image', 'description', 'sku', 'brand', 'offers', 'aggregateRating', 'review'],
                'other'       => ['gtin', 'mpn', 'color', 'material', 'category'],
            ],
            'JobPosting'   => [
                'label'       => 'Job posting',
                'required'    => ['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'],
                'recommended' => ['employmentType', 'validThrough', 'baseSalary', 'directApply'],
                'other'       => ['jobLocationType', 'applicantLocationRequirements', 'identifier', 'educationRequirements', 'experienceRequirements'],
            ],
            'Course'       => [
                'label'       => 'Course',
                'required'    => ['name', 'description', 'provider'],
                'recommended' => ['offers', 'hasCourseInstance'],
                'other'       => ['courseCode', 'educationalLevel', 'teaches'],
            ],
        ];

        foreach ($types as $type => &$definition) {
            $parent = isset($definition['parent']) ? $types[$definition['parent']] : [];
            foreach (['required', 'recommended', 'other'] as $group) {
                $definition[$group] = $definition[$group] ?? $parent[$group] ?? [];
            }
            $definition['other'] = array_values(array_unique(array_merge(
                $definition['other'],
                $definition['extra'] ?? [],
                self::THING
            )));
            unset($definition['extra']);
        }
        unset($definition);

        return apply_filters('ddwpt_schema_types', $types);
    }

    /** Required properties missing from a rendered node, for preview warnings. */
    public static function missing_required(array $node): array
    {
        $types = self::all();
        $type  = is_array($node['@type'] ?? null) ? ($node['@type'][0] ?? '') : ($node['@type'] ?? '');

        if (!isset($types[$type])) return [];

        return array_values(array_filter($types[$type]['required'], fn($property) => !isset($node[$property])));
    }

    /**
     * Presets: a starting template per type, with tokens pre-filled where the
     * mapping is obvious (title, permalink, featured image) and blank
     * strings left for the editor to map.
     */
    public static function presets(): array
    {
        $common = [
            'name'          => '{post_title}',
            'headline'      => '{post_title}',
            'title'         => '{post_title}',
            'url'           => '{post_url}',
            'image'         => '{featured_image}',
            'description'   => '{post_content:summary|220}',
            'datePublished' => '{post_date}',
            'datePosted'    => '{post_date}',
            'dateModified'  => '{post_modified}',
            'author'        => '{ref_organization}',
            'publisher'     => '{ref_organization}',
            'provider'      => '{ref_organization}',
            'hiringOrganization' => '{ref_organization}',
            'parentOrganization' => '{ref_organization}',
            'mainEntityOfPage'   => '{post_url}',
            'address'       => '{:address|AU}',
            'geo'           => '{:geo}',
            'openingHoursSpecification' => '{:hours}',
            'telephone'     => '{:phone|+61}',
            'prepTime'      => '{:minutes:duration}',
            'cookTime'      => '{:minutes:duration}',
            'recipeIngredient'   => ['@each' => '', '@value' => '{item}'],
            'recipeInstructions' => ['@each' => '', '@type' => 'HowToStep', 'text' => '{item:clean}'],
            'mainEntity'    => [
                '@each'          => '',
                '@type'          => 'Question',
                'name'           => '',
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => ''],
            ],
            'offers'        => ['@type' => 'Offer', 'price' => '', 'priceCurrency' => 'AUD', 'availability' => 'https://schema.org/InStock'],
            'location'      => ['@type' => 'Place', 'name' => '', 'address' => '{:address|AU}'],
            'jobLocation'   => ['@type' => 'Place', 'address' => '{:address|AU}'],
        ];

        $presets = [];

        foreach (self::all() as $type => $definition) {
            $template = ['@type' => $type, '@id' => '{post_url}#' . strtolower($type)];

            foreach (array_merge($definition['required'], $definition['recommended']) as $property) {
                $template[$property] = $common[$property] ?? '';
            }

            if ($type === 'FAQPage') {
                unset($template['@id']);
            }

            $presets[$type] = ['label' => $definition['label'], 'template' => $template];
        }

        return apply_filters('ddwpt_schema_presets', $presets);
    }
}
