<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support\Log;

/**
 * PP.20: file-based, per-key alert throttle. One JSON map `key => {last, suppressed}` guarded
 * by flock(). Any I/O failure answers "do not send" (fail quiet: no mail storm on a broken disk).
 */
final class AlertThrottle
{
    public function __construct(private readonly string $file, private readonly int $windowSeconds)
    {
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /** @return array{send: bool, suppressed: int} */
    public function hit(string $key, int $now): array
    {
        $deny = ['send' => false, 'suppressed' => 0];

        try {
            $handle = @fopen($this->file, 'c+');
            if ($handle === false) {
                return $deny;
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    return $deny;
                }
                @chmod($this->file, 0600);

                $raw = stream_get_contents($handle);
                $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
                if (!is_array($state)) {
                    $state = [];
                }

                $entry = $state[$key] ?? null;
                $last = is_array($entry) ? (int) ($entry['last'] ?? 0) : null;
                $suppressed = is_array($entry) ? (int) ($entry['suppressed'] ?? 0) : 0;

                if ($last === null || $now - $last >= $this->windowSeconds) {
                    $decision = ['send' => true, 'suppressed' => $suppressed];
                    $state[$key] = ['last' => $now, 'suppressed' => 0];
                } else {
                    $decision = $deny;
                    $state[$key] = ['last' => $last, 'suppressed' => $suppressed + 1];
                }

                $encoded = json_encode($state);
                if ($encoded === false
                    || !ftruncate($handle, 0)
                    || fseek($handle, 0) !== 0
                    || fwrite($handle, $encoded) === false
                ) {
                    return $deny;
                }
                fflush($handle);
                flock($handle, LOCK_UN);

                return $decision;
            } finally {
                fclose($handle);
            }
        } catch (\Throwable) {
            return $deny;
        }
    }
}
