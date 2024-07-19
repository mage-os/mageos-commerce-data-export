<?php
/**
 * Copyright 2023 Adobe
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

use Magento\Framework\Registry;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

$objectManager = Bootstrap::getObjectManager();
/** @var OrderRepositoryInterface $orderRepository */
$orderRepository = $objectManager->get(OrderRepositoryInterface::class);
/** @var InvoiceRepositoryInterface $invoiceRepository */
$invoiceRepository = $objectManager->get(InvoiceRepositoryInterface::class);
/** @var ShipmentRepositoryInterface $shipmentRepository */
$shipmentRepository = $objectManager->get(ShipmentRepositoryInterface::class);
/** @var CreditmemoRepositoryInterface $creditmemoRepository */
$creditmemoRepository = $objectManager->get(CreditmemoRepositoryInterface::class);
/** @var OrderInterface $order */
$order = $objectManager->get(OrderInterfaceFactory::class)->create()->loadByIncrementId('100000111');
/** @var Registry $registry */
$registry = $objectManager->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

foreach ($order->getInvoiceCollection() as $invoice) {
    $invoiceRepository->delete($invoice);
}

$orderRepository->delete($order);

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);

$creditMemoGridAggregator = $objectManager->get(\CreditmemoGridAggregator::class);
$creditMemoGridAggregator->purge('100000111', 'order_increment_id');

Resolver::getInstance()->requireDataFixture('Magento/Catalog/_files/product_simple_rollback.php');
