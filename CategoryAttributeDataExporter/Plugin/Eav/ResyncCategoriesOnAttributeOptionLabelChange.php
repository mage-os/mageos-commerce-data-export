<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\CategoryAttributeDataExporter\Plugin\Eav;

use Magento\CatalogDataExporter\Model\Eav\AttributeOptionLabelChangeResync;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Plugin that triggers {@see AttributeOptionLabelChangeResync} (configured for categories via
 * di.xml) around attribute save, to keep the categories feed in sync with option label changes.
 */
class ResyncCategoriesOnAttributeOptionLabelChange
{
    /**
     * @param AttributeOptionLabelChangeResync $categoryResync
     */
    public function __construct(private readonly AttributeOptionLabelChangeResync $categoryResync)
    {
    }

    /**
     * Snapshot and diff option labels around the attribute save via the configured resync service.
     *
     * @param AttributeResource $subject
     * @param callable $proceed
     * @param AbstractModel $object
     *
     * @return AttributeResource
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSave(
        AttributeResource $subject,
        callable $proceed,
        AbstractModel $object
    ): AttributeResource {
        $oldLabels = $this->categoryResync->beforeSave($object);
        $result = $proceed($object);
        $this->categoryResync->afterSave($object, $oldLabels);

        return $result;
    }
}
