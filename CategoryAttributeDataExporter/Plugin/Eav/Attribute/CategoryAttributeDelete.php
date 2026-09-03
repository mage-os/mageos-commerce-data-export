<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CategoryAttributeDataExporter\Plugin\Eav\Attribute;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\CatalogDataExporter\Model\Eav\AttributeDeleteResync;

/**
 * Plugin that triggers {@see AttributeDeleteResync} (configured for categories via di.xml)
 * before an EAV attribute is deleted, to keep the categories feed in sync.
 */
class CategoryAttributeDelete
{
    /**
     * @param AttributeDeleteResync $categoryAttributeDeleteResync
     */
    public function __construct(private readonly AttributeDeleteResync $categoryAttributeDeleteResync)
    {
    }

    /**
     * Delegate to the configured resync service before the attribute is deleted.
     *
     * @param Attribute $attribute
     */
    public function beforeDelete(Attribute $attribute): void
    {
        $this->categoryAttributeDeleteResync->beforeDelete($attribute);
    }
}
