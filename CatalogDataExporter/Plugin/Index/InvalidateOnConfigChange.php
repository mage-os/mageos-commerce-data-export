<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\CatalogDataExporter\Plugin\Index;

use Magento\CatalogDataExporter\Model\Indexer\IndexInvalidationManager as DeprecatedIndexerManager;
use Magento\Config\Model\Config;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\DataExporter\Service\IndexInvalidationManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;

/**
 * Class InvalidateOnConfigChange
 *
 * Invalidates indexes on configuration change
 */
class InvalidateOnConfigChange
{
    private IndexInvalidationManager $invalidationManager;
    private string $invalidationEvent;
    private array $configValues;

    /**
     * @param DeprecatedIndexerManager $invalidationManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CommerceDataExportLoggerInterface $logger
     * @param string $invalidationEvent
     * @param array $configValues
     * @param IndexInvalidationManager|null $indexInvalidationManager
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        DeprecatedIndexerManager $invalidationManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CommerceDataExportLoggerInterface $logger,
        string $invalidationEvent = 'config_changed',
        array $configValues = [],
        ?IndexInvalidationManager $indexInvalidationManager = null
    ) {
        $this->invalidationEvent = $invalidationEvent;
        $this->configValues = $configValues;
        $this->invalidationManager = $indexInvalidationManager
            ?? ObjectManager::getInstance()->get(IndexInvalidationManager::class);
    }

    /**
     * Invalidate indexer if relevant config value is changed (around plugin)
     *
     * @param Config $subject
     * @param callable $proceed
     * @return mixed
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSave(Config $subject, callable $proceed)
    {
        try {
            $check = [];
            $savedSection = $subject->getSection();
            foreach ($this->configValues as $searchValue) {
                $path = explode('/', (string) $searchValue);
                $section = $path[0];
                $group = $path[1];
                $field = $path[2];
                if ($savedSection == $section) {
                    if (isset($subject['groups'][$group]['fields'][$field])) {
                        $check[$searchValue] = $this->scopeConfig->getValue($searchValue);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'CDE03-14 Failed to read config values. Indexer invalidation for event "%s" skipped. Error: %s',
                    $this->invalidationEvent,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }

        $result = $proceed();

        foreach ($check as $path => $beforeValue) {
            if ($beforeValue != $this->scopeConfig->getValue($path)) {
                $this->invalidationManager->invalidate($this->invalidationEvent);
                break;
            }
        }

        return $result;
    }
}
