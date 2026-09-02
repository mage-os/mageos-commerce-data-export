<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider;

use Magento\CatalogDataExporter\Model\Provider\EavAttributes\EntityEavAttributesResolver;
use Magento\CatalogDataExporter\Model\Provider\Product\Formatter\FormatterInterface;
use Magento\CatalogDataExporter\Model\Query\ProductMainQuery;
use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\DataExporter\Export\DataProcessorInterface;
use Magento\DataExporter\Export\ScopeResolverInterface;
use Magento\DataExporter\Model\FailedItemsRegistry;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\Store;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Products data provider
 */
class Products implements DataProcessorInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var ProductMainQuery
     */
    private $productMainQuery;

    /**
     * @var FormatterInterface
     */
    private $formatter;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EntityEavAttributesResolver
     */
    private $entityEavAttributesResolver;

    /**
     * @var array required attributes for product export
     */
    private array $requiredAttributes;
    /**
     * @var FailedItemsRegistry|mixed
     */
    private mixed $failedItemsRegistry;

    /**
     * @var ScopeResolverInterface
     */
    private ScopeResolverInterface $scopeResolver;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ProductMainQuery $productMainQuery
     * @param FormatterInterface $formatter
     * @param LoggerInterface $logger
     * @param EntityEavAttributesResolver $entityEavAttributesResolver
     * @param array $requiredAttributes
     * @param FailedItemsRegistry|null $failedRegistry
     * @param ScopeResolverInterface|null $scopeResolver
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ProductMainQuery $productMainQuery,
        FormatterInterface $formatter,
        LoggerInterface $logger,
        EntityEavAttributesResolver $entityEavAttributesResolver,
        array $requiredAttributes = [],
        ?FailedItemsRegistry $failedRegistry = null,
        ?ScopeResolverInterface $scopeResolver = null
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productMainQuery = $productMainQuery;
        $this->formatter = $formatter;
        $this->logger = $logger;
        $this->entityEavAttributesResolver = $entityEavAttributesResolver;
        $this->requiredAttributes = $requiredAttributes;
        $this->failedItemsRegistry = $failedRegistry ??
            ObjectManager::getInstance()->get(FailedItemsRegistry::class);
        $this->scopeResolver = $scopeResolver ??
            ObjectManager::getInstance()->get(ScopeResolverInterface::class);
    }

    /**
     * Get provider data
     *
     * @param array $arguments
     * @param callable $dataProcessorCallback
     * @param FeedIndexMetadata $metadata
     * @param ? $node
     * @param ? $info
     * @return void
     * @throws UnableRetrieveData
     * @throws \Zend_Db_Statement_Exception
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute(
        array $arguments,
        callable $dataProcessorCallback,
        FeedIndexMetadata $metadata,
        $node = null,
        $info = null
    ): void {
        $queryArguments = [];
        $mappedProducts = [];
        $attributesData = [];

        $explicitScopes = false;
        foreach ($arguments as $value) {
            if (isset($value['scopeId'])) {
                $explicitScopes = true;
                $scope = $value['scopeId'];
            } else {
                $scope = Store::DEFAULT_STORE_ID;
            }
            $queryArguments[$scope][$value['productId']] = $value['attribute_ids'] ?? [];
        }

        $connection = $this->resourceConnection->getConnection();
        $notFoundByScope = [];

        foreach ($queryArguments as $scopeId => $productData) {
            $foundProductIds = [];
            foreach ($this->resolveScopeBatches((int)$scopeId, $explicitScopes, $metadata) as $scopeBatch) {
                $storeViewItemN = [];
                $cursor = $connection->query(
                    $this->productMainQuery->getQuery(\array_keys($productData), $scopeBatch)
                );

                while ($row = $cursor->fetch()) {
                    $storeViewCode = $row['storeViewCode'];
                    $productId = $row['productId'];
                    $foundProductIds[$productId] = true;

                    if (!isset($storeViewItemN[$storeViewCode])) {
                        $storeViewItemN[$storeViewCode] = 0;
                    }
                    $storeViewItemN[$storeViewCode]++;

                    $mappedProducts[$storeViewCode][$productId] = $row;
                    $attributesData[$storeViewCode][$productId] = $productData[$productId];

                    if ($storeViewItemN[$storeViewCode] % $metadata->getBatchSize() == 0
                        || count($mappedProducts) % $metadata->getBatchSize() == 0) {
                        $this->processProducts(
                            $mappedProducts,
                            $attributesData,
                            $dataProcessorCallback,
                            $storeViewCode
                        );
                        unset($mappedProducts[$storeViewCode], $attributesData[$storeViewCode]);
                    }
                }
            }

            $missingProductIds = \array_diff(\array_keys($productData), \array_keys($foundProductIds));
            if (!empty($missingProductIds)) {
                $notFoundByScope[$scopeId] = $missingProductIds;
            }
        }

        if (!empty($mappedProducts)) {
            $this->processProducts($mappedProducts, $attributesData, $dataProcessorCallback);
        }

        foreach ($notFoundByScope as $scopeId => $productIds) {
            $this->logger->info(
                \sprintf(
                    'The following products will be marked as "deleted" in scope "%s": "%s"',
                    $scopeId == 0 ? 'all scopes' : $scopeId,
                    \implode(',', $productIds),
                )
            );
        }
    }

    /**
     * Resolve the store view id batches to extract for a given argument scope group.
     *
     * When a 3rd-party caller injects an explicit non-default scopeId per item, that single store
     * view is honored as-is (backward compatibility). Otherwise the scope resolver decides which
     * store views to extract (all store views by default, discoverable ones under ACO) and the list
     * is chunked by the configured store-view batch size.
     *
     * @param int $scopeId
     * @param bool $explicitScopes
     * @param FeedIndexMetadata $metadata
     * @return array
     */
    private function resolveScopeBatches(int $scopeId, bool $explicitScopes, FeedIndexMetadata $metadata): array
    {
        if ($explicitScopes && $scopeId !== Store::DEFAULT_STORE_ID) {
            return [[$scopeId]];
        }

        $scopeIds = $this->scopeResolver->getScopes($metadata);
        if (empty($scopeIds)) {
            return [];
        }

        return \array_chunk($scopeIds, $metadata->getStoreViewBatchSize());
    }

    /**
     * For backward compatibility - to allow 3rd party plugins work
     *
     * @param array $values
     * @return array
     */
    public function get(array $values) : array
    {
        return $values;
    }

    /**
     * Process products data
     *
     * @param array $mappedProducts
     * @param array $attributesData
     * @param callable $dataProcessorCallback
     * @param string|null $storeViewCode
     * @return void
     * @throws UnableRetrieveData
     */
    private function processProducts(
        array $mappedProducts,
        array $attributesData,
        callable $dataProcessorCallback,
        ?string $storeViewCode = null
    ): void {
        $output = [];
        if (null === $storeViewCode) {
            foreach ($mappedProducts as $mappedStoreViewCode => $products) {
                $this->formatOutput($products, $attributesData[$mappedStoreViewCode], $output, $mappedStoreViewCode);
            }
        } else {
            $this->formatOutput(
                $mappedProducts[$storeViewCode],
                $attributesData[$storeViewCode],
                $output,
                $storeViewCode
            );
        }

        foreach ($output as &$part) {
            foreach ($part as $entityId => $attributes) {
                $missedAttributes = array_diff($this->requiredAttributes, array_keys(array_filter($attributes)));
                if (!empty($missedAttributes)) {
                    // remove product without attributes from output to prevent errors on downstream
                    unset($part[$entityId]);
                    $this->failedItemsRegistry->addFailed($attributes, new UnableRetrieveData(
                        'One or more required EAV attributes ('
                        . implode(',', $missedAttributes)
                        . ") are not set for product: $entityId"
                    ));
                }
            }
        }

        $dataProcessorCallback($this->get(\array_merge(...$output)));
    }

    /**
     * Format output
     *
     * @param array $products
     * @param array $attributesData
     * @param array $output
     * @param string $storeViewCode
     * @return void
     * @throws UnableRetrieveData
     */
    private function formatOutput(
        array $products,
        array $attributesData,
        array &$output,
        string $storeViewCode
    ): void {
        $output[] = \array_map($this->formatter->format(...), \array_replace_recursive(
            $products,
            $this->entityEavAttributesResolver->resolve($attributesData, $storeViewCode)
        ));
    }
}
