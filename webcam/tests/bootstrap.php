<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/TestTempDir.php';
require __DIR__ . '/Support/HttpServer.php';
require __DIR__ . '/Support/TestFixtures.php';
require __DIR__ . '/Support/WebcamHttpTestCase.php';

TestTempDir::ensure();
HttpServer::ensure();
TestTempDir::registerShutdownCleanup();
TestFixtures::seed();
