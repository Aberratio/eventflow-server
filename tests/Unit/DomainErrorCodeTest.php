<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZmierzymyCzas\Http\DomainErrorCode;

final class DomainErrorCodeTest extends TestCase
{
    public function testReturnsSnakeCaseDomainCode(): void
    {
        foreach (['file_too_large', 'not_found', 'competitor_already_merged'] as $code) {
            self::assertSame($code, DomainErrorCode::of(new RuntimeException($code)));
        }
    }

    public function testRethrowsPdoExceptionEvenWithASnakeCaseMessage(): void
    {
        $exception = new PDOException('not_found');

        $this->expectExceptionObject($exception);

        DomainErrorCode::of($exception);
    }

    #[DataProvider('nonDomainMessages')]
    public function testRethrowsNonDomainMessages(string $message): void
    {
        $exception = new RuntimeException($message);

        $this->expectExceptionObject($exception);

        DomainErrorCode::of($exception);
    }

    /** @return array<string, array{string}> */
    public static function nonDomainMessages(): array
    {
        return [
            'sqlstate' => ["SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x'"],
            'mailer body' => ['central_mailer_enqueue_failed: HTTP 500 {"to":"a@b.pl"}'],
            'empty' => [''],
            'free text' => ['Upload failed'],
            'too long' => [str_repeat('x', 81)],
        ];
    }
}
