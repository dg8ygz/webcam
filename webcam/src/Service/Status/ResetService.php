<?php
declare(strict_types=1);

namespace Webcam\Service\Status;

use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Http\HttpClient;
use Webcam\Infrastructure\Process\BoundedProcessRunner;
use Webcam\Service\Capture\CameraPrivacyState;

/**
 * Führt die für eine Kamera konfigurierten Reset-Schritte aus.
 * Shell-Schritte laufen mit hartem Timeout und begrenzter Ausgabe; Erfolg und
 * Fehler werden an StatusService und Logger zurückgegeben.
 */
final class ResetService
{
  private const SHELL_OUTPUT_MAX_BYTES = 4096;

  private readonly HttpClient $http;

  public function __construct(private readonly App $app, private readonly ?CameraPrivacyState $privacy = null)
  {
    $this->http = new HttpClient();
  }

  public function execute(Camera $camera, string $reason): void
  {
    $watchdog = $camera->status()['watchdog'] ?? null;
    if ($watchdog === null) {
      return;
    }
    // Legacy DB entries must pass the same complete validation as Admin saves.
    // No request, sleep, shell or successful-reset marker may precede this check.
    try {
      $watchdog = (new WatchdogConfigNormalizer())->normalize($watchdog);
    } catch (\InvalidArgumentException $exception) {
      $this->app->logger()->error('camera reset configuration invalid', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return;
    }
    if (empty($watchdog['enabled'])) {
      return;
    }
    // A recent authenticated helper can explicitly report a planned dark/time
    // skip. Never reset its power merely because no archive image was created.
    if (($this->privacy ?? CameraPrivacyState::forPaths($this->app->paths()))->pauseFresh($camera->slug())) {
      $this->app->logger()->info('camera reset skipped: capture privacy pause',['camera'=>$camera->slug()]);
      return;
    }
    $sequence = $watchdog['reset_sequence'] ?? [];
    if ($sequence === []) {
      return;
    }

    $this->app->logger()->warning('camera reset', [
      'camera' => $camera->slug(),
      'reason' => $reason,
    ]);

    foreach ($sequence as $step) {
      $type = $step['type'];
      if ($type === 'http-get') {
        try {
          $response = $this->http->get($step['url'], ['timeout' => $step['timeout']]);
          if (!$response->isSuccessful()) {
            throw new \RuntimeException('Reset endpoint returned HTTP ' . $response->status);
          }
        } catch (\Throwable $t) {
          $this->app->logger()->error('camera reset http-get failed', [
            'camera' => $camera->slug(),
            'url' => $step['url'],
            'error' => $t->getMessage(),
          ]);
        }
      } elseif ($type === 'delay') {
        sleep($step['seconds']);
      } elseif ($type === 'shell') {
        $this->executeShellCommand($camera, trim($step['command']), $this->shellTimeoutSeconds($step));
      }
    }

    $this->app->statusService()->recordReset($camera, $reason);
  }

  /**
   * @param array<string,mixed> $step
   */
  private function shellTimeoutSeconds(array $step): int
  {
    $configured = $step['timeout_seconds'] ?? $step['timeout'] ?? $this->app->config('watchdog_shell_timeout_seconds', 20);
    $timeout = is_numeric($configured) ? (int) $configured : 20;
    return max(1, min(WatchdogConfigNormalizer::MAX_SHELL_TIMEOUT_SECONDS, $timeout));
  }

  private function executeShellCommand(Camera $camera, string $command, int $timeoutSeconds): void
  {
    $context = [
      'camera' => $camera->slug(),
      'command_hash' => hash('sha256', $command),
      'command_preview' => $this->redactCommand($command),
      'timeout_seconds' => $timeoutSeconds,
    ];

    if (str_contains($command, "\0")) {
      $this->app->logger()->error('camera reset shell invalid command', $context);
      return;
    }
    $result = (new BoundedProcessRunner())->run(
      ['/bin/sh', '-c', $command],
      $timeoutSeconds,
      [
        'output_max_bytes' => self::SHELL_OUTPUT_MAX_BYTES,
        'output_retention' => 'tail',
        'require_process_group' => true,
      ]
    );
    if (!$result['started']) {
      $this->app->logger()->error('camera reset shell start failed', $context);
      return;
    }

    $context['exit_code'] = $result['exit_code'];
    $context['output'] = $this->limitOutput(
      trim($result['stdout'] . "\n" . $result['stderr'])
    );
    if ($result['timed_out']) {
      $this->app->logger()->error('camera reset shell timed out', $context);
      return;
    }
    if ($result['exit_code'] !== 0) {
      $this->app->logger()->error('camera reset shell failed', $context);
      return;
    }

    $this->app->logger()->info('camera reset shell ok', $context);
  }

  private function redactCommand(string $command): string
  {
    $command = preg_replace('/(pass(word)?|token|key|secret)=([^\\s&]+)/i', '$1=***', $command) ?? $command;
    $command = preg_replace('#://([^:/@\\s]+):([^@/\\s]+)@#', '://$1:***@', $command) ?? $command;
    return strlen($command) > 240 ? substr($command, 0, 237) . '...' : $command;
  }

  private function limitOutput(string $output): string
  {
    if (strlen($output) <= self::SHELL_OUTPUT_MAX_BYTES) {
      return $output;
    }
    return substr($output, -self::SHELL_OUTPUT_MAX_BYTES);
  }
}
