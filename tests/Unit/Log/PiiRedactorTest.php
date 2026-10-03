<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZmierzymyCzas\Support\Log\PiiRedactor;

final class PiiRedactorTest extends TestCase
{
    public function testScrubsSecretsAndPersonalDataInFreeText(): void
    {
        $input = 'user jan.kowalski@example.test sent Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig '
            . 'phone +48 600 700 800 pesel 90010112345 '
            . "Duplicate entry 'Jan Kowalski' for key 'x' token=abc123";

        $out = PiiRedactor::scrubString($input);

        foreach (['jan.kowalski@example.test', 'eyJhbGciOiJIUzI1NiJ9', '600 700 800', '90010112345', 'Jan Kowalski', 'abc123'] as $literal) {
            self::assertStringNotContainsString($literal, $out);
        }
        self::assertStringContainsString('Bearer [redacted]', $out);
        self::assertStringContainsString('[phone]', $out);
        self::assertStringContainsString('[pesel]', $out);
        self::assertStringContainsString("'[redacted]'", $out);
        self::assertStringContainsString('token=[redacted]', $out);
    }

    public function testRedactsDenylistedKeysAtAnyDepth(): void
    {
        $out = PiiRedactor::redactContext([
            'user' => ['email' => 'a@b.pl', 'firstName' => 'Jan', 'birthDate' => '1990-01-01'],
            'Authorization' => 'Bearer x',
            'X-API-Key' => 'k',
            'bz_organization_token' => 't',
            'payload' => ['a' => 1],
            'fields' => ['first_name' => ['value' => 'Jan']],
        ]);

        self::assertSame('[redacted]', $out['user']['email']);
        self::assertSame('[redacted]', $out['user']['firstName']);
        self::assertSame('[redacted]', $out['user']['birthDate']);
        self::assertSame('[redacted]', $out['Authorization']);
        self::assertSame('[redacted]', $out['X-API-Key']);
        self::assertSame('[redacted]', $out['bz_organization_token']);
        self::assertSame('[redacted]', $out['payload']);
        self::assertSame('[redacted]', $out['fields']);
    }

    public function testKeepsOperationalIdentifiersAndAmounts(): void
    {
        $context = [
            'registrationId' => '3f2b8c1e-4a5d-4e6f-9a7b-1c2d3e4f5a6b',
            'transactionId' => 'TX-log',
            'expectedGrosze' => 8000,
            'notifiedGrosze' => 7999,
            'gateway' => 'tpay',
            'amount' => '30.00',
            'appliedFieldKeys' => ['first_name'],
            'eventId' => 'e', 'distanceId' => 'd', 'outcome' => 'applied', 'status' => 'x', 'changeType' => 'c',
            'fieldKeys' => ['a'], 'count' => 3, 'requestId' => 'abcdef0123456789', 'method' => 'GET',
            'path' => '/api/v1/health', 'alert' => 'http.server_error', 'http_status' => 500, 'paid' => true,
            'applied' => true, 'amountMismatch' => false,
        ];

        self::assertSame($context, PiiRedactor::redactContext($context));
        self::assertSame(
            'id 3f2b8c1e-4a5d-4e6f-9a7b-1c2d3e4f5a6b amount 8000 7999',
            PiiRedactor::scrubString('id 3f2b8c1e-4a5d-4e6f-9a7b-1c2d3e4f5a6b amount 8000 7999')
        );
    }

    public function testThrowableSummaryHasClassScrubbedMessageAndNoArguments(): void
    {
        $previousIni = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                self::throwingHelper('secret-arg@example.test');
            } catch (RuntimeException $e) {
                $wrapped = new RuntimeException('outer for jan@example.test', 5, $e);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIni);
        }

        $json = (string) json_encode(PiiRedactor::summarizeThrowable($wrapped));

        self::assertStringContainsString('RuntimeException', $json);
        self::assertStringContainsString('PiiRedactorTest.php:', $json);
        self::assertStringContainsString('previous', $json);
        self::assertStringNotContainsString('secret-arg', $json);
        self::assertStringNotContainsString('example.test', $json);
    }

    private static function throwingHelper(string $argument): never
    {
        throw new RuntimeException('failed with ' . strlen($argument) . ' chars');
    }
}
