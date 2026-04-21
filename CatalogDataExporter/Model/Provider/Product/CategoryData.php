<?php
/**
 * Copyright 2022 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider\Product;

use Magento\CatalogDataExporter\Model\Provider\Category\CategoryUrlPathBuilder;
use Magento\CatalogDataExporter\Model\Query\ProductCategoryDataQuery;
use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;

/**
 * Product categories data provider
 */
class CategoryData
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var ProductCategoryDataQuery
     */
    private $productCategoryDataQuery;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CategoryUrlPathBuilder
     */
    private $urlPathBuilder;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ProductCategoryDataQuery $productCategoryDataQuery
     * @param LoggerInterface $logger
     * @param CategoryUrlPathBuilder|null $urlPathBuilder
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ProductCategoryDataQuery $productCategoryDataQuery,
        LoggerInterface $logger,
        ?CategoryUrlPathBuilder $urlPathBuilder = null
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productCategoryDataQuery = $productCategoryDataQuery;
        $this->logger = $logger;
        $this->urlPathBuilder = $urlPathBuilder ?? ObjectManager::getInstance()->get(CategoryUrlPathBuilder::class);
    }

    /**
     * Get provider data
     *
     * @param array $values
     * @return array
     * @throws UnableRetrieveData
     */
    public function get(array $values) : array
    {
        $connection = $this->resourceConnection->getConnection();
        $queryArguments = [];
        $output = [];
        try {
            foreach ($values as $value) {
                $queryArguments['productId'][$value['productId']] = $value['productId'];
                $queryArguments['storeViewCode'][$value['storeViewCode']] = $value['storeViewCode'];
            }
            foreach ($queryArguments['storeViewCode'] as $storeViewCode) {
                $results = $connection->fetchAll(
                    $this->productCategoryDataQuery->getQuery($queryArguments, $storeViewCode)
                );

                // Build url_paths for all unique categories returned for this store.
                $pathsByEntityId = [];
                foreach ($results as $result) {
                    $pathsByEntityId[(int)$result['categoryId']] = $result['path'];
                }
                $urlPaths = $this->urlPathBuilder->resolveUrlPaths($pathsByEntityId, $storeViewCode);

                foreach ($results as $result) {
                    $key = implode('-', [$storeViewCode, $result['productId'], $result['categoryId']]);
                    $categoryData = $result;
                    unset($categoryData['path']); // internal field, not part of the feed schema
                    $path = $urlPaths[(int)$result['categoryId']] ?? null;
                    if (!$path) {
                        $this->logger->error(sprintf(
                            'Unable to resolve url_path for category %d with path "%s" for store view "%s".',
                            $result['categoryId'],
                            $result['path'],
                            $storeViewCode
                        ));
                        continue;
                    }
                    $categoryData['categoryPath'] = $path;

                    $output[$key]['productId'] = $result['productId'];
                    $output[$key]['storeViewCode'] = $storeViewCode;
                    $output[$key]['categoryData'] = $categoryData;
                }
            }
        } catch (\Throwable $exception) {
            throw new UnableRetrieveData(
                sprintf('Unable to retrieve category data for products: %s', $exception->getMessage()),
                0,
                $exception
            );
        }
        return $output;
    }
}
