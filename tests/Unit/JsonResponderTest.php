<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use ZmierzymyCzas\Support\JsonResponder;

final class JsonResponderTest extends TestCase
{
    public function testOkReturns200WithSuccessTrue(): void
    {
        $response = JsonResponder::ok(new Response(), ['id' => 'abc']);

        self::assertSame(200, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertTrue($body['success']);
        self::assertSame(['id' => 'abc'], $body['data']);
        self::assertArrayNotHasKey('message', $body);
    }

    public function testOkWithMessageIncludesMessageField(): void
    {
        $response = JsonResponder::ok(new Response(), null, 'Created', 201);

        self::assertSame(201, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertTrue($body['success']);
        self::assertSame('Created', $body['message']);
        self::assertNull($body['data']);
    }

    public function testErrorReturnsCorrectShapeAndStatus(): void
    {
        $response = JsonResponder::error(new Response(), 'not_found', 'Event not found', 404);

        self::assertSame(404, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('not_found', $body['error']['code']);
        self::assertSame('Event not found', $body['error']['message']);
        self::assertArrayNotHasKey('details', $body['error']);
    }

    public function testErrorWithDetailsIncludesDetailsField(): void
    {
        $response = JsonResponder::error(new Response(), 'validation_error', 'Invalid input', 422, ['field' => 'email']);

        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame(['field' => 'email'], $body['error']['details']);
    }

    public function testPaginatedReturnsCorrectStructure(): void
    {
        $data = [['id' => '1'], ['id' => '2']];
        $response = JsonResponder::paginated(new Response(), $data, 25, 2, 10);

        self::assertSame(200, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame($data, $body['data']);
        self::assertSame(25, $body['total']);
        self::assertSame(2, $body['page']);
        self::assertSame(10, $body['pageSize']);
        self::assertSame(3, $body['totalPages']);
    }

    public function testPaginatedTotalPagesRoundsUp(): void
    {
        $response = JsonResponder::paginated(new Response(), [], 11, 1, 10);
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame(2, $body['totalPages']);
    }

    public function testPaginatedWithZeroTotalReturnsOnePage(): void
    {
        $response = JsonResponder::paginated(new Response(), [], 0, 1, 10);
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame(1, $body['totalPages']);
    }

    public function testContentTypeHeaderIsSetToJson(): void
    {
        $response = JsonResponder::ok(new Response(), null);
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testPolishCharactersAreNotEscaped(): void
    {
        $response = JsonResponder::ok(new Response(), ['name' => 'Złoty Środek']);
        $raw = (string) $response->getBody();
        self::assertStringContainsString('Złoty Środek', $raw);
        self::assertStringNotContainsString('\\u', $raw);
    }
}
