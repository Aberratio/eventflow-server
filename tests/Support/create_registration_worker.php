<?php

declare(strict_types=1);

// Child process for RegistrationPriceFloorAndCapacityTest. argv[1] = JSON payload for
// RegistrationRepository::create(). Prints "ok" or the exception message (own PDO connection = real parallelism).

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;

require __DIR__ . '/../bootstrap.php';

try {
    $payload = json_decode((string) ($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    (new RegistrationRepository(Connection::fromEnv()))->create($payload);
    echo 'ok';
} catch (\Throwable $e) {
    echo $e->getMessage();
}
