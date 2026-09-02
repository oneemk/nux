<?php
/**
 * Runtime bKash configuration loader.
 *
 * Secrets are never committed to Git. Configuration may be supplied through
 * environment variables or the legacy config.php $config array using the
 * ispluka_bkash_* keys. Existing MySQL configuration is untouched.
 */
class IsplukaBkashConfig
{
    public static function load()
    {
        global $config;

        $values = [
            'base_url' => self::value('ISPLUKA_BKASH_BASE_URL', 'ispluka_bkash_base_url', $config ?? []),
            'app_key' => self::value('ISPLUKA_BKASH_APP_KEY', 'ispluka_bkash_app_key', $config ?? []),
            'app_secret' => self::value('ISPLUKA_BKASH_APP_SECRET', 'ispluka_bkash_app_secret', $config ?? []),
            'username' => self::value('ISPLUKA_BKASH_USERNAME', 'ispluka_bkash_username', $config ?? []),
            'password' => self::value('ISPLUKA_BKASH_PASSWORD', 'ispluka_bkash_password', $config ?? []),
        ];

        $values['timeout'] = max(1, (int) self::value('ISPLUKA_BKASH_TIMEOUT', 'ispluka_bkash_timeout', $config ?? [], 20));
        $verifyTls = self::value('ISPLUKA_BKASH_VERIFY_TLS', 'ispluka_bkash_verify_tls', $config ?? [], true);
        $values['verify_tls'] = !in_array(strtolower(trim((string) $verifyTls)), ['0', 'false', 'no', 'off'], true);

        foreach (['base_url', 'app_key', 'app_secret', 'username', 'password'] as $required) {
            if (trim((string) $values[$required]) === '') {
                throw new RuntimeException('bKash configuration is incomplete: ' . $required . ' is missing.');
            }
        }

        if (!filter_var($values['base_url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('bKash base URL is invalid.');
        }

        return $values;
    }

    private static function value($envKey, $configKey, array $config, $default = '')
    {
        $env = getenv($envKey);
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }

        if (isset($config[$configKey]) && trim((string) $config[$configKey]) !== '') {
            return trim((string) $config[$configKey]);
        }

        return $default;
    }
}
