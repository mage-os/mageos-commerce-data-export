<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Integration;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute as CatalogEavAttribute;
use Magento\CatalogDataExporter\Test\Fixture\ShirtColorProducts as ShirtColorProductsFixture;
use Magento\Indexer\Cron\UpdateMview;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Verifies that deleting a product attribute schedules a partial reindex for products that
 * were using it, via Magento\CatalogDataExporter\Plugin\Eav\Attribute\ProductAttributeDelete.
 *
 * @magentoAppArea adminhtml
 */
class ProductAttributeDeleteTest extends AbstractProductTestHelper
{
    private const ATTRIBUTE_CODE = ShirtColorProductsFixture::ATTRIBUTE_CODE;

    /**
     * @var UpdateMview
     */
    private $mViewCron;

    protected function setUp(): void
    {
        $this->mViewCron = Bootstrap::getObjectManager()->create(UpdateMview::class);

        parent::setUp();
    }

    /**
     * After the attribute is deleted, products that carried it must be scheduled for partial
     * reindex, so the deleted attribute no longer appears in their feed row.
     */
    #[DbIsolation(false)]
    #[AppIsolation(true)]
    #[DataFixture(ShirtColorProductsFixture::class)]
    public function testProductFeedReflectsAttributeDeletion(): void
    {
        $sku = ShirtColorProductsFixture::SKU_BLUE_1;

        // Sanity check: the attribute value is present before deletion.
        $extracted = $this->getExtractedProduct($sku, 'default');
        $this->assertNotEmpty($extracted, 'No feed entry for the fixture product before attribute deletion.');
        $this->assertNotNull(
            $this->findAttributeEntry($extracted['feedData']['attributes'] ?? [], self::ATTRIBUTE_CODE),
            sprintf('%s entry missing in feedData.attributes before deletion.', self::ATTRIBUTE_CODE)
        );

        $objectManager = Bootstrap::getObjectManager();

        /** @var CatalogEavAttribute $attribute */
        $attribute = $objectManager->create(CatalogEavAttribute::class);
        $attribute->loadByCode('catalog_product', self::ATTRIBUTE_CODE);
        $this->assertNotEmpty($attribute->getId(), self::ATTRIBUTE_CODE . ' attribute must exist.');

        $attribute->delete();

        $this->mViewCron->execute();

        $extracted = $this->getExtractedProduct($sku, 'default');
        $this->assertNotEmpty($extracted, 'Product feed row must still exist after attribute deletion.');
        $this->assertNull(
            $this->findAttributeEntry($extracted['feedData']['attributes'] ?? [], self::ATTRIBUTE_CODE),
            sprintf(
                '%s must no longer appear in feedData.attributes after the attribute is deleted.',
                self::ATTRIBUTE_CODE
            )
        );
    }

    /**
     * @param array $attributes
     * @param string $attributeCode
     * @return array|null
     */
    private function findAttributeEntry(array $attributes, string $attributeCode): ?array
    {
        foreach ($attributes as $entry) {
            if (($entry['attributeCode'] ?? null) === $attributeCode) {
                return $entry;
            }
        }
        return null;
    }
}
