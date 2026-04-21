<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

Resolver::getInstance()->requireDataFixture('Magento_CatalogDataExporter::Test/_files/setup_stores.php');

/**
 * Creates a two-tree category structure under the default root (id=2):
 *
 *   cat1-move  (id=810, url_key=cat1-move, url_path=cat1-move)
 *     cat1-child-move  (id=811, url_key=cat1-child-move, url_path=cat1-move/cat1-child-move)
 *
 *   cat2-move  (id=812, url_key=cat2-move, url_path=cat2-move)
 *     cat2-child-move  (id=813, url_key=cat2-child-move, url_path=cat2-move/cat2-child-move)
 *
 * Used by MovedCategoryFeedTest to verify that after moving cat1-move under cat2-child-move
 * the category feed reflects the updated urlPath.
 */
$objectManager = Bootstrap::getObjectManager();
/** @var CategoryFactory $categoryFactory */
$categoryFactory = $objectManager->get(CategoryFactory::class);

$categories = [
    [
        'id'              => 810,
        'name'            => 'Cat1 Move Test',
        'parent_id'       => 2,
        'path'            => '1/2/810',
        'level'           => 2,
        'url_key'         => 'cat1-move',
        'available_sort_by' => ['name'],
        'default_sort_by' => 'name',
        'is_active'       => true,
        'position'        => 10,
    ],
    [
        'id'              => 811,
        'name'            => 'Cat1 Child Move Test',
        'parent_id'       => 810,
        'path'            => '1/2/810/811',
        'level'           => 3,
        'url_key'         => 'cat1-child-move',
        'available_sort_by' => ['name'],
        'default_sort_by' => 'name',
        'is_active'       => true,
        'position'        => 11,
    ],
    [
        'id'              => 812,
        'name'            => 'Cat2 Move Test',
        'parent_id'       => 2,
        'path'            => '1/2/812',
        'level'           => 2,
        'url_key'         => 'cat2-move',
        'available_sort_by' => ['name'],
        'default_sort_by' => 'name',
        'is_active'       => true,
        'position'        => 12,
    ],
    [
        'id'              => 813,
        'name'            => 'Cat2 Child Move Test',
        'parent_id'       => 812,
        'path'            => '1/2/812/813',
        'level'           => 3,
        'url_key'         => 'cat2-child-move',
        'available_sort_by' => ['name'],
        'default_sort_by' => 'name',
        'is_active'       => true,
        'position'        => 13,
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
