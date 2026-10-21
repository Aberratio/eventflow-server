<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Psr7\Response;
use ZmierzymyCzas\Domain\DomainError;
use ZmierzymyCzas\Domain\Errors\Conflict;
use ZmierzymyCzas\Domain\Errors\Forbidden;
use ZmierzymyCzas\Domain\Errors\Gone;
use ZmierzymyCzas\Domain\Errors\Internal;
use ZmierzymyCzas\Domain\Errors\InvalidInput;
use ZmierzymyCzas\Domain\Errors\NotFound;
use ZmierzymyCzas\Http\DomainErrorCode;
use ZmierzymyCzas\Http\DomainErrorResponder;

final class DomainErrorResponderTest extends TestCase
{
    /** @return array{int, array<string, mixed>} */
    private static function read(ResponseInterface $response): array
    {
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);

        return [$response->getStatusCode(), (array) $body['error']];
    }

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
    public function testStatusComesFromTheThrownCategory(string $class, int $status): void
    {
        [$actualStatus, $error] = self::read(DomainErrorResponder::respond(new Response(), new $class('team_not_found'), ['team_not_found']));

        self::assertSame($status, $actualStatus);
        self::assertSame('team_not_found', $error['code']);
    }

    public function testListedCodeUsesTheCatalogMessage(): void
    {
        [$status, $error] = self::read(DomainErrorResponder::respond(new Response(), new Conflict('registration_team_full'), ['registration_team_full']));

        self::assertSame(409, $status);
        self::assertSame('This team already has its maximum number of members.', $error['message']);
        self::assertArrayNotHasKey('details', $error);
    }

    public function testMessageOverrideWinsOverTheCatalog(): void
    {
        [$status, $error] = self::read(DomainErrorResponder::respond(new Response(), new Conflict('registration_capacity_exceeded'), [
            'registration_capacity_exceeded' => 'Wybrany dystans osiągnął limit uczestników.',
        ]));

        self::assertSame(409, $status);
        self::assertSame('registration_capacity_exceeded', $error['code']);
        self::assertSame('Wybrany dystans osiągnął limit uczestników.', $error['message']);
    }

    public function testStatusCodeAndDetailsOverrides(): void
    {
        $response = DomainErrorResponder::respond(new Response(), new NotFound('registration_not_found'), [
            'registration_not_found' => ['code' => 'not_found', 'message' => 'Registration not found', 'status' => 410, 'details' => ['id' => 'r1']],
        ]);

        [$status, $error] = self::read($response);

        self::assertSame(410, $status);
        self::assertSame('not_found', $error['code']);
        self::assertSame('Registration not found', $error['message']);
        self::assertSame(['id' => 'r1'], $error['details']);
    }

    public function testErrorDetailsAreForwardedWhenTheRouteGivesNone(): void
    {
        [, $error] = self::read(DomainErrorResponder::respond(new Response(), new Conflict('team_member_active', ['memberId' => 'm1']), ['team_member_active']));

        self::assertSame(['memberId' => 'm1'], $error['details']);
    }

    public function testListAndKeyedEntriesMix(): void
    {
        $handles = ['team_not_found', 'team_not_captain' => 'Only the captain.'];

        [$status, $error] = self::read(DomainErrorResponder::respond(new Response(), new Forbidden('team_not_captain'), $handles));
        self::assertSame(403, $status);
        self::assertSame('Only the captain.', $error['message']);

        [$status, $error] = self::read(DomainErrorResponder::respond(new Response(), new NotFound('team_not_found'), $handles));
        self::assertSame(404, $status);
        self::assertSame('Team was not found.', $error['message']);
    }

    public function testUnlistedDomainCodeIsRethrownWithoutAFallback(): void
    {
        $exception = new Conflict('no_event_credits');

        $this->expectExceptionObject($exception);

        DomainErrorResponder::respond(new Response(), $exception, ['team_not_found']);
    }

    public function testEmptyHandlesRethrow(): void
    {
        $exception = new InvalidInput('validation_error');

        $this->expectExceptionObject($exception);

        DomainErrorResponder::respond(new Response(), $exception);
    }

    public function testFallbackAnswersEveryOtherDomainCodeKeepingTheThrownCode(): void
    {
        [$status, $error] = self::read(DomainErrorResponder::respond(
            new Response(),
            new Conflict('discount_batch_code_space_exhausted'),
            ['validation_error'],
            ['message' => 'Nie udało się zgłosić wyniku.', 'status' => 422],
        ));

        self::assertSame(422, $status);
        self::assertSame('discount_batch_code_space_exhausted', $error['code']);
        self::assertSame('Nie udało się zgłosić wyniku.', $error['message']);
    }

    public function testFallbackMayRenameTheResponseCode(): void
    {
        [$status, $error] = self::read(DomainErrorResponder::respond(
            new Response(),
            new InvalidInput('anything'),
            [],
            ['code' => 'not_found', 'message' => 'Jeden z profili Zawodnika nie istnieje.', 'status' => 404],
        ));

        self::assertSame(404, $status);
        self::assertSame('not_found', $error['code']);
    }

    public function testListedCodeWinsOverTheFallback(): void
    {
        [$status, $error] = self::read(DomainErrorResponder::respond(
            new Response(),
            new Conflict('competitor_already_merged'),
            ['competitor_already_merged'],
            ['message' => 'Jeden z profili Zawodnika nie istnieje.', 'status' => 404],
        ));

        self::assertSame(409, $status);
        self::assertSame('Jeden z profili jest już scalony.', $error['message']);
    }

    public function testFallbackAlsoAnswersAPlainRuntimeExceptionWithADomainCode(): void
    {
        [$status, $error] = self::read(DomainErrorResponder::respond(
            new Response(),
            new RuntimeException('some_code'),
            [],
            ['message' => 'Nie udalo sie przeanalizowac importu.', 'status' => 422],
        ));

        self::assertSame(422, $status);
        self::assertSame('some_code', $error['code']);
    }

    public function testPdoExceptionIsRethrownEvenWithAFallbackAndASnakeCaseMessage(): void
    {
        $exception = new PDOException('not_found');

        $this->expectExceptionObject($exception);

        DomainErrorResponder::respond(new Response(), $exception, ['not_found'], ['message' => 'x', 'status' => 422]);
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

    #[DataProvider('nonDomainMessages')]
    public function testNonDomainMessagesAreRethrownEvenWithAFallback(string $message): void
    {
        $exception = new RuntimeException($message);

        $this->expectExceptionObject($exception);

        DomainErrorResponder::respond(new Response(), $exception, [], ['message' => 'x', 'status' => 422]);
    }

    public function testListedCodeFromAPlainRuntimeExceptionNeedsAnExplicitStatus(): void
    {
        $exception = new RuntimeException('team_not_found');

        try {
            DomainErrorResponder::respond(new Response(), $exception, ['team_not_found']);
            self::fail('A plain RuntimeException has no category, so there is no status to answer with.');
        } catch (RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }

        [$status] = self::read(DomainErrorResponder::respond(new Response(), $exception, ['team_not_found' => ['status' => 404]]));
        self::assertSame(404, $status);
    }

    public function testListedCodeWithoutACatalogMessageNeedsAnOverride(): void
    {
        $this->expectException(LogicException::class);

        DomainErrorResponder::respond(new Response(), new Conflict('no_event_credits'), ['no_event_credits']);
    }

    public function testCatalogKeysAreDomainCodesWithMessages(): void
    {
        foreach (DomainErrorResponder::CATALOG as $code => $message) {
            self::assertMatchesRegularExpression(DomainErrorCode::PATTERN, (string) $code);
            self::assertNotSame('', $message, $code);
        }
    }
}
