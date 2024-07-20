<?php
/**
 * Copyright 2022 Adobe
 * All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains
 * the property of Adobe and its suppliers, if any. The intellectual
 * and technical concepts contained herein are proprietary to Adobe
 * and its suppliers and are protected by all applicable intellectual
 * property laws, including trade secret and copyright laws.
 * Dissemination of this information or reproduction of this material
 * is strictly forbidden unless prior written permission is obtained
 * from Adobe.
 */

declare(strict_types=1);

namespace Magento\ConfigurationDataExporter\Model;

use Magento\Store\Model\ScopeInterface;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;

/**
 * Perform full export of system configuration
 */
class FullExportProcessor implements FullExportProcessorInterface
{
    const ERROR_EMPTY_CONFIGURATION_MSG = 'Full configuration export for store - %s skipped. Empty configuration.';
    const SUCCESS_CONFIGURATION_EXPORT_MSG = 'Full configuration export for store - %s : %s items processed.';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\ConfigurationDataExporter\Model\ConfigExportCallbackInterface
     */
    private $configExportCallback;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param ConfigExportCallbackInterface $configExportCallback
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\ConfigurationDataExporter\Model\ConfigExportCallbackInterface $configExportCallback,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configExportCallback = $configExportCallback;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Process export of system configuration for each store view.
     *
     * @param int|null $storeId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function process(?int $storeId = null): void
    {
        if ($storeId) {
            $stores = [$this->storeManager->getStore($storeId)];
        } else {
            $stores = $this->storeManager->getStores();
        }

        foreach ($stores as $store) {
            $storeId = (int)$store->getId();

            $storeConfigArray = $this->scopeConfig->getValue(
                null,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            if (empty($storeConfigArray)) {
                $this->logger->error(
                    sprintf(self::ERROR_EMPTY_CONFIGURATION_MSG, $storeId),
                    ['FULL CONFIGURATION EXPORT']
                );

                continue;
            }

            $storeConfigArray = $this->convert($storeConfigArray, $storeId);
            $this->configExportCallback->execute(
                \Magento\ConfigurationDataExporter\Model\ConfigExportCallbackInterface::EVENT_TYPE_FULL,
                $storeConfigArray
            );

            $this->logger->info(
                sprintf(self::SUCCESS_CONFIGURATION_EXPORT_MSG, $storeId, count($storeConfigArray)),
                ['FULL CONFIGURATION EXPORT']
            );
        }
    }

    /**
     * Convert source config data into format expected by message builder.
     *
     * @param array $config
     * @param int $storeId
     *
     * @return array
     */
    private function convert(array $config, int $storeId): array
    {
        $result = [];

        foreach ($config as $sectionId => $section) {
            foreach ($section as $groupId => $fields) {

                // some of config.xml paths provided incorrectly - e.g. Magento_Sales::etc/config.xml
                if (!is_array($fields)) {
                    continue;
                }

                foreach ($fields as $fieldId => $value) {
                    $result[] = [
                        'scope' => ScopeInterface::SCOPE_STORE,
                        'scope_id' => $storeId,
                        'path' => $sectionId . '/' . $groupId . '/' . $fieldId,
                        'value' => $value
                    ];
                }
            }
        }

        return $result;
    }
}
