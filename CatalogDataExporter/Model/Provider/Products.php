<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider;

use Magento\CatalogDataExporter\Model\Provider\EavAttributes\EntityEavAttributesResolver;
use Magento\CatalogDataExporter\Model\Provider\Product\Formatter\FormatterInterface;
use Magento\CatalogDataExporter\Model\Query\ProductMainQuery;
use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;

/**
 * Products data provider
 */
class Products
{
    private ResourceConnection $resourceConnection;
    private ProductMainQuery $productMainQuery;
    private FormatterInterface $formatter;
    private LoggerInterface $logger;
    private EntityEavAttributesResolver $entityEavAttributesResolver;

    /**
     * @var array required attributes for product export
     */
    private array $requiredAttributes;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ProductMainQuery $productMainQuery
     * @param FormatterInterface $formatter
     * @param LoggerInterface $logger
     * @param EntityEavAttributesResolver $entityEavAttributesResolver
     * @param array $requiredAttributes
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ProductMainQuery $productMainQuery,
        FormatterInterface $formatter,
        LoggerInterface $logger,
        EntityEavAttributesResolver $entityEavAttributesResolver,
        array $requiredAttributes = []
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productMainQuery = $productMainQuery;
        $this->formatter = $formatter;
        $this->logger = $logger;
        $this->entityEavAttributesResolver = $entityEavAttributesResolver;
        $this->requiredAttributes = $requiredAttributes;
    }

    /**
     * Get provider data
     *
     * @param array $values
     *
     * @return array
     * @throws UnableRetrieveData
     */
    public function get(array $values) : array
    {
        $output = [];
        $queryArguments = [];
        $mappedProducts = [];
        $attributesData = [];

        foreach ($values as $value) {
            $scope = $value['scopeId'] ?? Store::DEFAULT_STORE_ID;
            $queryArguments[$scope][$value['productId']] = $value['attribute_ids'] ?? [];
        }

        $connection = $this->resourceConnection->getConnection();
        foreach ($queryArguments as $scopeId => $productData) {
            $cursor = $connection->query(
                $this->productMainQuery->getQuery(\array_keys($productData), $scopeId ?: null)
            );

            while ($row = $cursor->fetch()) {
                $mappedProducts[$row['storeViewCode']][$row['productId']] = $row;
                $attributesData[$row['storeViewCode']][$row['productId']] = $productData[$row['productId']];
            }
        }
        if (!$mappedProducts) {
            $productsIds = \implode(',', \array_unique(\array_column($values, 'productId')));
            $scopes = \implode(',', \array_unique(\array_column($values, 'scopeId')));
            $this->logger->info(
                \sprintf('Cannot collect product data for ids %s in scopes %s', $productsIds, $scopes)
            );
        }

        foreach ($mappedProducts as $storeCode => $products) {
            $output[] = \array_map(function ($row) {
                return $this->formatter->format($row);
            }, \array_replace_recursive(
                $products,
                $this->entityEavAttributesResolver->resolve($attributesData[$storeCode], $storeCode)
            ));
        }

        $errorEntityIds = [];
        foreach ($output as $part) {
            foreach ($part as $entityId => $attributes) {
                if (array_diff($this->requiredAttributes, array_keys(array_filter($attributes)))) {
                    $errorEntityIds[] = $entityId;
                }
            }
        }
        if (!empty($errorEntityIds)) {
            $this->logger->warning(
                'One or more required EAV attributes ('
                . implode(',', $this->requiredAttributes)
                . ') are not set for products: ' . implode(',', $errorEntityIds)
            );
        }

        /** @phpstan-ignore-next-line */
        return !empty($output) ? \array_merge(...$output) : [];
    }
}
