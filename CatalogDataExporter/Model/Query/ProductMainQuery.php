<?php
/**
 * Copyright 2021 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Query;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Store;

/**
 * Base product data query for catalog data exporter
 */
class ProductMainQuery
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var string
     */
    private $mainTable;

    /**
     * @param ResourceConnection $resourceConnection
     * @param string $mainTable
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        string $mainTable
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->mainTable = $mainTable;
    }

    /**
     * Get query for provider
     *
     * @param array $ids
     * @param int|int[]|null $scopeIds Store view id(s) to extract; null or empty means all store views
     *
     * @return Select
     */
    public function getQuery(array $ids, int|array|null $scopeIds = null) : Select
    {
        $connection = $this->resourceConnection->getConnection();
        $scopeIds = $this->normalizeScopeIds($scopeIds);

        $select = $connection->select()
            ->from(
                ['main_table' => $this->resourceConnection->getTableName($this->mainTable)],
                [
                    'sku',
                    'productId' => 'main_table.entity_id',
                    'type' => 'main_table.type_id',
                    'createdAt' => 'main_table.created_at',
                    'updatedAt' => 'main_table.updated_at',
                ]
            );

        if (empty($scopeIds)) {
            $select->joinCross(
                ['s' => $this->resourceConnection->getTableName('store')],
                ['storeViewCode' => 's.code']
            );
        } else {
            $select->join(
                ['s' => $this->resourceConnection->getTableName('store')],
                $connection->quoteInto('s.store_id IN (?)', $scopeIds),
                ['storeViewCode' => 's.code']
            );
        }

        return $select
            ->join(
                ['cpw' => $this->resourceConnection->getTableName('catalog_product_website')],
                'cpw.website_id = s.website_id AND cpw.product_id = main_table.entity_id',
                []
            )
            ->where('s.store_id != ?', Store::DEFAULT_STORE_ID)
            ->where('main_table.entity_id IN (?)', $ids);
    }

    /**
     * Normalize the scope id argument to a list of ints (backward compatible with int|null callers).
     *
     * @param int|int[]|null $scopeIds
     * @return int[]
     */
    private function normalizeScopeIds(int|array|null $scopeIds): array
    {
        if ($scopeIds === null) {
            return [];
        }
        if (!is_array($scopeIds)) {
            $scopeIds = [$scopeIds];
        }

        return array_values(array_filter(array_map('intval', $scopeIds)));
    }
}
