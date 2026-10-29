<?php
// Reproduce the production status alias on the isolated PHP test server.
// Only the fixed CSS path is mapped; no dynamic path or live installation.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/camera-status/style.css') {
    header('Content-Type: text/css');
    readfile(dirname(__DIR__, 2) . '/status/public/style.css');
    return true;
}
return false;
