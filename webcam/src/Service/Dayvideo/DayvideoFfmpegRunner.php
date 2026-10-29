<?php
declare(strict_types=1);

namespace Webcam\Service\Dayvideo;

use Webcam\Infrastructure\Process\BoundedProcessRunner;

/** Führt ffmpeg ohne Shell, mit Timeout und begrenzter Diagnoseausgabe aus. */
final class DayvideoFfmpegRunner
{
  private const OUTPUT_MAX_BYTES = 65536;

  /**
   * @param list<string> $files
   * @return array{exit_code:int,timed_out:bool,output:list<string>}
   */
  public function run(array $files, string $target, int $width, int $fps, int $timeout, string $binary): array
  {
    if (!function_exists('proc_open')) {
      return $this->failure('proc_open is not available');
    }
    $list = tempnam(sys_get_temp_dir(), 'dayvideo_');
    if ($list === false || !$this->writeConcatFile($list, $files, $fps)) {
      if (is_string($list)) {
        @unlink($list);
      }
      return $this->failure('dayvideo concat file unavailable');
    }

    try {
      return $this->runProcess($binary, $list, $target, $width, $timeout);
    } finally {
      @unlink($list);
    }
  }

  /** @param list<string> $files */
  private function writeConcatFile(string $path, array $files, int $fps): bool
  {
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
      return false;
    }
    $duration = 1 / $fps;
    foreach ($files as $file) {
      fwrite($handle, "file '" . str_replace("'", "'\\''", $file) . "'\n");
      fwrite($handle, 'duration ' . $duration . "\n");
    }
    fwrite($handle, "file '" . str_replace("'", "'\\''", end($files)) . "'\n");
    return fclose($handle);
  }

  /** @return array{exit_code:int,timed_out:bool,output:list<string>} */
  private function runProcess(string $binary, string $list, string $target, int $width, int $timeout): array
  {
    $command = [
      $binary, '-y', '-safe', '0', '-f', 'concat', '-i', $list, '-vsync', 'vfr',
      '-vf', 'scale=min(iw\\,' . $width . '):-2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
      '-movflags', '+faststart', $target,
    ];
    $result = (new BoundedProcessRunner())->run($command, $timeout, [
      'output_max_bytes' => self::OUTPUT_MAX_BYTES,
      'output_retention' => 'tail',
      'require_process_group' => true,
    ]);
    if (!$result['started']) {
      return $this->failure('ffmpeg process could not be started');
    }
    $output = trim($result['stdout'] . "\n" . $result['stderr']);
    if (strlen($output) > self::OUTPUT_MAX_BYTES) {
      $output = substr($output, -self::OUTPUT_MAX_BYTES);
    }
    if ($result['timed_out']) {
      $output .= "\nffmpeg timed out after {$timeout}s";
    }
    $lines = preg_split('/\R/', trim($output)) ?: [];
    return [
      'exit_code' => $result['exit_code'],
      'timed_out' => $result['timed_out'],
      'output' => array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== '')),
    ];
  }

  /** @return array{exit_code:int,timed_out:bool,output:list<string>} */
  private function failure(string $message): array
  {
    return ['exit_code' => 127, 'timed_out' => false, 'output' => [$message]];
  }
}
