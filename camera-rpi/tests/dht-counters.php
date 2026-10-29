#!/usr/bin/env php
<?php
declare(strict_types=1);
// Compile the real kernel control-flow against bounded fake IRQ/GPIO calls.
$base=dirname(__DIR__); $source=file_get_contents($base.'/ops/dht-driver-debug/dht11.c');
$stage=sys_get_temp_dir().'/dht-counter-test-'.bin2hex(random_bytes(6)); mkdir($stage,0700);
try {
    foreach (['decoder-under-test.inc'=>['static unsigned char dht11_decode_byte(',"/*\n * IRQ handler called on GPIO edges"],
        'read-under-test.inc'=>['static int dht11_read_raw(', '/* One coherent read-only snapshot']] as $name=>[$a,$b]) {
        $start=strpos($source,$a); $end=strpos($source,$b,$start ?: 0);
        if ($start===false || $end===false) throw new RuntimeException('Driver test boundary changed');
        file_put_contents($stage.'/'.$name,substr($source,$start,$end-$start));
    }
    $cmd=['cc','-std=c11','-Wall','-Wextra','-Werror','-Wno-unused-parameter','-I'.$stage,
        '-I'.$base.'/ops/dht-driver-debug',$base.'/tests/dht-counters.c','-o',$stage.'/test'];
    passthru(implode(' ',array_map('escapeshellarg',$cmd)),$rc);
    if ($rc) throw new RuntimeException('Counter harness compilation failed');
    passthru(escapeshellarg($stage.'/test').' '.escapeshellarg($base.'/tests/fixtures/dht-edges-20260913.tsv'),$rc);
    if ($rc) throw new RuntimeException('Counter checks failed');
} finally {
    foreach (['decoder-under-test.inc','read-under-test.inc','test'] as $name) if (is_file($stage.'/'.$name)) unlink($stage.'/'.$name);
    rmdir($stage);
}
