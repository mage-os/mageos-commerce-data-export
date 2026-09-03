<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CategoryAttributeDataExporter\Test\Integration\Category;

use Magento\CategoryAttributeDataExporter\Test\Fixture\CategoryColorAttribute as CategoryColorAttributeFixture;
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
 * Verifies that updating a category attribute's option labels triggers a partial
 * reindex of the categories feed and the new labels appear in feedData.attributes.
 *
 * @magentoAppArea adminhtml
 */
class CategoryAttributeOptionLabelChangeTest extends AbstractCategoryAttributeValueTestCase
{
    private const ATTRIBUTE_CODE = CategoryColorAttributeFixture::ATTRIBUTE_CODE;
    private const STORE_DEFAULT = 'default';
    private const STORE_SECOND = CategoryColorAttributeFixture::STORE_CODE;

    /**
     * After admin updates option labels the categories feed must reflect the new labels
     * per store. Categories without the attribute must not contain a category_color entry.
     */
    #[DbIsolation(false)]
    #[AppIsolation(true)]
    #[DataFixture(CategoryColorAttributeFixture::class)]
    public function testCategoryFeedReflectsAttributeOptionLabelChange(): void
    {
        $blueCategoryId   = CategoryColorAttributeFixture::BLUE_CATEGORY_ID;
        $yellowCategoryId = CategoryColorAttributeFixture::YELLOW_CATEGORY_ID;
        $noAttrCategoryId = CategoryColorAttributeFixture::NO_ATTR_CATEGORY_ID;

        // 1. Initial state: verify original labels in both stores.
        $this->assertCategoryColorLabel($blueCategoryId, self::STORE_DEFAULT, 'blue');
        $this->assertCategoryColorLabel($yellowCategoryId, self::STORE_DEFAULT, 'yellow');

        $this->assertCategoryColorLabel($blueCategoryId, self::STORE_SECOND, 'blue_2nd_store');
        $this->assertCategoryColorLabel($yellowCategoryId, self::STORE_SECOND, 'yellow_2nd_store');

        $this->assertCategoryColorAbsent($noAttrCategoryId, self::STORE_DEFAULT);
        $this->assertCategoryColorAbsent($noAttrCategoryId, self::STORE_SECOND);

        // 2. Change option labels:
        //    - blue admin label  -> blue_updated  (second-store label left as blue_2nd_store)
        //    - yellow 2nd-store label -> yellow_2nd_store_updated (admin label "yellow" unchanged)
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

        // 3. Trigger cron to drain the mview changelog.
        $this->runMviewCron();

        // 4. Default store: blue shows "blue_updated", yellow unchanged.
        $this->assertCategoryColorLabel($blueCategoryId, self::STORE_DEFAULT, 'blue_updated');
        $this->assertCategoryColorLabel($yellowCategoryId, self::STORE_DEFAULT, 'yellow');

        // 5. Second store: blue 2nd-store label unchanged; yellow 2nd-store label updated.
        $this->assertCategoryColorLabel($blueCategoryId, self::STORE_SECOND, 'blue_2nd_store');
        $this->assertCategoryColorLabel($yellowCategoryId, self::STORE_SECOND, 'yellow_2nd_store_updated');

        // 6. Category without attribute — still absent.
        $this->assertCategoryColorAbsent($noAttrCategoryId, self::STORE_DEFAULT);
        $this->assertCategoryColorAbsent($noAttrCategoryId, self::STORE_SECOND);
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

    /**
     * Reset the in-memory option-label cache held on the category option-value resolver singleton.
     */
    private function resetAttributeMetadataCache(): void
    {
        $provider = Bootstrap::getObjectManager()->get(
            'Magento\CategoryAttributeDataExporter\Model\Provider\EavAttributes\CategoryEavAttributeOptionValueResolver'
        );
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('attributeMetadata');
        $prop->setValue($provider, null);
    }

    /**
     * Assert feedData.attributes contains a category_color entry with the expected label.
     */
    private function assertCategoryColorLabel(int $categoryId, string $storeViewCode, string $expectedLabel): void
    {
        $item = $this->findCategoryEntry($categoryId, $storeViewCode);
        $this->assertNotEmpty(
            $item,
            sprintf('No feed entry for category %d in store "%s".', $categoryId, $storeViewCode)
        );

        $values = $this->getAttributeValues($item, self::ATTRIBUTE_CODE);
        $this->assertNotNull(
            $values,
            sprintf(
                '%s missing in attributes for category %d / store "%s".',
                self::ATTRIBUTE_CODE,
                $categoryId,
                $storeViewCode
            )
        );
        $this->assertNotEmpty($values);
        $this->assertEquals(
            $expectedLabel,
            $values[0],
            sprintf(
                'Unexpected %s label for category %d in store "%s".',
                self::ATTRIBUTE_CODE,
                $categoryId,
                $storeViewCode
            )
        );
    }

    /**
     * Assert no category_color entry exists in feedData.attributes.
     */
    private function assertCategoryColorAbsent(int $categoryId, string $storeViewCode): void
    {
        $item = $this->findCategoryEntry($categoryId, $storeViewCode);
        $this->assertNotEmpty(
            $item,
            sprintf('No feed entry for category %d in store "%s".', $categoryId, $storeViewCode)
        );

        $this->assertNull(
            $this->getAttributeValues($item, self::ATTRIBUTE_CODE),
            sprintf(
                '%s must be absent for category %d / store "%s", but entry was found.',
                self::ATTRIBUTE_CODE,
                $categoryId,
                $storeViewCode
            )
        );
    }
}
