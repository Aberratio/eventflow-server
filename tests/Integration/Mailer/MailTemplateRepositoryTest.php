<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Mailer;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E1.10 §5 (model szablonów) against a real `mail_templates` table: global vs
 * per-event override resolution, the silent fallback chain (override -> global
 * -> MailCatalog's built-in default), validation of unknown variables at save
 * time, and the systemic-mail-types-reject-an-override guard.
 */
final class MailTemplateRepositoryTest extends TestCase
{
    private PDO $pdo;
    private MailTemplateRepository $templates;
    private string $eventId;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->eventId = 'phpunit-mt-' . bin2hex(random_bytes(4));
            $this->templates = new MailTemplateRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for mail template tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM mail_templates WHERE event_id = :eventId')->execute(['eventId' => $this->eventId]);
        $this->pdo->prepare("DELETE FROM mail_templates WHERE mail_type = :mailType AND event_id = ''")
            ->execute(['mailType' => MailCatalog::REGISTRATION_CREATED]);
    }

    public function testResolveFallsBackToCatalogDefaultWhenNothingIsSaved(): void
    {
        $resolved = $this->templates->resolve(MailCatalog::REGISTRATION_CREATED, $this->eventId);

        self::assertSame('catalog_default', $resolved['source']);
        self::assertSame(1, $resolved['version']);
        self::assertSame(MailCatalog::get(MailCatalog::REGISTRATION_CREATED)['defaultSubject'], $resolved['subject']);
    }

    public function testResolveUsesGlobalDefaultWhenNoEventOverrideExists(): void
    {
        $this->templates->saveGlobal(MailCatalog::REGISTRATION_CREATED, 'Global subject {{eventName}}', '<p>{{eventName}}</p>', 'admin-1');

        $resolved = $this->templates->resolve(MailCatalog::REGISTRATION_CREATED, $this->eventId);

        self::assertSame('global', $resolved['source']);
        self::assertSame('Global subject {{eventName}}', $resolved['subject']);
    }

    public function testEventOverrideWinsOverGlobalDefault(): void
    {
        $this->templates->saveGlobal(MailCatalog::REGISTRATION_CREATED, 'Global', '<p>{{eventName}}</p>', 'admin-1');
        $this->templates->saveEventOverride(MailCatalog::REGISTRATION_CREATED, $this->eventId, 'Organizer subject', '<p>{{eventName}}</p>', 'organizer-1');

        $resolved = $this->templates->resolve(MailCatalog::REGISTRATION_CREATED, $this->eventId);

        self::assertSame('event_override', $resolved['source']);
        self::assertSame('Organizer subject', $resolved['subject']);
    }

    public function testDeletingOverrideFallsBackToGlobalAgain(): void
    {
        $this->templates->saveGlobal(MailCatalog::REGISTRATION_CREATED, 'Global', '<p>x</p>', 'admin-1');
        $this->templates->saveEventOverride(MailCatalog::REGISTRATION_CREATED, $this->eventId, 'Override', '<p>x</p>', 'organizer-1');

        $this->templates->deleteEventOverride(MailCatalog::REGISTRATION_CREATED, $this->eventId);

        self::assertSame('global', $this->templates->resolve(MailCatalog::REGISTRATION_CREATED, $this->eventId)['source']);
    }

    public function testSavingAnUnknownVariableIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->templates->saveGlobal(MailCatalog::REGISTRATION_CREATED, 'Hi {{totallyUnknownVar}}', '<p>x</p>', 'admin-1');
    }

    public function testSystemicMailTypeRejectsAnEventOverride(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->templates->saveEventOverride(MailCatalog::PASSWORD_RESET, $this->eventId, 'Hack', '<p>{{resetLink}}</p>', 'organizer-1');
    }

    public function testSavingTheSameScopeTwiceIncrementsVersion(): void
    {
        $first = $this->templates->saveGlobal(MailCatalog::REGISTRATION_CREATED, 'V1', '<p>x</p>', 'admin-1');
        $second = $this->templates->saveGlobal(MailCatalog::REGISTRATION_CREATED, 'V2', '<p>x</p>', 'admin-1');

        self::assertSame(1, (int) $first['version']);
        self::assertSame(2, (int) $second['version']);
    }
}
