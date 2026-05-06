<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Integration;

use Magento\CatalogDataExporter\Model\Provider\Product\AttributeMetadata;
use Magento\CatalogDataExporter\Test\Fixture\ShirtColorMultiselectProducts as ShirtColorMultiselectProductsFixture;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection as OptionCollection;
use Magento\Indexer\Cron\UpdateMview;
use Magento\Store\Model\Store;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Same scenario as ProductAttributeOptionLabelChangeTest but for a `multiselect` attribute
 * (varchar backend with comma-separated option ids). Verifies the plugin's varchar/FIND_IN_SET
 * path picks up products that carry the changed option ids - including the multi-value case
 * where a single product references both options at once.
 *
 * @magentoAppArea adminhtml
 */
class ProductAttributeMultiselectOptionLabelChangeTest extends AbstractProductTestHelper
{
    private const ATTRIBUTE_CODE = 'shirt_color_multi';

    private const SKU_BLUE = 'shirt-color-multi-product-1';
    private const SKU_YELLOW = 'shirt-color-multi-product-2';
    private const SKU_BOTH = 'shirt-color-multi-product-3';
    private const SKU_NO_ATTR = 'shirt-color-multi-product-4';

    private const STORE_DEFAULT = 'default';
    private const STORE_SECOND = 'fixture_second_store';

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
     * After admin updates option labels of a multiselect attribute, products carrying any of
     * the changed options (including products that carry both options as a CSV) must reflect
     * the new labels in the feed - per store.
     */
    #[DbIsolation(false)]
    #[AppIsolation(true)]
    #[DataFixture(ShirtColorMultiselectProductsFixture::class)]
    public function testProductFeedReflectsMultiselectAttributeOptionLabelChange(): void
    {
        // 1. Initial state per store.
        $this->assertMultiselectLabels(self::SKU_BLUE, self::STORE_DEFAULT, ['blue']);
        $this->assertMultiselectLabels(self::SKU_YELLOW, self::STORE_DEFAULT, ['yellow']);
        $this->assertMultiselectLabels(self::SKU_BOTH, self::STORE_DEFAULT, ['blue', 'yellow']);

        $this->assertMultiselectLabels(self::SKU_BLUE, self::STORE_SECOND, ['blue_2nd_store']);
        $this->assertMultiselectLabels(self::SKU_YELLOW, self::STORE_SECOND, ['yellow_2nd_store']);
        $this->assertMultiselectLabels(self::SKU_BOTH, self::STORE_SECOND, ['blue_2nd_store', 'yellow_2nd_store']);

        $this->assertAttributeAbsent(self::SKU_NO_ATTR, self::STORE_DEFAULT);
        $this->assertAttributeAbsent(self::SKU_NO_ATTR, self::STORE_SECOND);

        // 2. Update option labels:
        //    - blue admin label  -> blue_updated  (second-store label preserved)
        //    - yellow second-store label -> yellow_2nd_store_updated (admin label preserved)
        [$blueOptionId, $yellowOptionId] = $this->getOptionIds();
        $secondStoreId = $this->getSecondStoreId();

        $objectManager = Bootstrap::getObjectManager();

        /** @var EavAttribute $attribute */
        $attribute = $objectManager->create(EavAttribute::class);
        $attribute->load(self::ATTRIBUTE_CODE, 'attribute_code');
        $this->assertNotEmpty($attribute->getId(), self::ATTRIBUTE_CODE . ' attribute must exist.');

        $attribute->setOption([
            'value' => [
                $blueOptionId => [
                    0 => 'blue_updated',
                    $secondStoreId => 'blue_2nd_store',
                ],
                $yellowOptionId => [
                    0 => 'yellow',
                    $secondStoreId => 'yellow_2nd_store_updated',
                ],
            ],
            'order' => [
                $blueOptionId => 1,
                $yellowOptionId => 2,
            ],
        ]);

        /** @var AttributeResource $attributeResource */
        $attributeResource = $objectManager->create(AttributeResource::class);
        $attributeResource->save($attribute);

        /** @var EavConfig $eavConfig */
        $eavConfig = $objectManager->get(EavConfig::class);
        $eavConfig->clear();

        $this->resetAttributeMetadataCache();

        // 3. Trigger cron (drains the changelog populated by the plugin).
        $this->mViewCron->execute();

        // 4. Default store: blue admin label changed; yellow admin unchanged.
        $this->assertMultiselectLabels(self::SKU_BLUE, self::STORE_DEFAULT, ['blue_updated']);
        $this->assertMultiselectLabels(self::SKU_YELLOW, self::STORE_DEFAULT, ['yellow']);
        $this->assertMultiselectLabels(self::SKU_BOTH, self::STORE_DEFAULT, ['blue_updated', 'yellow']);

        // 5. Second store: blue per-store label preserved; yellow per-store label updated.
        $this->assertMultiselectLabels(self::SKU_BLUE, self::STORE_SECOND, ['blue_2nd_store']);
        $this->assertMultiselectLabels(self::SKU_YELLOW, self::STORE_SECOND, ['yellow_2nd_store_updated']);
        $this->assertMultiselectLabels(
            self::SKU_BOTH,
            self::STORE_SECOND,
            ['blue_2nd_store', 'yellow_2nd_store_updated']
        );

        // 6. Product without the attribute - still absent.
        $this->assertAttributeAbsent(self::SKU_NO_ATTR, self::STORE_DEFAULT);
        $this->assertAttributeAbsent(self::SKU_NO_ATTR, self::STORE_SECOND);
    }

    /**
     * @return int[] [$blueOptionId, $yellowOptionId]
     */
    private function getOptionIds(): array
    {
        $objectManager = Bootstrap::getObjectManager();

        /** @var EavAttribute $attribute */
        $attribute = $objectManager->create(EavAttribute::class);
        $attribute->load(self::ATTRIBUTE_CODE, 'attribute_code');

        /** @var OptionCollection $optionCollection */
        $optionCollection = $objectManager->create(OptionCollection::class);
        $optionCollection->setAttributeFilter((int)$attribute->getId())
            ->setStoreFilter(0);

        $blueId = null;
        $yellowId = null;
        foreach ($optionCollection as $option) {
            $value = (string)$option->getValue();
            if ($value === 'blue' || $value === 'blue_updated') {
                $blueId = (int)$option->getOptionId();
            } elseif ($value === 'yellow') {
                $yellowId = (int)$option->getOptionId();
            }
        }

        $this->assertNotNull($blueId, 'Could not resolve option id for "blue".');
        $this->assertNotNull($yellowId, 'Could not resolve option id for "yellow".');

        return [$blueId, $yellowId];
    }

    /**
     * @return void
     */
    private function resetAttributeMetadataCache(): void
    {
        $provider = Bootstrap::getObjectManager()->get(AttributeMetadata::class);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('attributeMetadata');
        $prop->setValue($provider, null);
    }

    /**
     * @return int
     */
    private function getSecondStoreId(): int
    {
        /** @var Store $store */
        $store = Bootstrap::getObjectManager()->create(Store::class);
        $store->load(self::STORE_SECOND, 'code');
        $this->assertNotEmpty($store->getId(), 'fixture_second_store must exist.');

        return (int)$store->getId();
    }

    /**
     * Assert that feedData.attributes contains an entry for the attribute whose `value`
     * array equals the expected labels (order-insensitive).
     *
     * @param string $sku
     * @param string $storeViewCode
     * @param string[] $expectedLabels
     */
    private function assertMultiselectLabels(string $sku, string $storeViewCode, array $expectedLabels): void
    {
        $extracted = $this->getExtractedProduct($sku, $storeViewCode);
        $this->assertNotEmpty(
            $extracted,
            sprintf('No feed entry for sku "%s" in store "%s".', $sku, $storeViewCode)
        );
        $this->assertArrayHasKey('attributes', $extracted['feedData']);

        $entry = $this->findAttributeEntry($extracted['feedData']['attributes'], self::ATTRIBUTE_CODE);
        $this->assertNotNull(
            $entry,
            sprintf(
                '%s entry missing in feedData.attributes for sku "%s" / store "%s".',
                self::ATTRIBUTE_CODE,
                $sku,
                $storeViewCode
            )
        );

        $actual = $entry['value'] ?? [];
        sort($actual);
        $expected = $expectedLabels;
        sort($expected);

        $this->assertEquals(
            $expected,
            $actual,
            sprintf('Unexpected %s labels for sku "%s" in store "%s".', self::ATTRIBUTE_CODE, $sku, $storeViewCode)
        );
    }

    /**
     * @param string $sku
     * @param string $storeViewCode
     */
    private function assertAttributeAbsent(string $sku, string $storeViewCode): void
    {
        $extracted = $this->getExtractedProduct($sku, $storeViewCode);
        $this->assertNotEmpty(
            $extracted,
            sprintf('No feed entry for sku "%s" in store "%s".', $sku, $storeViewCode)
        );

        $attributes = $extracted['feedData']['attributes'] ?? [];
        $entry = $this->findAttributeEntry($attributes, self::ATTRIBUTE_CODE);
        $this->assertNull(
            $entry,
            sprintf(
                '%s must be absent for sku "%s" in store "%s", but entry was found.',
                self::ATTRIBUTE_CODE,
                $sku,
                $storeViewCode
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
