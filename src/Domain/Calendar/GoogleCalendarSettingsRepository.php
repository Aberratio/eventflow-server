<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Calendar;

use ZmierzymyCzas\Domain\Repository;

/** The single `google_calendar_settings` row. Holds no secrets. */
final class GoogleCalendarSettingsRepository extends Repository
{
    private const ID = '00000000-0000-0000-0000-000000000001';

    /** @return array{enabled: bool, calendarId: string, timeZone: string, lastSyncAt: ?string} */
    public function get(): array
    {
        $stmt = $this->pdo->prepare('SELECT enabled, calendar_id, time_zone, last_sync_at FROM google_calendar_settings WHERE id = :id');
        $stmt->execute(['id' => self::ID]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->pdo->prepare('INSERT IGNORE INTO google_calendar_settings (id) VALUES (:id)')->execute(['id' => self::ID]);

            return ['enabled' => false, 'calendarId' => '', 'timeZone' => 'Europe/Warsaw', 'lastSyncAt' => null];
        }

        return [
            'enabled' => (bool) $row['enabled'],
            'calendarId' => (string) $row['calendar_id'],
            'timeZone' => (string) $row['time_zone'],
            'lastSyncAt' => $row['last_sync_at'] !== null ? date(DATE_ATOM, (int) strtotime((string) $row['last_sync_at'])) : null,
        ];
    }

    /** @param array<string, mixed> $data only enabled / calendarId / timeZone are taken */
    public function update(array $data): void
    {
        $this->get();
        $set = [];
        if (array_key_exists('enabled', $data)) {
            $set['enabled'] = (bool) $data['enabled'];
        }
        if (array_key_exists('calendarId', $data)) {
            $set['calendar_id'] = trim((string) $data['calendarId']);
        }
        if (array_key_exists('timeZone', $data)) {
            $set['time_zone'] = trim((string) $data['timeZone']);
        }
        $this->updateById('google_calendar_settings', self::ID, $set);
    }

    public function touchLastSync(\DateTimeImmutable $at): void
    {
        $this->updateById('google_calendar_settings', self::ID, ['last_sync_at' => $at->format('Y-m-d H:i:s')]);
    }
}
