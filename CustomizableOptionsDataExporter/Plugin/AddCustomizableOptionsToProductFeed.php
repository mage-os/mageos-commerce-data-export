<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CustomizableOptionsDataExporter\Plugin;

use Magento\CustomizableOptionsDataExporter\Model\Provider\CustomizableOptionsProvider;
use Magento\DataExporter\Export\Processor;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Enrich the products feed with the ac_customizable_options attribute.
 *
 * Exports Adobe Commerce customizable options "as-is" (see MDEE-1029): the attribute value is a
 * serialized projection of the CatalogDataExporter option feed fields - "selectable" mirrors optionsV2,
 * "shopperInput" mirrors shopperInputOptions - carried opaquely as a JSON (OBJECT) attribute so no
 * catalog service schema change is required. Value UIDs use the canonical "custom-option/..." scheme.
 */
class AddCustomizableOptionsToProductFeed
{
    private const ATTRIBUTE_CODE = 'ac_customizable_options';

    /**
     * @param CustomizableOptionsProvider $customizableOptionsProvider
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly CustomizableOptionsProvider $customizableOptionsProvider,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Append the serialized customizable-options tree to each product feed item.
     *
     * @param Processor $processor
     * @param array $feedItems
     * @param string $feedName
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterProcess(Processor $processor, array $feedItems, string $feedName): array
    {
        if ($feedName !== 'products') {
            return $feedItems;
        }

        $customizableOptions = $this->customizableOptionsProvider->execute(
            array_map(
                static fn (array $item): array => [
                    'productId' => $item['productId'],
                    'storeViewCode' => $item['storeViewCode'],
                ],
                $feedItems
            )
        );

        foreach ($feedItems as &$product) {
            $key = $product['productId'] . '-' . $product['storeViewCode'];
            $data = $customizableOptions[$key] ?? null;
            if ($data === null) {
                continue;
            }
            $product['attributes'][] = [
                'attributeCode' => self::ATTRIBUTE_CODE,
                'value' => [$this->serializer->serialize(['schemaVersion' => 1] + $data)],
            ];
        }

        return $feedItems;
    }
}
