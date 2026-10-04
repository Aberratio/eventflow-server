<?php

declare(strict_types=1);

use Monolog\ErrorHandler;
use Psr\Log\LogLevel;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\Http\CurlHttpClient as MailerCurlHttpClient;
use ZmierzymyCzas\Scheduler\SchedulerCommand;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\Log\LogConfig;
use ZmierzymyCzas\Support\Log\LoggerFactory;

// PP.18: the cron entrypoint (see docs/scheduler.md). Not reachable over HTTP (public/ is the
// docroot), but refuse anyway.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(64);
}

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

// Same logger wiring as public/index.php.
$mailerConfig = CentralMailerConfig::fromEnv();
$logger = LoggerFactory::create(
    LogConfig::fromEnv(),
    $mailerConfig->isConfigured() ? new CentralMailerClient(new MailerCurlHttpClient(3), $mailerConfig) : null,
    $mailerConfig,
);
ErrorHandler::register($logger, false, [], LogLevel::CRITICAL);

// Finish well inside the 5-minute tick.
set_time_limit(240);

exit(SchedulerCommand::main(array_slice($argv, 1), $logger));
