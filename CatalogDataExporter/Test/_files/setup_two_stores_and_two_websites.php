<?php
/**
 * Copyright 2022 Adobe
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

use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Store\Model\Store;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;

$objectManager = Bootstrap::getObjectManager();
/** @var WebsiteRepositoryInterface $websiteRepository */
$websiteRepository = $objectManager->get(WebsiteRepositoryInterface::class);
$website = $websiteRepository->get('base');

$categoryFactory = $objectManager->get(CategoryFactory::class);
$categoryRepository = $objectManager->create(CategoryRepositoryInterface::class);

/** @var Category $rootCategory */
$rootCategory = $categoryFactory->create();
$rootCategory->isObjectNew(true);
$rootCategory->setName('Second Root Category')
    ->setParentId(Category::TREE_ROOT_ID)
    ->setIsActive(true)
    ->setPosition(2);
$rootCategory = $categoryRepository->save($rootCategory);

/**
 * @var \Magento\Store\Model\Group $storeGroup
 */
$storeGroup = $objectManager->create(\Magento\Store\Model\Group::class);
$storeGroup->setCode('second_store_group')
    ->setName('Second Store Group')
    ->setRootCategoryId($rootCategory->getId())
    ->setWebsite($website);
try {
    $storeGroup->save();
} catch (Exception $e) {
}


$store = $objectManager->create(Store::class);
$store->load('custom_store_view_one', 'code');

if (!$store->getId()) {
    $websiteId = $website->getId();
    $groupId = $storeGroup->getId();
    $store->setData([
        'code' => 'custom_store_view_one',
        'website_id' => $websiteId,
        'group_id' => $groupId,
        'name' => 'Custom Store View One',
        'sort_order' => 10,
        'is_active' => 1,
    ]);
    $store->save();
}

$store2 = $objectManager->create(Store::class);
$store2->load('custom_store_view_two', 'code');

if (!$store2->getId()) {
    try {
        /** @var $website2 \Magento\Store\Model\Website */
        $website2 = $websiteRepository->get('test');
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
    }
    if (!isset($website2) || !$website2->getId()) {
        $website2 = $objectManager->create(\Magento\Store\Model\Website::class);
        $website2->setData(
            [
                'code' => 'test',
                'name' => 'Test Website',
                'default_group_id' => '1',
                'is_default' => '0'
            ]
        );
        try {
            $website2->getResource()->save($website2);
        } catch (Exception $e) {
        }
    }
    $websiteId = $website2->getId();
    $groupId = $storeGroup->getId();
    $store2->setData([
        'code' => 'custom_store_view_two',
        'website_id' => $websiteId,
        'group_id' => $groupId,
        'name' => 'Custom Store View Two',
        'sort_order' => 11,
        'is_active' => 1,
    ]);
    $store2->save();
}

/* Refresh stores memory cache */
$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
