<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Model\Indexer;

use Magento\Framework\ObjectManagerInterface;

/**
 * Pool of readiness checkers, returns checkers for feed per feed name, can be configured via di.xml
 */
class FeedReadinessCheckerPool
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var array
     */
    private $checkers;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param array $checkers
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        array $checkers = []
    ) {
        $this->objectManager = $objectManager;
        $this->checkers = $checkers;
    }

    /**
     * Returns array of readiness checkers declared for feed by name
     *
     * @param string $feedName
     * @return FeedReadinessCheckerInterface[]
     */
    public function getCheckersForFeed(string $feedName): array
    {
        $output = [];
        if (isset($this->checkers[$feedName]) && is_array($this->checkers[$feedName])) {
            foreach ($this->checkers[$feedName] as $checkerName) {
                $checker = $this->objectManager->get($checkerName);
                if ($checker instanceof FeedReadinessCheckerInterface) {
                    $output[] = $checker;
                } else {
                    throw new \InvalidArgumentException(
                        "All checkers MUST implement " . FeedReadinessCheckerInterface::class
                    );
                }
            }
        }
        return $output;
    }
}
