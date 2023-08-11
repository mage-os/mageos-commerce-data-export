<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProductDataExporter\Model\Query;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Product variant query builder
 */
class VariantsQuery
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Get query for provider
     *
     * @param array $arguments
     * @return Select
     */
    public function getQuery(array $arguments) : Select
    {
        $productIds = $arguments['productId'] ?? [];
        $connection = $this->resourceConnection->getConnection();
        $joinField = $connection->getAutoIncrementField(
            $this->resourceConnection->getTableName('catalog_product_entity')
        );

        $select = $connection->select()
            ->from(['cpsl' => $this->resourceConnection->getTableName('catalog_product_super_link')], [])
            ->joinInner(
                ['cpsa' => $this->resourceConnection->getTableName('catalog_product_super_attribute')],
                'cpsa.product_id = cpsl.parent_id',
                []
            )
            ->joinInner(
                ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'cpe.entity_id = cpsl.product_id',
                []
            )
            ->joinInner(
                ['cpeParent' => $this->resourceConnection->getTableName('catalog_product_entity')],
                sprintf('cpeParent.%1$s = cpsl.parent_id', $joinField),
                []
            )->join(
                ['product_website' => $this->resourceConnection->getTableName('catalog_product_website')],
                'product_website.product_id = cpe.entity_id',
                []
            )->joinInner(
                ['s' => $this->resourceConnection->getTableName('store')],
                's.website_id = product_website.website_id'
            )
            ->columns(
                [
                    'storeViewCode' => 's.code',
                    'productId' => 'cpeParent.entity_id',
                    'sku' => 'cpe.sku'
                ]
            )
            ->where('cpeParent.entity_id IN (?)', $productIds)
            ->distinct();

        return $select;
    }
}
