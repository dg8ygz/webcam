<?php
// Test-only loopback origin: synthetic media, no real camera or keys.
$name = ltrim($_SERVER['REQUEST_URI'], '/');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . str_repeat('a', 64)) { http_response_code(403); exit; }
if ($name !== 'index.m3u8' && !preg_match('/^seg_[a-f0-9]{16}_[0-9]{6,12}\.ts$/D', $name)) { http_response_code(404); exit; }
$path = getenv('USB_HLS_FIXTURE') . '/' . $name;
if (!is_file($path)) { http_response_code(404); exit; }
file_put_contents(getenv('USB_HLS_FIXTURE') . '/requests', $name . "\n", FILE_APPEND | LOCK_EX);
readfile($path);
