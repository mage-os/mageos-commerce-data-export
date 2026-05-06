<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\CatalogDataExporter\Plugin\Eav;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\AbstractModel;

/**
 * Plugin that detects product attribute option label changes during attribute save and
 * schedules a product feed update for products carrying the changed (option, store) pairs.
 * TODO: move product threshold to env config to be able configure for edge case
 */
class ResyncProductsOnAttributeOptionLabelChange
{
    private const PRODUCTS_FEED_INDEXER = 'catalog_data_exporter_products';
    private const AFFECTED_PRODUCTS_THRESHOLD = 1000;

    private const FRONTEND_INPUT_SELECT = 'select';
    private const FRONTEND_INPUT_MULTISELECT = 'multiselect';
    private const SUPPORTED_FRONTEND_INPUTS = [self::FRONTEND_INPUT_SELECT, self::FRONTEND_INPUT_MULTISELECT];

    private ?int $productEntityTypeId = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param EavConfig $eavConfig
     * @param IndexerRegistry $indexerRegistry
     * @param MetadataPool $metadataPool
     * @param CommerceDataExportLoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly MetadataPool $metadataPool,
        private readonly CommerceDataExportLoggerInterface $logger
    ) {
    }

    /**
     * Detect and handle product attribute option label changes during attribute save.
     *
     * Snapshot option labels before save runs, then diff after save to schedule a product feed update
     * for products carrying any changed (option_id, store_id) pair.
     *
     * @param AttributeResource $subject
     * @param callable $proceed
     * @param AbstractModel $object
     *
     * @return AttributeResource
     */
    public function aroundSave(
        AttributeResource $subject,
        callable $proceed,
        AbstractModel $object
    ): AttributeResource {
        $oldLabels = null;
        if ($this->shouldHandle($object)) {
            try {
                $oldLabels = $this->loadOptionLabels((int) $object->getAttributeId());
            } catch (\Throwable $e) {
                $this->logError($object, $e);
            }
        }

        $result = $proceed($object);

        if ($oldLabels !== null) {
            try {
                [$changedOptionIds, $changedStoreIds] = $this->detectChanges(
                    $oldLabels,
                    $this->loadOptionLabels((int) $object->getAttributeId())
                );
                $this->scheduleProductFeedUpdate($object, $changedOptionIds, $changedStoreIds);
            } catch (\Throwable $e) {
                $this->logError($object, $e);
            }
        }

        return $result;
    }

    /**
     * Whether this attribute save is in scope for product feed scheduling.
     *
     * @param AbstractModel $object
     *
     * @return bool
     */
    private function shouldHandle(AbstractModel $object): bool
    {
        if ($object->isObjectNew()) {
            return false;
        }
        if (!\in_array((string) $object->getFrontendInput(), self::SUPPORTED_FRONTEND_INPUTS, true)) {
            return false;
        }
        return $this->isProductAttribute($object);
    }

    /**
     * Whether the attribute belongs to the catalog_product entity type.
     *
     * @param AbstractModel $object
     *
     * @return bool
     */
    private function isProductAttribute(AbstractModel $object): bool
    {
        $entityTypeId = (int) $object->getEntityTypeId();
        if ($entityTypeId <= 0) {
            return false;
        }
        if ($this->productEntityTypeId === null) {
            try {
                $this->productEntityTypeId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getId();
            } catch (\Throwable) {
                return false;
            }
        }
        return $entityTypeId === $this->productEntityTypeId;
    }

    /**
     * Load all option labels for the given attribute.
     *
     * Reads from eav_attribute_option_value instead of $object->getOptions() to prevent getting
     * cached results. Returns a nested array keyed by (option_id, store_id) with values as labels.
     *
     * @param int $attributeId
     *
     * @return array<int, array<int, string>>
     */
    private function loadOptionLabels(int $attributeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['o' => $this->resourceConnection->getTableName('eav_attribute_option')],
                []
            )
            ->joinInner(
                ['v' => $this->resourceConnection->getTableName('eav_attribute_option_value')],
                'v.option_id = o.option_id',
                ['option_id', 'store_id', 'value']
            )
            ->where('o.attribute_id = ?', $attributeId);

        $rows = $connection->fetchAll($select);
        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['option_id']][(int) $row['store_id']] = (string) $row['value'];
        }
        return $labels;
    }

    /**
     * Detect changes between old and new labels.
     *
     * @param array<int, array<int, string>> $oldLabels
     * @param array<int, array<int, string>> $newLabels
     *
     * @return array<int>
     */
    private function detectChanges(array $oldLabels, array $newLabels): array
    {
        $changedOptionIds = [];
        $changedStoreIds = [];

        foreach ($oldLabels as $optionId => $optionValues) {
            $changedOrAdded = array_diff($newLabels[$optionId] ?? [], $optionValues);
            $deletedOrAdded = array_diff($optionValues, $newLabels[$optionId] ?? []);
            if (!empty($deletedOrAdded) || !empty($changedOrAdded)) {
                $changedOptionIds[] = $optionId;
                $changedStoreIds = array_merge($changedStoreIds,  array_keys($changedOrAdded));
                $changedStoreIds = array_merge($changedStoreIds,  array_keys($deletedOrAdded));
            }
        }
        return [array_unique($changedOptionIds), array_unique($changedStoreIds)];
    }

    /**
     * Schedule product feed update based on number of affected products.
     *
     * Either invalidate the product feed indexer or insert affected product ids into the changelog,
     * depending on how many products carry any of the changed option ids.
     *
     * @param AbstractModel $attribute
     * @param int[] $changedOptionIds
     * @param int[] $changedStoreIds
     *
     * @return void
     */
    private function scheduleProductFeedUpdate(
        AbstractModel $attribute,
        array $changedOptionIds,
        array $changedStoreIds
    ): void {
        if ((string) $attribute->getBackendTable() === '' || empty($changedOptionIds)) {
            return;
        }

        // Always include admin (0) - product feed falls back to the admin row when a store override is absent.
        $changedStoreIds[] = 0;
        $storeIds = array_unique($changedStoreIds);

        $indexer = $this->indexerRegistry->get(self::PRODUCTS_FEED_INDEXER);

        if (!$indexer->getView()->isEnabled()) {
            $indexer->invalidate();
            $this->logger->info(
                'Attribute option label change detected. '
                . 'Full resync scheduled due to product feed is not in schedule update mode'
            );
            return;
        }

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $affectedProductsSelect = $this->buildAffectedProductsSelect(
            $attribute,
            $linkField,
            $changedOptionIds,
            $storeIds
        );
        $affectedProductCount = $this->countAffectedProducts($affectedProductsSelect);
        if ($affectedProductCount <= 0) {
            return;
        }

        if ($affectedProductCount > self::AFFECTED_PRODUCTS_THRESHOLD) {
            $indexer->invalidate();
        } else {
            $this->addProductIdsToChangelog(
                $affectedProductsSelect,
                (string) $indexer->getView()->getChangelog()->getName()
            );
        }

        $this->logger->info(sprintf(
            'Attribute option label change detected. Attribute id: %d, affected products: %d. %s.',
            (int) $attribute->getAttributeId(),
            $affectedProductCount,
            $affectedProductCount > self::AFFECTED_PRODUCTS_THRESHOLD
                ? 'Full resync scheduled'
                : 'Partial resync scheduled'
        ));
    }

    /**
     * Count distinct affected products, capped at threshold + 1.
     *
     * @param \Magento\Framework\DB\Select $affectedProductsSelect
     *
     * @return int
     */
    private function countAffectedProducts(\Magento\Framework\DB\Select $affectedProductsSelect): int
    {
        $connection = $this->resourceConnection->getConnection();
        $innerSelect = (clone $affectedProductsSelect)->limit(self::AFFECTED_PRODUCTS_THRESHOLD + 1);
        $countSelect = $connection->select()
            ->from(['t' => $innerSelect], [new Expression('COUNT(1)')]);

        return (int) $connection->fetchOne($countSelect);
    }

    /**
     * Insert distinct affected product ids into the product feed changelog (single query).
     *
     * @param \Magento\Framework\DB\Select $affectedProductsSelect
     * @param string $changelogTableName
     *
     * @return void
     */
    private function addProductIdsToChangelog(
        \Magento\Framework\DB\Select $affectedProductsSelect,
        string $changelogTableName
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $connection->query(
            $connection->insertFromSelect(
                $affectedProductsSelect,
                $this->resourceConnection->getTableName($changelogTableName),
                ['entity_id']
            )
        );
    }

    /**
     * Build the affected-products select - dispatches on frontend_input.
     *
     * Select attributes (int backend) use a direct `value IN (?)` which hits the
     * (attribute_id, value) index on catalog_product_entity_int. Multiselect
     * attributes (varchar backend, comma-separated option ids) use FIND_IN_SET
     * because no index can be used against a CSV.
     *
     * @param AbstractModel $attribute
     * @param string $linkField
     * @param int[] $changedOptionIds
     * @param int[] $storeIds
     *
     * @return \Magento\Framework\DB\Select
     */
    private function buildAffectedProductsSelect(
        AbstractModel $attribute,
        string $linkField,
        array $changedOptionIds,
        array $storeIds
    ): \Magento\Framework\DB\Select {
        return (string) $attribute->getFrontendInput() === self::FRONTEND_INPUT_MULTISELECT
            ? $this->buildAffectedMultiselectProductsSelect($attribute, $linkField, $changedOptionIds, $storeIds)
            : $this->buildAffectedSelectProductsSelect($attribute, $linkField, $changedOptionIds, $storeIds);
    }

    /**
     * Build the affected-products select for `select` attributes (int backend).
     *
     * @param AbstractModel $attribute
     * @param string $linkField
     * @param int[] $changedOptionIds
     * @param int[] $storeIds
     *
     * @return \Magento\Framework\DB\Select
     */
    private function buildAffectedSelectProductsSelect(
        AbstractModel $attribute,
        string $linkField,
        array $changedOptionIds,
        array $storeIds
    ): \Magento\Framework\DB\Select {
        $connection = $this->resourceConnection->getConnection();
        $joinCondition = sprintf('eav.%1$s = p.%1$s', $linkField)
            . $connection->quoteInto(' AND eav.attribute_id = ?', (int) $attribute->getAttributeId())
            . $connection->quoteInto(' AND eav.store_id IN (?)', $storeIds);

        return $connection->select()
            ->distinct()
            ->from(
                ['p' => $this->resourceConnection->getTableName('catalog_product_entity')],
                ['entity_id' => 'p.entity_id']
            )
            ->joinInner(
                ['eav' => $this->resourceConnection->getTableName((string) $attribute->getBackendTable())],
                $joinCondition,
                []
            )
            ->where('eav.value IN (?)', $changedOptionIds);
    }

    /**
     * Build the affected-products select for `multiselect` attributes (varchar backend, CSV value).
     *
     * Each changed option id contributes a FIND_IN_SET predicate; the predicates are OR'd.
     * Number of predicates is bounded by the number of options the admin changed in the save.
     *
     * @param AbstractModel $attribute
     * @param string $linkField
     * @param int[] $changedOptionIds
     * @param int[] $storeIds
     *
     * @return \Magento\Framework\DB\Select
     */
    private function buildAffectedMultiselectProductsSelect(
        AbstractModel $attribute,
        string $linkField,
        array $changedOptionIds,
        array $storeIds
    ): \Magento\Framework\DB\Select {
        $connection = $this->resourceConnection->getConnection();
        $joinCondition = sprintf('eav.%1$s = p.%1$s', $linkField)
            . $connection->quoteInto(' AND eav.attribute_id = ?', (int) $attribute->getAttributeId())
            . $connection->quoteInto(' AND eav.store_id IN (?)', $storeIds);

        $orParts = [];
        foreach ($changedOptionIds as $optionId) {
            $orParts[] = $connection->quoteInto('FIND_IN_SET(?, eav.value) > 0', (int) $optionId);
        }

        return $connection->select()
            ->distinct()
            ->from(
                ['p' => $this->resourceConnection->getTableName('catalog_product_entity')],
                ['entity_id' => 'p.entity_id']
            )
            ->joinInner(
                ['eav' => $this->resourceConnection->getTableName((string) $attribute->getBackendTable())],
                $joinCondition,
                []
            )
            ->where(implode(' OR ', $orParts));
    }

    /**
     * @param AbstractModel $object
     * @param \Throwable|\Exception $e
     *
     * @return void
     */
    private function logError(AbstractModel $object, \Throwable|\Exception $e): void
    {
        $this->logger->error(
            sprintf(
                'CDE03-21 Product sync scheduling error on attribute {%s} option change. Run resync. Error: %s',
                $object->getAttributeId(),
                $e->getMessage()
            ),
            ['exception' => $e]
        );
    }
}
