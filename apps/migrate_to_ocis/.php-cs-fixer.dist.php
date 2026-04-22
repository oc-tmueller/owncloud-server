<?php
// SPDX-License-Identifier: Apache-2.0

$config = new OC\CodingStandard\Config();

$config
    ->setUsingCache(true)
    ->getFinder()
    ->in(__DIR__)
    ->exclude('build')
    ->exclude('vendor-bin')
    ->exclude('vendor');

return $config;