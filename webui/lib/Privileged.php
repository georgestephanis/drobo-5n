<?php
declare(strict_types=1);

namespace DroboWebui;

/**
 * Readers and writers that need root. Only the helper (run through sudo) uses this class.
 */
final class Privileged
{
    private const PMU = '/usr/local/sbin/drobo-pmu';
    private const FAILED_BAYS = '/run/drobo-leds/failed-bays';
    private const PMU_TTL = 60;      // one full PMU read takes about 6 s, so cache it
    private const SMART_TTL = 120;

    /** Disk present / power for bays 0-4, from the backplane expander lines. */
    public static function bays(): array
    {
        $failed = array_map('trim', @file(self::FAILED_BAYS) ?: []);
        $bays = [];
        for ($n = 0; $n < 5; $n++) {
            $detect = Shell::out(['gpioget', '--numeric', "bay$n-detect"], 3);
            $power = Shell::out(['gpioget', '--numeric', '--as-is', "bay$n-power"], 3);
            $bays[] = [
                'bay' => $n,
                'present' => $detect === '0',      // detect is active-low
                'powered' => $power === '1',
                'failed' => in_array((string) $n, $failed, true),
                'known' => $detect !== null,
            ];
        }
        return $bays;
    }

    /**
     * Keep the bay lights in step with SMART: a disk that fails its health check gets its bay
     * listed in /run/drobo-leds/failed-bays, so drobo-leds blinks it red. Bays someone else
     * listed are left alone; we only add and remove our own (remembered in VAR_DIR). A disk
     * that is asleep keeps the mark it had. Returns the bays we marked.
     */
    public static function syncSmartFailedBays(array $smart): array
    {
        $mineFile = VAR_DIR . '/smart-failed-bays.json';
        $prevMine = array_map('strval', json_decode((string) @file_get_contents($mineFile), true) ?: []);
        $want = [];
        foreach (Hardware::disks() as $d) {
            if (!preg_match('/^bay([0-4])$/', $d['slot'], $m)) {
                continue;
            }
            $s = $smart[$d['name']] ?? null;
            $known = $s && ($s['available'] ?? false) && !($s['asleep'] ?? false);
            if ($known ? ($s['passed'] ?? null) === false : in_array($m[1], $prevMine, true)) {
                $want[] = $m[1];
            }
        }
        $want = array_values(array_unique($want));
        sort($want);

        $lines = array_values(array_filter(array_map('trim', @file(self::FAILED_BAYS) ?: []), 'strlen'));
        $new = array_values(array_unique(array_merge(array_diff($lines, $prevMine), $want)));
        sort($new);
        $old = $lines;
        sort($old);
        if ($new !== $old) {
            @mkdir(dirname(self::FAILED_BAYS), 0755, true);
            $tmp = self::FAILED_BAYS . '.tmp';
            file_put_contents($tmp, $new ? implode("\n", $new) . "\n" : '');
            rename($tmp, self::FAILED_BAYS);
        }
        @mkdir(VAR_DIR, 0755, true);
        file_put_contents($mineFile, json_encode($want));
        return $want;
    }

    public static function setBayPower(int $bay, bool $on): void
    {
        [$code, , $err] = Shell::run(['gpioset', '-t0', "bay$bay-power=" . ($on ? '1' : '0')], null, 5);
        if ($code !== 0) {
            throw new \RuntimeException("gpioset failed: $err");
        }
    }

    /**
     * Fan, PMU version, power input and backup battery, cached for PMU_TTL seconds.
     *
     * PMU variables are read the way the stock firmware's pmuVarDo does it: "X<name>" selects a
     * variable, then "=." reads it in decimal, "@." in hex, and "~.+" reads it and steps to the
     * next one. All of these only read. Variables: a = input voltage (mV), v = backup battery
     * voltage (mV), X = system flags (0x40 = running on battery), R = the five raw temperature
     * sensors (°C). One drobo-pmu call holds the PMU lock for all of them, so nothing else can
     * change the selection in between.
     */
    public static function pmu(bool $fresh = false): array
    {
        return self::cached('pmu', $fresh ? 0 : self::PMU_TTL, static function (): array {
            $cmds = ['V', 'FT.', 'FA.', 'Xa', '=.', 'Xv', '=.', 'XX', '@.', 'XR', '~.+', '~.+', '~.+', '~.+', '~.+'];
            [, $out] = Shell::run(array_merge([self::PMU], $cmds), null, 30);
            $r = ['version' => null, 'fanTarget' => null, 'fanActual' => null, 'inputVolts' => null,
                'batteryVolts' => null, 'onBattery' => null, 'sensors' => []];
            $var = null;
            foreach (explode("\n", $out) as $l) {
                if (!preg_match('/^(\S+)\s+->\s+(.*)$/', $l, $m)) {
                    continue;
                }
                [$cmd, $reply] = [$m[1], trim($m[2])];
                if ($cmd === 'V') {
                    $r['version'] = $reply;
                } elseif ($cmd === 'FT.' && preg_match('/^FT(\d\d)!/', $reply, $v)) {
                    $r['fanTarget'] = (int) $v[1];
                } elseif ($cmd === 'FA.' && preg_match('/^FA(\d\d)!/', $reply, $v)) {
                    $r['fanActual'] = (int) $v[1];
                } elseif ($cmd[0] === 'X') {
                    $var = $reply === $cmd . '!' ? substr($cmd, 1) : null;  // selection acknowledged
                } elseif ($var !== null && preg_match('/^[=@~](-?[0-9A-Fa-f]+)!$/', $reply, $v)) {
                    $n = $cmd[0] === '@' ? hexdec($v[1]) : (int) $v[1];
                    if ($var === 'a' && $n > 0) {
                        $r['inputVolts'] = $n / 1000;
                    } elseif ($var === 'v' && $n > 0) {
                        $r['batteryVolts'] = $n / 1000;
                    } elseif ($var === 'X') {
                        $r['onBattery'] = ($n & 0x40) !== 0;
                    } elseif ($var === 'R') {
                        $r['sensors'][] = $n;
                    }
                }
            }
            $r['batteryPercent'] = self::batteryPercent($r['batteryVolts']);
            return $r;
        });
    }

    /**
     * Rough charge of a single Li-ion 18650 cell from its resting voltage. It's only a guide:
     * the reading is taken while the PMU's charger may be topping the cell up.
     */
    public static function batteryPercent(?float $volts): ?int
    {
        if ($volts === null) {
            return null;
        }
        $curve = [[3.30, 0], [3.50, 8], [3.60, 15], [3.70, 30], [3.80, 50], [3.90, 65], [4.00, 80], [4.10, 92], [4.18, 100]];
        if ($volts <= $curve[0][0]) {
            return 0;
        }
        for ($i = 1; $i < count($curve); $i++) {
            [$v1, $p1] = $curve[$i];
            if ($volts <= $v1) {
                [$v0, $p0] = $curve[$i - 1];
                return (int) round($p0 + ($p1 - $p0) * ($volts - $v0) / ($v1 - $v0));
            }
        }
        return 100;
    }

    /**
     * SMART summary per disk on a SATA slot, cached. Uses "-n standby" so a sleeping disk
     * is reported as asleep instead of being spun up.
     */
    public static function smart(bool $fresh = false): array
    {
        return self::cached('smart', $fresh ? 0 : self::SMART_TTL, static function (): array {
            $out = [];
            foreach (Hardware::disks() as $d) {
                if ($d['slot'] === 'usb') {
                    continue;
                }
                [$code, $json] = Shell::run(['smartctl', '-j', '-n', 'standby', '-H', '-i', '-A', '/dev/' . $d['name']], null, 30);
                $j = json_decode($json, true) ?: [];
                $msgs = strtoupper(implode(' ', array_column($j['smartctl']['messages'] ?? [], 'string')));
                if (str_contains($msgs, 'STANDBY') || str_contains($msgs, 'SLEEP')) {
                    $out[$d['name']] = ['available' => true, 'asleep' => true];
                    continue;
                }
                if (!isset($j['smart_status']) && !isset($j['model_name'])) {
                    $out[$d['name']] = ['available' => false];
                    continue;
                }
                $attrs = [];
                foreach ($j['ata_smart_attributes']['table'] ?? [] as $a) {
                    $attrs[$a['id']] = $a['raw']['value'] ?? null;
                }
                $out[$d['name']] = [
                    'available' => true,
                    'asleep' => false,
                    'passed' => $j['smart_status']['passed'] ?? null,
                    'model' => $j['model_name'] ?? null,
                    'firmware' => $j['firmware_version'] ?? null,
                    'capacity' => $j['user_capacity']['bytes'] ?? null,
                    'rpm' => $j['rotation_rate'] ?? null,
                    'link' => $j['interface_speed']['current']['string'] ?? null,
                    'temperature' => $j['temperature']['current'] ?? null,
                    'powerOnHours' => $j['power_on_time']['hours'] ?? null,
                    'reallocated' => $attrs[5] ?? null,
                    'pending' => $attrs[197] ?? null,
                    'uncorrectable' => $attrs[198] ?? null,
                ];
            }
            return $out;
        });
    }

    /**
     * Update KEY=value lines in a shell defaults file (/etc/default/...), keeping everything
     * else, and a .bak of the old file. Values here are always integers or validated paths.
     */
    public static function setDefaults(string $file, array $vars): void
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
        $done = [];
        foreach ($lines as &$l) {
            if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)=/', $l, $m) && array_key_exists($m[1], $vars)) {
                $l = $m[1] . '=' . self::shellQuote((string) $vars[$m[1]]);
                $done[$m[1]] = true;
            }
        }
        unset($l);
        foreach ($vars as $k => $v) {
            if (!isset($done[$k])) {
                $lines[] = $k . '=' . self::shellQuote((string) $v);
            }
        }
        if (is_file($file)) {
            copy($file, "$file.bak");
        }
        file_put_contents("$file.new", "# Edited by drobo-webui on " . date('Y-m-d H:i') . "; the previous copy is $file.bak\n"
            . implode("\n", array_filter($lines, static fn($l) => !str_starts_with($l, '# Edited by drobo-webui'))) . "\n");
        chmod("$file.new", 0644);
        rename("$file.new", $file);
    }

    /** Drop-in the web UI writes when you pick your own NTP servers. */
    public const NTP_DROPIN = '/etc/systemd/timesyncd.conf.d/drobo-webui.conf';

    /** Clock, timezone and network-time state from timedatectl (no changes). */
    public static function timeInfo(): array
    {
        $kv = static function (array $argv): array {
            $out = [];
            foreach (explode("\n", (string) Shell::out($argv)) as $l) {
                if (str_contains($l, '=')) {
                    [$k, $v] = explode('=', $l, 2);
                    $out[$k] = $v;
                }
            }
            return $out;
        };
        $t = $kv(['timedatectl', 'show']);
        $sync = $kv(['timedatectl', 'show-timesync']);
        $servers = [];
        if (is_readable(self::NTP_DROPIN)
            && preg_match('/^NTP=(.*)$/m', (string) file_get_contents(self::NTP_DROPIN), $m)) {
            $servers = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
        }
        return [
            'timezone' => $t['Timezone'] ?? date_default_timezone_get(),
            'ntp' => ($t['NTP'] ?? 'no') === 'yes',
            'synchronized' => ($t['NTPSynchronized'] ?? 'no') === 'yes',
            'rtc' => $t['RTCTimeUSec'] ?? null,
            'now' => time(),
            'servers' => $servers,
            'defaultServers' => preg_split('/\s+/', trim($sync['FallbackNTPServers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
            'currentServer' => $sync['ServerName'] ?? null,
            'timezones' => array_values(array_filter(explode("\n", (string) Shell::out(['timedatectl', 'list-timezones'])))),
        ];
    }

    /** A host name or IP address, as accepted for an NTP server. */
    public static function validNtpServer(string $s): bool
    {
        return (bool) (filter_var($s, FILTER_VALIDATE_IP)
            || (strlen($s) <= 253 && preg_match('/^(?=.*[a-z])[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $s)));
    }

    /** KEY=value pairs from a shell defaults file (simple assignments only). */
    public static function readDefaults(string $file): array
    {
        $out = [];
        foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)=(.*)$/', $l, $m)) {
                $out[$m[1]] = trim(preg_replace('/\s+#.*$/', '', $m[2]), "\"' ");
            }
        }
        return $out;
    }

    public static function shellQuote(string $v): string
    {
        return preg_match('/^[A-Za-z0-9_.\/:-]+$/', $v) ? $v : "'" . str_replace("'", "'\\''", $v) . "'";
    }

    // --- disk sleep and SMART self-tests -------------------------------------

    public const SPINDOWN_RULE = '/etc/udev/rules.d/69-drobo-webui-spindown.rules';
    public const SPINDOWN_CHOICES = [0, 10, 20, 30, 60, 120];
    public const SMARTD_CONF = '/etc/smartd.conf';
    /** smartd -s schedules (T/MM/DD/d/HH; d 7 = Sunday). A test due while a disk sleeps runs when it wakes. */
    public const SMART_SCHEDULES = [
        'off' => '',
        'weekly' => 'S/../../7/03',
        'weekly-monthly' => 'S/../../7/03|L/../01/./04',
    ];

    /** hdparm -S value for N minutes: 1-240 count 5 s steps, 241-251 count 30 min steps. */
    public static function hdparmS(int $minutes): int
    {
        return $minutes === 0 ? 0 : ($minutes <= 20 ? $minutes * 12 : 240 + intdiv($minutes, 30));
    }

    public static function diskSettings(): array
    {
        $minutes = 0;
        if (preg_match('/minutes=(\d+)/', (string) @file_get_contents(self::SPINDOWN_RULE), $m)) {
            $minutes = (int) $m[1];
        }
        $schedule = 'unknown';
        $line = self::smartdScanLine(self::smartdLines());
        if ($line !== null) {
            $cur = preg_match('/\s-s\s+\(?([^\s)]+)\)?/', $line, $m) ? $m[1] : '';
            $schedule = array_search($cur, self::SMART_SCHEDULES, true) ?: 'custom';
        }
        return [
            'spindownMinutes' => $minutes,
            'spindownChoices' => self::SPINDOWN_CHOICES,
            'hdparm' => is_executable('/usr/sbin/hdparm'),
            'smartSchedule' => $schedule,
            'smartd' => Shell::out(['systemctl', 'is-active', 'smartd.service']) === 'active',
        ];
    }

    /** Bay disks that spin (not the mSATA SSD or the stick). */
    public static function spinningBayDisks(): array
    {
        return array_values(array_filter(Hardware::disks(), static fn($d) => str_starts_with($d['slot'], 'bay')
            && trim((string) @file_get_contents("/sys/block/{$d['name']}/queue/rotational")) === '1'));
    }

    /** Spin bay disks down after $minutes idle (0 = never), now and whenever a disk appears. */
    public static function setSpindown(int $minutes): array
    {
        in_array($minutes, self::SPINDOWN_CHOICES, true) || throw new \InvalidArgumentException('Unsupported spin-down time');
        is_executable('/usr/sbin/hdparm') || throw new \RuntimeException('hdparm is not installed (sudo apt install hdparm)');
        $v = self::hdparmS($minutes);
        if ($minutes === 0) {
            @unlink(self::SPINDOWN_RULE);
        } else {
            file_put_contents(self::SPINDOWN_RULE . '.new', "# Written by drobo-webui: spin bay disks down after $minutes minutes idle. minutes=$minutes\n"
                . 'ACTION=="add|change", SUBSYSTEM=="block", KERNEL=="sd*[!0-9]", ENV{DEVTYPE}=="disk", '
                . "ATTR{queue/rotational}==\"1\", RUN+=\"/usr/sbin/hdparm -q -S $v /dev/%k\"\n");
            chmod(self::SPINDOWN_RULE . '.new', 0644);
            rename(self::SPINDOWN_RULE . '.new', self::SPINDOWN_RULE);
        }
        Shell::run(['udevadm', 'control', '--reload']);
        $applied = [];
        foreach (self::spinningBayDisks() as $d) {
            [$code] = Shell::run(['/usr/sbin/hdparm', '-q', '-S', (string) $v, '/dev/' . $d['name']], null, 15);
            if ($code === 0) {
                $applied[] = $d['name'];
            }
        }
        return $applied;
    }

    public static function setSmartSchedule(string $key): void
    {
        array_key_exists($key, self::SMART_SCHEDULES) || throw new \InvalidArgumentException('Unknown schedule');
        $lines = self::smartdLines();
        $i = null;
        foreach ($lines as $n => $l) {
            if (preg_match('/^\s*DEVICESCAN\b/', $l)) {
                $i = $n;
                break;
            }
        }
        $i !== null || throw new \RuntimeException('No DEVICESCAN line in ' . self::SMARTD_CONF . '; edit it by hand');
        $l = rtrim(preg_replace('/\s-s\s+(\([^)]*\)|\S+)/', '', $lines[$i]));
        if (self::SMART_SCHEDULES[$key] !== '') {
            $l .= ' -s (' . self::SMART_SCHEDULES[$key] . ')';
        }
        $lines[$i] = $l;
        $lines = array_values(array_filter($lines, static fn($x) => !str_starts_with($x, '# Edited by drobo-webui')));
        array_splice($lines, 0, 0, ['# Edited by drobo-webui on ' . date('Y-m-d H:i') . '; the previous copy is ' . self::SMARTD_CONF . '.bak']);
        copy(self::SMARTD_CONF, self::SMARTD_CONF . '.bak');
        file_put_contents(self::SMARTD_CONF . '.new', implode("\n", $lines) . "\n");
        chmod(self::SMARTD_CONF . '.new', 0644);
        rename(self::SMARTD_CONF . '.new', self::SMARTD_CONF);
        [$code, , $err] = Shell::run(['systemctl', 'reload-or-restart', 'smartd.service'], null, 30);
        $code === 0 || throw new \RuntimeException("smartd didn't reload: $err");
    }

    private static function smartdLines(): array
    {
        return is_file(self::SMARTD_CONF) ? file(self::SMARTD_CONF, FILE_IGNORE_NEW_LINES) : [];
    }

    private static function smartdScanLine(array $lines): ?string
    {
        foreach ($lines as $l) {
            if (preg_match('/^\s*DEVICESCAN\b/', $l)) {
                return $l;
            }
        }
        return null;
    }

    // --- software updates ------------------------------------------------------

    public const APT_UNIT = 'drobo-webui-apt';
    /** Touched after each successful "Check now": apt leaves unchanged lists with their old dates. */
    public const APT_STAMP = VAR_DIR . '/apt-checked';

    /** What apt would upgrade, from the package lists as they are (no download). */
    public static function updatesInfo(): array
    {
        [, $sim] = Shell::run(['apt-get', '-s', '-o', 'Debug::NoLocking=1', 'upgrade'], null, 60);
        $pkgs = [];
        foreach (explode("\n", (string) $sim) as $l) {
            if (preg_match('/^Inst (\S+) (?:\[(\S+)\] )?\((\S+) ([^)]*)\)/', $l, $m)) {
                $pkgs[] = ['name' => $m[1], 'from' => $m[2] ?: null, 'to' => $m[3], 'security' => str_contains($m[4], 'security')];
            }
        }
        $lists = glob('/var/lib/apt/lists/*_InRelease') ?: [];
        $stamps = array_merge(array_map('filemtime', $lists), is_file(self::APT_STAMP) ? [filemtime(self::APT_STAMP)] : []);
        $checked = $stamps ? max($stamps) : null;
        $state = Shell::out(['systemctl', 'is-active', self::APT_UNIT . '.service']);
        [, $log] = Shell::run(['journalctl', '--no-pager', '-o', 'cat', '-n', '15', '-u', self::APT_UNIT . '.service']);
        $kernels = array_map('basename', glob('/lib/modules/*') ?: []);
        usort($kernels, 'version_compare');
        $running = php_uname('r');
        return [
            'packages' => $pkgs,
            'checked' => $checked,
            'running' => in_array($state, ['active', 'activating', 'reloading'], true),
            'log' => trim((string) $log),
            'rebootNeeded' => is_file('/run/reboot-required') || ($kernels && end($kernels) !== $running),
            'freeMB' => (int) floor((float) disk_free_space('/') / 1048576),   // a float: PHP ints are 32-bit on armhf
        ];
    }

    /** Start "apt-get update" or "apt-get upgrade" in the background, as its own systemd unit. */
    public static function startApt(string $what): void
    {
        $info = Shell::out(['systemctl', 'is-active', self::APT_UNIT . '.service']);
        in_array($info, ['active', 'activating'], true) && throw new \RuntimeException('An update is already running');
        $apt = ['apt-get', '-q', '-y', '-o', 'Dpkg::Options::=--force-confdef', '-o', 'Dpkg::Options::=--force-confold'];
        $argv = match ($what) {
            'check' => ['apt-get', '-q', '-o', 'APT::Update::Post-Invoke-Success::=touch ' . self::APT_STAMP, 'update'],
            'install' => array_merge($apt, ['upgrade']),
            default => throw new \InvalidArgumentException('updates check|install'),
        };
        if ($what === 'install' && disk_free_space('/') < 300 * 1048576) {
            throw new \RuntimeException('Less than 300 MB free on the system disk; free some space first');
        }
        Shell::run(['systemctl', 'reset-failed', self::APT_UNIT . '.service']);
        [$code, , $err] = Shell::run(array_merge(
            ['systemd-run', '--unit=' . self::APT_UNIT, '--collect', '--setenv=DEBIAN_FRONTEND=noninteractive', '--property=Nice=10'],
            $argv
        ));
        $code === 0 || throw new \RuntimeException("systemd-run failed: $err");
    }

    private static function cached(string $name, int $ttl, callable $fn): array
    {
        @mkdir(RUN_DIR, 0755, true);
        $file = RUN_DIR . "/$name.json";
        $lock = fopen("$file.lock", 'c');
        flock($lock, LOCK_EX);  // two page loads at once wait for one reading instead of both talking to the PMU
        try {
            if ($ttl > 0 && is_file($file) && time() - filemtime($file) < $ttl) {
                $data = json_decode((string) file_get_contents($file), true);
                if (is_array($data)) {
                    return $data;
                }
            }
            $data = $fn();
            file_put_contents("$file.new", json_encode($data));
            rename("$file.new", $file);
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
