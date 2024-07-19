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

namespace Magento\CatalogDataExporter\Model\Provider\EavAttributes;

use Magento\Store\Model\Store;

/**
 * Convert data returned from EAV to more friendly format. See example:
 * Raw data:
 *     [
 *         [
 *             entity_id => 42
 *             attribute_code => name
 *             value => Tesla 3
 *             some-attribute => attribute value
 *         ]
 *     ]
 *
 * Converted data
 *
 *     42 => [
 *             entity_id => 42
 *             id => 42
 *             name => Tesla 3
 *             some-attribute => attribute value
 *          ]
 */
class AttributesDataConverter
{
    /**
     * Convert attribute values for given attribute codes.
     *
     * @param array $fetchResult
     *
     * @return array
     */
    public function convert(array $fetchResult): array
    {
        $attributes = [];

        foreach ($fetchResult as $row) {
            $entityId = (int)$row['entity_id'];
            $attributeCode = $row['attribute_code'] ?? null;

            if (!isset($attributes[$entityId])) {
                $attributes[$entityId] = $row;
                $attributes[$entityId]['id'] = $entityId;
            }
            if ($attributeCode) {
                if ($this->isSkipDefaultStoreValue($row, $attributes, $entityId, $attributeCode)) {
                    continue;
                }
                $attributes[$entityId][$attributeCode] = $row['value'];
            }
            unset($attributes[$entityId]['value'], $attributes[$entityId]['attribute_code']);
        }

        return $attributes;
    }

    /**
     * Do not override store-specific value by default value
     *
     * @param array $row
     * @param array $attributes
     * @param int $entityId
     * @param string $attributeCode
     *
     * @return bool
     */
    private function isSkipDefaultStoreValue(array $row, array $attributes, int $entityId, string $attributeCode): bool
    {
        return isset($row['store_id'], $attributes[$entityId][$attributeCode])
            && (int)$row['store_id'] === Store::DEFAULT_STORE_ID;
    }
}
