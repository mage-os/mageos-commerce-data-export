<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Test\Integration\Category;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Verifies that the category feed computes urlPath from the store-specific url_key chain
 * rather than reading the stored url_path EAV value.
 *
 * The fixture creates:
 *   parent-cat (id=830)              admin url_key='parent-cat'
 *     child-cat (id=831)             admin url_key='child-cat'
 *
 * For fixture_second_store only url_key is overridden (url_path EAV is left unchanged):
 *   parent-cat (id=830)              store url_key='parent-cat-store2'
 *   child-cat  (id=831)              store url_key='child-cat-store2'
 *
 * @magentoAppArea adminhtml
 */
class CategoryUrlPathPerStoreTest extends AbstractCategoryTest
{
    private const PARENT_CAT_ID = 830;
    private const CHILD_CAT_ID  = 831;
    private const STORE_DEFAULT = 'default';
    private const STORE_SECOND  = 'fixture_second_store';

    // Admin-store url_key values - used by the default store view
    private const PARENT_URL_KEY_DEFAULT = 'parent-cat';
    private const CHILD_URL_KEY_DEFAULT  = 'child-cat';

    // Store-specific url_key values - used only by fixture_second_store
    private const PARENT_URL_KEY_STORE2 = 'parent-cat-store2';
    private const CHILD_URL_KEY_STORE2  = 'child-cat-store2';

    /**
     * Verifies that urlPath is computed from the url_key chain for each store view
     * and that different store views with different url_keys produce different urlPaths.
     *
     * @magentoDbIsolation disabled
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento_CatalogDataExporter::Test/_files/setup_category_url_key_per_store.php
     *
     * @return void
     * @throws NoSuchEntityException
     */
    public function testUrlPathIsComputedFromUrlKeyPerStoreView(): void
    {
        $this->emulatePartialReindexBehavior([self::PARENT_CAT_ID, self::CHILD_CAT_ID]);

        // --- default store: urlPath must follow the admin url_key chain ---

        $parentDefault = $this->getCategoryById(self::PARENT_CAT_ID, self::STORE_DEFAULT);
        $this->assertNotEmpty($parentDefault, 'Parent category must be in the feed for the default store.');
        $this->assertEquals(
            self::PARENT_URL_KEY_DEFAULT,
            $parentDefault['urlKey'],
            'urlKey for default store must equal the admin url_key.'
        );
        $this->assertEquals(
            self::PARENT_URL_KEY_DEFAULT,
            $parentDefault['urlPath'],
            'urlPath for a top-level category must equal its own url_key.'
        );

        $childDefault = $this->getCategoryById(self::CHILD_CAT_ID, self::STORE_DEFAULT);
        $this->assertNotEmpty($childDefault, 'Child category must be in the feed for the default store.');
        $this->assertEquals(
            self::CHILD_URL_KEY_DEFAULT,
            $childDefault['urlKey'],
            'urlKey for default store child must equal the admin url_key.'
        );
        $this->assertEquals(
            self::PARENT_URL_KEY_DEFAULT . '/' . self::CHILD_URL_KEY_DEFAULT,
            $childDefault['urlPath'],
            'Child urlPath must be parent url_key + "/" + child url_key.'
        );

        // --- fixture_second_store: urlPath must follow the store-specific url_key chain ---
        // The stored url_path EAV value for this store is the admin value ('parent-cat',
        // 'parent-cat/child-cat'), so any implementation reading from EAV would return the
        // wrong result.  Our implementation must use the store-specific url_key.

        $parentSecond = $this->getCategoryById(self::PARENT_CAT_ID, self::STORE_SECOND);
        $this->assertNotEmpty($parentSecond, 'Parent category must be in the feed for fixture_second_store.');
        $this->assertEquals(
            self::PARENT_URL_KEY_STORE2,
            $parentSecond['urlKey'],
            'urlKey for fixture_second_store must equal the store-specific url_key.'
        );
        $this->assertEquals(
            self::PARENT_URL_KEY_STORE2,
            $parentSecond['urlPath'],
            'urlPath for fixture_second_store parent must equal the store-specific url_key.'
        );

        $childSecond = $this->getCategoryById(self::CHILD_CAT_ID, self::STORE_SECOND);
        $this->assertNotEmpty($childSecond, 'Child category must be in the feed for fixture_second_store.');
        $this->assertEquals(
            self::CHILD_URL_KEY_STORE2,
            $childSecond['urlKey'],
            'urlKey for fixture_second_store child must equal the store-specific url_key.'
        );
        $this->assertEquals(
            self::PARENT_URL_KEY_STORE2 . '/' . self::CHILD_URL_KEY_STORE2,
            $childSecond['urlPath'],
            'Child urlPath for fixture_second_store must use store-specific url_keys for all ancestors.'
        );

        // --- cross-store: values must differ, confirming store isolation ---

        $this->assertNotEquals(
            $parentDefault['urlPath'],
            $parentSecond['urlPath'],
            'Parent urlPath must differ between store views when url_key differs.'
        );
        $this->assertNotEquals(
            $childDefault['urlPath'],
            $childSecond['urlPath'],
            'Child urlPath must differ between store views when any ancestor url_key differs.'
        );
    }
}
