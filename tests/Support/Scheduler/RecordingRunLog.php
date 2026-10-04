<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support\Scheduler;

use ZmierzymyCzas\Scheduler\SchedulerRunLog;

final class RecordingRunLog extends SchedulerRunLog
{
    /** @var array<string, string> */
    public array $status = [];
    /** @var array<string, ?string> */
    public array $errorClass = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    public function started(string $job): void
    {
        $this->status[$job] = 'running';
    }

    public function finished(string $job, string $status, ?string $errorClass, ?array $counters): void
    {
        $this->status[$job] = $status;
        $this->errorClass[$job] = $errorClass;
    }
}
