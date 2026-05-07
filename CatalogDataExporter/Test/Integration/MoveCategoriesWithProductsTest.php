<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains
 * the property of Adobe and its suppliers, if any. The intellectual
 * and technical concepts contained herein are proprietary to Adobe
 * and its suppliers and are protected by all applicable intellectual
 * property laws, including trade secret and copyright laws.
 * Dissemination of this information or reproduction of this material
 * is strictly forbidden unless prior written permission is obtained
 * from Adobe.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Integration;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Indexer\Cron\UpdateMview;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Test class for simple product export
 * @magentoAppArea adminhtml
 */
class MoveCategoriesWithProductsTest extends AbstractProductTestHelper
{
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
     * Validate simple product data
     *
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/categories.php
     *
     * @param string $sku
     * @param array $categoryData
     * @return void
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    #[DataProvider('getProductCategoriesDataProvider')]
    public function testMoveCategoryToTopLevel(string $productSku, array $categoryData) : void
    {
        $this->mViewCron->execute();

        $childCategoryId = "4";
        $childCategory = $this->categoryRepository->get($childCategoryId);
        $childCategory->move(9, null);
        $this->mViewCron->execute();

        $extractedProductData = $this->getExtractedProduct($productSku, 'default');
        $this->assertNotEmpty($extractedProductData, "Product with SKU $productSku not found in the feed.");
        $this->assertNotEmpty($extractedProductData['feedData']);
        $feedData = $extractedProductData['feedData'];
        $this->assertNotEmpty($feedData['categoryData']);
        $categoryToVerify = [];
        foreach ($categoryData as $expectedCategoryId => $expectedCategoryData) {
            foreach ($feedData['categoryData'] as $productCategoryData) {
                if ((string)$expectedCategoryId === $productCategoryData['categoryId']) {
                    $categoryToVerify = $productCategoryData;
                    break;
                }
            }
            $this->assertNotEmpty(
                $expectedCategoryData,
                "Category with ID {$expectedCategoryData['categoryId']} "
                . " not found in the feed for product with SKU $productSku."
            );
            $this->assertEquals(
                $expectedCategoryData,
                $categoryToVerify,
                "Category data for product with SKU $productSku does not match expected data."
            );
        }
    }

    /**
     * Data provider for product categories
     *
     * @return array[]
     */
    public static function getProductCategoriesDataProvider(): array
    {
        return [
            [
                'productSku' => 'simple',
                'categoryData' => [
                    '3' => [
                        'categoryId' => '3',
                        'categoryPath' => 'category-1',
                        'productPosition' => 0
                    ],
                    '4' => [
                        'categoryId' => '4',
                        'categoryPath' => 'movable-position-1/category-1-1',
                        'productPosition' => 0
                    ],
                    '9' => [
                        'categoryId' => '9',
                        'categoryPath' => 'movable-position-1',
                        'productPosition' => 10000
                    ],
                    '13' => [
                        'categoryId' => '13',
                        'categoryPath' => 'category-1/category-1-2',
                        'productPosition' => 0
                    ]
                ]
            ]
        ];
    }
}
