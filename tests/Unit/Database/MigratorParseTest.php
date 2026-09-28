<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Migrator;

final class MigratorParseTest extends TestCase
{
    public function testGuardAppliesOnlyToNextStatement(): void
    {
        $sql = <<<SQL
        -- @guard: column-missing events.background_id
        ALTER TABLE events ADD COLUMN background_id VARCHAR(36) NULL;
        -- @guard: foreign-key-missing events.fk_events_background
        ALTER TABLE events ADD CONSTRAINT fk_events_background FOREIGN KEY (background_id) REFERENCES event_backgrounds(id);
        ALTER TABLE events MODIFY role VARCHAR(20) NOT NULL;
        SQL;

        $steps = Migrator::parse($sql);

        self::assertCount(3, $steps);
        self::assertSame('column-missing', $steps[0]['guard']['kind']);
        self::assertSame('events', $steps[0]['guard']['table']);
        self::assertSame('background_id', $steps[0]['guard']['object']);
        self::assertStringContainsString('ADD COLUMN background_id', $steps[0]['statement']);

        self::assertSame('foreign-key-missing', $steps[1]['guard']['kind']);
        self::assertStringContainsString('ADD CONSTRAINT fk_events_background', $steps[1]['statement']);

        self::assertNull($steps[2]['guard']);
        self::assertStringContainsString('MODIFY role', $steps[2]['statement']);
    }

    public function testPhpStepAndGuardOrderPreserved(): void
    {
        $sql = <<<SQL
        -- @guard: column-missing events.qr_code
        ALTER TABLE events ADD COLUMN qr_code VARCHAR(64) NULL;
        -- @php: QrCodeBackfill::registrations
        -- @guard: column-exists events.legacy_qr
        ALTER TABLE events DROP COLUMN legacy_qr;
        SQL;

        $steps = Migrator::parse($sql);

        self::assertCount(3, $steps);
        self::assertSame('sql', $steps[0]['type']);
        self::assertSame('php', $steps[1]['type']);
        self::assertSame('QrCodeBackfill::registrations', $steps[1]['step']);
        self::assertSame('sql', $steps[2]['type']);
        self::assertSame('column-exists', $steps[2]['guard']['kind']);
    }

    public function testMalformedGuardMissingColonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Migrator::parse("-- @guard column-missing events.qr_code\nALTER TABLE events ADD COLUMN qr_code VARCHAR(64);\n");
    }

    public function testMalformedGuardMissingDotThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Migrator::parse("-- @guard: column-missing events_qr_code\nALTER TABLE events ADD COLUMN qr_code VARCHAR(64);\n");
    }

    public function testUnknownKindThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Migrator::parse("-- @guard: column-vanished events.qr_code\nALTER TABLE events ADD COLUMN qr_code VARCHAR(64);\n");
    }

    public function testGuardWithoutStatementThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Migrator::parse("-- @guard: column-missing events.qr_code\n-- just a trailing comment, no statement\n");
    }

    public function testNoGuardsParsesPlainStatements(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS foo (id INT); INSERT INTO foo (id) VALUES (1);";

        $steps = Migrator::parse($sql);

        self::assertCount(2, $steps);
        self::assertNull($steps[0]['guard']);
        self::assertNull($steps[1]['guard']);
    }
}
