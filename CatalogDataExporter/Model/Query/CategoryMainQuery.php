<?php
/**
 * Copyright 2021 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Query;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\Store;

/**
 * Base category data query for category data exporter
 */
class CategoryMainQuery
{
    private ?int $urlKeyAttributeId = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param string $mainTable
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly string $mainTable
    ) {
    }

    /**
     * Get query for provider
     *
     * @param array $ids
     * @param int|null $scopeId
     *
     * @return Select
     */
    public function getQuery(array $ids, ?int $scopeId = null) : Select
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['main_table' => $this->resourceConnection->getTableName($this->mainTable)],
                [
                    'categoryId' => 'main_table.entity_id',
                    'createdAt' => 'main_table.created_at',
                    'updatedAt' => 'main_table.updated_at',
                    'level' => 'main_table.level',
                    'position' => 'main_table.position',
                    'parentId' => 'main_table.parent_id',
                    'path' => 'main_table.path',
                ]
            );

        $storeColumns = ['storeViewCode' => 's.code', 'storeId' => 's.store_id'];

        if (null === $scopeId) {
            $select->joinCross(
                ['s' => $this->resourceConnection->getTableName('store')],
                $storeColumns
            );
        } else {
            $select->join(
                ['s' => $this->resourceConnection->getTableName('store')],
                $connection->quoteInto('s.store_id = ?', $scopeId),
                $storeColumns
            );
        }

        return $select
            ->join(
                ['sg' => $this->resourceConnection->getTableName('store_group')],
                's.group_id = sg.group_id',
                ['rootCategoryId' => 'sg.root_category_id']
            )
            ->where('s.store_id != ?', Store::DEFAULT_STORE_ID)
            ->where('main_table.entity_id IN (?)', $ids)
            ->where(
                \sprintf(
                    'main_table.path LIKE %s or main_table.path LIKE %s',
                    new Expression("CONCAT('%/', sg.root_category_id, '/%')"),
                    new Expression("CONCAT('%/', sg.root_category_id)")
                )
            );
    }

    /**
     * Returns a Select that fetches url_key for the given category IDs with store-specific fallback to store_id=0.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return Select
     */
    public function getUrlKeyQuery(array $categoryIds, int $storeId): Select
    {
        $connection = $this->resourceConnection->getConnection();
        $mainTable = $this->resourceConnection->getTableName($this->mainTable);
        $varcharTable = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $linkField = $connection->getAutoIncrementField($mainTable);
        $attrId = $this->getUrlKeyAttributeId();

        $storeCondition = sprintf(
            'url_key_store.%1$s = e.%1$s AND url_key_store.attribute_id = %2$d AND url_key_store.store_id = %3$d',
            $linkField,
            $attrId,
            $storeId
        );
        $defaultCondition = sprintf(
            'url_key_default.%1$s = e.%1$s AND url_key_default.attribute_id = %2$d AND url_key_default.store_id = %3$d',
            $linkField,
            $attrId,
            Store::DEFAULT_STORE_ID
        );

        return $connection->select()
            ->from(['e' => $mainTable], ['entity_id'])
            ->joinLeft(['url_key_store' => $varcharTable], $storeCondition, [])
            ->joinLeft(['url_key_default' => $varcharTable], $defaultCondition, [])
            ->columns(['url_key' => new Expression('IFNULL(url_key_store.value, url_key_default.value)')])
            ->where('e.entity_id IN (?)', $categoryIds);
    }

    /**
     * Looks up and caches the attribute_id for the category url_key EAV attribute.
     */
    private function getUrlKeyAttributeId(): int
    {
        if ($this->urlKeyAttributeId === null) {
            $connection = $this->resourceConnection->getConnection();
            $this->urlKeyAttributeId = (int)$connection->fetchOne(
                $connection->select()
                    ->from(
                        ['a' => $this->resourceConnection->getTableName('eav_attribute')],
                        ['attribute_id']
                    )
                    ->join(
                        ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                        't.entity_type_id = a.entity_type_id',
                        []
                    )
                    ->where('t.entity_table = ?', 'catalog_category_entity')
                    ->where('a.attribute_code = ?', 'url_key')
            );
        }

        return $this->urlKeyAttributeId;
    }
}
