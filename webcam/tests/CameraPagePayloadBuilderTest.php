<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\View\CameraPagePayloadBuilder;

/**
 * Verhindert ein erneutes Auseinanderlaufen von Früh- und Normalrender.
 *
 * Hier werden bewusst keine Templates gerendert. Geprüft wird die gemeinsame
 * Schnittstelle unmittelbar vor dem Renderer; die HTTP-Smoke-Tests decken die
 * anschließende Integration mit dem echten Kamera-Template ab.
 */
final class CameraPagePayloadBuilderTest extends TestCase
{
    public function testBuildAddsCompleteHarmlessDefaults(): void
    {
        $builder = new CameraPagePayloadBuilder();
        $payload = $builder->build(
            ['slug' => 'demo', 'hasInfoHtml' => true],
            ['viewMode' => 'bestof', 'bestOf' => [['relative_path' => 'one.jpg']]],
        );

        $this->assertSame('demo', $payload['slug']);
        $this->assertSame('bestof', $payload['viewMode']);
        $this->assertSame([['relative_path' => 'one.jpg']], $payload['bestOf']);
        $this->assertSame(['has_data' => false], $payload['timelineNav']);
        $this->assertNull($payload['snapshot']);
        $this->assertContains('js/camera.js', $payload['scripts']);
        $this->assertNotContains('js/camera-map.js', $payload['scripts']);
    }

    public function testConditionalScriptsAreAppendedOnceAndInOrder(): void
    {
        $builder = new CameraPagePayloadBuilder();
        $payload = $builder->build([], [
            'viewMode' => 'global-bestof',
            'enableLeaflet' => true,
        ]);

        $this->assertSame('js/camera-map.js', $payload['scripts'][count($payload['scripts']) - 2]);
        $this->assertSame('js/global-bestof.js', $payload['scripts'][count($payload['scripts']) - 1]);
        $this->assertSame(count($payload['scripts']), count(array_unique($payload['scripts'])));
    }

    public function testViewPayloadReplacesWholeNestedViewModels(): void
    {
        $builder = new CameraPagePayloadBuilder();
        $timeline = ['entries' => [['timestamp' => 123]]];
        $sidebar = ['weather' => '20 °C'];

        $payload = $builder->build([], [
            'timeline' => $timeline,
            'sidebarDownloads' => $sidebar,
        ]);

        $this->assertSame($timeline, $payload['timeline']);
        $this->assertSame($sidebar, $payload['sidebarDownloads']);
    }
}
