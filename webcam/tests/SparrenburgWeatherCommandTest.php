<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Process\BoundedProcessRunner;

/** Runs the real command with a private fake curl: no API, camera or live DB. */
final class SparrenburgWeatherCommandTest extends TestCase
{
    public function testSuccessKeepsWeatherFormatAndDoesNotPublishTimings(): void
    {
        $body = json_encode(['properties' => ['timeseries' => [[
            'time' => '2026-09-12T14:00:00Z',
            'data' => ['instant' => ['details' => [
                'air_temperature' => 20.5, 'air_pressure_at_sea_level' => 1015,
                'wind_speed' => 2, 'wind_from_direction' => 90,
            ]]],
        ]]]], JSON_THROW_ON_ERROR);
        $result = $this->runFixture($body, 0, 'http=200 dns=0.001 connect=0.038 total=0.200');
        self::assertSame(0, $result['exit_code']);
        self::assertSame("T:20.5C P:1015mBar W:7.2km/h O\nQuelle:www.met.no 16:00 Uhr\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testResolverTimeoutIsVisibleWithoutLeakingCurlStderr(): void
    {
        $result = $this->runFixture('', 28, 'http=000 dns=0.000000 connect=0.000000 total=3.000000');
        self::assertSame(28, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertSame("weather model: fetch failed curl_exit=28 http=000 dns=0.000000 connect=0.000000 total=3.000000\n", $result['stderr']);
    }

    public function testHttpFailureReportsStatusAndDoesNotBecomeWeather(): void
    {
        $result = $this->runFixture('server-secret-response', 22, 'http=429 dns=0.001 connect=0.040 total=0.300');
        self::assertSame(22, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('curl_exit=22 http=429', $result['stderr']);
        self::assertStringNotContainsString('server-secret-response', $result['stderr']);
    }

    public function testMissingOrUntrustedMetricsDoNotLeakIntoLog(): void
    {
        foreach (['', 'untrusted-secret', 'http=000 dns=' . str_repeat('1', 200) . ' connect=0 total=0'] as $metrics) {
            $result = $this->runFixture('', 7, $metrics);
            self::assertSame(7, $result['exit_code']);
            self::assertSame("weather model: fetch failed curl_exit=7 metrics=unavailable\n", $result['stderr']);
        }
    }

    public function testInvalidJsonAndMissingFieldsStillFailInsteadOfInventingValues(): void
    {
        foreach (['bad json', '{"properties":{}}'] as $body) {
            $result = $this->runFixture($body, 0, 'http=200 dns=0.001 connect=0.040 total=0.300');
            self::assertSame(2, $result['exit_code']);
            self::assertSame('', $result['stdout']);
            self::assertStringStartsWith('weather model:', $result['stderr']);
        }
    }

    /** The fake enforces unchanged transport limits and IPv4 on every path. */
    private function runFixture(string $body, int $code, string $metrics): array
    {
        $root = sys_get_temp_dir() . '/sparrenburg-weather-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $fake = <<<'SH'
#!/bin/bash
set -euo pipefail
[[ "$1" == --ipv4 && "$2" == -fsS ]] || exit 90
[[ "$3" == --connect-timeout && "$4" == 3 ]] || exit 91
[[ "$5" == --max-time && "$6" == 8 ]] || exit 92
[[ " $* " == *' --write-out '* ]] || exit 93
[[ " $* " != *' --insecure '* ]] || exit 94
printf '%s\n%s' "$FIXTURE_BODY" "$FIXTURE_METRICS"
printf 'private-url-token-must-not-be-logged\n' >&2
exit "$FIXTURE_EXIT"
SH;
            file_put_contents($root . '/curl', $fake);
            chmod($root . '/curl', 0700);
            $result = (new BoundedProcessRunner())->run([
                '/usr/bin/env', 'PATH=' . $root . ':/usr/bin:/bin',
                'FIXTURE_BODY=' . $body, 'FIXTURE_METRICS=' . $metrics, 'FIXTURE_EXIT=' . $code,
                '/bin/bash', dirname(__DIR__) . '/ops/weather-command-sparrenburg-metno.sh',
            ], 5, ['output_max_bytes' => 4096, 'require_process_group' => true]);
            self::assertTrue($result['started']);
            self::assertFalse($result['timed_out']);
            return $result;
        } finally {
            if (is_file($root . '/curl')) {
                unlink($root . '/curl');
            }
            rmdir($root);
        }
    }
}
