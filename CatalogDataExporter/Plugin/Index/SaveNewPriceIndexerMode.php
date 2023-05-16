<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Plugin\Index;

use Magento\Catalog\Model\Indexer\Product\Price\ModeSwitcherConfiguration;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\App\Config\MutableScopeConfigInterface;

/**
 * Create mysql view table for price index after enabled dimension future only in read mode
 */
class SaveNewPriceIndexerMode
{
    private MutableScopeConfigInterface $scopeConfig;
    private CommerceDataExportLoggerInterface $logger;

    /**
     * @param MutableScopeConfigInterface $scopeConfig
     * @param CommerceDataExportLoggerInterface $logger
     */
    public function __construct(
        MutableScopeConfigInterface $scopeConfig,
        CommerceDataExportLoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Save new price mode
     *
     * @param ModeSwitcherConfiguration $subject
     * @param $result
     * @param string $mode
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSaveMode(
        ModeSwitcherConfiguration $subject,
        $result,
        string $mode
    ) {
        try {
            $this->scopeConfig->setValue(
                ModeSwitcherConfiguration::XML_PATH_PRICE_DIMENSIONS_MODE,
                $mode
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Data Exporter exception has occurred: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
        return $result;
    }
}
