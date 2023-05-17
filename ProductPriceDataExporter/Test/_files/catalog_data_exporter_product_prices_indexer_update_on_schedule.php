<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
/** @var \Magento\Framework\Indexer\IndexerRegistry $indexerRegistry */
$indexerRegistry = $objectManager->create(\Magento\Framework\Indexer\IndexerRegistry::class);
$indexer = $indexerRegistry->get('catalog_data_exporter_product_prices');
$indexer->setScheduled(true);
