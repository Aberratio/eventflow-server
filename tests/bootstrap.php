<?php

declare(strict_types=1);

use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\TestDatabase;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

// Must run after the .env load above and before anything under test calls
// Env::load() again (HttpTestCase::setUp does) — see TestDatabase's docblock for why
// Env::set() is the only override that survives that.
TestDatabase::applyOverrides();
TestDatabase::applySessionSqlMode();
