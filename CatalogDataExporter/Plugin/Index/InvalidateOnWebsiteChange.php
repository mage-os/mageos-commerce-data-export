<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\CatalogDataExporter\Plugin\Index;

use Magento\CatalogDataExporter\Model\Indexer\IndexInvalidationManager as DeprecatedIndexerManager;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\DataExporter\Service\IndexInvalidationManager;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\ResourceModel\Website;

class InvalidateOnWebsiteChange
{
    private IndexInvalidationManager $invalidationManager;
    private string $invalidationEvent;

    /**
     * InvalidateOnChange constructor.
     *
     * @param DeprecatedIndexerManager $invalidationManager
     * @param CommerceDataExportLoggerInterface $logger
     * @param string $invalidationEvent
     * @param IndexInvalidationManager|null $indexInvalidationManager
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        DeprecatedIndexerManager $invalidationManager,
        CommerceDataExportLoggerInterface $logger,
        string $invalidationEvent = 'website_changed',
        ?IndexInvalidationManager $indexInvalidationManager = null
    ) {
        $this->invalidationEvent = $invalidationEvent;
        $this->invalidationManager = $indexInvalidationManager
            ?? ObjectManager::getInstance()->get(IndexInvalidationManager::class);
    }

    /**
     * Invalidate on save
     *
     * @param Website $subject
     * @param Website $result
     * @return Website
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(Website $subject, Website $result): Website
    {
        $this->invalidationManager->invalidate($this->invalidationEvent);
        return $result;
    }

    /**
     * Invalidate on delete
     *
     * @param Website $subject
     * @param Website $result
     * @return Website
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterDelete(Website $subject, Website $result): Website
    {
        $this->invalidationManager->invalidate($this->invalidationEvent);
        return $result;
    }
}
