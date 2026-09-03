<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CategoryAttributeDataExporter\Test\Integration\Category;

use Magento\CategoryAttributeDataExporter\Test\Fixture\CategoryColorMultiselectAttribute as MultiselectFixture;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection as OptionCollection;
use Magento\Store\Model\Store;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Same scenario as CategoryAttributeOptionLabelChangeTest but for a `multiselect` attribute
 * (varchar backend with comma-separated option ids). Verifies label updates are reflected
 * including the multi-value case where a single category carries both options.
 *
 * @magentoAppArea adminhtml
 */
class CategoryAttributeMultiselectOptionLabelChangeTest extends AbstractCategoryAttributeValueTestCase
{
    private const ATTRIBUTE_CODE = MultiselectFixture::ATTRIBUTE_CODE;
    private const STORE_DEFAULT = 'default';
    private const STORE_SECOND = MultiselectFixture::STORE_CODE;

    /**
     * After admin updates option labels of a multiselect category attribute, categories
     * carrying any of the changed options must reflect the new labels in the feed.
     */
    #[DbIsolation(false)]
    #[AppIsolation(true)]
    #[DataFixture(MultiselectFixture::class)]
    public function testCategoryFeedReflectsMultiselectAttributeOptionLabelChange(): void
    {
        $blueCategoryId   = MultiselectFixture::BLUE_CATEGORY_ID;
        $yellowCategoryId = MultiselectFixture::YELLOW_CATEGORY_ID;
        $bothCategoryId   = MultiselectFixture::BOTH_CATEGORY_ID;
        $noAttrCategoryId = MultiselectFixture::NO_ATTR_CATEGORY_ID;

        // 1. Initial state.
        $this->assertMultiselectLabels($blueCategoryId, self::STORE_DEFAULT, ['blue']);
        $this->assertMultiselectLabels($yellowCategoryId, self::STORE_DEFAULT, ['yellow']);
        $this->assertMultiselectLabels($bothCategoryId, self::STORE_DEFAULT, ['blue', 'yellow']);

        $this->assertMultiselectLabels($blueCategoryId, self::STORE_SECOND, ['blue_2nd_store']);
        $this->assertMultiselectLabels($yellowCategoryId, self::STORE_SECOND, ['yellow_2nd_store']);
        $this->assertMultiselectLabels($bothCategoryId, self::STORE_SECOND, ['blue_2nd_store', 'yellow_2nd_store']);

        $this->assertAttributeAbsent($noAttrCategoryId, self::STORE_DEFAULT);
        $this->assertAttributeAbsent($noAttrCategoryId, self::STORE_SECOND);

        // 2. Change option labels.
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
                    0              => 'blue_updated',
                    $secondStoreId => 'blue_2nd_store',
                ],
                $yellowOptionId => [
                    0              => 'yellow',
                    $secondStoreId => 'yellow_2nd_store_updated',
                ],
            ],
            'order' => [
                $blueOptionId   => 1,
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

        // 3. Trigger cron.
        $this->runMviewCron();

        // 4. Default store: blue admin label changed; yellow admin unchanged.
        $this->assertMultiselectLabels($blueCategoryId, self::STORE_DEFAULT, ['blue_updated']);
        $this->assertMultiselectLabels($yellowCategoryId, self::STORE_DEFAULT, ['yellow']);
        $this->assertMultiselectLabels($bothCategoryId, self::STORE_DEFAULT, ['blue_updated', 'yellow']);

        // 5. Second store: blue 2nd-store label preserved; yellow 2nd-store label updated.
        $this->assertMultiselectLabels($blueCategoryId, self::STORE_SECOND, ['blue_2nd_store']);
        $this->assertMultiselectLabels($yellowCategoryId, self::STORE_SECOND, ['yellow_2nd_store_updated']);
        $this->assertMultiselectLabels(
            $bothCategoryId,
            self::STORE_SECOND,
            ['blue_2nd_store', 'yellow_2nd_store_updated']
        );

        // 6. Category without attribute — still absent.
        $this->assertAttributeAbsent($noAttrCategoryId, self::STORE_DEFAULT);
        $this->assertAttributeAbsent($noAttrCategoryId, self::STORE_SECOND);
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
        $optionCollection->setAttributeFilter((int)$attribute->getId())->setStoreFilter(0);

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

    private function getSecondStoreId(): int
    {
        /** @var Store $store */
        $store = Bootstrap::getObjectManager()->create(Store::class);
        $store->load(self::STORE_SECOND, 'code');
        $this->assertNotEmpty($store->getId(), self::STORE_SECOND . ' must exist.');

        return (int)$store->getId();
    }

    private function resetAttributeMetadataCache(): void
    {
        $provider = Bootstrap::getObjectManager()->get(
            'Magento\CategoryAttributeDataExporter\Model\Provider\EavAttributes\CategoryEavAttributeOptionValueResolver'
        );
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('attributeMetadata');
        $prop->setValue($provider, null);
    }

    private function assertMultiselectLabels(int $categoryId, string $storeViewCode, array $expectedLabels): void
    {
        $item = $this->findCategoryEntry($categoryId, $storeViewCode);
        $this->assertNotEmpty(
            $item,
            sprintf('No feed entry for category %d in store "%s".', $categoryId, $storeViewCode)
        );

        $actual = $this->getAttributeValues($item, self::ATTRIBUTE_CODE);
        $this->assertNotNull(
            $actual,
            sprintf('%s missing for category %d / store "%s".', self::ATTRIBUTE_CODE, $categoryId, $storeViewCode)
        );

        sort($actual);
        $expected = $expectedLabels;
        sort($expected);

        $this->assertEquals(
            $expected,
            $actual,
            sprintf(
                'Unexpected %s labels for category %d / store "%s".',
                self::ATTRIBUTE_CODE,
                $categoryId,
                $storeViewCode
            )
        );
    }

    private function assertAttributeAbsent(int $categoryId, string $storeViewCode): void
    {
        $item = $this->findCategoryEntry($categoryId, $storeViewCode);
        $this->assertNotEmpty(
            $item,
            sprintf('No feed entry for category %d in store "%s".', $categoryId, $storeViewCode)
        );

        $this->assertNull(
            $this->getAttributeValues($item, self::ATTRIBUTE_CODE),
            sprintf(
                '%s must be absent for category %d / store "%s", but was found.',
                self::ATTRIBUTE_CODE,
                $categoryId,
                $storeViewCode
            )
        );
    }
}
