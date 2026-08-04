<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Test\Integration;

use Magento\DataExporter\Model\Indexer\FeedReadinessCheckerPool;
use Magento\TestFramework\Helper\Bootstrap;

trait DisablesFeedReadinessCheckers
{
    public static function disableFeedReadinessCheckers(): void
    {
        $pool = Bootstrap::getObjectManager()->get(FeedReadinessCheckerPool::class);
        (new \ReflectionClass($pool))->getProperty('checkers')->setValue($pool, []);
    }
}
