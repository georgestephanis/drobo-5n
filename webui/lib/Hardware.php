<?php
declare(strict_types=1);

namespace DroboWebui;

/**
 * Facts about the Drobo 5N that any user can read: disks and which slot they're in,
 * filesystems, temperatures, memory, network, services. Nothing here needs root.
 *
 * Bay map (research/backplane-and-bays.md): identify a slot by controller and port,
 * never by ataN or sdX, which change with probe order.
 */
final class Hardware
{
    /** [controller path fragment, ata port_no (1-based)] => slot */
    public const SLOTS = [
        'd00a0000.sata|1' => 'bay0',
        'd00a0000.sata|2' => 'msata',
        '0000:02:00.0|1' => 'bay1',
        '0000:02:00.0|2' => 'bay2',
        '0000:01:00.0|1' => 'bay3',
        '0000:01:00.0|2' => 'bay4',
    ];

    public const SERVICES = [
        'drobo-fan' => 'Fan control',
        'drobo-leds' => 'Front panel lights',
        'drobo-bays' => 'Bay power',
        'drobo-powerbtn' => 'Power button',
        'drobo-boot-ok' => 'Boot check',
        'drobo-webui-alerts.timer' => 'E-mail alerts',
        'smbd' => 'Windows file sharing (Samba)',
        'nfs-server' => 'NFS file sharing',
        'ssh' => 'SSH',
    ];

    private const REAL_FS = ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'vfat', 'exfat', 'zfs', 'f2fs', 'ntfs', 'ntfs3'];

    /** Every sd* disk with its slot, size and partitions. */
    public static function disks(): array
    {
        $disks = [];
        foreach (glob('/sys/block/sd*') ?: [] as $sys) {
            $name = basename($sys);
            $dev = realpath("$sys/device") ?: '';
            $disks[] = [
                'name' => $name,
                'slot' => self::slotOf($dev),
                'model' => trim((string) @file_get_contents("$sys/device/model")),
                'vendor' => trim((string) @file_get_contents("$sys/device/vendor")),
                'bytes' => (float) @file_get_contents("$sys/size") * 512,  // floats: armhf PHP ints are 32-bit
                'rotational' => trim((string) @file_get_contents("$sys/queue/rotational")) === '1',
                'partitions' => array_map('basename', glob("$sys/{$name}[0-9]*") ?: []),
            ];
        }
        $order = ['bay0' => 0, 'bay1' => 1, 'bay2' => 2, 'bay3' => 3, 'bay4' => 4, 'msata' => 5, 'usb' => 6, 'other' => 7];
        usort($disks, static fn($a, $b) => ($order[$a['slot']] <=> $order[$b['slot']]) ?: strcmp($a['name'], $b['name']));
        return $disks;
    }

    public static function slotOf(string $devPath): string
    {
        if (str_contains($devPath, '.usb/')) {
            return 'usb';
        }
        if (preg_match('#/(ata\d+)/#', $devPath, $m)) {
            $port = trim((string) @file_get_contents("/sys/class/ata_port/{$m[1]}/port_no"));
            foreach (self::SLOTS as $key => $slot) {
                [$ctl, $p] = explode('|', $key);
                if ($p === $port && str_contains($devPath, "/$ctl/")) {
                    return $slot;
                }
            }
        }
        return 'other';
    }

    /** Mounted filesystems that hold data (no proc/tmpfs/etc), with usage. */
    public static function filesystems(): array
    {
        $out = [];
        $seen = [];
        foreach (file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$src, $mnt, $type, $opts] = array_pad(explode(' ', $line), 4, '');
            $mnt = self::unescape($mnt);
            if (!in_array($type, self::REAL_FS, true) || isset($seen[$src])) {
                continue;  // a bind mount of the same device is shown once
            }
            $seen[$src] = true;
            $total = @disk_total_space($mnt);
            $free = @disk_free_space($mnt);
            if ($total === false || $free === false) {
                continue;
            }
            $out[] = [
                'device' => $src,
                'mount' => $mnt,
                'type' => $type,
                'bay' => self::onBayDisks($src),
                'readOnly' => in_array('ro', explode(',', $opts), true),
                'total' => (float) $total,
                'free' => (float) $free,
                'used' => (float) ($total - $free),
            ];
        }
        return $out;
    }

    /**
     * Is this block device (/dev/sdc1, /dev/md0, /dev/mapper/x) entirely on bay disks? The front
     * panel's capacity lights count only these (kit/drobo-leds.sh does the same in shell).
     */
    public static function onBayDisks(string $src): bool
    {
        $real = str_starts_with($src, '/dev/') ? realpath($src) : false;
        return $real !== false && self::blockOnBays(basename($real), 0);
    }

    private static function blockOnBays(string $blk, int $depth): bool
    {
        if ($depth > 4 || !preg_match('/^[a-z0-9-]+$/', $blk)) {
            return false;
        }
        if (is_file("/sys/class/block/$blk/partition")) {
            $blk = basename(dirname((string) realpath("/sys/class/block/$blk")));
        }
        $slaves = glob("/sys/block/$blk/slaves/*") ?: [];
        if ($slaves) {  // md, LVM, dm-crypt: on the bays if every member is
            foreach ($slaves as $sl) {
                if (!self::blockOnBays(basename($sl), $depth + 1)) {
                    return false;
                }
            }
            return true;
        }
        return str_starts_with(self::slotOf(realpath("/sys/block/$blk/device") ?: ''), 'bay');
    }

    /** Is any partition of /dev/$disk mounted? */
    public static function diskMounted(string $disk): bool
    {
        foreach (file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('#^/dev/' . preg_quote($disk, '#') . '\d*\s#', $line)) {
                return true;
            }
        }
        foreach (glob("/sys/block/$disk/{$disk}*/holders/*") ?: [] as $h) {
            return true;  // in use by md, LVM or dm-crypt
        }
        return glob("/sys/block/$disk/holders/*") ? true : false;
    }

    /** Temperatures in °C: the SoC sensor and each drive's drivetemp sensor (by disk name). */
    public static function temperatures(): array
    {
        $soc = null;
        $disks = [];
        foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $h) {
            $name = trim((string) @file_get_contents("$h/name"));
            $raw = @file_get_contents("$h/temp1_input");
            if ($raw === false) {
                continue;
            }
            $c = round((int) $raw / 1000, 1);
            if (str_contains($name, 'thermal')) {
                $soc = $c;
            } elseif ($name === 'drivetemp') {
                $blk = glob("$h/device/block/*") ?: [];
                if ($blk) {
                    $disks[basename($blk[0])] = $c;
                }
            }
        }
        return ['soc' => $soc, 'disks' => $disks];
    }

    public static function system(): array
    {
        $uptime = (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
        $load = array_slice(explode(' ', (string) @file_get_contents('/proc/loadavg')), 0, 3);
        $mem = [];
        foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $l, $m)) {
                $mem[$m[1]] = (float) $m[2] * 1024;
            }
        }
        $os = @parse_ini_file('/etc/os-release') ?: [];
        return [
            'hostname' => gethostname(),
            'os' => $os['PRETTY_NAME'] ?? 'Linux',
            'kernel' => php_uname('r'),
            'uptimeSeconds' => (int) $uptime,
            'load' => array_map('floatval', $load),
            'cpus' => count(glob('/sys/devices/system/cpu/cpu[0-9]*') ?: []),
            'memTotal' => $mem['MemTotal'] ?? 0,
            'memAvailable' => $mem['MemAvailable'] ?? 0,
            'bootSet' => trim((string) @file_get_contents('/boot/drobo/VERSION')) ?: null,
            'bootCopyOnSsd' => is_file('/media/sdboot/boot/drobo/VERSION'),
            // is-system-running exits non-zero when degraded, so read stdout either way
            'state' => trim(Shell::run(['systemctl', 'is-system-running'])[1]),
            'time' => date(DATE_ATOM),
        ];
    }

    public static function network(): array
    {
        $json = Shell::out(['ip', '-j', 'addr', 'show']);
        $out = [];
        foreach (json_decode((string) $json, true) ?: [] as $if) {
            if (($if['ifname'] ?? '') === 'lo') {
                continue;
            }
            $sys = '/sys/class/net/' . $if['ifname'];
            $speed = (int) @file_get_contents("$sys/speed");
            $out[] = [
                'name' => $if['ifname'],
                'up' => in_array('LOWER_UP', $if['flags'] ?? [], true),
                'speedMbps' => $speed > 0 ? $speed : null,
                'addresses' => array_values(array_map(
                    static fn($a) => $a['local'] . '/' . $a['prefixlen'],
                    array_filter($if['addr_info'] ?? [], static fn($a) => ($a['scope'] ?? '') !== 'link')
                )),
                'rxBytes' => (float) @file_get_contents("$sys/statistics/rx_bytes"),
                'txBytes' => (float) @file_get_contents("$sys/statistics/tx_bytes"),
            ];
        }
        return $out;
    }

    /** Services that are installed, with their state. */
    public static function services(): array
    {
        $units = array_map(static fn($u) => str_contains($u, '.') ? $u : "$u.service", array_keys(self::SERVICES));
        [, $show] = Shell::run(array_merge(['systemctl', 'show', '-p', 'Id,LoadState,ActiveState,Result,Type', '--'], $units));
        $out = [];
        $byId = array_combine($units, array_keys(self::SERVICES));
        foreach (preg_split('/\n\n+/', trim($show)) as $block) {
            $p = [];
            foreach (explode("\n", $block) as $l) {
                [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
                $p[$k] = $v;
            }
            $unit = $byId[$p['Id'] ?? ''] ?? null;
            if ($unit === null || ($p['LoadState'] ?? '') !== 'loaded') {
                continue;
            }
            $state = $p['ActiveState'] ?? 'unknown';
            if ($state === 'inactive' && ($p['Type'] ?? '') === 'oneshot' && ($p['Result'] ?? '') === 'success') {
                $state = 'done';  // a one-shot job that already ran and succeeded
            }
            $out[] = ['unit' => $unit, 'label' => self::SERVICES[$unit], 'state' => $state];
        }
        return $out;
    }

    /** Linux software RAID arrays from /proc/mdstat. */
    public static function arrays(): array
    {
        $out = [];
        $lines = @file('/proc/mdstat', FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $l) {
            if (!preg_match('/^(md\d+)\s*:\s*(\w+)\s+(?:\(\w+\)\s+)?(raid\d+|linear)?\s*(.*)$/', $l, $m)) {
                continue;
            }
            $next = $lines[$i + 1] ?? '';
            $status = preg_match('/\[([U_]+)\]/', $next, $s) ? $s[1] : '';
            $sync = '';
            if (preg_match('/(recovery|resync|reshape|check)\s*=\s*([\d.]+%)/', $lines[$i + 2] ?? '', $r)) {
                $sync = "$r[1] $r[2]";
            }
            $out[] = [
                'name' => $m[1],
                'state' => $m[2],
                'level' => $m[3] ?: null,
                'members' => array_values(array_map(
                    static fn($d) => preg_replace('/\[\d+\].*$/', '', $d),
                    array_filter(explode(' ', $m[4]))
                )),
                'health' => $status,
                'degraded' => str_contains($status, '_'),
                'sync' => $sync ?: null,
            ];
        }
        return $out;
    }

    private static function unescape(string $s): string
    {
        return preg_replace_callback('/\\\\([0-7]{3})/', static fn($m) => chr(octdec($m[1])), $s);
    }
}
