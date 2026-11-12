<?php
/**
 * Shared setup for the web front end (public/*.php) and the root helper.
 *
 * Paths can be moved for development with DROBO_WEBUI_ETC / DROBO_WEBUI_VAR.
 */
declare(strict_types=1);

namespace DroboWebui;

const VERSION = '0.4.3';

define('DroboWebui\ETC_DIR', getenv('DROBO_WEBUI_ETC') ?: '/etc/drobo-webui');
define('DroboWebui\VAR_DIR', getenv('DROBO_WEBUI_VAR') ?: '/var/lib/drobo-webui');
const RUN_DIR = '/run/drobo-webui';
const HELPER = '/usr/lib/drobo-webui/drobo-webui-helper';

// PHP ignores the system time zone unless php.ini sets one; use the board's.
$tz = trim((string) @file_get_contents('/etc/timezone'));
if ($tz === '' && ($link = @readlink('/etc/localtime')) && preg_match('#zoneinfo/(.+)$#', $link, $m)) {
    $tz = $m[1];
}
if ($tz === '' || !@date_default_timezone_set($tz)) {
    date_default_timezone_set('UTC');
}
unset($tz, $link, $m);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, __NAMESPACE__ . '\\')) {
        $file = __DIR__ . '/' . substr($class, strlen(__NAMESPACE__) + 1) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
