<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains
 * the property of Adobe and its suppliers, if any. The intellectual
 * and technical concepts contained herein are proprietary to Adobe
 * and its suppliers and are protected by all applicable intellectual
 * property laws, including trade secret and copyright laws.
 * Dissemination of this information or reproduction of this material
 * is strictly forbidden unless prior written permission is obtained
 * from Adobe.
 */
declare(strict_types=1);

namespace Magento\ParentProductDataExporter\Model\Provider;

use Magento\ParentProductDataExporter\Model\Query\ProductParentQuery;
use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\Framework\App\ResourceConnection;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;

/**
 * Product parents data provider
 */
class Parents
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var ProductParentQuery
     */
    private $productParentQuery;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ProductParentQuery $productParentQuery
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ProductParentQuery $productParentQuery,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productParentQuery = $productParentQuery;
        $this->logger = $logger;
    }

    /**
     * Format provider data
     *
     * @param array $row
     * @return array
     */
    private function format(array $row) : array
    {
        $output = $row;
        $output['parents'] = [
            'sku' => $row['sku'],
            'productType' => $row['productType']
        ];
        return $output;
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
        $queryArguments = [];
        foreach ($values as $value) {
            $queryArguments['productId'][] = $value['productId'];
            $queryArguments['storeViewCode'][] = $value['storeViewCode'];
        }
        $queryArguments['storeViewCode'] = array_unique($queryArguments['storeViewCode']);
        $queryArguments['productId'] = array_unique($queryArguments['productId']);
        $output = [];
        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $this->productParentQuery->getQuery($queryArguments);
            $cursor = $connection->query($select);
            while ($row = $cursor->fetch()) {
                $output[] = $this->format($row);
            }
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            throw new UnableRetrieveData('Unable to retrieve parent product data');
        }
        return $output;
    }
}
