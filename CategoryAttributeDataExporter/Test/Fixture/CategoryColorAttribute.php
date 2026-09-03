<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CategoryAttributeDataExporter\Test\Fixture;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Catalog\Model\Entity\Attribute as CatalogEntityAttribute;
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
 * Creates a second store, a `category_color` select attribute for catalog_category (int backend)
 * with two options whose labels differ between admin and the second store, and three categories:
 * two with the attribute (blue, yellow) and one without.
 *
 * Used by CategoryAttributeOptionLabelChangeTest.
 */
class CategoryColorAttribute implements RevertibleDataFixtureInterface
{
    public const STORE_CODE = 'fixture_second_store';
    public const ATTRIBUTE_CODE = 'category_color';

    public const BLUE_CATEGORY_ID = 9001;
    public const YELLOW_CATEGORY_ID = 9002;
    public const NO_ATTR_CATEGORY_ID = 9003;

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
        private readonly CategoryRepository $categoryRepository,
        private readonly Registry $registry
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(array $data = []): ?DataObject
    {
        $secondStoreId = $this->ensureStoreExists();
        [$blueOptionId, $yellowOptionId] = $this->ensureCategoryColorAttribute($secondStoreId);
        $this->createCategories($blueOptionId, $yellowOptionId);

        return new DataObject([
            'second_store_id' => $secondStoreId,
            'blue_option_id'  => $blueOptionId,
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

        foreach ([self::BLUE_CATEGORY_ID, self::YELLOW_CATEGORY_ID, self::NO_ATTR_CATEGORY_ID] as $id) {
            try {
                $this->categoryRepository->deleteByIdentifier($id);
            } catch (\Throwable) {
            }
        }

        try {
            $attribute = $this->objectManager->create(EavAttribute::class);
            $attribute->load(self::ATTRIBUTE_CODE, 'attribute_code');
            if ($attribute->getId()) {
                $attribute->delete();
            }
        } catch (\Throwable) {
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
     * @return int store_id
     */
    private function ensureStoreExists(): int
    {
        $store = $this->storeFactory->create();
        $this->storeResource->load($store, self::STORE_CODE, 'code');
        if (!$store->getId()) {
            $store->setCode(self::STORE_CODE)
                ->setWebsiteId((int)$this->storeManager->getWebsite()->getId())
                ->setGroupId((int)$this->storeManager->getWebsite()->getDefaultGroupId())
                ->setName('Fixture Store')
                ->setSortOrder(10)
                ->setIsActive(1);
            $this->storeResource->save($store);
            $this->storeManager->reinitStores();
        }
        return (int)$store->getId();
    }

    /**
     * @param int $secondStoreId
     * @return int[] [$blueOptionId, $yellowOptionId]
     */
    private function ensureCategoryColorAttribute(int $secondStoreId): array
    {
        /** @var EntityType $entityType */
        $entityType = $this->entityTypeFactory->create()->loadByCode('catalog_category');
        $entityTypeId = (int)$entityType->getId();
        $defaultAttributeSetId = (int)$entityType->getDefaultAttributeSetId();
        $attributeGroupId = $this->getDefaultGroupId($defaultAttributeSetId);

        $existing = $this->objectManager->create(CatalogEntityAttribute::class);
        $existing->load(self::ATTRIBUTE_CODE, 'attribute_code');
        if (!$existing->getId()) {
            $attribute = $this->objectManager->create(CatalogEntityAttribute::class);
            $attribute->setData([
                'attribute_code'   => self::ATTRIBUTE_CODE,
                'entity_type_id'   => $entityTypeId,
                'attribute_set_id' => $defaultAttributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'is_global'        => 0,
                'is_user_defined'  => 1,
                'frontend_input'   => 'select',
                'is_unique'        => 0,
                'is_required'      => 0,
                'frontend_label'   => ['Category Color'],
                'backend_type'     => 'int',
                'source_model'     => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option'           => [
                    'value' => [
                        'category_color_blue'   => [self::ADMIN_LABEL_BLUE],
                        'category_color_yellow' => [self::ADMIN_LABEL_YELLOW],
                    ],
                    'order' => [
                        'category_color_blue'   => 1,
                        'category_color_yellow' => 2,
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
                    0              => self::ADMIN_LABEL_BLUE,
                    $secondStoreId => self::STORE_LABEL_BLUE,
                ],
                $yellowOptionId => [
                    0              => self::ADMIN_LABEL_YELLOW,
                    $secondStoreId => self::STORE_LABEL_YELLOW,
                ],
            ],
            'order' => [
                $blueOptionId   => 1,
                $yellowOptionId => 2,
            ],
        ]);
        $this->objectManager->create(AttributeResource::class)->save($attribute);

        return [$blueOptionId, $yellowOptionId];
    }

    /**
     * @return int[] [$blueOptionId, $yellowOptionId]
     */
    private function resolveOptionIds(): array
    {
        $attribute = $this->objectManager->create(EavAttribute::class);
        $attribute->load(self::ATTRIBUTE_CODE, 'attribute_code');

        $collection = $this->optionCollectionFactory->create();
        $collection->setAttributeFilter((int)$attribute->getId())->setStoreFilter(0);

        $blueId = null;
        $yellowId = null;
        foreach ($collection as $option) {
            $value = (string)$option->getValue();
            if ($value === self::ADMIN_LABEL_BLUE) {
                $blueId = (int)$option->getOptionId();
            } elseif ($value === self::ADMIN_LABEL_YELLOW) {
                $yellowId = (int)$option->getOptionId();
            }
        }
        if ($blueId === null || $yellowId === null) {
            throw new \RuntimeException('Failed to resolve category_color option ids during fixture setup.');
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
        return (int)$set->getDefaultGroupId();
    }

    /**
     * @param int $blueOptionId
     * @param int $yellowOptionId
     */
    private function createCategories(int $blueOptionId, int $yellowOptionId): void
    {
        $categories = [
            [
                'id'             => self::BLUE_CATEGORY_ID,
                'name'           => 'Category Color Blue',
                'category_color' => $blueOptionId,
            ],
            [
                'id'             => self::YELLOW_CATEGORY_ID,
                'name'           => 'Category Color Yellow',
                'category_color' => $yellowOptionId,
            ],
            [
                'id'             => self::NO_ATTR_CATEGORY_ID,
                'name'           => 'Category Color None',
                'category_color' => null,
            ],
        ];

        foreach ($categories as $data) {
            /** @var Category $category */
            $category = $this->objectManager->create(Category::class);
            $category->isObjectNew(true);
            $category->setId($data['id'])
                ->setName($data['name'])
                ->setParentId(2)
                ->setPath('1/2/' . $data['id'])
                ->setLevel(2)
                ->setIsActive(true)
                ->setPosition(1)
                ->setStoreId(0);
            if ($data['category_color'] !== null) {
                $category->setData(self::ATTRIBUTE_CODE, $data['category_color']);
            }
            $category->save();
        }
    }
}
