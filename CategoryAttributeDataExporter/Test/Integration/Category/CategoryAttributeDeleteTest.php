<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CategoryAttributeDataExporter\Test\Integration\Category;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute as CatalogEavAttribute;
use Magento\CategoryAttributeDataExporter\Test\Fixture\CategoryColorAttribute as CategoryColorAttributeFixture;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Verifies that deleting a category attribute schedules a partial reindex for categories that
 * were using it, via Magento\CategoryAttributeDataExporter\Plugin\Eav\Attribute\CategoryAttributeDelete.
 *
 * @magentoAppArea adminhtml
 */
class CategoryAttributeDeleteTest extends AbstractCategoryAttributeValueTestCase
{
    private const ATTRIBUTE_CODE = CategoryColorAttributeFixture::ATTRIBUTE_CODE;

    /**
     * After the attribute is deleted, categories that carried it must be scheduled for partial
     * reindex, so the deleted attribute no longer appears in their feed row.
     */
    #[DbIsolation(false)]
    #[AppIsolation(true)]
    #[DataFixture(CategoryColorAttributeFixture::class)]
    public function testCategoryFeedReflectsAttributeDeletion(): void
    {
        $blueCategoryId = CategoryColorAttributeFixture::BLUE_CATEGORY_ID;

        // Sanity check: the attribute value is present before deletion.
        $item = $this->findCategoryEntry($blueCategoryId, 'default');
        $this->assertNotEmpty($item, 'No feed entry for the fixture category before attribute deletion.');
        $this->assertNotNull(
            $this->getAttributeValues($item, self::ATTRIBUTE_CODE),
            sprintf('%s entry missing in feedData.attributes before deletion.', self::ATTRIBUTE_CODE)
        );

        $objectManager = Bootstrap::getObjectManager();

        /** @var CatalogEavAttribute $attribute */
        $attribute = $objectManager->create(CatalogEavAttribute::class);
        $attribute->loadByCode('catalog_category', self::ATTRIBUTE_CODE);
        $this->assertNotEmpty($attribute->getId(), self::ATTRIBUTE_CODE . ' attribute must exist.');

        $attribute->delete();

        $this->runMviewCron();

        $item = $this->findCategoryEntry($blueCategoryId, 'default');
        $this->assertNotEmpty($item, 'Category feed row must still exist after attribute deletion.');
        $this->assertNull(
            $this->getAttributeValues($item, self::ATTRIBUTE_CODE),
            sprintf(
                '%s must no longer appear in feedData.attributes after the attribute is deleted.',
                self::ATTRIBUTE_CODE
            )
        );
    }
}
