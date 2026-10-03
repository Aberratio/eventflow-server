<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZmierzymyCzas\Support\Log\LogConfig;
use ZmierzymyCzas\Support\Log\LoggerFactory;

final class LoggerFactoryTest extends TestCase
{
    private string $tmp;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zc-pp20-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->previousErrorLog = ini_get('error_log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        $this->removeDir($this->tmp);
    }

    public function testWritesOneJsonLinePerRecordToDatedFileInLogDir(): void
    {
        $logDir = $this->tmp . DIRECTORY_SEPARATOR . 'logs';
        $logger = LoggerFactory::create(LogConfig::fromArray(['APP_ENV' => 'production', 'LOG_DIR' => $logDir]));

        $logger->error('x', ['requestId' => 'r1']);

        $file = $logDir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log';
        self::assertFileExists($file);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        self::assertCount(1, $lines);
        $decoded = json_decode($lines[0], true);
        self::assertSame('ERROR', $decoded['level_name']);
        self::assertSame('r1', $decoded['context']['requestId']);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($file) & 0777);
        }
    }

    public function testPiiNeverReachesTheLogFile(): void
    {
        $logDir = $this->tmp . DIRECTORY_SEPARATOR . 'logs';
        $logger = LoggerFactory::create(LogConfig::fromArray(['APP_ENV' => 'production', 'LOG_DIR' => $logDir]));

        $logger->error('Failed for jan@example.test', [
            'phone' => '600700800',
            'exception' => new RuntimeException("Duplicate entry 'jan@example.test' for key 'users.email'"),
            'headers' => ['Authorization' => 'Bearer abc.def.ghi'],
        ]);

        $raw = (string) file_get_contents($logDir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log');
        self::assertNotSame('', $raw);
        foreach (['jan@example.test', '600700800', 'abc.def.ghi'] as $literal) {
            self::assertStringNotContainsString($literal, $raw);
        }
    }

    public function testFallsBackToErrorLogWhenLogDirIsNotWritable(): void
    {
        $notADir = $this->tmp . DIRECTORY_SEPARATOR . 'file.txt';
        file_put_contents($notADir, 'x');
        $errorLog = $this->tmp . DIRECTORY_SEPARATOR . 'php-error.log';
        ini_set('error_log', $errorLog);

        $logger = LoggerFactory::create(LogConfig::fromArray(['APP_ENV' => 'production', 'LOG_DIR' => $notADir]));
        $logger->error('lands in error_log', ['requestId' => 'r2']);

        $contents = (string) file_get_contents($errorLog);
        self::assertStringContainsString('lands in error_log', $contents);
        self::assertStringContainsString('LOG_DIR not writable', $contents);
    }

    public function testLoggingNeverThrowsWhenAHandlerFails(): void
    {
        $logDir = $this->tmp . DIRECTORY_SEPARATOR . 'logs';
        $logger = LoggerFactory::create(LogConfig::fromArray(['APP_ENV' => 'production', 'LOG_DIR' => $logDir]));

        // Replace the directory by a file after the logger was built.
        $this->removeDir($logDir);
        file_put_contents($logDir, 'now a file');

        $logger->error('must not throw');
        self::assertTrue(true);
    }

    public function testInvalidValuesFallBackToDefaultsWithAWarning(): void
    {
        $config = LogConfig::fromArray([
            'APP_ENV' => 'production', 'LOG_DIR' => $this->tmp . '/logs',
            'LOG_LEVEL' => 'verbose', 'LOG_MAX_FILES' => '0', 'ALERT_EMAIL' => 'nope',
        ]);

        self::assertSame('info', strtolower($config->level->getName()));
        self::assertSame(30, $config->maxFiles);
        self::assertNull($config->alertEmail);
        self::assertCount(3, $config->warnings);
        $joined = implode(' | ', $config->warnings);
        foreach (['LOG_LEVEL', 'LOG_MAX_FILES', 'ALERT_EMAIL'] as $key) {
            self::assertStringContainsString($key, $joined);
        }
        self::assertStringNotContainsString('verbose', $joined);
        self::assertStringNotContainsString('nope', $joined);

        $logger = LoggerFactory::create($config);
        $logger->info('hello');
        $contents = (string) file_get_contents($this->tmp . '/logs/app-' . date('Y-m-d') . '.log');
        self::assertSame(3, substr_count($contents, '"level_name":"WARNING"'));
    }

    public function testLocalWithoutLogDirUsesErrorLog(): void
    {
        $config = LogConfig::fromArray(['APP_ENV' => 'local']);
        self::assertNull($config->logDir);

        $errorLog = $this->tmp . DIRECTORY_SEPARATOR . 'php-error.log';
        ini_set('error_log', $errorLog);
        LoggerFactory::create($config)->error('local record');

        self::assertStringContainsString('local record', (string) file_get_contents($errorLog));
    }

    private function removeDir(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeDir($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
