<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Integration;

use Magento\CatalogDataExporter\Model\Provider\Product\AttributeMetadata;
use Magento\CatalogDataExporter\Test\Fixture\ShirtColorProducts as ShirtColorProductsFixture;
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
 * Verifies that updating attribute option labels via Magento\Eav\Model\ResourceModel\Entity\Attribute::save
 * triggers a partial reindex of the products feed and the new labels are reflected in feedData.
 *
 * Covers the plugin
 * Magento\CatalogDataExporter\Plugin\Eav\ResyncProductsOnAttributeOptionLabelChange
 * which schedules feed updates for products that reference the changed option(s).
 *
 * @magentoAppArea adminhtml
 */
class ProductAttributeOptionLabelChangeTest extends AbstractProductTestHelper
{
    private const ATTRIBUTE_CODE = 'shirt_color';

    private const SKU_BLUE_1 = 'shirt-color-product-1';
    private const SKU_BLUE_2 = 'shirt-color-product-2';
    private const SKU_YELLOW = 'shirt-color-product-3';
    private const SKU_NO_ATTR = 'shirt-color-product-4';

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
     * After admin updates option labels, the products feed must reflect the new labels
     * (per store) for products that use the updated options. Products without the
     * attribute must not contain a shirt_color entry.
     */
    #[DbIsolation(false)]
    #[AppIsolation(true)]
    #[DataFixture(ShirtColorProductsFixture::class)]
    public function testProductFeedReflectsAttributeOptionLabelChange(): void
    {
        // 1. Initial state: assert the original labels are present in both stores.
        $this->assertShirtColorLabel(self::SKU_BLUE_1, self::STORE_DEFAULT, 'blue');
        $this->assertShirtColorLabel(self::SKU_BLUE_2, self::STORE_DEFAULT, 'blue');
        $this->assertShirtColorLabel(self::SKU_YELLOW, self::STORE_DEFAULT, 'yellow');

        $this->assertShirtColorLabel(self::SKU_BLUE_1, self::STORE_SECOND, 'blue_2nd_store');
        $this->assertShirtColorLabel(self::SKU_BLUE_2, self::STORE_SECOND, 'blue_2nd_store');
        $this->assertShirtColorLabel(self::SKU_YELLOW, self::STORE_SECOND, 'yellow_2nd_store');

        // Product 4 has no shirt_color value: confirm absence in both stores.
        $this->assertShirtColorAbsent(self::SKU_NO_ATTR, self::STORE_DEFAULT);
        $this->assertShirtColorAbsent(self::SKU_NO_ATTR, self::STORE_SECOND);

        // 2. Change option labels:
        //    - blue -> blue_updated (admin store only; second-store label kept as blue_2nd_store)
        //    - yellow_2nd_store -> yellow_2nd_store_updated (admin label "yellow" left unchanged)
        [$blueOptionId, $yellowOptionId] = $this->getOptionIds();
        $secondStoreId = $this->getSecondStoreId();

        $objectManager = Bootstrap::getObjectManager();

        /** @var EavAttribute $attribute */
        $attribute = $objectManager->create(EavAttribute::class);
        $attribute->load(self::ATTRIBUTE_CODE, 'attribute_code');
        $this->assertNotEmpty($attribute->getId(), 'shirt_color attribute must exist.');

        // Submission shape consumed by Attribute::_processAttributeOptions /
        // _updateAttributeOptionValues. All stores referenced for an option must be
        // present because the resource model deletes & re-inserts every store row.
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

        // Drop EAV config caches so the attribute model & options reload with new labels.
        /** @var EavConfig $eavConfig */
        $eavConfig = $objectManager->get(EavConfig::class);
        $eavConfig->clear();

        // AttributeMetadata is a DI singleton that memoizes per-attribute option labels in
        // its private $attributeMetadata property on first lookup (during setUp's reindex).
        // Without this reset the partial reindex below would render stale labels.
        $this->resetAttributeMetadataCache();

        // 3. Trigger cron run

        $this->mViewCron->execute();

        // 4. Default store: blue products show "blue_updated", yellow product still shows "yellow".
        $this->assertShirtColorLabel(self::SKU_BLUE_1, self::STORE_DEFAULT, 'blue_updated');
        $this->assertShirtColorLabel(self::SKU_BLUE_2, self::STORE_DEFAULT, 'blue_updated');
        $this->assertShirtColorLabel(self::SKU_YELLOW, self::STORE_DEFAULT, 'yellow');

        // 5. Second store: blue products' second-store label unchanged ("blue_2nd_store"),
        //    yellow product now shows "yellow_2nd_store_updated".
        $this->assertShirtColorLabel(self::SKU_BLUE_1, self::STORE_SECOND, 'blue_2nd_store');
        $this->assertShirtColorLabel(self::SKU_BLUE_2, self::STORE_SECOND, 'blue_2nd_store');
        $this->assertShirtColorLabel(self::SKU_YELLOW, self::STORE_SECOND, 'yellow_2nd_store_updated');

        // 6. Product 4 (no shirt_color) - still absent in feedData.attributes.
        $this->assertShirtColorAbsent(self::SKU_NO_ATTR, self::STORE_DEFAULT);
        $this->assertShirtColorAbsent(self::SKU_NO_ATTR, self::STORE_SECOND);
    }

    /**
     * Resolve the option ids for "blue" and "yellow" (in that order) by querying the
     * admin-store option collection.
     *
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
     * Reset the in-memory option-label cache held on the AttributeMetadata singleton so the
     * next reindex re-reads labels from eav_attribute_option_value.
     *
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
     * Assert that feedData.attributes contains a shirt_color entry whose first value
     * matches the expected label.
     *
     * @param string $sku
     * @param string $storeViewCode
     * @param string $expectedLabel
     */
    private function assertShirtColorLabel(string $sku, string $storeViewCode, string $expectedLabel): void
    {
        $extracted = $this->getExtractedProduct($sku, $storeViewCode);
        $this->assertNotEmpty(
            $extracted,
            sprintf('No feed entry for sku "%s" in store "%s".', $sku, $storeViewCode)
        );
        $this->assertArrayHasKey('attributes', $extracted['feedData']);

        $attributeEntry = $this->findAttributeEntry($extracted['feedData']['attributes'], self::ATTRIBUTE_CODE);
        $this->assertNotNull(
            $attributeEntry,
            sprintf(
                'shirt_color entry missing in feedData.attributes for sku "%s" / store "%s".',
                $sku,
                $storeViewCode
            )
        );
        $this->assertNotEmpty($attributeEntry['value'] ?? null);
        $this->assertEquals(
            $expectedLabel,
            $attributeEntry['value'][0],
            sprintf(
                'Unexpected shirt_color label for sku "%s" in store "%s".',
                $sku,
                $storeViewCode
            )
        );
    }

    /**
     * Assert there is no shirt_color entry in feedData.attributes.
     *
     * @param string $sku
     * @param string $storeViewCode
     */
    private function assertShirtColorAbsent(string $sku, string $storeViewCode): void
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
                'shirt_color must be absent for sku "%s" in store "%s", but entry was found.',
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
