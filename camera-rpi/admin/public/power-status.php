<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/power.php';
session_write_close();
if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); header('Allow: GET'); exit; }
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(camera_power_view(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
