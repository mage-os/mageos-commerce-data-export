<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

Resolver::getInstance()->requireDataFixture('Magento/ConfigurableProduct/_files/product_configurable_rollback.php');
Resolver::getInstance()->requireDataFixture('Magento/Sales/_files/order_rollback.php');
