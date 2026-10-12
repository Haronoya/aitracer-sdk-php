<?php

declare(strict_types=1);

namespace AITracer\Tests;

use AITracer\Config;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The base URL (option or AITRACER_BASE_URL) must use https;
 * http is allowed only for localhost, 127.0.0.1 and ::1.
 */
class BaseUrlTest extends TestCase
{
    private string|false $savedEnv;

    protected function setUp(): void
    {
        $this->savedEnv = getenv('AITRACER_BASE_URL');
        putenv('AITRACER_BASE_URL');
    }

    protected function tearDown(): void
    {
        putenv($this->savedEnv === false ? 'AITRACER_BASE_URL' : 'AITRACER_BASE_URL=' . $this->savedEnv);
    }

    private function makeConfig(array $options = []): Config
    {
        return new Config(array_merge(['api_key' => 'at-test-key', 'project' => 'test-project'], $options));
    }

    public static function acceptedUrls(): array
    {
        return [
            ['https://api.aitracer.co', 'https://api.aitracer.co'],
            ['https://api.aitracer.co/', 'https://api.aitracer.co'],
            ['HTTPS://example.com:8443/aitracer', 'HTTPS://example.com:8443/aitracer'],
            ['http://localhost:8000', 'http://localhost:8000'],
            ['http://LOCALHOST:8000/', 'http://LOCALHOST:8000'],
            ['http://127.0.0.1:8000', 'http://127.0.0.1:8000'],
            ['http://[::1]:8000', 'http://[::1]:8000'],
        ];
    }

    #[DataProvider('acceptedUrls')]
    public function testAcceptsOption(string $baseUrl, string $expected): void
    {
        $this->assertSame($expected, $this->makeConfig(['base_url' => $baseUrl])->baseUrl);
    }

    #[DataProvider('acceptedUrls')]
    public function testAcceptsEnvironmentVariable(string $baseUrl, string $expected): void
    {
        putenv('AITRACER_BASE_URL=' . $baseUrl);
        $this->assertSame($expected, $this->makeConfig()->baseUrl);
    }

    public function testDefaultIsHttps(): void
    {
        $this->assertSame('https://api.aitracer.co', $this->makeConfig()->baseUrl);
    }

    public static function insecureUrls(): array
    {
        return [
            ['http://api.aitracer.co'],
            ['http://192.168.0.10:8000'],
            ['http://localhost.example.com'],
            ['http://127.0.0.1.example.com'],
            ['ftp://api.aitracer.co'],
            ['ws://localhost:8000'],
        ];
    }

    #[DataProvider('insecureUrls')]
    public function testRejectsOption(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('https is required');
        $this->makeConfig(['base_url' => $baseUrl]);
    }

    #[DataProvider('insecureUrls')]
    public function testRejectsEnvironmentVariable(string $baseUrl): void
    {
        putenv('AITRACER_BASE_URL=' . $baseUrl);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('https is required');
        $this->makeConfig();
    }

    public static function malformedUrls(): array
    {
        return [['api.aitracer.co'], ['https://'], ['//api.aitracer.co']];
    }

    #[DataProvider('malformedUrls')]
    public function testRejectsMalformed(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected an absolute URL');
        $this->makeConfig(['base_url' => $baseUrl]);
    }
}
