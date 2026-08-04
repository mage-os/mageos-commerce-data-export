<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Model\Indexer;

/**
 * Reports whether a feed's export destination is configured and ready to process data.
 *
 * @see FeedReadinessCheckerPool for registration
 */
interface FeedReadinessCheckerInterface
{
    /**
     * Returns false when indexation for the feed should be skipped.
     *
     * @return bool
     */
    public function isReady(): bool;
}
