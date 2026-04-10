<?php

namespace Anibalealvarezs\KlaviyoHubDriver\Drivers;

use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiDriverCore\Traits\HasUpdatableCredentials;
use Anibalealvarezs\KlaviyoApi\KlaviyoApi;
use Anibalealvarezs\KlaviyoApi\Enums\AggregatedMeasurement;
use Anibalealvarezs\KlaviyoHubDriver\Conversions\KlaviyoConvert;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;

class KlaviyoDriver implements SyncDriverInterface
{

    /**
     * Store credentials for this driver.
     * 
     * @param array $credentials
     * @return void
     */
    public static function storeCredentials(array $credentials): void
    {
        // No implementation needed for this driver
    }

    /**
     * Get the public resources exposed by this driver.
     * 
     * @return array
     */
    public static function getPublicResources(): array
    {
        return [];
    }

    /**
     * Get the display label for the channel.
     * 
     * @return string
     */
    public static function getChannelLabel(): string
    {
        return 'Klaviyo';
    }

    /**
     * Get the routes served by this driver.
     * 
     * @return array
     */
    public static function getRoutes(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function fetchAvailableAssets(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function validateAuthentication(): array
    {
        return [
            'success' => true,
            'message' => 'Status unknown for this driver.',
            'details' => []
        ];
    }

    public static function getCommonConfigKey(): ?string
    {
        return null;
    }
    use HasUpdatableCredentials;

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

    public function getAuthProvider(): ?AuthProviderInterface
    {
        return $this->authProvider;
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
            $api = new KlaviyoApi($this->authProvider->getAccessToken());

            $type = $config['type'] ?? 'all';

            // 1. Sync Metrics (Aggregates)
            if ($type === 'all' || $type === 'metrics') {
                $this->syncMetrics($api, $startDate, $endDate, $config);
            }

            // 2. Sync Customers (Profiles)
            if ($type === 'all' || $type === 'customers') {
                $this->syncCustomers($api, $startDate, $endDate, $config);
            }

            // 3. Sync Products (Catalog Items)
            if ($type === 'all' || $type === 'products') {
                $this->syncProducts($api, $config);
            }

            return new Response(json_encode(['status' => 'success', 'message' => "Klaviyo sync [{$type}] completed"]));

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("KlaviyoDriver error: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function syncMetrics(KlaviyoApi $api, DateTime $startDate, DateTime $endDate, array $config): void
    {
        if ($this->logger) {
            $this->logger->info("Syncing Klaviyo Metrics Aggregates...");
        }

        $metricIds = $config['metrics'] ?? [];
        if (empty($metricIds)) {
            $metricMap = $api->getMetricsMap();
            $metricIds = array_keys($metricMap);
        } else {
            $metricMap = $config['metricMap'] ?? [];
        }

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
                callback: function ($aggregates) use ($metricId, $metricMap) {
                    // Convert raw data into metrics using the SDK
                    $collection = KlaviyoConvert::metricAggregates($aggregates, (string)$metricId, $metricMap);
                    
                    // Persist converted collection in the host
                    if ($this->dataProcessor && $collection->count() > 0) {
                        ($this->dataProcessor)($collection, $this->logger);
                    }
                }
            );
        }
    }

    private function syncCustomers(KlaviyoApi $api, DateTime $startDate, DateTime $endDate, array $config): void
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
            callback: function ($customers) {
                // Convert raw data into metrics/entities using the SDK
                $collection = KlaviyoConvert::customers($customers);
                
                // Persist converted collection in the host
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    private function syncProducts(KlaviyoApi $api, array $config): void
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
            callback: function ($products) {
                // Convert raw data into metrics/entities using the SDK
                $collection = KlaviyoConvert::products($products);
                
                // Persist converted collection in the host
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    public function getApi(array $config = []): mixed
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getConfigSchema(): array
    {
        return [
            'global' => [
                'enabled' => true,
                'cache_history_range' => '1 year',
                'cache_aggregations' => false,
            ],
            'entity' => [
                'id' => '',
                'name' => '',
                'enabled' => true,
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function validateConfig(array $config): array
    {
        return $config;
    }

    /**
     * @inheritdoc
     */
    public function seedDemoData(SeederInterface $seeder, array $config = []): void
    {
        // Placeholder for future implementation
    }

    public array $updatableCredentials = [
        'KLAVIYO_API_KEY'
    ];
    public function boot(): void
    {
    }

    /**
     * @inheritdoc
     */
    public function getAssetPatterns(): array
    {
        return [
            'klaviyo_account' => [
                'prefix' => 'kv:account',
                'hostnames' => ['klaviyo.com'],
                'url_id_regex' => null
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function initializeEntities(mixed $entityManager, array $config = []): array
    {
        return ['initialized' => 0, 'skipped' => 0];
    }

    /**
     * @inheritdoc
     */
    public function reset(mixed $entityManager, string $mode = 'all', array $config = []): array
    {
        if (!$entityManager instanceof \Doctrine\ORM\EntityManagerInterface) {
            throw new \Exception("EntityManagerInterface required for KlaviyoDriver reset.");
        }

        $resetter = new \Anibalealvarezs\KlaviyoHubDriver\Services\KlaviyoResetService($entityManager);
        return $resetter->reset($this->getChannel(), $mode);
    }

    public function updateConfiguration(array $newData, array $currentConfig): array
    {
        return $currentConfig;
    }

    public function prepareUiConfig(array $channelConfig): array
    {
        return [];
    }
}

