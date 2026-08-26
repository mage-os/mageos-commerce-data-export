<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CustomizableOptionsDataExporter\Test\Integration;

use Magento\CatalogDataExporter\Test\Integration\AbstractProductTestHelper;

/**
 * Verifies that customizable options are exported "as-is" into the ac_customizable_options product attribute.
 *
 * The attribute is a faithful projection of the CatalogDataExporter option feed fields: its "selectable"
 * bucket mirrors optionsV2 and its "shopperInput" bucket mirrors shopperInputOptions. Value UIDs must use
 * the canonical "custom-option/..." scheme so they round-trip through addProductsToCart (CCSAAS-4796) - not
 * the "customizable/..." prefix shown illustratively in MDEE-1029's description.
 *
 * Extends the CatalogDataExporter integration helper to reuse its feed extraction and reindex plumbing;
 * this is a test-time coupling only.
 *
 * @magentoDbIsolation disabled
 * @magentoAppIsolation enabled
 */
class CustomizableOptionsAttributeTest extends AbstractProductTestHelper
{
    private const ATTRIBUTE_CODE = 'ac_customizable_options';

    /**
     * The core fixture creates SKU "simple" carrying both selectable (drop_down/radio/checkbox/multiple)
     * and shopper-input (field/area/file/date) custom options, so both buckets are exercised.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_with_options.php
     */
    public function testCustomizableOptionsExportedAsAttribute(): void
    {
        $sku = 'simple';
        $productId = (int)$this->productRepository->get($sku)->getId();
        $this->emulatePartialReindexBehavior([$productId]);

        $extracted = $this->getExtractedProduct($sku, 'default');
        $feedData = $extracted['feedData'] ?? [];
        self::assertNotEmpty($feedData, 'Product feed data must not be empty');

        $attribute = null;
        foreach ($feedData['attributes'] ?? [] as $candidate) {
            if (($candidate['attributeCode'] ?? null) === self::ATTRIBUTE_CODE) {
                $attribute = $candidate;
                break;
            }
        }
        self::assertNotNull(
            $attribute,
            'ac_customizable_options attribute must be present for a product with customizable options'
        );

        $payload = $this->jsonSerializer->unserialize($attribute['value'][0]);

        self::assertSame(1, $payload['schemaVersion'] ?? null, 'schemaVersion must be present');

        // The attribute is the pre-schema projection of the option providers: it carries the same options
        // (and the same canonical UIDs) as the native optionsV2 / shopperInputOptions feed fields. It is not
        // byte-equal to them - the feed fields pass through et_schema serialization, which adds schema-only
        // value keys (e.g. swatch data) and coerces price to float - so the stable invariant is UID parity.
        self::assertNotEmpty($payload['selectable'], 'Fixture is expected to define selectable options');
        self::assertSame(
            $this->collectValueUids($feedData['optionsV2'] ?? []),
            $this->collectValueUids($payload['selectable']),
            'selectable bucket must carry the same option value UIDs as optionsV2'
        );
        self::assertSame(
            $this->collectOptionUids($feedData['shopperInputOptions'] ?? []),
            $this->collectOptionUids($payload['shopperInput'] ?? []),
            'shopperInput bucket must carry the same option UIDs as shopperInputOptions'
        );

        // Guard the UID scheme: a selectable value UID must decode to the canonical "custom-option/..." prefix.
        $firstValueUid = $payload['selectable'][0]['values'][0]['id'] ?? '';
        self::assertNotEmpty($firstValueUid, 'Selectable option value must carry a UID');
        self::assertStringStartsWith(
            'custom-option/',
            (string)base64_decode($firstValueUid),
            'Value UID must use the canonical custom-option/ scheme, not the illustrative customizable/ prefix'
        );
    }

    /**
     * Collect and sort value-level UIDs from a selectable-options list.
     *
     * @param array $options
     * @return string[]
     */
    private function collectValueUids(array $options): array
    {
        $uids = [];
        foreach ($options as $option) {
            foreach ($option['values'] ?? [] as $value) {
                if (isset($value['id'])) {
                    $uids[] = (string)$value['id'];
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /**
     * Collect and sort option-level UIDs from a shopper-input-options list.
     *
     * @param array $options
     * @return string[]
     */
    private function collectOptionUids(array $options): array
    {
        $uids = [];
        foreach ($options as $option) {
            if (isset($option['id'])) {
                $uids[] = (string)$option['id'];
            }
        }
        sort($uids);
        return $uids;
    }
}
