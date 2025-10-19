<?php
/**
 * Copyright 2021 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProductDataExporter\Model\Provider\Product;

/**
 * Create configurable product option value uid in base64 encode
 */
class ConfigurableOptionValueUid
{
    /**
     * Option type name
     */
    public const OPTION_TYPE = 'configurable';

    /**
     * Separator of uid for encoding
     */
    private const UID_SEPARATOR = '/';

    /**
     * Returns uid based on attribute id and option value
     *
     * @param string $attributeId
     * @param string $valueIndex
     * @return string
     * @see \Magento\ConfigurableProductGraphQl\Model\Options\SelectionUidFormatter::encode
     */
    public function resolve(string $attributeId, string $valueIndex): string
    {
        $optionDetails = [
            self::OPTION_TYPE,
            $attributeId,
            $valueIndex
        ];

        $content = implode(self::UID_SEPARATOR, $optionDetails);

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return \base64_encode($content);
    }
}
