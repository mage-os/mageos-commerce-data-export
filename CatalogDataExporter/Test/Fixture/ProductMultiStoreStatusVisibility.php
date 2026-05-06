<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Fixture;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterfaceFactory;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\TestFramework\Fixture\RevertibleDataFixtureInterface;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

/**
 * Creates a simple product that has store-specific status and visibility values only in
 * fixture_second_store, with no admin-store (store_id=0) values for those attributes.
 *
 * Resulting EAV state for catalog_product_entity_int (status and visibility):
 * - store_id = 0  : no row (admin-store row is deleted after product creation)
 * - store_id = N  : status=1 (Enabled), visibility=2 (Catalog)  [fixture_second_store]
 * - default store : no row
 *
 * Expected feed output:
 * - fixture_second_store : status="Enabled",  visibility="Catalog"
 * - default store        : status="Disabled", visibility="Not Visible Individually"
 */
class ProductMultiStoreStatusVisibility implements RevertibleDataFixtureInterface
{
    private const STORE_CODE = 'fixture_second_store';
    private const PRODUCT_SKU = 'product_multistore_status_visibility';
    private const PRODUCT_ID = 61;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreInterfaceFactory $storeFactory,
        private readonly StoreResource $storeResource,
        private readonly ProductFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Registry $registry
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(array $data = []): ?DataObject
    {
        Resolver::getInstance()->requireDataFixture('Magento_CatalogDataExporter::Test/_files/setup_stores.php');
        $secondStoreId = $this->getStoreId();

        // Save the product with explicit status/visibility so that Magento writes
        // the standard store_id=0 EAV rows - we will remove them right after.
        $product = $this->productFactory->create();
        $product->isObjectNew(true);
        $product->setTypeId(Type::TYPE_SIMPLE)
            ->setId(self::PRODUCT_ID)
            ->setAttributeSetId(4)
            ->setName('Multi Store Status Visibility Product')
            ->setSku(self::PRODUCT_SKU)
            ->setPrice(10)
            ->setWeight(1.0)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setWebsiteIds([1])
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 0, 'is_qty_decimal' => 0, 'is_in_stock' => 0])
            ->save();

        $connection = $this->resourceConnection->getConnection();
        $productEntityTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $linkField = $connection->getAutoIncrementField($productEntityTable);
        $linkFieldValue = (int)$connection->fetchOne(
            $connection->select()
                ->from(['e' => $productEntityTable], [$linkField])
                ->where('e.entity_id = ?', self::PRODUCT_ID)
        );

        // Remove admin-store (store_id=0) rows so neither the default store
        // nor any store without an explicit override can fall back to them.
        $this->removeAdminStoreValue('catalog_product_entity_int', 'status', $linkField, $linkFieldValue);
        $this->removeAdminStoreValue('catalog_product_entity_int', 'visibility', $linkField, $linkFieldValue);

        // Insert store-specific values for fixture_second_store only.
        $this->insertStoreValue(
            'catalog_product_entity_int',
            'status',
            Status::STATUS_ENABLED,
            $linkField,
            $linkFieldValue,
            $secondStoreId
        );
        $this->insertStoreValue(
            'catalog_product_entity_int',
            'visibility',
            Visibility::VISIBILITY_IN_CATALOG,
            $linkField,
            $linkFieldValue,
            $secondStoreId
        );

        return new DataObject(['sku' => self::PRODUCT_SKU, 'id' => self::PRODUCT_ID]);
    }

    /**
     * @inheritdoc
     */
    public function revert(DataObject $data): void
    {
        $this->registry->unregister('isSecureArea');
        $this->registry->register('isSecureArea', true);

        try {
            $product = $this->productRepository->get(self::PRODUCT_SKU);
            $this->productRepository->delete($product);
        } catch (\Exception) {
            // nothing to delete
        }
        $this->registry->unregister('isSecureArea');
        $this->registry->register('isSecureArea', false);

        Resolver::getInstance()->requireDataFixture(
            'Magento_CatalogDataExporter::Test/_files/setup_stores_rollback.php'
        );
    }

    /**
     * Creates fixture_second_store if it does not already exist.
     *
     * @return int store_id
     */
    private function getStoreId(): int
    {
        $store = $this->storeFactory->create();
        $this->storeResource->load($store, self::STORE_CODE, 'code');
        return (int)$store->getId();
    }

    /**
     * Deletes the admin-store (store_id=0) EAV row for the given attribute.
     */
    private function removeAdminStoreValue(
        string $backendTable,
        string $attributeCode,
        string $linkField,
        int $linkFieldValue
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $attributeId = $this->getAttributeId($attributeCode);
        if ($attributeId === 0) {
            return;
        }
        $connection->delete(
            $this->resourceConnection->getTableName($backendTable),
            [
                $linkField . ' = ?' => $linkFieldValue,
                'attribute_id = ?' => $attributeId,
                'store_id = ?' => 0,
            ]
        );
    }

    /**
     * Inserts or updates a store-specific EAV row for the given attribute.
     */
    private function insertStoreValue(
        string $backendTable,
        string $attributeCode,
        mixed $value,
        string $linkField,
        int $linkFieldValue,
        int $storeId
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $attributeId = $this->getAttributeId($attributeCode);
        if ($attributeId === 0) {
            return;
        }
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName($backendTable),
            [
                $linkField => $linkFieldValue,
                'attribute_id' => $attributeId,
                'store_id' => $storeId,
                'value' => $value,
            ],
            ['value']
        );
    }

    /**
     * Returns the attribute_id for the given product EAV attribute code.
     */
    private function getAttributeId(string $attributeCode): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->resourceConnection->getTableName('eav_attribute')], ['attribute_id'])
                ->join(
                    ['et' => $this->resourceConnection->getTableName('eav_entity_type')],
                    'a.entity_type_id = et.entity_type_id',
                    []
                )
                ->where('et.entity_table = ?', 'catalog_product_entity')
                ->where('a.attribute_code = ?', $attributeCode)
        );
    }
}
