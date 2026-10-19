<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * D-01 (QA-API-01): public detail routes answer 404 in the standard error
 * shape for an unknown resource (never 200 with `data: null`).
 */
final class UnknownDetailRouteContractTest extends HttpTestCase
{
    /** @return array<string, array{string}> */
    public static function unknownDetailRoutes(): array
    {
        return [
            'series by slug' => ['/api/v1/series/slug/no-such-slug-d01'],
            'series by id' => ['/api/v1/series/no-such-id-d01'],
            'product by slug' => ['/api/v1/shop/products/slug/no-such-slug-d01'],
            'product by id' => ['/api/v1/shop/products/no-such-id-d01'],
            'challenge by slug' => ['/api/v1/challenges/slug/no-such-slug-d01'],
            'challenge by id' => ['/api/v1/challenges/no-such-id-d01'],
            'info page by slug' => ['/api/v1/info-pages/slug/no-such-slug-d01'],
            'partner by id' => ['/api/v1/partners/no-such-id-d01'],
            'event background by id' => ['/api/v1/event-backgrounds/no-such-id-d01'],
        ];
    }

    /** @dataProvider unknownDetailRoutes */
    public function testUnknownResourceAnswers404(string $path): void
    {
        $response = $this->request('GET', $path);
        $payload = $this->json($response);

        self::assertSame(404, $response->getStatusCode(), $path);
        self::assertSame('not_found', $payload['error']['code'] ?? null, $path);
        self::assertArrayNotHasKey('data', $payload);
    }
}
