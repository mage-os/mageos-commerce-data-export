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

namespace Magento\SalesOrdersDataExporter\Model\Provider\Items;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Class for retrieving product sku for order item
 */
class OrderItemProductSku
{
    private const PARENT_PRODUCT_TYPES = [
        \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE,
        \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE
    ];

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CommerceDataExportLoggerInterface
     */
    private CommerceDataExportLoggerInterface $logger;

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param CommerceDataExportLoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        CommerceDataExportLoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    /**
     * Retrieves and processes order items.
     *
     * @param array $values
     * @return array
     */
    public function get(array $values): array
    {
        $output = [];
        foreach ($values as $orderItems) {
            foreach ($orderItems as $item) {
                $output[$item['entityId']]['entityId'] = $item['entityId'];
                $output[$item['entityId']]['productSku'] = $this->getProductSku($item);
            }
        }
        return $output;
    }

    /**
     * Retrieves SKU from product repository when the order item has a parent product type.
     *
     * @param array $row
     * @return string
     */
    public function getProductSku(array $row)
    {
        if (isset($row['productType'], $row['productId'])
            && in_array($row['productType'], self::PARENT_PRODUCT_TYPES)
        ) {
            try {
                $product = $this->productRepository->getById($row['productId']);
                return $product->getSku();
            } catch (NoSuchEntityException $e) {
                $this->logger->error('Unable to retrieve product information for ID: ' . $row['productId']);
            }
        }

        return $row['sku'];
    }
}
