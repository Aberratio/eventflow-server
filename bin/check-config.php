<?php

declare(strict_types=1);

use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Config\ConfigurationException;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

try {
    $config = AppConfig::fromProcessEnv();
} catch (ConfigurationException $e) {
    echo "Configuration INVALID:\n";
    foreach ($e->errors() as $error) {
        echo '- ' . $error . "\n";
    }
    exit(1);
}

echo sprintf(
    "Configuration OK (APP_ENV=%s, CORS origins: %d, Tpay sandbox: %s, PayNow sandbox: %s).\n",
    $config->appEnv,
    count($config->corsOrigins),
    $config->tpaySandbox ? 'yes' : 'no',
    $config->paynowSandbox ? 'yes' : 'no'
);
exit(0);
