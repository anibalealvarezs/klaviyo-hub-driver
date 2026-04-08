<?php

namespace Anibalealvarezs\KlaviyoHubDriver\Drivers;

use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\KlaviyoApi\KlaviyoApi;
use Anibalealvarezs\KlaviyoApi\Enums\AggregatedMeasurement;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;

class KlaviyoDriver implements SyncDriverInterface
{
    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;
    /** @var callable|null */
    private $dataProcessor = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function setDataProcessor(callable $processor): void
    {
        $this->dataProcessor = $processor;
    }

    public function getChannel(): string
    {
        return 'klaviyo';
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider) {
            throw new Exception("AuthProvider not set for KlaviyoDriver");
        }

        if (!$this->dataProcessor) {
            throw new Exception("DataProcessor not set for KlaviyoDriver");
        }

        if ($this->logger) {
            $this->logger->info("Starting KlaviyoDriver sync (Modular)...");
        }

        try {
            $api = new KlaviyoApi(
                apiKey: $this->authProvider->getAccessToken()
            );

            $type = $config['type'] ?? 'metrics';

            return match ($type) {
                'metrics' => $this->syncMetrics($api, $startDate, $endDate, $config),
                'customers' => $this->syncCustomers($api, $startDate, $endDate, $config),
                'products' => $this->syncProducts($api, $config),
                default => throw new Exception("Unsupported entity type for Klaviyo: {$type}"),
            };

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("KlaviyoDriver error: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function syncMetrics(KlaviyoApi $api, DateTime $startDate, DateTime $endDate, array $config): Response
    {
        $metricNames = $config['metricNames'] ?? ($config['klaviyo']['metrics'] ?? []);
        $metricIds = [];
        $metricMap = [];

        $api->getAllMetricsAndProcess(
            metricFields: ['id', 'name'],
            callback: function ($metrics) use (&$metricIds, &$metricMap, $metricNames) {
                foreach ($metrics as $metric) {
                    if (empty($metricNames) || in_array($metric['attributes']['name'], $metricNames)) {
                        $metricIds[] = $metric['id'];
                        $metricMap[$metric['id']] = $metric['attributes']['name'];
                    }
                }
            }
        );

        $formattedFilters = [
            ["operator" => "greater-than", "field" => "datetime", "value" => $startDate->format('Y-m-d H:i:s')],
            ["operator" => "less-than", "field" => "datetime", "value" => $endDate->format('Y-m-d H:i:s')]
        ];

        foreach ($metricIds as $metricId) {
            if ($this->logger) {
                $this->logger->info("Processing Klaviyo metric: " . ($metricMap[$metricId] ?? $metricId));
            }
            $api->getAllMetricAggregatesAndProcess(
                metricId: $metricId,
                measurements: [AggregatedMeasurement::count],
                filter: $formattedFilters,
                sortField: 'datetime',
                callback: function ($aggregates) use ($metricId, $metricMap, $config) {
                    // Delegate processing to the host
                    ($this->dataProcessor)(
                        data: $aggregates,
                        type: 'metrics',
                        metricId: $metricId,
                        metricMap: $metricMap,
                        config: $config
                    );
                }
            );
        }

        return new Response(json_encode(['status' => 'success', 'message' => 'Klaviyo metrics sync completed']));
    }

    private function syncCustomers(KlaviyoApi $api, DateTime $startDate, DateTime $endDate, array $config): Response
    {
        if ($this->logger) {
            $this->logger->info("Syncing Klaviyo Customers...");
        }
        
        $api->getAllProfilesAndProcess(
            profileFields: $config['fields'] ?? null,
            additionalFields: ['predictive_analytics', 'subscriptions'],
            filter: [
                ["operator" => "greater-than", "field" => "created", "value" => $startDate->format('Y-m-d H:i:s')],
                ["operator" => "less-than", "field" => "created", "value" => $endDate->format('Y-m-d H:i:s')],
            ],
            sortField: 'created',
            callback: function ($customers) use ($config) {
                // Delegate processing to the host
                ($this->dataProcessor)(
                    data: $customers,
                    type: 'customers',
                    config: $config
                );
            }
        );

        return new Response(json_encode(['status' => 'success', 'message' => 'Klaviyo customers sync completed']));
    }

    private function syncProducts(KlaviyoApi $api, array $config): Response
    {
        if ($this->logger) {
            $this->logger->info("Syncing Klaviyo Products...");
        }

        $formattedFilters = [];
        if (isset($config['filters'])) {
            foreach ($config['filters'] as $key => $value) {
                $formattedFilters[] = [
                    "operator" => 'equals',
                    "field" => $key,
                    "value" => $value,
                ];
            }
        }

        $api->getAllCatalogItemsAndProcess(
            catalogItemsFields: $config['fields'] ?? null,
            filter: $formattedFilters,
            callback: function ($products) use ($config) {
                // Delegate processing to the host
                ($this->dataProcessor)(
                    data: $products,
                    type: 'products',
                    config: $config
                );
            }
        );

        return new Response(json_encode(['status' => 'success', 'message' => 'Klaviyo products sync completed']));
    }
}
