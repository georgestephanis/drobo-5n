<?php
declare(strict_types=1);

namespace DroboWebui;

/**
 * One admin password (hash in config.json, set with drobo-webui-passwd), PHP sessions,
 * a CSRF token for every change, and a limit on wrong passwords per address.
 */
final class Auth
{
    private const IDLE_SECONDS = 8 * 3600;
    private const MAX_FAILS = 5;
    private const FAIL_WINDOW = 600;
    private const LOCKOUT = 300;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = VAR_DIR . '/sessions';
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_name('drobo_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
        ]);
        session_start();
        if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > self::IDLE_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function loggedIn(): bool
    {
        return !empty($_SESSION['user']);
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'];
    }

    /** For state-changing requests: right token, and (if the browser says) the same origin. */
    public static function checkCsrf(): bool
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
            return false;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $host = parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ":$p" : '');
            return strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0;
        }
        return true;
    }

    /** @return string|null error message, or null on success */
    public static function login(string $password): ?string
    {
        $cfg = Config::load();
        if (!$cfg['passwordHash']) {
            return 'No password is set yet. On the Drobo, run: sudo drobo-webui-passwd';
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
        $wait = self::lockedFor($ip);
        if ($wait > 0) {
            return 'Too many wrong passwords. Try again in ' . ceil($wait / 60) . ' minute(s).';
        }
        if (!password_verify($password, $cfg['passwordHash'])) {
            self::recordFail($ip);
            usleep(800000);
            return 'Wrong password.';
        }
        self::clearFails($ip);
        session_regenerate_id(true);
        $_SESSION['user'] = 'admin';
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function passwordSet(): bool
    {
        return (bool) Config::load()['passwordHash'];
    }

    // --- wrong-password limit, kept in a small JSON file the web server owns ---

    private static function throttleFile(): string
    {
        return VAR_DIR . '/web/throttle.json';
    }

    private static function withThrottle(callable $fn): mixed
    {
        $f = @fopen(self::throttleFile(), 'c+');
        if (!$f) {
            return $fn([])[1] ?? null;  // can't record; still answer
        }
        flock($f, LOCK_EX);
        $data = json_decode((string) stream_get_contents($f), true) ?: [];
        $now = time();
        foreach ($data as $ip => $r) {  // forget old entries
            if ($now - ($r['last'] ?? 0) > self::FAIL_WINDOW + self::LOCKOUT) {
                unset($data[$ip]);
            }
        }
        [$data, $result] = $fn($data);
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, json_encode($data));
        flock($f, LOCK_UN);
        fclose($f);
        return $result;
    }

    private static function lockedFor(string $ip): int
    {
        return (int) self::withThrottle(static function (array $d) use ($ip) {
            $r = $d[$ip] ?? null;
            $left = $r && $r['fails'] >= self::MAX_FAILS ? self::LOCKOUT - (time() - $r['last']) : 0;
            return [$d, max(0, $left)];
        });
    }

    private static function recordFail(string $ip): void
    {
        self::withThrottle(static function (array $d) use ($ip) {
            $r = $d[$ip] ?? ['fails' => 0, 'last' => 0];
            if (time() - $r['last'] > self::FAIL_WINDOW) {
                $r['fails'] = 0;
            }
            $d[$ip] = ['fails' => $r['fails'] + 1, 'last' => time()];
            return [$d, null];
        });
    }

    private static function clearFails(string $ip): void
    {
        self::withThrottle(static function (array $d) use ($ip) {
            unset($d[$ip]);
            return [$d, null];
        });
    }
}
