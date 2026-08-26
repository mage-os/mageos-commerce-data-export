<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CustomizableOptionsDataExporter\Model\Provider;

use Magento\CatalogDataExporter\Model\Provider\Product\ProductOptions\SelectableOptions;
use Magento\CatalogDataExporter\Model\Provider\Product\CustomizableOptions\ProductShopperInputOptions;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;

/**
 * Assemble customizable options (selectable + shopper-input) into a per-product, per-store-view tree.
 *
 * Delegates to the canonical CatalogDataExporter option providers so option UIDs, prices, price types
 * and store-view resolution stay identical to the optionsV2 / shopperInputOptions feed fields.
 */
class CustomizableOptionsProvider
{
    /**
     * @param SelectableOptions $selectableOptions
     * @param ProductShopperInputOptions $shopperInputOptions
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SelectableOptions $selectableOptions,
        private readonly ProductShopperInputOptions $shopperInputOptions,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Assemble the customizable options tree keyed by "<productId>-<storeViewCode>".
     *
     * @param array $values rows of ['productId' => ..., 'storeViewCode' => ...]
     * @return array keyed by "<productId>-<storeViewCode>" => ['selectable' => [...], 'shopperInput' => [...]]
     */
    public function execute(array $values): array
    {
        $output = [];
        try {
            foreach ($this->selectableOptions->get($values) as $row) {
                $key = $row['productId'] . '-' . $row['storeViewCode'];
                $output[$key]['selectable'][] = $row['optionsV2'];
            }
            foreach ($this->shopperInputOptions->get($values) as $row) {
                $key = $row['productId'] . '-' . $row['storeViewCode'];
                $output[$key]['shopperInput'][] = $row['shopperInputOptions'];
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'CDE01-23 Unable to assemble "ac_customizable_options" attribute. Error: %s',
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return [];
        }
        return $output;
    }
}
