<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublicCsrfGuardTest extends TestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];
    private array $cookieBackup = [];
    private array $filesBackup = [];
    private string|false $envBackup = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->cookieBackup = $_COOKIE;
        $this->filesBackup = $_FILES;
        $this->envBackup = getenv('WEBCAM_ENV');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_COOKIE = $this->cookieBackup;
        $_FILES = $this->filesBackup;

        if ($this->envBackup === false) {
            putenv('WEBCAM_ENV');
        } else {
            putenv('WEBCAM_ENV=' . $this->envBackup);
        }

        parent::tearDown();
    }

    public function testLikeEndpointRejectsTestFlagWithoutTestEnvironment(): void
    {
        putenv('WEBCAM_ENV=prod');

        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'localhost',
        ];
        $_GET = ['test' => '1'];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        http_response_code(200);
        ob_start();
        include __DIR__ . '/../like.php';
        $output = (string) ob_get_clean();

        $this->assertSame(403, http_response_code());
        $this->assertStringContainsString('CSRF', $output);
        $this->assertStringContainsString('Origin', $output);
    }
}
