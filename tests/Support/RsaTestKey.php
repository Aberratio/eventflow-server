<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

/**
 * Generates a throw-away RSA key pair for signing tests (nothing is committed). Some Windows PHP builds ship
 * without a default openssl.cnf, so a few well-known locations are tried before giving up.
 */
final class RsaTestKey
{
    /** @return array{private: string, public: string} */
    public static function generate(): array
    {
        $candidates = [null, 'C:/xampp/php/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'];
        foreach ($candidates as $config) {
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            if ($config !== null) {
                if (!is_file($config)) {
                    continue;
                }
                $options['config'] = $config;
            }
            $key = @openssl_pkey_new($options);
            if ($key === false) {
                continue;
            }
            $pem = '';
            if (!@openssl_pkey_export($key, $pem, null, $config !== null ? ['config' => $config] : [])) {
                continue;
            }
            $details = openssl_pkey_get_details($key);

            return ['private' => $pem, 'public' => (string) ($details['key'] ?? '')];
        }

        throw new \RuntimeException('Cannot generate an RSA key: OpenSSL has no usable configuration.');
    }
}
