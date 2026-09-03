<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Plugin\Eav\Attribute;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\CatalogDataExporter\Model\Eav\AttributeDeleteResync;

/**
 * Plugin that triggers {@see AttributeDeleteResync} (configured for products via di.xml) before
 * an EAV attribute is deleted, to keep the products feed in sync.
 */
class ProductAttributeDelete
{
    /**
     * @param AttributeDeleteResync $productAttributeDeleteResync
     */
    public function __construct(private readonly AttributeDeleteResync $productAttributeDeleteResync)
    {
    }

    /**
     * Delegate to the configured resync service before the attribute is deleted.
     *
     * @param Attribute $attribute
     */
    public function beforeDelete(Attribute $attribute): void
    {
        $this->productAttributeDeleteResync->beforeDelete($attribute);
    }
}
