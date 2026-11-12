<?php
/**
 * JSON API for the dashboard: api.php?r=ROUTE. GET reads, POST changes (JSON body,
 * X-CSRF-Token header). Everything except "session" and "login" needs a signed-in session,
 * apart from the read-only status routes when "publicStatus" is turned on.
 */
declare(strict_types=1);

namespace DroboWebui;

require __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    $j = json_decode((string) file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

Auth::start();
$route = (string) ($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cfg = Config::load();

$open = ['session', 'login'];
$publicRead = ['status', 'disks', 'problems'];
if (!in_array($route, $open, true) && !Auth::loggedIn()
    && !($cfg['ui']['publicStatus'] && $method === 'GET' && in_array($route, $publicRead, true))) {
    out(['error' => 'Please sign in.'], 401);
}
if ($method === 'POST' && !Auth::checkCsrf()) {  // login too, so another site can't sign a browser in
    out(['error' => 'This page is out of date. Reload it and try again.'], 403);
}

try {
    switch ("$method $route") {
        case 'GET session':
            out([
                'loggedIn' => Auth::loggedIn(),
                'passwordSet' => Auth::passwordSet(),
                'publicStatus' => (bool) $cfg['ui']['publicStatus'],
                'csrf' => Auth::csrfToken(),
                'version' => VERSION,
                'hostname' => gethostname(),
                'ui' => $cfg['ui'],
            ]);

        case 'POST login':
            $err = Auth::login((string) (body()['password'] ?? ''));
            $err === null ? out(['ok' => true, 'csrf' => Auth::csrfToken()]) : out(['error' => $err], 401);

        case 'POST logout':
            Auth::logout();
            out(['ok' => true, 'csrf' => Auth::csrfToken()]);

        case 'GET status':
            $priv = Helper::call('status');
            out([
                'system' => Hardware::system(),
                'temperatures' => Hardware::temperatures(),
                'filesystems' => Hardware::filesystems(),
                'arrays' => Hardware::arrays(),
                'disks' => Hardware::disks(),
                'network' => Hardware::network(),
                'services' => Hardware::services(),
                'bays' => $priv['bays'],
                'pmu' => $priv['pmu'],
                'gaugePaths' => $priv['gaugePaths'],
            ]);

        case 'GET disks':
            $fresh = !empty($_GET['fresh']) && Auth::loggedIn();
            out(['disks' => Hardware::disks(), 'smart' => Helper::call('smart', $fresh ? ['--fresh'] : [])['smart']]);

        case 'GET problems':
            out(Helper::call('problems'));

        case 'GET settings':
            out(['config' => Config::public($cfg)] + Helper::call('settings-get'));

        case 'POST settings/config':
            out(Helper::call('config-update', [], body()));

        case 'POST settings/hostname':
            out(Helper::call('set-hostname', [(string) (body()['hostname'] ?? '')]));

        case 'POST settings/timezone':
            out(Helper::call('set-timezone', [(string) (body()['timezone'] ?? '')]));

        case 'POST settings/ntp':
            $b = body();
            $servers = $b['servers'] ?? [];
            is_array($servers) || out(['error' => 'servers must be a list'], 400);
            out(Helper::call('set-ntp', array_merge([!empty($b['on']) ? 'on' : 'off'],
                array_map('strval', array_values($servers)))));

        case 'POST settings/time':
            out(Helper::call('set-time', [(string) (int) (body()['epoch'] ?? 0)]));

        case 'POST settings/fan':
            $b = body();
            out(Helper::call('set-fan', array_map(
                static fn($k) => (string) ($b[$k] ?? ''),
                ['FLOOR', 'SOC_LO', 'SOC_HI', 'DISK_LO', 'DISK_HI']
            )));

        case 'POST settings/gauge':
            $paths = body()['paths'] ?? [];
            is_array($paths) || out(['error' => 'paths must be a list'], 400);
            out(Helper::call('set-gauge', array_map('strval', array_values($paths))));

        case 'POST settings/password':
            $b = body();
            out(Helper::call('set-password', [], ['current' => (string) ($b['current'] ?? ''), 'new' => (string) ($b['new'] ?? '')]));

        case 'POST bay':
            $b = body();
            out(Helper::call('bay-power', [(string) ($b['bay'] ?? ''), !empty($b['on']) ? 'on' : 'off']));

        case 'POST test-email':
            out(Helper::call('test-email', [], null, 90));

        case 'GET updates':
            out(Helper::call('updates', [], null, 90));

        case 'POST updates':
            out(Helper::call('updates', [(string) (body()['action'] ?? '')], null, 90));

        case 'POST settings/disks':
            $b = body();
            out(Helper::call('set-disks', [(string) (int) ($b['spindownMinutes'] ?? 0), (string) ($b['smartSchedule'] ?? '')], null, 60));

        case 'GET https':
            out(Https::info());

        case 'GET https/cert':
            $pem = Https::pem() ?? out(['error' => 'There is no certificate yet.'], 404);
            header('Content-Type: application/x-x509-ca-cert');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]/', '', strtolower((string) gethostname())) . '.crt"');
            echo $pem;
            exit;

        case 'POST https':
            out(Helper::call('https', [(string) (body()['action'] ?? '')], null, 60));

        case 'POST power':
            out(Helper::call('power', [(string) (body()['action'] ?? '')]));

        case 'GET logs':
            out(Helper::call('logs', [(string) ($_GET['unit'] ?? ''), (string) ($_GET['lines'] ?? '200')]));

        default:
            out(['error' => 'Unknown request.'], 404);
    }
} catch (\InvalidArgumentException $e) {
    out(['error' => $e->getMessage()], 400);
} catch (\RuntimeException $e) {
    out(['error' => $e->getMessage()], 422);
}
