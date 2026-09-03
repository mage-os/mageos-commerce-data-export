<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Eav;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\DataExporter\Service\IndexInvalidationManager;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\Store;

/**
 * MySQL trigger does not call in case of cascade deleting (by FK), as a result an entity is not
 * re-indexed when its attribute is deleted (attribute deleted from eav_attribute table directly)
 * @see https://bugs.mysql.com/bug.php?id=11472
 *
 * Schedules a reindex of entities affected by an attribute deletion, if the feed indexer mode is
 * set to "Update On Schedule". Since the operation is triggered from Admin UI we cannot allow a
 * long-running operation, so this works in 2 modes:
 * - invalidate the feed indexer if the amount of affected entities exceeds the configured threshold
 * - add affected entities to the changelog for partial reindex otherwise
 *
 * Entity specifics (entity interface, feed metadata, invalidation event name) are injected via
 * DI. Configured for products and categories via separate virtual types (see di.xml); the
 * concrete `Plugin\Eav\Attribute\*AttributeDelete` plugins each inject the matching configured
 * instance. The feed's main table and indexer id are read from the already-configured
 * `ProductFeedIndexMetadata`/`CategoryFeedIndexMetadata` instead of being duplicated here, and
 * the target EAV entity type code is derived from `entityInterface` via `MetadataPool`.
 */
class AttributeDeleteResync
{
    private const MAX_ENTITIES_FOR_INSERT = 10000;

    /**
     * @param ResourceConnection $resourceConnection
     * @param MetadataPool $metadataPool
     * @param IndexerRegistry $indexerRegistry
     * @param CommerceDataExportLoggerInterface $logger
     * @param IndexInvalidationManager $invalidationManager
     * @param EavConfig $eavConfig
     * @param FeedIndexMetadata $feedMetadata
     * @param string $entityInterface
     * @param string $invalidationEvent
     * @param int $maxEntitiesPerInsert
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CommerceDataExportLoggerInterface $logger,
        private readonly IndexInvalidationManager $invalidationManager,
        private readonly EavConfig $eavConfig,
        private readonly FeedIndexMetadata $feedMetadata,
        private readonly string $entityInterface,
        private readonly string $invalidationEvent,
        private readonly int $maxEntitiesPerInsert = self::MAX_ENTITIES_FOR_INSERT
    ) {
    }

    /**
     * Schedule affected entities for reindex when an EAV attribute is deleted.
     *
     * @param Attribute $attribute
     */
    public function beforeDelete(Attribute $attribute): void
    {
        if (!$this->isTargetEntityAttribute($attribute)) {
            return;
        }

        $indexer = $this->indexerRegistry->get((string) $this->feedMetadata->getIndexerId());
        if (!$indexer->isScheduled()) {
            return;
        }

        if ($attribute->isStatic()) {
            // only user defined and some system attributes supported for the feed.
            return;
        }
        $attributeCode = $attribute->getAttributeCode();
        try {
            $connection = $this->resourceConnection->getConnection('indexer');
            $metadata = $this->metadataPool->getMetadata($this->entityInterface);
            $linkField = $metadata->getLinkField();
            $query = $connection->select()
                ->from(['attribute' => $attribute->getBackendTable()], [])
                ->where('attribute.attribute_id=?', $attribute->getId())
                ->where('attribute.store_id=?', Store::DEFAULT_STORE_ID);

            $countQuery = clone $query;
            $countQuery->columns('count(1)');
            if ($connection->fetchOne($countQuery) > $this->maxEntitiesPerInsert) {
                $this->invalidationManager->invalidate($this->invalidationEvent);
            } else {
                $query->joinInner(
                    ['entity' => $this->resourceConnection->getTableName($this->feedMetadata->getSourceTableName())],
                    \sprintf('attribute.%1$s = entity.%1$s', $linkField),
                    ['entity.entity_id']
                );
                $connection->query($query->insertFromSelect(
                    $this->resourceConnection->getTableName($indexer->getView()->getChangelog()->getName()),
                    ['entity_id']
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'CDE03-06 Feed sync scheduling error on attribute "%s" deletion. Run full resync. Error: %s',
                    $attributeCode,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }
    }

    /**
     * Whether the attribute belongs to the configured target entity type.
     *
     * @param Attribute $attribute
     * @return bool
     */
    private function isTargetEntityAttribute(Attribute $attribute): bool
    {
        $entityTypeId = (int) $attribute->getEntityTypeId();
        if ($entityTypeId <= 0) {
            return false;
        }
        try {
            $entityTypeCode = $this->metadataPool->getMetadata($this->entityInterface)->getEavEntityType();
            $targetEntityTypeId = (int) $this->eavConfig->getEntityType($entityTypeCode)->getId();
        } catch (\Throwable) {
            return false;
        }
        return $entityTypeId === $targetEntityTypeId;
    }
}
