<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Integration;

use Magento\CatalogDataExporter\Test\Fixture\ProductMultiStoreStatusVisibility;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;

/**
 * Verifies product export behavior in a multi-store setup when only one store view has explicit
 * EAV values for status and visibility, and the admin store (store_id=0) has no value at all.
 *
 * EAV state under test (catalog_product_entity_int):
 * - store_id = 0 (admin)       : no row for status or visibility
 * - fixture_second_store       : status=1 (Enabled), visibility=2 (Catalog)
 * - default store              : no row for status or visibility
 *
 * Expected feed output:
 * - fixture_second_store : status="Enabled",  visibility="Catalog"
 * - default store        : status="Disabled", visibility="Not Visible Individually"
 */
class MultiStoreStatusVisibilityFallbackTest extends AbstractProductTestHelper
{
    private const SKU = 'product_multistore_status_visibility';

    /**
     * Store view with an explicit EAV value must export the store-specific status and visibility.
     *
     * @throws NoSuchEntityException
     */
    #[DbIsolation(false)]
    #[AppIsolation(true)]
    #[DataFixture(ProductMultiStoreStatusVisibility::class)]
    public function testStoreWithExplicitValueExportsCorrectStatusAndVisibility(): void
    {
        $productId = $this->getProductId(self::SKU);
        $this->emulatePartialReindexBehavior([$productId]);

        $extracted = $this->getExtractedProduct(self::SKU, 'fixture_second_store');

        $this->assertNotEmpty(
            $extracted,
            "Product '" . self::SKU . "' must appear in the feed for 'fixture_second_store'."
        );
        $this->assertEquals(
            'Enabled',
            $extracted['feedData']['status'],
            'fixture_second_store: status must be "Enabled" - store-specific EAV value 1 is set.'
        );
        $this->assertEquals(
            'Catalog',
            $extracted['feedData']['visibility'],
            'fixture_second_store: visibility must be "Catalog" - store-specific EAV value 2 is set.'
        );

        // Verify default values for status and visibility fields

        $extracted = $this->getExtractedProduct(self::SKU, 'default');

        $this->assertNotEmpty(
            $extracted,
            "Product '" . self::SKU . "' must still appear in the feed for 'default' store "
            . "even though no EAV value exists for status or visibility in that store or the admin store."
        );
        $this->assertEquals(
            'Disabled',
            $extracted['feedData']['status'],
            'default store: status must fall back to "Disabled" when no EAV row exists for '
            . 'either the admin store (store_id=0) or the default store view.'
        );
        $this->assertEquals(
            'Not Visible Individually',
            $extracted['feedData']['visibility'],
            'default store: visibility must fall back to "Not Visible Individually" when no EAV '
            . 'row exists for either the admin store (store_id=0) or the default store view.'
        );
    }
}
