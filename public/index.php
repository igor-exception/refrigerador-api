<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

$app = require dirname(__DIR__) . '/app.php';
$app->run();
