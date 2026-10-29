<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

$config = require __DIR__ . '/config/app.php';

return new Webcam\App($config);
