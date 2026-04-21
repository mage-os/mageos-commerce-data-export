<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

Resolver::getInstance()->requireDataFixture('Magento_CatalogDataExporter::Test/_files/setup_stores.php');

/**
 * Creates a two-level category hierarchy under the default root (id=2):
 *
 *   parent-cat  (id=830, url_key='parent-cat')
 *     child-cat  (id=831, url_key='child-cat')
 *
 * Then overrides url_key for 'fixture_second_store' WITHOUT touching url_path:
 *   parent-cat  (id=830) → url_key='parent-cat-store2'  (url_path stored = 'parent-cat')
 *   child-cat   (id=831) → url_key='child-cat-store2'   (url_path stored = 'parent-cat/child-cat')
 *
 * The deliberate gap between stored url_path and url_key for the second store view is the
 * scenario our "compute url_path from url_key on-the-fly" implementation must handle
 * correctly: the feed must return 'parent-cat-store2' and
 * 'parent-cat-store2/child-cat-store2', not the stale stored values.
 */
$objectManager = Bootstrap::getObjectManager();
/** @var CategoryFactory $categoryFactory */
$categoryFactory = $objectManager->get(CategoryFactory::class);

$categories = [
    [
        'id'               => 830,
        'name'             => 'Parent Category URL Key Per Store',
        'parent_id'        => 2,
        'path'             => '1/2/830',
        'level'            => 2,
        'url_key'          => 'parent-cat',
        'available_sort_by' => ['name'],
        'default_sort_by'  => 'name',
        'is_active'        => true,
        'position'         => 30,
    ],
    [
        'id'               => 831,
        'name'             => 'Child Category URL Key Per Store',
        'parent_id'        => 830,
        'path'             => '1/2/830/831',
        'level'            => 3,
        'url_key'          => 'child-cat',
        'available_sort_by' => ['name'],
        'default_sort_by'  => 'name',
        'is_active'        => true,
        'position'         => 31,
    ],
];

foreach ($categories as $data) {
    /** @var Category $category */
    $category = $categoryFactory->create();
    $category->isObjectNew(true);
    $category
        ->setId($data['id'])
        ->setName($data['name'])
        ->setParentId($data['parent_id'])
        ->setPath($data['path'])
        ->setLevel($data['level'])
        ->setUrlKey($data['url_key'])
        ->setAvailableSortBy($data['available_sort_by'])
        ->setDefaultSortBy($data['default_sort_by'])
        ->setIsActive($data['is_active'])
        ->setPosition($data['position'])
        ->setStoreId(0)
        ->save();
}

// Override url_key for fixture_second_store only - intentionally do NOT set url_path so
// the stored url_path remains the admin-store value.  This exercises the case where
// url_path EAV and url_key EAV are out of sync, which our implementation must handle
// by always computing urlPath from the url_key chain.
/** @var StoreManagerInterface $storeManager */
$storeManager = $objectManager->get(StoreManagerInterface::class);
$secondStore = $storeManager->getStore('fixture_second_store');
$currentStore = $storeManager->getStore();

/** @var CategoryRepositoryInterface $categoryRepository */
$categoryRepository = $objectManager->create(CategoryRepositoryInterface::class);

$storeSpecificUrlKeys = [
    830 => 'parent-cat-store2',
    831 => 'child-cat-store2',
];

$storeManager->setCurrentStore($secondStore);
foreach ($storeSpecificUrlKeys as $categoryId => $urlKey) {
    $category = $categoryRepository->get($categoryId, $secondStore->getId());
    $category->setUrlKey($urlKey);
    $categoryRepository->save($category);
}
$storeManager->setCurrentStore($currentStore);
