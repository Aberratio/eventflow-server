<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Retention\RetentionConfig;
use ZmierzymyCzas\Domain\Retention\RetentionRepository;
use ZmierzymyCzas\Scheduler\Jobs\RetentionPurgeJob;
use ZmierzymyCzas\Scheduler\SchedulerJobs;
use ZmierzymyCzas\Support\Env;

/** Confidential form answers and the activity log are purged after the configured period (90 days / 12 months by default). */
final class RetentionPurgeJobTest extends TestCase
{
    private PDO $pdo;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->prefix = 'phpretention-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        Migrator::applyAll($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $like = ['p' => $this->prefix . '%'];
        $this->pdo->prepare('DELETE FROM activity_logs WHERE user_id LIKE :p')->execute($like);
        $this->pdo->prepare('DELETE FROM registrations WHERE id LIKE :p')->execute($like);
        $this->pdo->prepare('DELETE FROM form_fields WHERE id LIKE :p')->execute($like);
        $this->pdo->prepare('DELETE FROM events WHERE id LIKE :p')->execute($like);
    }

    public function testJobIsRegisteredInTheScheduler(): void
    {
        self::assertContains('retention.purge', SchedulerJobs::NAMES);
    }

    public function testDeletesConfidentialAnswersOfEventsEndedOver90DaysAgoOnly(): void
    {
        $now = new \DateTimeImmutable('2026-10-19 12:00:00');
        $oldEvent = $this->event('old', '2025-09-01');       // ended > 90 days ago
        $recentEvent = $this->event('recent', '2026-09-01');  // ended ~7 weeks ago (inside the 90-day default)
        $oldConfidential = $this->field($oldEvent, 'oldc', true);
        $oldPlain = $this->field($oldEvent, 'oldp', false);
        $recentConfidential = $this->field($recentEvent, 'recc', true);
        $oldReg = $this->registration($oldEvent, 'oldreg');
        $recentReg = $this->registration($recentEvent, 'recreg');
        $this->answer($oldConfidential, $oldReg);
        $this->answer($oldPlain, $oldReg);
        $this->answer($recentConfidential, $recentReg);

        $counters = $this->job()->run($now);

        self::assertSame(1, $counters['confidentialAnswers']);
        self::assertSame(0, $this->answerCount($oldConfidential), 'confidential answer past retention is gone');
        self::assertSame(1, $this->answerCount($oldPlain), 'non-confidential answers are not retention-bound');
        self::assertSame(1, $this->answerCount($recentConfidential), 'inside the window the answer stays');
        self::assertSame(0, $this->job()->run($now)['confidentialAnswers'], 'idempotent: second run deletes nothing');
    }

    public function testDeletesActivityLogEntriesOlderThanTwelveMonths(): void
    {
        $now = new \DateTimeImmutable('2026-10-19 12:00:00');
        $this->log('old', $now->modify('-13 months'));
        $this->log('new', $now->modify('-11 months'));

        $counters = $this->job()->run($now);

        self::assertGreaterThanOrEqual(1, $counters['activityLogs']);
        self::assertSame(0, $this->logCount('old'));
        self::assertSame(1, $this->logCount('new'));
    }

    public function testConfiguredPeriodsOverrideTheDefaults(): void
    {
        $now = new \DateTimeImmutable('2026-10-19 12:00:00');
        $event = $this->event('mid', '2026-09-01');           // ended 48 days ago
        $field = $this->field($event, 'midc', true);
        $this->answer($field, $this->registration($event, 'midreg'));
        $this->log('l4', $now->modify('-4 months'));
        $this->log('l2', $now->modify('-2 months'));
        $config = new RetentionConfig(confidentialAnswersDays: 30, activityLogMonths: 3);

        $counters = (new RetentionPurgeJob(new RetentionRepository($this->pdo), new AdminRepository($this->pdo), $config))->run($now);

        self::assertGreaterThanOrEqual(1, $counters['confidentialAnswers']);
        self::assertSame(0, $this->answerCount($field), '48 days > 30-day override');
        self::assertSame(0, $this->logCount('l4'), 'older than the 3-month override');
        self::assertSame(1, $this->logCount('l2'));
    }

    public function testDefaultPeriodsKeepFortyEightDayOldAnswersAndElevenMonthOldLogs(): void
    {
        $now = new \DateTimeImmutable('2026-10-19 12:00:00');
        $event = $this->event('mid2', '2026-09-01');
        $field = $this->field($event, 'mid2c', true);
        $this->answer($field, $this->registration($event, 'mid2reg'));
        $this->log('l11', $now->modify('-11 months'));

        $this->job()->run($now);

        self::assertSame(1, $this->answerCount($field));
        self::assertSame(1, $this->logCount('l11'));
    }

    public function testReadingTheActivityLogNoLongerDeletes(): void
    {
        $this->log('old-read', new \DateTimeImmutable('-13 months'));
        (new AdminRepository($this->pdo))->activityLogs();

        self::assertSame(1, $this->logCount('old-read'), 'purging is the scheduler job, not a side effect of GET');
    }

    private function job(): RetentionPurgeJob
    {
        return new RetentionPurgeJob(new RetentionRepository($this->pdo), new AdminRepository($this->pdo));
    }

    private function event(string $key, string $endDate): string
    {
        $id = $this->prefix . '-ev-' . $key;
        $this->pdo->prepare(
            "INSERT INTO events (id, name, slug, discipline, date, end_date, location, city, description, image_url, organizer_id, organizer_name, registration_deadline, publication_status)
             VALUES (:id, :name, :slug, 'running', :date, :end, 'Test', 'Test', '', '', 'x', 'x', :dl, 'finished')"
        )->execute(['id' => $id, 'name' => $id, 'slug' => $id, 'date' => $endDate, 'end' => $endDate, 'dl' => $endDate]);

        return $id;
    }

    private function field(string $eventId, string $key, bool $confidential): string
    {
        $id = $this->prefix . '-ff-' . $key;
        $this->pdo->prepare(
            "INSERT INTO form_fields (id, event_id, kind, field_key, storage_location, state, field_type, is_confidential)
             VALUES (:id, :e, 'custom', :k, 'registration', 'shown', 'short_text', :c)"
        )->execute(['id' => $id, 'e' => $eventId, 'k' => $id, 'c' => $confidential ? 1 : 0]);

        return $id;
    }

    private function registration(string $eventId, string $key): string
    {
        $id = $this->prefix . '-' . $key;
        $this->pdo->prepare(
            "INSERT INTO registrations (id, event_id, event_name, user_id, user_name, user_email, distance_id, distance_name, category, registered_at, status, qr_code)
             VALUES (:id, :e, 'n', 'u', 'U', 'u@example.test', 'd', 'D', 'Open', :at, 'confirmed', :qr)"
        )->execute(['id' => $id, 'e' => $eventId, 'at' => date(DATE_ATOM), 'qr' => 'ret_' . bin2hex(random_bytes(12))]);

        return $id;
    }

    private function answer(string $fieldId, string $registrationId): void
    {
        $this->pdo->prepare(
            'INSERT INTO form_field_answers (id, form_field_id, registration_id, value_text) VALUES (:id, :f, :r, :v)'
        )->execute(['id' => $this->prefix . '-' . bin2hex(random_bytes(4)), 'f' => $fieldId, 'r' => $registrationId, 'v' => 'sekret']);
    }

    private function answerCount(string $fieldId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM form_field_answers WHERE form_field_id = :f');
        $stmt->execute(['f' => $fieldId]);

        return (int) $stmt->fetchColumn();
    }

    private function log(string $key, \DateTimeImmutable $at): void
    {
        $this->pdo->prepare(
            "INSERT INTO activity_logs (id, user_id, action, description, timestamp) VALUES (:id, :u, 'server_action', 'x', :t)"
        )->execute(['id' => $this->prefix . '-l-' . $key, 'u' => $this->prefix . '-' . $key, 't' => $at->format(DATE_ATOM)]);
    }

    private function logCount(string $key): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE user_id = :u');
        $stmt->execute(['u' => $this->prefix . '-' . $key]);

        return (int) $stmt->fetchColumn();
    }
}
