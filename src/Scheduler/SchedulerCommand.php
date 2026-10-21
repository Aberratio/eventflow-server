<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler;

use PDO;
use Psr\Log\LoggerInterface;
use ZmierzymyCzas\Database\Connection;

/**
 * Argv handling, wiring and exit codes of bin/scheduler.php. In-process testable.
 *
 * Exit codes: 0 ok / skipped / lock held; 1 at least one job failed; 2 database unavailable;
 * 64 usage error.
 */
final class SchedulerCommand
{
    public const EXIT_OK = 0;
    public const EXIT_JOB_FAILED = 1;
    public const EXIT_BOOTSTRAP = 2;
    public const EXIT_USAGE = 64;

    /** @param list<string> $args */
    public static function main(array $args, LoggerInterface $logger, ?PDO $pdo = null, ?callable $jobsFactory = null): int
    {
        $only = null;
        $status = false;
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                echo self::usage();

                return self::EXIT_OK;
            }
            if ($arg === '--list') {
                echo implode("\n", SchedulerJobs::NAMES) . "\n";

                return self::EXIT_OK;
            }
            if ($arg === '--status') {
                $status = true;
                continue;
            }
            if (str_starts_with($arg, '--job=')) {
                $only = substr($arg, 6);
                if (!in_array($only, SchedulerJobs::NAMES, true)) {
                    return self::usageError('Unknown job: ' . $only);
                }
                continue;
            }

            return self::usageError('Unknown option: ' . $arg);
        }

        try {
            $pdo ??= Connection::fromEnv();
        } catch (\PDOException $e) {
            $logger->critical('Scheduler could not connect to the database', ['exception' => $e, 'alert' => 'db.unavailable']);

            return self::EXIT_BOOTSTRAP;
        }

        if ($status) {
            foreach ((new SchedulerRunLog($pdo, $logger))->all() as $row) {
                echo implode("\t", [
                    (string) $row['job'], (string) $row['last_started_at'], (string) ($row['last_finished_at'] ?? '-'),
                    (string) $row['last_status'], (string) ($row['last_error_class'] ?? '-'),
                ]) . "\n";
            }

            return self::EXIT_OK;
        }

        // Wiring errors (bad env, missing class) get a defined exit code instead of an uncaught fatal.
        try {
            $jobs = $jobsFactory !== null ? $jobsFactory($pdo, $logger) : SchedulerJobs::build($pdo, $logger);
        } catch (\Throwable $e) {
            $logger->critical('Scheduler could not build its jobs', ['exception' => $e, 'alert' => 'scheduler.job_failed']);

            return self::EXIT_BOOTSTRAP;
        }

        $runner = new SchedulerRunner(
            new MysqlAdvisoryLock($pdo),
            new SchedulerRunLog($pdo, $logger),
            $logger,
            $jobs,
            $pdo,
            static function (string $line): void {
                echo $line . "\n";
            },
        );

        return $runner->run($only);
    }

    private static function usageError(string $message): int
    {
        fwrite(STDERR, $message . "\n" . self::usage());

        return self::EXIT_USAGE;
    }

    private static function usage(): string
    {
        return "Usage: php bin/scheduler.php [--list | --status | --job=<name> | --help]\n"
            . "  (no option)   run every job once, in order\n"
            . "  --job=<name>  run a single job\n"
            . "  --list        print job names (no database access)\n"
            . "  --status      print the last run of every job\n";
    }
}
