<?php
declare(strict_types=1);

namespace DroboWebui;

use InvalidArgumentException;

/**
 * /etc/drobo-webui/config.json: the web UI's own settings (login, display, e-mail alerts).
 * root:www-data 0640. The web front end only reads it; every change goes through the helper.
 */
final class Config
{
    public const DEFAULTS = [
        'version' => 1,
        'passwordHash' => null,
        'ui' => [
            'refreshSeconds' => 5,
            'tempUnit' => 'C',
            'publicStatus' => false,        // let anyone on the network see the status page without signing in
        ],
        'alerts' => [
            'enabled' => false,
            'minLevel' => 'warning',        // critical | warning | info
            'to' => '',
            'from' => '',
            'server' => '',
            'port' => 587,
            'security' => 'starttls',       // none | starttls | ssl
            'username' => '',
            'password' => '',
            'diskTempMax' => 50,
            'socTempMax' => 75,
            'capacityPct' => 85,
        ],
    ];

    public static function path(): string
    {
        return ETC_DIR . '/config.json';
    }

    public static function load(): array
    {
        $raw = @file_get_contents(self::path());
        $data = $raw === false ? [] : json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        return self::merge(self::DEFAULTS, $data);
    }

    /** Write atomically, keeping root:www-data 0640. Root only. */
    public static function save(array $config): void
    {
        $tmp = self::path() . '.new';
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException('cannot write ' . $tmp);
        }
        @chown($tmp, 'root');
        @chgrp($tmp, 'www-data');
        chmod($tmp, 0640);
        rename($tmp, self::path());
    }

    /** What the browser may see: everything except secrets. */
    public static function public(array $config): array
    {
        $c = $config;
        unset($c['passwordHash']);
        $c['alerts']['passwordSet'] = $c['alerts']['password'] !== '';
        unset($c['alerts']['password']);
        return $c;
    }

    /**
     * Validate a settings update from the browser and merge it into $config.
     * Unknown keys are ignored; bad values throw with a message meant for the user.
     */
    public static function applyUpdate(array $config, array $in): array
    {
        if (isset($in['ui']) && is_array($in['ui'])) {
            $ui = $in['ui'];
            if (array_key_exists('refreshSeconds', $ui)) {
                $config['ui']['refreshSeconds'] = self::int($ui['refreshSeconds'], 2, 120, 'Refresh interval');
            }
            if (array_key_exists('tempUnit', $ui)) {
                $config['ui']['tempUnit'] = self::oneOf($ui['tempUnit'], ['C', 'F'], 'Temperature unit');
            }
            if (array_key_exists('publicStatus', $ui)) {
                $config['ui']['publicStatus'] = (bool) $ui['publicStatus'];
            }
        }
        if (isset($in['alerts']) && is_array($in['alerts'])) {
            $a = $in['alerts'];
            $cur = &$config['alerts'];
            if (array_key_exists('enabled', $a)) {
                $cur['enabled'] = (bool) $a['enabled'];
            }
            if (array_key_exists('minLevel', $a)) {
                $cur['minLevel'] = self::oneOf($a['minLevel'], ['critical', 'warning', 'info'], 'Alert level');
            }
            foreach (['to' => 'To address', 'from' => 'From address'] as $k => $label) {
                if (array_key_exists($k, $a)) {
                    $v = trim((string) $a[$k]);
                    if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                        throw new InvalidArgumentException("$label is not an e-mail address");
                    }
                    $cur[$k] = $v;
                }
            }
            if (array_key_exists('server', $a)) {
                $v = trim((string) $a['server']);
                if ($v !== '' && !preg_match('/^[A-Za-z0-9.-]{1,253}$/', $v)) {
                    throw new InvalidArgumentException('Mail server must be a host name or IP address');
                }
                $cur['server'] = $v;
            }
            if (array_key_exists('port', $a)) {
                $cur['port'] = self::int($a['port'], 1, 65535, 'Mail port');
            }
            if (array_key_exists('security', $a)) {
                $cur['security'] = self::oneOf($a['security'], ['none', 'starttls', 'ssl'], 'Connection security');
            }
            if (array_key_exists('username', $a)) {
                $cur['username'] = self::line($a['username'], 'User name');
            }
            // An empty password field means "keep the saved one"; clearPassword removes it.
            if (!empty($a['clearPassword'])) {
                $cur['password'] = '';
            } elseif (isset($a['password']) && $a['password'] !== '') {
                $cur['password'] = self::line($a['password'], 'Password');
            }
            if (array_key_exists('diskTempMax', $a)) {
                $cur['diskTempMax'] = self::int($a['diskTempMax'], 30, 70, 'Disk temperature limit');
            }
            if (array_key_exists('socTempMax', $a)) {
                $cur['socTempMax'] = self::int($a['socTempMax'], 50, 100, 'Processor temperature limit');
            }
            if (array_key_exists('capacityPct', $a)) {
                $cur['capacityPct'] = self::int($a['capacityPct'], 50, 99, 'Capacity warning');
            }
            unset($cur);
            if ($config['alerts']['enabled'] && ($config['alerts']['to'] === '' || $config['alerts']['server'] === '')) {
                throw new InvalidArgumentException('Alerts need a To address and a mail server');
            }
        }
        return $config;
    }

    public static function int(mixed $v, int $min, int $max, string $label): int
    {
        if (is_string($v)) {
            $v = trim($v);
        }
        if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v))) {
            throw new InvalidArgumentException("$label must be a whole number");
        }
        $v = (int) $v;
        if ($v < $min || $v > $max) {
            throw new InvalidArgumentException("$label must be between $min and $max");
        }
        return $v;
    }

    public static function oneOf(mixed $v, array $allowed, string $label): string
    {
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw new InvalidArgumentException("$label must be one of: " . implode(', ', $allowed));
        }
        return $v;
    }

    /** One line of printable text, at most 200 bytes. */
    public static function line(mixed $v, string $label): string
    {
        $v = (string) $v;
        if (strlen($v) > 200 || preg_match('/[\x00-\x1f\x7f]/', $v)) {
            throw new InvalidArgumentException("$label must be one line of text");
        }
        return $v;
    }

    private static function merge(array $defaults, array $data): array
    {
        foreach ($defaults as $k => $v) {
            if (is_array($v) && isset($data[$k]) && is_array($data[$k])) {
                $defaults[$k] = self::merge($v, $data[$k]);
            } elseif (array_key_exists($k, $data) && !is_array($data[$k])) {
                $defaults[$k] = $data[$k];
            }
        }
        return $defaults;
    }
}
