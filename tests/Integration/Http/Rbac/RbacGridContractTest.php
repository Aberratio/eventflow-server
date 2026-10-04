<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http\Rbac;

use PHPUnit\Framework\Attributes\DataProvider;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;
use ZmierzymyCzas\Tests\Support\SavepointPdo;

/**
 * PP.4: data-driven RBAC regression grid over every route of the real Slim route
 * collector. Every request runs inside a savepoint that is rolled back, so allowed
 * actors can exercise write routes too and every actor sees pristine fixtures.
 */
final class RbacGridContractTest extends HttpTestCase
{
    private static ?SavepointPdo $sharedPdo = null;
    private static RbacFixtures $fx;

    public static function setUpBeforeClass(): void
    {
        // A missing schema makes fixture building error out - it must never skip.
        [self::$sharedPdo, self::$fx] = SavepointPdo::openWorld(RbacFixtures::build(...));
    }

    public static function tearDownAfterClass(): void
    {
        self::$sharedPdo?->rollbackOuter();
        self::$sharedPdo = null;
    }

    protected function createPdo(): \PDO
    {
        return self::$sharedPdo ?? throw new \LogicException('The fixture world is not open.');
    }

    /** @return list<string> */
    private function registeredKeys(): array
    {
        $keys = [];
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }
                $keys[] = $method . ' ' . $route->getPattern();
            }
        }

        return $keys;
    }

    public function testEveryRegisteredRouteHasAGridRow(): void
    {
        $missing = array_values(array_diff($this->registeredKeys(), array_keys(RbacGrid::rows())));

        self::assertSame([], $missing, "Routes without an RBAC grid row - classify them in tests/Integration/Http/Rbac/RbacGrid.php:\n" . implode("\n", $missing));
    }

    public function testEveryGridRowMatchesARegisteredRoute(): void
    {
        $registered = $this->registeredKeys();
        $stale = [];
        foreach (RbacGrid::rows() as $key => $row) {
            if (!empty($row['optional'])) {
                continue;
            }
            if (!in_array($key, $registered, true)) {
                $stale[] = $key;
            }
        }

        self::assertSame([], $stale, "Grid rows without a registered route:\n" . implode("\n", $stale));
    }

    public function testEveryRowCoversEveryActorWithABasis(): void
    {
        $envGated = [];
        foreach (RbacGrid::rows() as $key => $row) {
            self::assertNotSame('', trim((string) ($row['basis'] ?? '')), "{$key}: empty basis");
            self::assertArrayHasKey($row['policy'], RbacGrid::policies() + [RbacGrid::ENV_GATED => []], "{$key}: unknown policy");
            if ($row['policy'] === RbacGrid::ENV_GATED) {
                $envGated[] = $key;
                continue;
            }
            $expected = RbacGrid::expectations($key);
            self::assertSame(RbacGrid::ACTORS, array_keys($expected), "{$key}: actors");
            foreach ($expected as $actor => $token) {
                self::assertTrue(
                    is_int($token) || in_array($token, [RbacGrid::PASS, RbacGrid::OK], true),
                    "{$key}: bad token for {$actor}"
                );
            }
        }
        self::assertSame(['POST /api/v1/dev/seed-event'], $envGated);
    }

    /** @return array<string, array{string}> */
    public static function gridRows(): array
    {
        $cases = [];
        foreach (RbacGrid::rows() as $key => $row) {
            if ($row['policy'] !== RbacGrid::ENV_GATED) {
                $cases[$key] = [$key];
            }
        }

        return $cases;
    }

    #[DataProvider('gridRows')]
    public function testRouteHonoursGrid(string $key): void
    {
        $row = RbacGrid::rows()[$key];
        [$method, $pattern] = explode(' ', $key, 2);
        $path = self::$fx->path($pattern, $row['params'] ?? []);
        $body = self::$fx->body($row['body'] ?? []);
        $expected = RbacGrid::expectations($key);

        $failures = [];
        foreach (RbacGrid::ACTORS as $actor) {
            $want = $expected[$actor];
            if ($want === RbacGrid::SKIP) {
                continue;
            }
            self::$sharedPdo->savepoint('rbac_actor');
            try {
                $headers = [];
                if ($actor !== 'anon') {
                    $identity = self::$fx->actors[$actor];
                    $headers = $this->bearer($identity['id'], $identity['role'], $identity['email']);
                }
                $response = $this->request($method, $path, $body, $headers);
                $status = $response->getStatusCode();
                $payload = json_decode((string) $response->getBody(), true);
                $code = is_array($payload) ? (string) ($payload['error']['code'] ?? '') : '';
            } finally {
                self::$sharedPdo->rollbackTo('rbac_actor');
            }

            $ok = match (true) {
                $want === RbacGrid::PASS => !in_array($status, [401, 403], true),
                $want === RbacGrid::OK => $status >= 200 && $status < 300,
                default => $status === $want,
            };
            if (!$ok) {
                $failures[] = sprintf('%s: expected %s, got %d (%s)', $actor, (string) $want, $status, $code);
            }
        }

        self::assertSame([], $failures, sprintf("%s %s\nbasis: %s\n%s", $method, $path, $row['basis'], implode("\n", $failures)));
    }

    /** @return list<string> */
    private function listedIds(string $path, ?array $identity): array
    {
        $headers = $identity === null ? [] : $this->bearer($identity['id'], $identity['role'], $identity['email']);
        $response = $this->request('GET', str_replace('{prefix}', self::$fx->prefix, $path), [], $headers);
        self::assertSame(200, $response->getStatusCode(), $path);
        $payload = $this->json($response);

        return array_map(static fn (array $e): string => (string) $e['id'], $payload['data'] ?? []);
    }

    /** @return array<string, array{string}> */
    public static function visibilityProbes(): array
    {
        return array_map(static fn (string $k): array => [$k], array_combine(array_keys(RbacGrid::visibilityProbes()), array_keys(RbacGrid::visibilityProbes())));
    }

    #[DataProvider('visibilityProbes')]
    public function testVisibilityProbesHold(string $name): void
    {
        $probe = RbacGrid::visibilityProbes()[$name];
        foreach ($probe['actors'] as $actor => $want) {
            $identity = $actor === 'anon' ? null : self::$fx->actors[$actor];
            if ($probe['kind'] === 'list') {
                $ids = $this->listedIds($probe['path'], $identity);
                self::assertContains(self::$fx->values['event'], $ids, "{$name} / {$actor}");
                self::assertNotContains(self::$fx->values['eventDraft'], $ids, "{$name} / {$actor}");
                self::assertNotContains(self::$fx->values['eventPrivate'], $ids, "{$name} / {$actor}");
                self::assertNotContains(self::$fx->values['eventDeleted'], $ids, "{$name} / {$actor}");
                continue;
            }
            if ($probe['kind'] === 'list+private') {
                $ids = $this->listedIds($probe['path'], $identity);
                self::assertContains(self::$fx->values['event'], $ids, "{$name} / {$actor}");
                self::assertContains(self::$fx->values['eventPrivate'], $ids, "{$name} / {$actor}");
                self::assertNotContains(self::$fx->values['eventDeleted'], $ids, "{$name} / {$actor}");
                continue;
            }
            $path = (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => self::$fx->values[$m[1]], $probe['path']);
            $headers = $identity === null ? [] : $this->bearer($identity['id'], $identity['role'], $identity['email']);
            $response = $this->request('GET', $path, [], $headers);
            self::assertSame($want, $response->getStatusCode(), "{$name} / {$actor}");
        }
    }

    // PP.4 tripwires T1-T3 closed by PP.55; re-add a knownGaps() provider here for the next known-open leak.

    public function testDevSeedEndpointIsClosedOutsideLocal(): void
    {
        $path = '/api/v1/dev/seed-event';
        try {
            Env::set('APP_ENV', 'production');
            foreach (RbacGrid::ACTORS as $actor) {
                self::assertSame(403, $this->seedStatus($path, $actor), "production / {$actor}");
            }

            Env::set('APP_ENV', 'staging');
            foreach (['anon', 'participant', 'owner'] as $actor) {
                self::assertSame(403, $this->seedStatus($path, $actor), "staging / {$actor}");
            }
        } finally {
            Env::restore('APP_ENV');
        }
    }

    private function seedStatus(string $path, string $actor): int
    {
        $headers = [];
        if ($actor !== 'anon') {
            $identity = self::$fx->actors[$actor];
            $headers = $this->bearer($identity['id'], $identity['role'], $identity['email']);
        }
        self::$sharedPdo->savepoint('rbac_seed');
        try {
            return $this->request('POST', $path, [], $headers)->getStatusCode();
        } finally {
            self::$sharedPdo->rollbackTo('rbac_seed');
        }
    }
}
