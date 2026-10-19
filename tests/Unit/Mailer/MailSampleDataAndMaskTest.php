<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailSampleData;
use ZmierzymyCzas\Domain\Mailer\MailRecipientMask;

final class MailSampleDataAndMaskTest extends TestCase
{
    public function testEveryCatalogVariableHasAnExplicitSample(): void
    {
        foreach (MailCatalog::all() as $mailType => $definition) {
            $samples = MailSampleData::for($mailType);
            self::assertSame($definition['variables'], array_keys($samples), $mailType);
            foreach ($samples as $name => $value) {
                self::assertNotSame('[' . $name . ']', $value, "$mailType.$name has no explicit sample value");
            }
        }
    }

    public function testOverridesWinAndUnknownMailTypeThrows(): void
    {
        self::assertSame('Bieg X', MailSampleData::for(MailCatalog::PAYMENT_CONFIRMED, ['eventName' => 'Bieg X'])['eventName']);
        $this->expectException(\InvalidArgumentException::class);
        MailSampleData::for('nope');
    }

    public function testRecipientMask(): void
    {
        self::assertSame('j***@example.com', MailRecipientMask::mask('jan.kowalski@example.com'));
        self::assertSame('ż***@example.com', MailRecipientMask::mask('żaneta@example.com'));
        self::assertSame('***', MailRecipientMask::mask('@example.com'));
        self::assertSame('***', MailRecipientMask::mask('not-an-email'));
        self::assertSame('', MailRecipientMask::mask(''));
    }
}
