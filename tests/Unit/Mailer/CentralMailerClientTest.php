<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\IdempotencyConflictException;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\RecipientSuppressedException;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

final class CentralMailerClientTest extends TestCase
{
    private FakeCentralMailerHttpClient $http;
    private CentralMailerClient $client;

    protected function setUp(): void
    {
        $this->http = new FakeCentralMailerHttpClient();
        $config = new CentralMailerConfig(apiKey: 'app-key-123', baseUrl: 'https://mailer.example.test');
        $this->client = new CentralMailerClient($this->http, $config);
    }

    public function testEnqueueBuildsRequestPerContractAndReturnsIdAndStatus(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'em-1', 'status' => 'pending'])));

        $result = $this->client->enqueue([
            'to' => 'runner@example.test',
            'subject' => 'Zapis potwierdzony',
            'html' => '<p>hi</p>',
            'contextId' => 'evt-123',
        ], 'zapis-potwierdzenie:reg-1');

        self::assertSame(['id' => 'em-1', 'status' => 'pending'], $result);
        self::assertCount(1, $this->http->calls);
        $call = $this->http->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://mailer.example.test/emails', $call['url']);
        self::assertSame('app-key-123', $call['headers']['X-API-Key']);
        self::assertSame('zapis-potwierdzenie:reg-1', $call['headers']['Idempotency-Key']);

        $sent = json_decode((string) $call['body'], true);
        self::assertSame('runner@example.test', $sent['to']);
        self::assertSame('Zapis potwierdzony', $sent['subject']);
        self::assertSame('<p>hi</p>', $sent['html']);
        self::assertSame('normal', $sent['priority']);
        self::assertSame('evt-123', $sent['contextId']);
        self::assertArrayNotHasKey('category', $sent);
    }

    public function testEnqueueOmitsIdempotencyKeyHeaderWhenNoneGiven(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'em-1', 'status' => 'pending'])));

        $this->client->enqueue(['to' => 'a@b.test', 'subject' => 's', 'html' => '<p>x</p>']);

        self::assertArrayNotHasKey('Idempotency-Key', $this->http->calls[0]['headers']);
    }

    public function testEnqueueWithTechnicalPriorityForcesCategoryTransactional(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'em-2', 'status' => 'pending'])));

        $this->client->enqueue([
            'to' => 'user@example.test',
            'subject' => 'Reset hasła',
            'html' => '<p>link</p>',
            'priority' => 'technical',
            'category' => 'marketing', // must be overridden — §1.2
        ], 'reset-hasla:user-1:token-1');

        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertSame('technical', $sent['priority']);
        self::assertSame('transactional', $sent['category']);
    }

    public function testEnqueueRejectsUnknownPriority(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->enqueue(['to' => 'a@b.test', 'subject' => 's', 'html' => '<p>x</p>', 'priority' => 'urgent']);
    }

    public function testEnqueueThrowsIdempotencyConflictOn409(): void
    {
        $this->http->queueResponse(new HttpResponse(409, json_encode(['error' => 'idempotency_conflict'])));

        $this->expectException(IdempotencyConflictException::class);
        $this->client->enqueue(['to' => 'a@b.test', 'subject' => 's', 'html' => '<p>x</p>'], 'same-key');
    }

    public function testEnqueueThrowsRecipientSuppressedOn422(): void
    {
        $this->http->queueResponse(new HttpResponse(422, json_encode(['error' => 'recipient_suppressed'])));

        $this->expectException(RecipientSuppressedException::class);
        $this->client->enqueue(['to' => 'blocked@example.test', 'subject' => 's', 'html' => '<p>x</p>']);
    }

    public function testEnqueueThrowsOnOtherFailureStatus(): void
    {
        $this->http->queueResponse(new HttpResponse(500, 'server error'));

        $this->expectException(\RuntimeException::class);
        $this->client->enqueue(['to' => 'a@b.test', 'subject' => 's', 'html' => '<p>x</p>']);
    }

    public function testEnqueueThrowsOnMalformedResponse(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['status' => 'pending'])));

        $this->expectException(\RuntimeException::class);
        $this->client->enqueue(['to' => 'a@b.test', 'subject' => 's', 'html' => '<p>x</p>']);
    }

    public function testGetStatusFetchesById(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['id' => 'em-1', 'status' => 'sent'])));

        $result = $this->client->getStatus('em-1');

        self::assertSame('GET', $this->http->calls[0]['method']);
        self::assertSame('https://mailer.example.test/emails/em-1', $this->http->calls[0]['url']);
        self::assertSame('app-key-123', $this->http->calls[0]['headers']['X-API-Key']);
        self::assertSame(['id' => 'em-1', 'status' => 'sent'], $result);
    }

    public function testGetStatusByContextFetchesWithQueryParams(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['total' => 2, 'statusCounts' => ['sent' => 2]])));

        $result = $this->client->getStatusByContext('evt-123', 10, 5);

        self::assertSame('https://mailer.example.test/emails/context/evt-123?limit=10&offset=5', $this->http->calls[0]['url']);
        self::assertSame(['total' => 2, 'statusCounts' => ['sent' => 2]], $result);
    }
}
