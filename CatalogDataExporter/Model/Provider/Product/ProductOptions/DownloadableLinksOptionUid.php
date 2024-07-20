<?php
/**
 * Copyright 2021 Adobe
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

namespace Magento\CatalogDataExporter\Model\Provider\Product\ProductOptions;

/**
 * Downloadable links option value uid generator
 */
class DownloadableLinksOptionUid implements OptionValueUidInterface
{
    /**
     * Downloadable links Option type name
     */
    public const OPTION_TYPE = 'downloadable';

    /**
     * Option id key
     */
    public const OPTION_ID = 'linkId';

    /**
     * Returns uid based on link id
     *
     * @param string[] $params
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function resolve(array $params): string
    {
        if (!isset($params[self::OPTION_ID])) {
            throw new \InvalidArgumentException(
                'Cannot generate downloadable product link uid, because link id is missing'
            );
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return base64_encode(implode('/', [self::OPTION_TYPE, $params[self::OPTION_ID]]));
    }
}
