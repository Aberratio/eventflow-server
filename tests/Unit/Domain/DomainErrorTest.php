<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\DomainError;
use ZmierzymyCzas\Domain\Errors\Conflict;
use ZmierzymyCzas\Domain\Errors\Forbidden;
use ZmierzymyCzas\Domain\Errors\Gone;
use ZmierzymyCzas\Domain\Errors\Internal;
use ZmierzymyCzas\Domain\Errors\InvalidInput;
use ZmierzymyCzas\Domain\Errors\NotFound;

final class DomainErrorTest extends TestCase
{
    /** @return array<string, array{class-string<DomainError>, int}> */
    public static function categories(): array
    {
        return [
            'not found' => [NotFound::class, 404],
            'conflict' => [Conflict::class, 409],
            'invalid input' => [InvalidInput::class, 422],
            'forbidden' => [Forbidden::class, 403],
            'gone' => [Gone::class, 410],
            'internal' => [Internal::class, 500],
        ];
    }

    /** @param class-string<DomainError> $class */
    #[DataProvider('categories')]
    public function testMessageIsTheCodeAndStatusMatchesTheCategory(string $class, int $status): void
    {
        $error = new $class('some_code');

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame('some_code', $error->getMessage());
        self::assertSame('some_code', $error->errorCode);
        self::assertSame($status, $error->httpStatus());
        self::assertSame([], $error->details);
    }

    public function testDetailsAreKept(): void
    {
        $error = new Conflict('event_limit_exceeded', ['limit' => 3]);

        self::assertSame(['limit' => 3], $error->details);
        self::assertSame('event_limit_exceeded', $error->getMessage());
    }

    public function testExistingRuntimeExceptionHandlersStillCatchIt(): void
    {
        try {
            throw new NotFound('registration_not_found');
        } catch (\RuntimeException $e) {
            self::assertSame('registration_not_found', $e->getMessage());
        }
    }
}
