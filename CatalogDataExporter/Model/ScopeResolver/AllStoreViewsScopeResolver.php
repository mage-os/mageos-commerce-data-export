<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\ScopeResolver;

use Magento\DataExporter\Export\ScopeResolverInterface;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;

/**
 * Default store-view scope resolver: returns every non-admin store view id.
 *
 * Preserves the historical behaviour where catalog feeds are extracted for all store views.
 * The ACO adapter replaces this with a resolver that returns only discoverable store views.
 */
class AllStoreViewsScopeResolver implements ScopeResolverInterface
{
    /**
     * @var int[]|null
     */
    private ?array $storeIds = null;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getScopes(FeedIndexMetadata $metadata): array
    {
        if ($this->storeIds === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(['s' => $this->resourceConnection->getTableName('store')], ['store_id'])
                ->where('s.store_id != ?', Store::DEFAULT_STORE_ID)
                ->order('s.store_id ASC');
            $this->storeIds = array_map('intval', $connection->fetchCol($select));
        }

        return $this->storeIds;
    }
}
