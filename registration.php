<?php
declare(strict_types=1);

/**
 * LR LogCleaner Module Registration
 *
 * @package LR_LogCleaner
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'LR_LogCleaner',
    __DIR__
);