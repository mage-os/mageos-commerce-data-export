<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Integration;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Indexer\Model\Processor;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Verifies that the product feed correctly reflects the updated categoryData.categoryPath
 * for products assigned to a category that has been moved to a new position in the hierarchy.
 *
 * Fixture structure (root category id = 2):
 *   cat1-move  (810)           url_path = "cat1-move"
 *     cat1-child-move  (811)   url_path = "cat1-move/cat1-child-move"
 *   cat2-move  (812)           url_path = "cat2-move"
 *     cat2-child-move  (813)   url_path = "cat2-move/cat2-child-move"
 *
 * Product 820 (sku='product_cat_move') is assigned to cats 810 and 811.
 *
 * After moving 810 under 813 and removing the admin-store url_path (as AC does):
 *   cat2-move  (812)
 *     cat2-child-move  (813)
 *       cat1-move  (810)  → expected categoryPath = "cat2-move/cat2-child-move/cat1-move"
 *         cat1-child-move  (811)  → expected categoryPath = "cat2-move/cat2-child-move/cat1-move/cat1-child-move"
 *
 * @magentoAppArea adminhtml
 */
class MovedCategoryProductFeedTest extends AbstractProductTestHelper
{
    private const PRODUCT_SKU = 'product_cat_move';
    private const STORE_VIEW_CODES = ['default', 'fixture_second_store'];
    private const CAT1_ID = 810;
    private const CAT1_CHILD_ID = 811;
    private const CAT2_CHILD_ID = 813;
    private const EXPECTED_CAT1_PATH_AFTER_MOVE = 'cat2-move/cat2-child-move/cat1-move';
    private const EXPECTED_CAT1_CHILD_PATH_AFTER_MOVE = 'cat2-move/cat2-child-move/cat1-move/cat1-child-move';

    /**
     * After moving a category (with admin-store url_path removed, as AC does) and running
     * incremental reindex, the product feed must contain the updated categoryPath in categoryData.
     *
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento_CatalogDataExporter::Test/_files/product_for_category_move.php
     *
     * @return void
     * @throws NoSuchEntityException
     */
    public function testMovedCategoryUrlPathUpdatedInProductFeed(): void
    {
        $productId = $this->getProductId(self::PRODUCT_SKU);
        $this->emulatePartialReindexBehavior([$productId]);

        foreach (self::STORE_VIEW_CODES as $storeViewCode) {
            $before = $this->getExtractedProduct(self::PRODUCT_SKU, $storeViewCode);
            $this->assertNotEmpty($before, "[$storeViewCode] Product must be in the feed before the move.");
            $beforeByCatId = $this->indexCategoryData($before['feedData']['categoryData'] ?? []);
            $this->assertEquals(
                'cat1-move',
                $beforeByCatId[(string)self::CAT1_ID]['categoryPath'],
                "[$storeViewCode] Initial categoryPath for cat1-move must equal its url_key."
            );
            $this->assertEquals(
                'cat1-move/cat1-child-move',
                $beforeByCatId[(string)self::CAT1_CHILD_ID]['categoryPath'],
                "[$storeViewCode] Initial categoryPath for cat1-child-move must reflect initial hierarchy."
            );
        }

        $this->moveCategory(self::CAT1_ID, self::CAT2_CHILD_ID);

        $this->emulatePartialReindexBehavior([$productId]);

        foreach (self::STORE_VIEW_CODES as $storeViewCode) {
            $after = $this->getExtractedProduct(self::PRODUCT_SKU, $storeViewCode);
            $this->assertNotEmpty(
                $after,
                "[$storeViewCode] Product must still be in the feed after the category move."
            );

            $afterByCatId = $this->indexCategoryData($after['feedData']['categoryData'] ?? []);

            $this->assertArrayHasKey(
                (string)self::CAT1_ID,
                $afterByCatId,
                "[$storeViewCode] Category 810 must still appear in product categoryData after being moved."
            );
            $this->assertEquals(
                self::EXPECTED_CAT1_PATH_AFTER_MOVE,
                $afterByCatId[(string)self::CAT1_ID]['categoryPath'],
                "[$storeViewCode] categoryPath for cat1-move must reflect the new hierarchy position."
            );

            $this->assertArrayHasKey(
                (string)self::CAT1_CHILD_ID,
                $afterByCatId,
                "[$storeViewCode] Category 811 must still appear in product categoryData after its parent was moved."
            );
            $this->assertEquals(
                self::EXPECTED_CAT1_CHILD_PATH_AFTER_MOVE,
                $afterByCatId[(string)self::CAT1_CHILD_ID]['categoryPath'],
                "[$storeViewCode] categoryPath for cat1-child-move must reflect the new parent hierarchy position."
            );
        }
    }

    /**
     * Emulate the admin-panel category move
     *
     * @param int $categoryId Category to move
     * @param int $newParentId New parent category ID
     */
    private function moveCategory(int $categoryId, int $newParentId): void
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance()->create(Category::class);
        $category->setStoreId(0);
        $category->load($categoryId);
        $category->move($newParentId, null);
    }

    /**
     * Index categoryData array by categoryId for easy lookup.
     *
     * @param array $categoryData
     * @return array<string, array>
     */
    private function indexCategoryData(array $categoryData): array
    {
        $result = [];
        foreach ($categoryData as $item) {
            $result[(string)$item['categoryId']] = $item;
        }
        return $result;
    }
}
