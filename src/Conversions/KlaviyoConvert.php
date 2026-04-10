<?php

declare(strict_types=1);

namespace Anibalealvarezs\KlaviyoHubDriver\Conversions;

use Anibalealvarezs\ApiDriverCore\Conversions\UniversalMetricConverter;
use Anibalealvarezs\ApiDriverCore\Conversions\UniversalEntityConverter;
use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * KlaviyoConvert
 * 
 * Standardizes Klaviyo entity and metric data into APIs Hub objects
 * using the Universal conversion engines from the base skeleton.
 */
class KlaviyoConvert
{
    /**
     * Converts Klaviyo customers into a collection.
     */
    public static function customers(array $customers): ArrayCollection
    {
        return UniversalEntityConverter::convert($customers, [
            'channel' => 'klaviyo',
            'platform_id_field' => 'id',
            'date_field' => 'attributes.created',
            'mapping' => [
                'email' => 'attributes.email',
                'platformCreatedAt' => fn($r) => isset($r['attributes']['created']) ? Carbon::parse($r['attributes']['created']) : Carbon::now(),
            ],
        ]);
    }

    /**
     * Converts Klaviyo products into a collection.
     */
    public static function products(array $products): ArrayCollection
    {
        return UniversalEntityConverter::convert($products, [
            'channel' => 'klaviyo',
            'platform_id_field' => 'id',
            'date_field' => 'attributes.created',
            'mapping' => [
                'sku' => 'sku',
                'vendor' => fn($r) => null,
                'variants' => fn($r) => self::productVariants($r['included'] ?? []),
            ],
        ]);
    }

    /**
     * Converts Klaviyo product variants into a collection.
     */
    public static function productVariants(array $productVariants): ArrayCollection
    {
        return UniversalEntityConverter::convert($productVariants, [
            'channel' => 'klaviyo',
            'platform_id_field' => 'id',
            'date_field' => 'attributes.created',
            'mapping' => [
                'sku' => 'sku',
            ],
        ]);
    }

    /**
     * Converts Klaviyo product categories into a collection.
     */
    public static function productCategories(array $productCategories): ArrayCollection
    {
        return UniversalEntityConverter::convert($productCategories, [
            'channel' => 'klaviyo',
            'platform_id_field' => 'id',
            'date_field' => 'attributes.created',
        ]);
    }

    /**
     * Converts Klaviyo metric aggregates response using UniversalMetricConverter.
     */
    public static function metricAggregates(array $aggregates, string $metricId, array $metricNamesMap = []): ArrayCollection
    {
        $metricName = $metricNamesMap[$metricId] ?? 'Unknown Metric';
        $dates = $aggregates['dates'] ?? [];
        $data = $aggregates['data'] ?? [];

        $rows = [];
        foreach ($dates as $index => $date) {
            if (isset($data[$index])) {
                $rows[] = array_merge($data[$index]['dimensions'] ?? [], [
                    'date' => $date,
                    'count' => $data[$index]['measurements']['count'] ?? 0,
                    'measurements' => $data[$index]['measurements'] ?? [],
                ]);
            }
        }

        return UniversalMetricConverter::convert($rows, [
            'channel' => 'klaviyo',
            'period' => 'daily',
            'platform_id_field' => 'platform_id',
            'fallback_platform_id' => $metricId,
            'date_field' => 'date',
            'metrics' => [
                'count' => $metricName
            ],
            'dimensions' => array_filter(array_keys($rows[0] ?? []), fn($k) => !in_array($k, ['date', 'count', 'measurements'])),
            'context' => [
                'platform_id' => $metricId,
            ],
        ]);
    }
}
