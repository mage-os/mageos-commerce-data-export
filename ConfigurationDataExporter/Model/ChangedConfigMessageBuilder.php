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

namespace Magento\ConfigurationDataExporter\Model;

use Magento\ConfigurationDataExporter\Event\Data\ChangedConfig;
use Magento\ConfigurationDataExporter\Event\Data\ChangedConfigFactory;
use Magento\ConfigurationDataExporter\Event\Data\MetaFactory;
use Magento\ConfigurationDataExporter\Event\Data\ConfigFactory;
use Magento\ConfigurationDataExporter\Event\Data\DataFactory;
use Magento\ConfigurationDataExporter\Api\WhitelistProviderInterface;

/**
 * Class that builds queue message for changed config
 */
class ChangedConfigMessageBuilder
{
    /**
     * @var ChangedConfigFactory
     */
    private $changedConfigFactory;

    /**
     * @var MetaFactory
     */
    private $metaFactory;

    /**
     * @var DataFactory
     */
    private $dataFactory;

    /**
     * @var ConfigFactory
     */
    private $configFactory;

    /**
     * @var WhitelistProviderInterface
     */
    private $whitelistProviderPool;

    /**
     * @param ChangedConfigFactory $changedConfigFactory
     * @param MetaFactory $metaFactory
     * @param DataFactory $dataFactory
     * @param ConfigFactory $configFactory
     * @param WhitelistProviderInterface $whitelistProviderPool
     */
    public function __construct(
        ChangedConfigFactory $changedConfigFactory,
        MetaFactory $metaFactory,
        DataFactory $dataFactory,
        ConfigFactory $configFactory,
        WhitelistProviderInterface $whitelistProviderPool
    ) {
        $this->changedConfigFactory = $changedConfigFactory;
        $this->metaFactory = $metaFactory;
        $this->dataFactory = $dataFactory;
        $this->configFactory = $configFactory;
        $this->whitelistProviderPool = $whitelistProviderPool;
    }

    /**
     * Build message object
     *
     * @param string $eventType
     * @param array $configData
     *
     * @return ChangedConfig
     */
    public function build(string $eventType, array $configData): ChangedConfig
    {
        $configArray = [];
        $whitelistedPaths = $this->whitelistProviderPool->getWhitelist();

        foreach ($configData as $item) {
            $isPathAllowed = array_filter($whitelistedPaths, function ($path) use ($item) {
                return strpos($item['path'], $path) === 0;
            });

            if (!empty($isPathAllowed)) {
                $configArray[] = $this->configFactory->create(
                    [
                        'store' => (int)$item['scope_id'],
                        'name' => (string)$item['path'],
                        'value' => $item['value']
                    ]
                );
            }
        }

        return $this->changedConfigFactory->create(
            [
                'meta' => $this->metaFactory->create(['event' => $eventType]),
                'data' => $this->dataFactory->create(['config' => $configArray])
            ]
        );
    }
}
