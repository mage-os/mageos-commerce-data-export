<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider\Product\Formatter;

/**
 * Class SystemEnumFormatter
 *
 * Formats system enum values.
 */
class SystemEnumFormatter implements FormatterInterface
{
    /**
     * @var array
     */
    private $systemEnums;

    /**
     * @param array $systemEnums
     */
    public function __construct(
        array $systemEnums = []
    ) {
        $this->systemEnums = $systemEnums;
    }

    /**
     * Format data
     *
     * @param array $row
     * @return array
     */
    public function format(array $row): array
    {
        foreach ($this->systemEnums as $enumName => $enumMap) {
            if (array_key_exists($enumName, $row)) {
                $enumKey = $row[$enumName] !== null ? $row[$enumName] : '_';
                $row[$enumName] = $enumMap[$enumKey] ?? null;
            }
        }
        return $row;
    }
}
