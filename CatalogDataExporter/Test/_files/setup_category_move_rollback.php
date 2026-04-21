<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;

/** @var ObjectManagerInterface $objectManager */
$objectManager = Bootstrap::getObjectManager();

/** @var Registry $registry */
$registry = $objectManager->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

/** @var CategoryRepositoryInterface $categoryRepository */
$categoryRepository = $objectManager->create(CategoryRepositoryInterface::class);

foreach ([811, 810, 813, 812] as $categoryId) {
    try {
        $category = $categoryRepository->get($categoryId);
        if ($category->getId()) {
            $categoryRepository->delete($category);
        }
    } catch (Exception) {
        // Nothing to delete
    }
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
