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
use Magento\Store\Model\ResourceModel\Store;

/**
 * Class InvalidateOnGroupChange
 *
 * Invalidates indexes on Store change
 */
class InvalidateOnStoreChange
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
        string $invalidationEvent = 'group_changed',
        ?IndexInvalidationManager $indexInvalidationManager = null
    ) {
        $this->invalidationEvent = $invalidationEvent;
        $this->invalidationManager = $indexInvalidationManager
            ?? ObjectManager::getInstance()->get(IndexInvalidationManager::class);
    }

    /**
     * Invalidate on save
     *
     * @param Store $subject
     * @param Store $result
     * @return Store
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(Store $subject, Store $result): Store
    {
        $this->invalidationManager->invalidate($this->invalidationEvent);
        return $result;
    }

    /**
     * Invalidate on delete
     *
     * @param Store $subject
     * @param Store $result
     * @return Store
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterDelete(Store $subject, Store $result): Store
    {
        $this->invalidationManager->invalidate($this->invalidationEvent);
        return $result;
    }
}
