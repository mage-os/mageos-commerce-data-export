<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterfaceFactory;
use Magento\Store\Api\Data\WebsiteInterfaceFactory;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
/** @var WebsiteResource $websiteResource */
$websiteResource = $objectManager->get(WebsiteResource::class);
/** @var StoreResource $storeResource */
$storeResource = $objectManager->get(StoreResource::class);
/** @var Registry $registry */
$registry = $objectManager->get(Registry::class);

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

$store = $objectManager->get(StoreInterfaceFactory::class)->create();
$storeResource->load($store, 'cde_third_store', 'code');
if ($store->getId()) {
    $storeResource->delete($store);
}

$website = $objectManager->get(WebsiteInterfaceFactory::class)->create();
$websiteResource->load($website, 'cde_test_website', 'code');
if ($website->getId()) {
    $websiteResource->delete($website);
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);

$objectManager->get(StoreManagerInterface::class)->reinitStores();
