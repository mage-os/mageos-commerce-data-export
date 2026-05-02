<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Fixture;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Entity\Attribute as CatalogEntityAttribute;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Eav\Model\Entity\Type as EntityType;
use Magento\Eav\Model\Entity\TypeFactory as EntityTypeFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterfaceFactory;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\RevertibleDataFixtureInterface;

/**
 * Creates a second store view, a `shirt_color` select product attribute (int backend) with two
 * options whose labels differ between admin and the second store, and four simple products: three
 * referencing shirt_color (two blue, one yellow) and one without.
 *
 * Used by ProductAttributeOptionLabelChangeTest to verify the products feed reflects option-label
 * edits.
 */
class ShirtColorProducts implements RevertibleDataFixtureInterface
{
    public const STORE_CODE = 'fixture_second_store';
    public const ATTRIBUTE_CODE = 'shirt_color';

    public const SKU_BLUE_1 = 'shirt-color-product-1';
    public const SKU_BLUE_2 = 'shirt-color-product-2';
    public const SKU_YELLOW = 'shirt-color-product-3';
    public const SKU_NO_ATTR = 'shirt-color-product-4';

    private const ADMIN_LABEL_BLUE = 'blue';
    private const ADMIN_LABEL_YELLOW = 'yellow';
    private const STORE_LABEL_BLUE = 'blue_2nd_store';
    private const STORE_LABEL_YELLOW = 'yellow_2nd_store';

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly StoreInterfaceFactory $storeFactory,
        private readonly StoreResource $storeResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly EntityTypeFactory $entityTypeFactory,
        private readonly OptionCollectionFactory $optionCollectionFactory,
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
        $secondStoreId = $this->ensureStoreExists();
        [$blueOptionId, $yellowOptionId] = $this->ensureShirtColorAttribute($secondStoreId);
        $this->createProducts($blueOptionId, $yellowOptionId);

        return new DataObject([
            'second_store_id' => $secondStoreId,
            'blue_option_id' => $blueOptionId,
            'yellow_option_id' => $yellowOptionId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function revert(DataObject $data): void
    {
        $this->registry->unregister('isSecureArea');
        $this->registry->register('isSecureArea', true);

        foreach ([self::SKU_BLUE_1, self::SKU_BLUE_2, self::SKU_YELLOW, self::SKU_NO_ATTR] as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                $this->productRepository->delete($product);
            } catch (\Throwable) {
                // nothing to delete
            }
        }

        try {
            $attribute = $this->objectManager->create(EavAttribute::class);
            $attribute->load(self::ATTRIBUTE_CODE, 'attribute_code');
            if ($attribute->getId()) {
                $attribute->delete();
            }
        } catch (\Throwable) {
            // nothing to delete
        }

        $store = $this->storeFactory->create();
        $this->storeResource->load($store, self::STORE_CODE, 'code');
        if ($store->getId()) {
            $this->storeResource->delete($store);
            $this->storeManager->reinitStores();
        }

        $this->registry->unregister('isSecureArea');
        $this->registry->register('isSecureArea', false);
    }

    /**
     * Create the second store view if it does not already exist.
     *
     * @return int store_id
     */
    private function ensureStoreExists(): int
    {
        $store = $this->storeFactory->create();
        $this->storeResource->load($store, self::STORE_CODE, 'code');
        if (!$store->getId()) {
            $store->setCode(self::STORE_CODE)
                ->setWebsiteId((int) $this->storeManager->getWebsite()->getId())
                ->setGroupId((int) $this->storeManager->getWebsite()->getDefaultGroupId())
                ->setName('Fixture Store')
                ->setSortOrder(10)
                ->setIsActive(1);
            $this->storeResource->save($store);
            $this->storeManager->reinitStores();
        }
        return (int) $store->getId();
    }

    /**
     * Create the shirt_color attribute (if missing) with admin labels then re-save with per-store
     * labels for the given store id. Two saves are required because store-specific labels reference
     * option ids that only exist after the initial insert.
     *
     * @param int $secondStoreId
     * @return int[] [$blueOptionId, $yellowOptionId]
     */
    private function ensureShirtColorAttribute(int $secondStoreId): array
    {
        /** @var EntityType $entityType */
        $entityType = $this->entityTypeFactory->create()->loadByCode('catalog_product');
        $entityTypeId = (int) $entityType->getId();
        $defaultAttributeSetId = (int) $entityType->getDefaultAttributeSetId();
        $attributeGroupId = $this->getDefaultGroupId($defaultAttributeSetId);

        $existing = $this->objectManager->create(CatalogEntityAttribute::class);
        $existing->load(self::ATTRIBUTE_CODE, 'attribute_code');
        if (!$existing->getId()) {
            $attribute = $this->objectManager->create(CatalogEntityAttribute::class);
            $attribute->setData([
                'attribute_code' => self::ATTRIBUTE_CODE,
                'entity_type_id' => $entityTypeId,
                'attribute_set_id' => $defaultAttributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'is_global' => 0,
                'is_user_defined' => 1,
                'frontend_input' => 'select',
                'is_unique' => 0,
                'is_required' => 0,
                'is_searchable' => 0,
                'is_visible_in_advanced_search' => 0,
                'is_comparable' => 0,
                'is_filterable' => 0,
                'is_filterable_in_search' => 0,
                'is_used_for_promo_rules' => 0,
                'is_html_allowed_on_front' => 1,
                'is_visible_on_front' => 1,
                'used_in_product_listing' => 1,
                'used_for_sort_by' => 0,
                'frontend_label' => ['Shirt Color'],
                'backend_type' => 'int',
                'source_model' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option' => [
                    'value' => [
                        'shirt_color_blue' => [self::ADMIN_LABEL_BLUE],
                        'shirt_color_yellow' => [self::ADMIN_LABEL_YELLOW],
                    ],
                    'order' => [
                        'shirt_color_blue' => 1,
                        'shirt_color_yellow' => 2,
                    ],
                ],
            ]);
            $attribute->save();
        }

        [$blueOptionId, $yellowOptionId] = $this->resolveOptionIds();

        $attribute = $this->objectManager->create(CatalogEntityAttribute::class);
        $attribute->load(self::ATTRIBUTE_CODE, 'attribute_code');
        $attribute->setOption([
            'value' => [
                $blueOptionId => [
                    0 => self::ADMIN_LABEL_BLUE,
                    $secondStoreId => self::STORE_LABEL_BLUE,
                ],
                $yellowOptionId => [
                    0 => self::ADMIN_LABEL_YELLOW,
                    $secondStoreId => self::STORE_LABEL_YELLOW,
                ],
            ],
            'order' => [
                $blueOptionId => 1,
                $yellowOptionId => 2,
            ],
        ]);
        $this->objectManager->create(AttributeResource::class)->save($attribute);

        return [$blueOptionId, $yellowOptionId];
    }

    /**
     * Resolve admin-store option ids for the blue/yellow values.
     *
     * @return int[] [$blueOptionId, $yellowOptionId]
     */
    private function resolveOptionIds(): array
    {
        $attribute = $this->objectManager->create(EavAttribute::class);
        $attribute->load(self::ATTRIBUTE_CODE, 'attribute_code');

        $collection = $this->optionCollectionFactory->create();
        $collection->setAttributeFilter((int) $attribute->getId())
            ->setStoreFilter(0);

        $blueId = null;
        $yellowId = null;
        foreach ($collection as $option) {
            $value = (string) $option->getValue();
            if ($value === self::ADMIN_LABEL_BLUE) {
                $blueId = (int) $option->getOptionId();
            } elseif ($value === self::ADMIN_LABEL_YELLOW) {
                $yellowId = (int) $option->getOptionId();
            }
        }
        if ($blueId === null || $yellowId === null) {
            throw new \RuntimeException('Failed to resolve shirt_color option ids during fixture setup.');
        }
        return [$blueId, $yellowId];
    }

    /**
     * Look up the default attribute group id of the given attribute set.
     *
     * @param int $attributeSetId
     * @return int
     */
    private function getDefaultGroupId(int $attributeSetId): int
    {
        $set = $this->objectManager->create(\Magento\Eav\Model\Entity\Attribute\Set::class);
        $set->load($attributeSetId);
        return (int) $set->getDefaultGroupId();
    }

    /**
     * Create the four simple products, three of which carry shirt_color values.
     *
     * @param int $blueOptionId
     * @param int $yellowOptionId
     * @return void
     */
    private function createProducts(int $blueOptionId, int $yellowOptionId): void
    {
        $productData = [
            [
                'sku' => self::SKU_BLUE_1,
                'name' => 'Shirt Color Product 1',
                'shirt_color' => $blueOptionId,
            ],
            [
                'sku' => self::SKU_BLUE_2,
                'name' => 'Shirt Color Product 2',
                'shirt_color' => $blueOptionId,
            ],
            [
                'sku' => self::SKU_YELLOW,
                'name' => 'Shirt Color Product 3',
                'shirt_color' => $yellowOptionId,
            ],
            [
                'sku' => self::SKU_NO_ATTR,
                'name' => 'Shirt Color Product 4 (no attr)',
                'shirt_color' => null,
            ],
        ];

        foreach ($productData as $data) {
            $product = $this->productFactory->create();
            $product->isObjectNew(true);
            $product->setTypeId(Type::TYPE_SIMPLE)
                ->setAttributeSetId(4)
                ->setName($data['name'])
                ->setSku($data['sku'])
                ->setTaxClassId(2)
                ->setDescription('description')
                ->setShortDescription('short description')
                ->setPrice(50)
                ->setWeight(1)
                ->setVisibility(Visibility::VISIBILITY_BOTH)
                ->setStatus(Status::STATUS_ENABLED)
                ->setWebsiteIds([1])
                ->setStockData([
                    'use_config_manage_stock' => 1,
                    'qty' => 100,
                    'is_qty_decimal' => 0,
                    'is_in_stock' => 1,
                ]);
            if ($data['shirt_color'] !== null) {
                $product->setCustomAttribute('shirt_color', $data['shirt_color']);
            }
            $product->save();
        }
    }
}
