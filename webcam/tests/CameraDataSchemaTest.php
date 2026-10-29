<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Storage\CameraDataSchemaRepository;

final class CameraDataSchemaTest extends TestCase
{
  public function testDocumentedCameraSchemaMatchesRuntimeSchema(): void
  {
    $documentedSchema = (string) file_get_contents(__DIR__ . '/../database/schema.camera.sql');

    $runtimeSchema = CameraDataSchemaRepository::schemaSql();

    $this->assertSame(
      $this->normalizeSql($runtimeSchema),
      $this->normalizeSql($documentedSchema)
    );
  }

  private function normalizeSql(string $sql): string
  {
    $lines = preg_split('/\R/', $sql) ?: [];
    $kept = [];

    foreach ($lines as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, 'PRAGMA ')) {
        continue;
      }
      $kept[] = $trimmed;
    }

    return preg_replace('/\s+/', ' ', implode("\n", $kept)) ?? '';
  }
}
