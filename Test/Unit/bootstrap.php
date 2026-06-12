<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

// Dual-context bootstrap: the suite runs both from a standalone repo checkout
// (CI — composer autoload in the repo root) and from inside a Magento
// installation at app/code/SimpleMage/CategoryProductIndexer (Magento's unit
// test bootstrap, which also registers the app/code autoloader).
$candidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../../../../dev/tests/unit/framework/bootstrap.php',
];

foreach ($candidates as $bootstrap) {
    if (file_exists($bootstrap)) {
        require $bootstrap;

        return;
    }
}

throw new RuntimeException(
    'No autoloader found. Run `composer install` in the module root (standalone) '
    . 'or install the module under app/code/ of a Magento project.'
);
