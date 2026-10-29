<?php
declare(strict_types=1);
// Deterministically replace an atomic capture request between lstat and open.
// Real files/flock; no GPIO, daemon, live configuration or camera access.
$source = $argv[1] ?? __DIR__.'/../scripts/camera-rpi-climate.php';
$stage = sys_get_temp_dir().'/climate-read-race-'.bin2hex(random_bytes(8));
mkdir($stage,0700);
$checks = 0;
function verify(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
try {
    $code = file_get_contents($source);
    $code = preg_replace('/^\$climateLib = .*;$/m',
        '$climateLib = '.var_export(realpath(__DIR__.'/../scripts/lib'),true).';', $code, 1, $count);
    verify($count===1,'fixture library path');
    $needle = '$before = @lstat($path);';
    verify(substr_count($code,$needle)===1,'single filesystem observation hook');
    $code = str_replace($needle, $needle.' if (isset($GLOBALS["capture_read_hook"])) { ($GLOBALS["capture_read_hook"])($path); }', $code);
    file_put_contents($stage.'/helper.php',$code);
    require $stage.'/helper.php';
    putenv('CAMERA_RPI_CLIMATE_RUNTIME='.$stage);
    $app = new ClimateApp();
    $reader = new ReflectionMethod(ClimateApp::class,'captureRequest');
    $held = fopen($stage.'/capture.lock','ce');
    $probe = fopen($stage.'/capture.lock','ce');
    flock($held,LOCK_EX);
    $old = ['token'=>str_repeat('a',32),'until'=>hrtime(true)/1e9-600];
    $fresh = ['token'=>str_repeat('b',32),'until'=>hrtime(true)/1e9+60];
    ClimateApp::atomic($stage.'/capture.request',json_encode($old));
    $replacements = 0;
    $GLOBALS['capture_read_hook'] = function(string $path) use ($stage,$fresh,&$replacements): void {
        if ($path===$stage.'/capture.request' && $replacements++===0) {
            ClimateApp::atomic($path,json_encode($fresh));
        }
    };
    [$token,$until,$busy] = $reader->invoke($app,$probe);
    verify($busy && $token===$fresh['token'] && $until===$fresh['until'],
        'atomic replacement returns fresh request without timeout');
    unset($GLOBALS['capture_read_hook']);
    putenv('CAMERA_RPI_CLIMATE_CONFIG='.$stage.'/config.json');
    $configApp = new ClimateApp();
    ClimateApp::atomic($stage.'/config.json', json_encode(ClimateConfig::DEFAULTS));
    $baseline = $configApp->loadConfig();
    $changes = 0;
    $GLOBALS['capture_read_hook'] = function(string $path) use($stage,&$changes): void {
        if ($path === $stage.'/config.json' && $changes++ === 0) {
            $config = ClimateConfig::DEFAULTS; $config['mode'] = 'manual80';
            ClimateApp::atomic($path,json_encode($config));
        }
    };
    $freshConfig = $configApp->loadConfig();
    verify($freshConfig[0]['mode']==='manual80','config publication retried');
    $changes = 0;
    $GLOBALS['capture_read_hook'] = function(string $path) use($stage,&$changes): void {
        if ($path === $stage.'/config.json') {
            $changes++; ClimateApp::atomic($path,json_encode(ClimateConfig::DEFAULTS));
        }
    };
    verify($configApp->loadConfig(true)===$freshConfig && $changes===3,'bounded race retains last daemon configuration');
    $rejected = false;
    try { $configApp->loadConfig(); } catch (ClimateSnapshotReplaced) { $rejected = true; }
    verify($rejected,'CLI cannot silently use stale configuration');
    unset($GLOBALS['capture_read_hook']);
    ClimateApp::atomic($stage.'/config.json','{bad');
    $rejected=false;
    try { $configApp->loadConfig(true); } catch (Throwable) { $rejected=true; }
    verify($rejected,'malformed configuration never falls back');

    ClimateApp::atomic($stage.'/capture.request',json_encode($old));
    verify($reader->invoke($app,$probe)===[$old['token'],$old['until'],true],
        'expired request remains visible to deadline enforcement');
    foreach (['{}','{"token":"bad","until":1}','not JSON'] as $bad) {
        ClimateApp::atomic($stage.'/capture.request',$bad);
        $rejected=false;
        try { $reader->invoke($app,$probe); } catch (Throwable) { $rejected=true; }
        verify($rejected,'malformed request still rejected');
    }
    unlink($stage.'/capture.request');
    symlink($stage.'/helper.php',$stage.'/capture.request');
    $rejected=false;
    try { $reader->invoke($app,$probe); } catch (Throwable) { $rejected=true; }
    verify($rejected,'symlink still rejected');
    unlink($stage.'/capture.request');
    verify($reader->invoke($app,$probe)===['',0,true],'publication gap has no nonce');
    ClimateApp::atomic($stage.'/capture.request',json_encode($fresh));
    $calls=0;
    $GLOBALS['capture_read_hook'] = function(string $path) use($stage,$fresh,&$calls): void {
        if ($path===$stage.'/capture.request') { $calls++; ClimateApp::atomic($path,json_encode($fresh)); }
    };
    $rejected=false;
    try { $reader->invoke($app,$probe); } catch (Throwable) { $rejected=true; }
    verify($rejected && $calls===3,'continuous replacement bounded to three reads');
    unset($GLOBALS['capture_read_hook']);
    flock($held,LOCK_UN);
    verify($reader->invoke($app,$probe)===['',0,false],'unlocked request cannot authorize pause');
    fclose($held); fclose($probe);
    echo "PASS: $checks capture snapshot race and validation checks\n";
} finally {
    unset($GLOBALS['capture_read_hook']);
    foreach (glob($stage.'/*') as $path) { unlink($path); }
    rmdir($stage);
}
