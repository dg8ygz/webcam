<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\View;

use RuntimeException;

/**
 * Rendert ein fachliches Template und bettet dessen HTML in das gemeinsame Layout.
 *
 * extract() verwendet EXTR_SKIP, damit übergebene Daten keine internen Variablen
 * überschreiben. Output-Buffer fangen echo-Ausgaben der Templates als String ab;
 * der aufrufende Entry-Point entscheidet anschließend über Cache und Response.
 */
final class Renderer
{
  public function __construct(
    private readonly string $templatePath,
    private readonly ?string $layout = 'layout'
  ) {}

  /**
   * @param string $template Dateiname ohne .php
   * @param array<string,mixed> $data
   */
  public function render(string $template, array $data = []): string
  {
    $templateFile = $this->templatePath . '/' . $template . '.php';
    if (!is_file($templateFile)) {
      throw new RuntimeException("Template not found: {$templateFile}");
    }
    extract($data, EXTR_SKIP);
    ob_start();
    include $templateFile;
    $content = (string)ob_get_clean();
    if ($this->layout === null) {
      return $content;
    }
    $layoutFile = $this->templatePath . '/' . $this->layout . '.php';
    if (!is_file($layoutFile)) {
      throw new RuntimeException("Layout not found: {$layoutFile}");
    }
    ob_start();
    include $layoutFile;
    return (string)ob_get_clean();
  }
}
