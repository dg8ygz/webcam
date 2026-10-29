#!/usr/bin/env php
<?php
declare(strict_types=1);
// Compile the real decoder body with kernel API stubs and shared frame guards.
// Generated sources/binaries stay in a private temporary directory, not /run
// on a camera. Only this runner's exact three generated files are removed.
$base = dirname(__DIR__);
$source = file_get_contents($base . '/ops/dht-driver-debug/dht11.c');
$start = strpos($source, 'static unsigned char dht11_decode_byte(');
if ($start === false) throw new RuntimeException('Decoder start changed');
$end = strpos($source, "/*\n * IRQ handler called on GPIO edges", $start);
if ($end === false) throw new RuntimeException('Decoder end changed');
$stage = sys_get_temp_dir() . '/camera-dht-frame-' . bin2hex(random_bytes(8));
if (!mkdir($stage, 0700)) throw new RuntimeException('Cannot create stage');
try {
    file_put_contents($stage . '/decoder-under-test.inc', substr($source, $start, $end-$start));
    $args = ['cc', '-std=c11', '-Wall', '-Wextra', '-Werror',
        '-I'.$stage, '-I'.$base.'/ops/dht-driver-debug',
        $base.'/tests/dht-frame.c', '-o', $stage.'/test'];
    passthru(implode(' ', array_map('escapeshellarg',$args)), $rc);
    if ($rc !== 0) throw new RuntimeException('Compilation failed');
    passthru(escapeshellarg($stage.'/test').' '.escapeshellarg($base.'/tests/fixtures/dht-edges-20260913.tsv'), $rc);
    if ($rc !== 0) throw new RuntimeException('Frame checks failed');
} finally {
    foreach (['decoder-under-test.inc', 'test'] as $name) {
        if (is_file($stage.'/'.$name)) unlink($stage.'/'.$name);
    }
    rmdir($stage);
}
