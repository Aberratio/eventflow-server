<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Calendar;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Bz\Http\HttpResponse;
use ZmierzymyCzas\Domain\Calendar\GoogleCalendarClient;
use ZmierzymyCzas\Domain\Calendar\GoogleCalendarConfig;
use ZmierzymyCzas\Domain\Calendar\GoogleCalendarEventMapper;
use ZmierzymyCzas\Tests\Support\FakeBzHttpClient;
use ZmierzymyCzas\Tests\Support\RsaTestKey;

final class GoogleCalendarClientTest extends TestCase
{
    private string $privatePem;
    private string $publicPem;
    private FakeBzHttpClient $http;
    private GoogleCalendarClient $client;
    private int $now;

    protected function setUp(): void
    {
        $this->now = time();
        $pair = RsaTestKey::generate();
        $this->privatePem = $pair['private'];
        $this->publicPem = $pair['public'];
        $this->http = new FakeBzHttpClient();
        $this->client = new GoogleCalendarClient(
            $this->http,
            new GoogleCalendarConfig('svc@proj.iam.gserviceaccount.com', $this->privatePem),
            fn (): int => $this->now
        );
    }

    public function testCreateEventSignsRs256AssertionAndPostsToCalendarApi(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(200, json_encode(['id' => 'g-evt-1'])));

        $id = $this->client->createEvent('cal@group.calendar.google.com', ['summary' => 'Bieg']);

        self::assertSame('g-evt-1', $id);
        [$token, $api] = $this->http->calls;

        self::assertSame('POST', $token['method']);
        self::assertSame('https://oauth2.googleapis.com/token', $token['url']);
        parse_str((string) $token['body'], $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $claims = (array) JWT::decode($form['assertion'], new Key($this->publicPem, 'RS256'));
        self::assertSame('svc@proj.iam.gserviceaccount.com', $claims['iss']);
        self::assertSame('https://www.googleapis.com/auth/calendar.events', $claims['scope']);
        self::assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        self::assertSame($this->now + 3600, $claims['exp']);

        self::assertSame('POST', $api['method']);
        self::assertSame('https://www.googleapis.com/calendar/v3/calendars/cal%40group.calendar.google.com/events', $api['url']);
        self::assertSame('Bearer tok-1', $api['headers']['Authorization']);
        self::assertSame(['summary' => 'Bieg'], json_decode((string) $api['body'], true));
    }

    public function testAccessTokenIsReusedUntilNearExpiry(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(200, json_encode(['id' => 'a'])));
        $this->http->queueResponse(new HttpResponse(200, json_encode(['id' => 'a'])));
        $this->client->createEvent('c', []);
        $this->client->updateEvent('c', 'a', []);

        self::assertCount(3, $this->http->calls, 'one token call + two API calls');
        self::assertSame('PUT', $this->http->calls[2]['method']);
        self::assertStringEndsWith('/events/a', $this->http->calls[2]['url']);
    }

    public function testDeleteEventSendsDeleteAndTreats404And410AsSuccess(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 't', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(204, ''));
        $this->http->queueResponse(new HttpResponse(404, 'not found'));
        $this->http->queueResponse(new HttpResponse(410, 'gone'));

        $this->client->deleteEvent('cal@group.calendar.google.com', 'g-evt-1');
        $this->client->deleteEvent('cal@group.calendar.google.com', 'g-evt-1');
        $this->client->deleteEvent('cal@group.calendar.google.com', 'g-evt-1');

        $api = $this->http->calls[1];
        self::assertSame('DELETE', $api['method']);
        self::assertSame('https://www.googleapis.com/calendar/v3/calendars/cal%40group.calendar.google.com/events/g-evt-1', $api['url']);
        self::assertNull($api['body']);
    }

    public function testDeleteEventStillThrowsOnOtherErrors(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 't', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(500, 'boom'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $this->client->deleteEvent('c', 'g');
    }

    public function testNon2xxFromApiThrows(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 't', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(403, 'forbidden'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');
        $this->client->createEvent('c', []);
    }

    public function testTokenFailureThrowsWithoutLeakingBody(): void
    {
        $this->http->queueResponse(new HttpResponse(400, 'invalid_grant secret-detail'));
        try {
            $this->client->createEvent('c', []);
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('google_calendar_token_failed', $e->getMessage());
            self::assertStringNotContainsString('secret-detail', $e->getMessage());
        }
    }

    public function testConfigFromJsonRequiresEmailAndKey(): void
    {
        self::assertNull(GoogleCalendarConfig::fromJson('not json'));
        self::assertNull(GoogleCalendarConfig::fromJson('{"client_email":"a@b"}'));
        $config = GoogleCalendarConfig::fromJson('{"client_email":"a@b","private_key":"k"}');
        self::assertSame('https://oauth2.googleapis.com/token', $config?->tokenUri);
    }

    public function testMapperBuildsAllDayEventWithExclusiveEndAndLink(): void
    {
        $body = GoogleCalendarEventMapper::map(
            ['name' => 'Bieg', 'slug' => 'bieg-1', 'location' => 'Stadion', 'city' => 'Poznan', 'date' => '2026-09-20', 'startDate' => '2026-09-20', 'endDate' => '2026-09-21'],
            'Europe/Warsaw',
            'https://app.example.test/'
        );

        self::assertSame('Bieg', $body['summary']);
        self::assertSame('Stadion, Poznan', $body['location']);
        self::assertSame(['date' => '2026-09-20'], $body['start']);
        self::assertSame(['date' => '2026-09-22'], $body['end']);
        self::assertSame('https://app.example.test/events/bieg-1', $body['description']);
    }

    public function testMapperUsesTimedEventWhenDateHasTimeAndNullWithoutDate(): void
    {
        $body = GoogleCalendarEventMapper::map(['name' => 'N', 'slug' => 's', 'startDate' => '2026-09-20T09:30:00'], 'Europe/Warsaw', 'https://a.test');
        self::assertSame('2026-09-20T09:30:00+02:00', $body['start']['dateTime']);
        self::assertSame('2026-09-20T12:30:00+02:00', $body['end']['dateTime']);
        self::assertNull(GoogleCalendarEventMapper::map(['name' => 'N', 'slug' => 's'], 'Europe/Warsaw', 'https://a.test'));
    }
}
