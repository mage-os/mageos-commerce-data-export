<?php
/**
 * Copyright 2021 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\ProductVariantDataExporter\Model\Provider\ProductVariants;

/**
 * Product variant option value provider interface
 */
interface OptionValueInterface
{
    /**
     * Get product variant option value
     *
     * @param array $row
     * @return array
     */
    public function resolve(array $row) : array;
}
