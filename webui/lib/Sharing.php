<?php
declare(strict_types=1);

namespace DroboWebui;

use InvalidArgumentException;
use RuntimeException;

/**
 * Network sharing of bay disks (the Sharing page). Root only: the helper and the
 * drobo-webui-disk@.service units call it.
 *
 * - A udev rule starts drobo-webui-disk@sdXN.service for every filesystem on an ATA disk; its
 *   "disk-attach" call records the disk (by filesystem UUID) and, when sharing is on and the disk
 *   is in a bay, mounts it at /srv/disks/NAME. When the disk goes away the unit stops and
 *   "disk-detach" unmounts it.
 * - Each mounted disk becomes its own Samba share in /etc/samba/drobo-webui-shares.conf, which
 *   smb.conf includes. Who may use it comes from the per-person access list kept here, so it
 *   survives the disk being removed and put back.
 * - New disks are shared read-only until "Allow changes" is turned on for them. Read-only mounts
 *   don't replay a journal either, so a read-only disk is never written to.
 *
 * State: /etc/drobo-webui/sharing.json (root:www-data 0640), changed under a lock.
 */
final class Sharing
{
    public const ROOT = '/srv/disks';
    public const SMB_CONF = '/etc/samba/smb.conf';
    public const SHARES_CONF = '/etc/samba/drobo-webui-shares.conf';
    public const INCLUDE = 'include = ' . self::SHARES_CONF;
    public const SHARE_USER = 'drobo-share';
    /** Filesystems we mount, with the mount -t type. */
    public const FS = [
        'ext2' => 'ext2', 'ext3' => 'ext3', 'ext4' => 'ext4', 'xfs' => 'xfs', 'btrfs' => 'btrfs',
        'f2fs' => 'f2fs', 'exfat' => 'exfat', 'vfat' => 'vfat', 'ntfs' => 'ntfs3',
    ];
    /** No Unix owners or extended attributes: owner comes from mount options, Mac metadata goes in ._ files. */
    public const FOREIGN_FS = ['exfat', 'vfat', 'ntfs'];
    private const RESERVED_SHARES = ['global', 'homes', 'printers', 'print$', 'ipc$'];
    private const RESERVED_USERS = ['root', 'daemon', 'bin', 'sys', 'nobody', 'www-data', 'drobo-share', 'sshd', 'messagebus'];

    public static function file(): string
    {
        return ETC_DIR . '/sharing.json';
    }

    public static function load(): array
    {
        $j = json_decode((string) @file_get_contents(self::file()), true);
        $j = is_array($j) ? $j : [];
        return [
            'enabled' => (bool) ($j['enabled'] ?? false),
            'disks' => is_array($j['disks'] ?? null) ? $j['disks'] : [],
            'users' => is_array($j['users'] ?? null) ? $j['users'] : [],
        ];
    }

    private static function save(array $s): void
    {
        $tmp = self::file() . '.new';
        file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        @chown($tmp, 'root');
        @chgrp($tmp, 'www-data');
        chmod($tmp, 0640);
        rename($tmp, self::file());
    }

    /** Run $fn with the state locked; whatever it leaves in $s is saved. */
    private static function locked(callable $fn): mixed
    {
        @mkdir(RUN_DIR, 0755, true);
        $fh = fopen(RUN_DIR . '/sharing.lock', 'c');
        flock($fh, LOCK_EX);
        try {
            $s = self::load();
            $before = $s;
            $r = $fn($s);
            if ($s !== $before) {
                self::save($s);
            }
            return $r;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    // --- devices ---------------------------------------------------------------

    /** udev's view of a block device (no disk access, so a sleeping disk stays asleep). */
    public static function probe(string $dev): array
    {
        $p = [];
        foreach (explode("\n", (string) Shell::out(['udevadm', 'info', '--query=property', "--name=/dev/$dev"], 10)) as $l) {
            [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
            $p[$k] = $v;
        }
        $label = isset($p['ID_FS_LABEL_ENC'])
            ? preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', static fn($m) => chr(hexdec($m[1])), $p['ID_FS_LABEL_ENC'])
            : ($p['ID_FS_LABEL'] ?? '');
        return [
            'type' => $p['ID_FS_TYPE'] ?? '',
            'usage' => $p['ID_FS_USAGE'] ?? '',
            'uuid' => $p['ID_FS_UUID'] ?? '',
            'label' => trim((string) $label),
            'ptable' => $p['ID_PART_TABLE_TYPE'] ?? '',
        ];
    }

    /** sdc1 -> sdc */
    public static function diskOf(string $dev): string
    {
        return preg_replace('/\d+$/', '', $dev);
    }

    public static function slotOfDev(string $dev): string
    {
        return Hardware::slotOf(realpath('/sys/block/' . self::diskOf($dev) . '/device') ?: '');
    }

    /** Filesystem-bearing devices on bay disks: [dev => probe + slot]. */
    public static function bayDevices(): array
    {
        $out = [];
        foreach (Hardware::disks() as $d) {
            if (!str_starts_with($d['slot'], 'bay')) {
                continue;
            }
            $cands = $d['partitions'] ?: [$d['name']];
            foreach ($cands as $dev) {
                $out[$dev] = self::probe($dev) + ['slot' => $d['slot'], 'disk' => $d['name'], 'model' => $d['model']];
            }
        }
        return $out;
    }

    /** [mount point => device] for everything mounted under ROOT, plus device => mount point. */
    private static function ourMounts(): array
    {
        $byMount = [];
        foreach (file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$src, $mnt] = array_pad(explode(' ', $line), 2, '');
            $mnt = preg_replace_callback('/\\\\([0-7]{3})/', static fn($m) => chr(octdec($m[1])), $mnt);
            if (str_starts_with($mnt, self::ROOT . '/')) {
                $byMount[$mnt] = $src;
            }
        }
        return $byMount;
    }

    private static function mountedAnywhere(string $dev): ?string
    {
        foreach (file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$src, $mnt] = array_pad(explode(' ', $line), 2, '');
            if ($src === "/dev/$dev") {
                return preg_replace_callback('/\\\\([0-7]{3})/', static fn($m) => chr(octdec($m[1])), $mnt);
            }
        }
        return null;
    }

    /** Is this filesystem listed in /etc/fstab (someone set it up by hand, e.g. for a pool)? */
    private static function inFstab(string $dev, array $p): bool
    {
        foreach (file('/etc/fstab', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $f = preg_split('/\s+/', trim($l));
            if (!$f[0] || str_starts_with($f[0], '#')) {
                continue;
            }
            if ($f[0] === "/dev/$dev" || ($p['uuid'] !== '' && $f[0] === 'UUID=' . $p['uuid'])
                || ($p['label'] !== '' && $f[0] === 'LABEL=' . $p['label'])) {
                return true;
            }
        }
        return false;
    }

    // --- attach / detach -------------------------------------------------------

    /** A filesystem appeared (udev, or a rescan). Record it, and share it if sharing is on. */
    public static function attach(string $dev): array
    {
        preg_match('/^sd[a-z]+\d*$/', $dev) || throw new InvalidArgumentException('bad device');
        if (!is_dir("/sys/class/block/$dev")) {
            return ['skipped' => 'gone'];
        }
        $slot = self::slotOfDev($dev);
        if (!str_starts_with($slot, 'bay')) {
            return ['skipped' => 'not a bay disk'];
        }
        $p = self::probe($dev);
        if ($p['usage'] !== 'filesystem' || !isset(self::FS[$p['type']]) || $p['uuid'] === '') {
            return ['skipped' => 'no filesystem we share' . ($p['type'] ? " ({$p['type']})" : '')];
        }
        if (self::inFstab($dev, $p)) {
            return ['skipped' => 'listed in /etc/fstab'];
        }
        return self::locked(static function (array &$s) use ($dev, $p, $slot): array {
            $rec = self::remember($s, $dev, $p, $slot);
            $r = ['uuid' => $p['uuid'], 'name' => $rec['name']];
            if ($s['enabled']) {
                $r['mounted'] = self::mountRec($s, $p['uuid'], $dev);
                self::writeSamba($s);
            }
            return $r;
        });
    }

    /** A filesystem went away (or is about to): unmount it if it's ours. */
    public static function detach(string $dev): array
    {
        preg_match('/^sd[a-z]+\d*$/', $dev) || throw new InvalidArgumentException('bad device');
        return self::locked(static function (array &$s) use ($dev): array {
            $done = [];
            foreach (self::ourMounts() as $mnt => $src) {
                if ($src === "/dev/$dev") {
                    self::unmount($mnt, !is_dir("/sys/class/block/$dev"));
                    $done[] = $mnt;
                }
            }
            if ($done) {
                self::writeSamba($s);
            }
            return ['unmounted' => $done];
        });
    }

    private static function remember(array &$s, string $dev, array $p, string $slot): array
    {
        $uuid = $p['uuid'];
        $disk = self::diskOf($dev);
        $rec = $s['disks'][$uuid] ?? [
            'name' => self::uniqueName($s, $p['label'] !== '' ? $p['label'] : trim((string) @file_get_contents("/sys/block/$disk/device/model"))),
            'writable' => false,
            'firstSeen' => time(),
        ];
        $rec['label'] = $p['label'];
        $rec['fstype'] = $p['type'];
        $rec['bytes'] = (float) @file_get_contents("/sys/class/block/$dev/size") * 512;
        $rec['model'] = trim((string) @file_get_contents("/sys/block/$disk/device/model"));
        $rec['lastSeen'] = time();
        $rec['lastSlot'] = $slot;
        $s['disks'][$uuid] = $rec;
        return $rec;
    }

    /** A share name from a label: letters, digits, space . _ -; unique; not a reserved or hand-made share. */
    private static function uniqueName(array $s, string $want): string
    {
        $base = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ._-]/', '', $want)), ' .');
        $base = substr($base, 0, 40) ?: 'Disk';
        $taken = array_map('strtolower', array_merge(
            array_column($s['disks'], 'name'),
            self::RESERVED_SHARES,
            self::handMadeShares()
        ));
        $name = $base;
        for ($i = 2; in_array(strtolower($name), $taken, true); $i++) {
            $name = "$base $i";
        }
        return $name;
    }

    /** Share names in smb.conf itself (not ours). */
    private static function handMadeShares(): array
    {
        preg_match_all('/^\s*\[([^\]]+)\]/m', (string) @file_get_contents(self::SMB_CONF), $m);
        return $m[1];
    }

    private static function mountOptions(string $fstype, bool $writable): string
    {
        $o = ['nosuid', 'nodev', 'noexec', 'noatime', $writable ? 'rw' : 'ro'];
        if (in_array($fstype, self::FOREIGN_FS, true)) {
            $pw = posix_getpwnam(self::SHARE_USER);
            if ($pw) {
                $o[] = 'uid=' . $pw['uid'];
                $o[] = 'gid=' . $pw['gid'];
            }
            $o[] = 'fmask=0113';
            $o[] = 'dmask=0002';
            if ($fstype === 'vfat') {
                $o[] = 'utf8';
                $o[] = 'shortname=mixed';
            }
        } elseif (!$writable) {
            // Plain "ro" still replays a journal; these don't touch the disk at all.
            $o[] = match ($fstype) {
                'ext3', 'ext4' => 'noload',
                'xfs' => 'norecovery',
                'btrfs' => 'rescue=nologreplay',
                default => 'ro',
            };
        }
        return implode(',', array_unique($o));
    }

    /** Mount a recorded disk at ROOT/NAME. Returns true when it's mounted there. */
    private static function mountRec(array &$s, string $uuid, string $dev): bool
    {
        $rec = &$s['disks'][$uuid];
        $target = self::ROOT . '/' . $rec['name'];
        $cur = self::mountedAnywhere($dev);
        if ($cur === $target) {
            unset($rec['error']);
            return true;
        }
        if ($cur !== null) {
            $rec['error'] = "Already mounted at $cur";
            return false;
        }
        @mkdir(self::ROOT, 0755, true);
        @mkdir($target, 0755);
        [$code, , $err] = Shell::run(['mount', '-t', self::FS[$rec['fstype']], '-o',
            self::mountOptions($rec['fstype'], (bool) $rec['writable']), "/dev/$dev", $target], null, 120);
        if ($code !== 0) {
            @rmdir($target);
            $rec['error'] = trim($err) ?: "mount failed ($code)";
            return false;
        }
        unset($rec['error']);
        return true;
    }

    /**
     * Unmount one of our disks. If it's busy, first disconnect the Samba clients of its share (a Mac
     * reconnects by itself next time the share is opened) and try again; only a program on the Drobo
     * itself, or a lost disk ($lazy), is left.
     */
    private static function unmount(string $mnt, bool $lazy = false): void
    {
        [$code, , $err] = Shell::run(['umount', $mnt], null, 60);
        for ($try = 0; $code !== 0 && str_contains($err, 'busy') && $try < 5; $try++) {
            self::closeShare(basename($mnt));
            usleep(700000);
            [$code, , $err] = Shell::run(['umount', $mnt], null, 60);
        }
        if ($code !== 0) {
            if (!$lazy) {
                throw new RuntimeException(str_contains($err, 'busy')
                    ? 'The disk is in use by a program on the Drobo itself. Stop it and try again.'
                    : 'umount failed: ' . trim($err));
            }
            Shell::run(['umount', '-l', $mnt], null, 30);
        }
        @rmdir($mnt);
    }

    /** Disconnect everyone from one Samba share. */
    private static function closeShare(string $name): void
    {
        if (Shell::out(['systemctl', 'is-active', 'smbd.service']) === 'active') {
            Shell::run(['smbcontrol', 'smbd', 'close-share', $name], null, 15);
        }
    }

    /** Mount (sharing on) or unmount (off) everything, and rewrite the Samba shares. */
    public static function setEnabled(bool $on): array
    {
        self::locked(static function (array &$s) use ($on): void {
            $s['enabled'] = $on;
        });
        if ($on) {
            self::ensureInclude();
            $res = [];
            foreach (array_keys(self::bayDevices()) as $dev) {
                $res[$dev] = self::attach($dev);
            }
            self::locked(static fn(array &$s) => self::writeSamba($s));
            return $res;
        }
        return self::locked(static function (array &$s): array {
            $busy = [];
            foreach (array_keys(self::ourMounts()) as $mnt) {
                try {
                    self::unmount($mnt);
                } catch (RuntimeException) {
                    $busy[] = basename($mnt);
                }
            }
            self::writeSamba($s);
            if ($busy) {
                throw new RuntimeException('Sharing is off, but these disks are in use and stay mounted until they are free: ' . implode(', ', $busy));
            }
            return [];
        });
    }

    /** Where a recorded filesystem is right now: its device, or null. */
    private static function devOf(string $uuid): ?string
    {
        $link = '/dev/disk/by-uuid/' . $uuid;
        $real = is_link($link) ? realpath($link) : false;
        return $real ? basename($real) : null;
    }

    public static function setWritable(string $uuid, bool $on): void
    {
        self::locked(static function (array &$s) use ($uuid, $on): void {
            isset($s['disks'][$uuid]) || throw new InvalidArgumentException('Unknown disk');
            if ((bool) $s['disks'][$uuid]['writable'] === $on) {
                return;
            }
            $dev = self::devOf($uuid);
            $name = $s['disks'][$uuid]['name'];
            $target = self::ROOT . '/' . $name;
            $wasMounted = $dev !== null && self::mountedAnywhere($dev) === $target;
            $s['disks'][$uuid]['writable'] = $on;
            if ($wasMounted) {
                // Switch in place where we can, so people connected to the share aren't thrown off.
                // Not when allowing changes on ext3/4, XFS or Btrfs: their read-only mount skipped
                // the journal, and only a fresh mount replays it.
                $fresh = $on && !in_array($s['disks'][$uuid]['fstype'], self::FOREIGN_FS, true)
                    && in_array($s['disks'][$uuid]['fstype'], ['ext3', 'ext4', 'xfs', 'btrfs'], true);
                $code = 1;
                if (!$fresh) {
                    [$code] = Shell::run(['mount', '-o', 'remount,' . ($on ? 'rw' : 'ro'), $target], null, 60);
                }
                if ($code !== 0) {
                    self::unmount($target);
                    self::mountRec($s, $uuid, $dev);
                }
            }
            self::writeSamba($s);   // the share's "read only" changed, so its clients are reconnected
        });
    }

    /** Unmount every share on a disk (sdc), so it can be powered off or pulled. */
    public static function releaseDisk(string $disk): array
    {
        return self::locked(static function (array &$s) use ($disk): array {
            $done = [];
            foreach (self::ourMounts() as $mnt => $src) {
                if (self::diskOf(basename($src)) === $disk) {
                    self::unmount($mnt);
                    $done[] = basename($mnt);
                }
            }
            if ($done) {
                self::writeSamba($s);
            }
            return $done;
        });
    }

    /** Unmount a disk and switch its bay off: the bay light goes yellow, then it's safe to pull. */
    public static function eject(string $uuid): array
    {
        $dev = self::devOf($uuid) ?? throw new RuntimeException('That disk is not connected.');
        $disk = self::diskOf($dev);
        $slot = self::slotOfDev($dev);
        preg_match('/^bay([0-4])$/', $slot, $m) || throw new RuntimeException('That disk is not in a bay.');
        self::releaseDisk($disk);
        Hardware::diskMounted($disk) && throw new RuntimeException('Another filesystem on this disk is mounted outside the Sharing page; unmount it first.');
        Shell::run(['sync'], null, 60);
        Privileged::setBayPower((int) $m[1], false);
        return ['bay' => (int) $m[1]];
    }

    public static function forget(string $uuid): void
    {
        self::locked(static function (array &$s) use ($uuid): void {
            isset($s['disks'][$uuid]) || throw new InvalidArgumentException('Unknown disk');
            self::devOf($uuid) === null || throw new RuntimeException('Remove the disk before forgetting it.');
            unset($s['disks'][$uuid]);
            foreach ($s['users'] as &$u) {
                $u['disks'] = array_values(array_diff($u['disks'] ?? [], [$uuid]));
            }
        });
    }

    public static function rename(string $uuid, string $name): void
    {
        self::locked(static function (array &$s) use ($uuid, $name): void {
            isset($s['disks'][$uuid]) || throw new InvalidArgumentException('Unknown disk');
            preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,39}$/', $name)
                || throw new InvalidArgumentException('A share name uses letters, digits, spaces, dots, dashes and underscores (up to 40)');
            if ($name === $s['disks'][$uuid]['name']) {
                return;
            }
            $others = $s;
            unset($others['disks'][$uuid]);
            self::uniqueName($others, $name) === $name || throw new InvalidArgumentException("There is already a share called $name");
            $dev = self::devOf($uuid);
            $old = self::ROOT . '/' . $s['disks'][$uuid]['name'];
            $wasMounted = $dev !== null && self::mountedAnywhere($dev) === $old;
            if ($wasMounted) {
                self::unmount($old);
            }
            $s['disks'][$uuid]['name'] = $name;
            if ($wasMounted) {
                self::mountRec($s, $uuid, $dev);
            }
            self::writeSamba($s);
        });
    }

    // --- Samba -----------------------------------------------------------------

    public static function sambaInstalled(): bool
    {
        return is_executable('/usr/sbin/smbd') && is_file(self::SMB_CONF);
    }

    /** Add our include line to the end of smb.conf (once, with a .bak). */
    public static function ensureInclude(): void
    {
        if (!self::sambaInstalled()) {
            return;
        }
        $conf = (string) file_get_contents(self::SMB_CONF);
        if (str_contains($conf, self::INCLUDE)) {
            return;
        }
        if (!is_file(self::SHARES_CONF)) {
            file_put_contents(self::SHARES_CONF, "# Written by drobo-webui (Sharing page). Changes here are overwritten.\n");
        }
        copy(self::SMB_CONF, self::SMB_CONF . '.bak');
        file_put_contents(self::SMB_CONF . '.new', rtrim($conf) . "\n\n# Bay disks, one share each: written by drobo-webui (Sharing page)\n" . self::INCLUDE . "\n");
        chmod(self::SMB_CONF . '.new', 0644);
        rename(self::SMB_CONF . '.new', self::SMB_CONF);
    }

    /** Remove the include line (package removal). */
    public static function removeInclude(): void
    {
        $conf = @file_get_contents(self::SMB_CONF);
        if ($conf === false || !str_contains($conf, self::INCLUDE)) {
            return;
        }
        $conf = preg_replace('/\n*# Bay disks, one share each:[^\n]*\n/', "\n", $conf);
        $conf = str_replace(self::INCLUDE . "\n", '', $conf);
        file_put_contents(self::SMB_CONF . '.new', rtrim($conf) . "\n");
        chmod(self::SMB_CONF . '.new', 0644);
        rename(self::SMB_CONF . '.new', self::SMB_CONF);
    }

    /** Samba accounts (pdbedit): name => uid. */
    public static function sambaAccounts(): array
    {
        $out = [];
        foreach (explode("\n", (string) Shell::out(['pdbedit', '-L'], 15)) as $l) {
            [$name, $uid] = array_pad(explode(':', $l), 2, '');
            if ($name !== '') {
                $out[$name] = (int) $uid;
            }
        }
        return $out;
    }

    /** Who may use a disk, and who may change it. */
    private static function access(array $s, string $uuid, array $accounts): array
    {
        $valid = [];
        $write = [];
        foreach ($s['users'] as $name => $u) {
            if (!isset($accounts[$name]) || !(($u['all'] ?? false) || in_array($uuid, $u['disks'] ?? [], true))) {
                continue;
            }
            $valid[] = $name;
            if (!($u['readOnly'] ?? false)) {
                $write[] = $name;
            }
        }
        return [$valid, $write];
    }

    private static function writeSamba(array $s): void
    {
        if (!self::sambaInstalled()) {
            return;
        }
        $accounts = self::sambaAccounts();
        $mounted = self::ourMounts();
        $txt = "# Written by drobo-webui (Sharing page). Changes here are overwritten.\n";
        foreach ($s['disks'] as $uuid => $d) {
            $path = self::ROOT . '/' . $d['name'];
            if (!$s['enabled'] || !isset($mounted[$path])) {
                continue;
            }
            [$valid, $write] = self::access($s, $uuid, $accounts);
            if (!$valid) {
                continue;  // nobody may use it: don't offer it at all
            }
            $txt .= "\n[{$d['name']}]\n"
                . '   comment = ' . ($d['label'] !== '' ? $d['label'] : $d['model']) . " ({$d['fstype']})\n"
                . "   path = $path\n"
                . "   browseable = yes\n"
                . '   read only = ' . ($d['writable'] && $write ? 'no' : 'yes') . "\n"
                . '   valid users = ' . implode(' ', $valid) . "\n"
                . ($d['writable'] && $write ? '   write list = ' . implode(' ', $write) . "\n" : '')
                . '   force user = ' . self::SHARE_USER . "\n"
                . '   force group = ' . self::SHARE_USER . "\n"
                . "   create mask = 0664\n"
                . "   directory mask = 0775\n";
            if (in_array($d['fstype'], self::FOREIGN_FS, true)) {
                // No extended attributes on these: let Macs keep their metadata in ._ files on the disk.
                $txt .= "   vfs objects = catia\n   ea support = no\n";
            }
        }
        $old = self::stanzas((string) @file_get_contents(self::SHARES_CONF));
        file_put_contents(self::SHARES_CONF . '.new', $txt);
        chmod(self::SHARES_CONF . '.new', 0644);
        rename(self::SHARES_CONF . '.new', self::SHARES_CONF);
        if (Shell::out(['systemctl', 'is-active', 'smbd.service']) === 'active') {
            Shell::run(['smbcontrol', 'smbd', 'reload-config'], null, 15);
            // Samba checks access when a client connects, not afterwards: a share that went away or
            // changed (who may use it, read-only) disconnects its clients, so they reconnect under the new rules.
            $new = self::stanzas($txt);
            foreach ($old as $name => $body) {
                if (($new[$name] ?? null) !== $body) {
                    Shell::run(['smbcontrol', 'smbd', 'close-share', $name], null, 15);
                }
            }
        }
    }

    /** [share name => its lines] from our shares file. */
    private static function stanzas(string $txt): array
    {
        $out = [];
        foreach (preg_split('/^(?=\[)/m', $txt) as $chunk) {
            if (preg_match('/^\[([^\]]+)\]/', $chunk, $m)) {
                $out[$m[1]] = trim($chunk);
            }
        }
        return $out;
    }

    /** Rewrite the shares after a change that isn't a disk event. */
    public static function refresh(): void
    {
        self::locked(static fn(array &$s) => self::writeSamba($s));
    }

    // --- people ----------------------------------------------------------------

    private static function validName(string $name): void
    {
        preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $name)
            || throw new InvalidArgumentException('A user name is lower-case letters, digits, - and _ (up to 32), starting with a letter');
        in_array($name, self::RESERVED_USERS, true) && throw new InvalidArgumentException("$name is reserved");
    }

    private static function validPassword(string $pw): void
    {
        strlen($pw) >= 8 || throw new InvalidArgumentException('Use at least 8 characters');
        (strlen($pw) <= 128 && !preg_match('/[\x00-\x1f\x7f]/', $pw)) || throw new InvalidArgumentException('That password is too long or has control characters');
    }

    private static function cleanAccess(array $s, array $in): array
    {
        $disks = array_values(array_intersect(array_map('strval', (array) ($in['disks'] ?? [])), array_keys($s['disks'])));
        return ['all' => (bool) ($in['all'] ?? false), 'disks' => $disks, 'readOnly' => (bool) ($in['readOnly'] ?? false)];
    }

    private static function smbpasswd(array $args, string $pw): void
    {
        [$code, , $err] = Shell::run(array_merge(['smbpasswd', '-s'], $args), "$pw\n$pw\n", 20);
        $code === 0 || throw new RuntimeException('smbpasswd failed: ' . trim($err));
    }

    /** A new person: a Samba account (and, if needed, a Linux account that can't log in). */
    public static function addUser(array $in): void
    {
        self::sambaInstalled() || throw new RuntimeException('Samba is not installed.');
        $name = (string) ($in['name'] ?? '');
        $pw = (string) ($in['password'] ?? '');
        self::validName($name);
        self::validPassword($pw);
        isset(self::sambaAccounts()[$name]) && throw new InvalidArgumentException("$name already has a file-sharing account");
        self::locked(static function (array &$s) use ($name, $pw, $in): void {
            $pw_ = posix_getpwnam($name);
            $managed = false;
            if ($pw_ === false) {
                [$code, , $err] = Shell::run(['useradd', '--no-create-home', '--no-user-group', '--gid', self::SHARE_USER,
                    '--shell', '/usr/sbin/nologin', '--comment', 'File sharing only (drobo-webui)', $name]);
                $code === 0 || throw new RuntimeException('useradd failed: ' . trim($err));
                $managed = true;
            } elseif ($pw_['uid'] < 1000) {
                throw new InvalidArgumentException("$name is a system account");
            }
            try {
                self::smbpasswd(['-a', $name], $pw);
            } catch (RuntimeException $e) {
                if ($managed) {
                    Shell::run(['userdel', $name]);
                }
                throw $e;
            }
            $s['users'][$name] = self::cleanAccess($s, $in) + ['managed' => $managed];
            self::writeSamba($s);
        });
    }

    public static function setAccess(array $in): void
    {
        $name = (string) ($in['name'] ?? '');
        self::validName($name);
        isset(self::sambaAccounts()[$name]) || throw new InvalidArgumentException("$name has no file-sharing account");
        self::locked(static function (array &$s) use ($name, $in): void {
            $s['users'][$name] = self::cleanAccess($s, $in) + ['managed' => $s['users'][$name]['managed'] ?? false];
            self::writeSamba($s);
        });
    }

    public static function setPassword(array $in): void
    {
        $name = (string) ($in['name'] ?? '');
        $pw = (string) ($in['password'] ?? '');
        self::validName($name);
        self::validPassword($pw);
        isset(self::sambaAccounts()[$name]) || throw new InvalidArgumentException("$name has no file-sharing account");
        self::smbpasswd([$name], $pw);
    }

    public static function removeUser(string $name): void
    {
        self::validName($name);
        self::locked(static function (array &$s) use ($name): void {
            if (isset(self::sambaAccounts()[$name])) {
                [$code, , $err] = Shell::run(['smbpasswd', '-x', $name]);
                $code === 0 || throw new RuntimeException('smbpasswd failed: ' . trim($err));
            }
            if (($s['users'][$name]['managed'] ?? false) && posix_getpwnam($name)) {
                Shell::run(['userdel', $name]);
            }
            unset($s['users'][$name]);
            self::writeSamba($s);
        });
    }

    // --- what the page shows ---------------------------------------------------

    public static function state(): array
    {
        $s = self::load();
        $mounted = self::ourMounts();
        $accounts = self::sambaInstalled() ? self::sambaAccounts() : [];
        $bayDevs = self::bayDevices();
        $byUuid = [];
        foreach ($bayDevs as $dev => $p) {
            if ($p['uuid'] !== '') {
                $byUuid[$p['uuid']] = $dev;
            }
        }
        $disks = [];
        foreach ($s['disks'] as $uuid => $d) {
            $dev = $byUuid[$uuid] ?? null;
            $path = self::ROOT . '/' . $d['name'];
            $isMounted = isset($mounted[$path]);
            [$valid, $write] = self::access($s, (string) $uuid, $accounts);
            $disks[] = [
                'uuid' => (string) $uuid,
                'name' => $d['name'],
                'label' => $d['label'] ?? '',
                'fstype' => $d['fstype'] ?? '',
                'model' => $d['model'] ?? '',
                'bytes' => $d['bytes'] ?? null,
                'writable' => (bool) ($d['writable'] ?? false),
                'foreign' => in_array($d['fstype'] ?? '', self::FOREIGN_FS, true),
                'firstSeen' => $d['firstSeen'] ?? null,
                'lastSeen' => $d['lastSeen'] ?? null,
                'slot' => $dev ? $bayDevs[$dev]['slot'] : null,
                'lastSlot' => $d['lastSlot'] ?? null,
                'device' => $dev,
                'mounted' => $isMounted,
                'used' => $isMounted ? (float) (disk_total_space($path) - disk_free_space($path)) : null,
                'total' => $isMounted ? (float) disk_total_space($path) : null,
                'error' => $d['error'] ?? null,
                'users' => $valid,
                'writers' => $d['writable'] ?? false ? $write : [],
            ];
        }
        // Bay devices we can't share, so the page can say why
        $other = [];
        foreach ($bayDevs as $dev => $p) {
            if ($p['uuid'] !== '' && isset($s['disks'][$p['uuid']])) {
                continue;
            }
            $why = match (true) {
                $p['ptable'] !== '' && $dev === $p['disk'] => 'A partition table with no partitions on it',
                $p['usage'] === '' =>'No filesystem Linux can read (empty, or a Drobo BeyondRAID disk pack)',
                $p['usage'] !== 'filesystem' => "Part of something else ({$p['type']})",
                !isset(self::FS[$p['type']]) => "Unsupported filesystem ({$p['type']})",
                self::inFstab($dev, $p) => 'Set up by hand in /etc/fstab',
                default => 'Not seen yet: turn sharing on, or put the disk back in',
            };
            if ($why !== null) {
                $other[] = ['device' => $dev, 'slot' => $p['slot'], 'model' => $p['model'], 'reason' => $why];
            }
        }
        $users = [];
        foreach ($accounts as $name => $uid) {
            $u = $s['users'][$name] ?? ['all' => false, 'disks' => [], 'readOnly' => false, 'managed' => false];
            $users[] = ['name' => $name, 'all' => (bool) $u['all'], 'disks' => array_values($u['disks'] ?? []),
                'readOnly' => (bool) ($u['readOnly'] ?? false), 'managed' => (bool) ($u['managed'] ?? false)];
        }
        return [
            'enabled' => $s['enabled'],
            'samba' => [
                'installed' => self::sambaInstalled(),
                'running' => Shell::out(['systemctl', 'is-active', 'smbd.service']) === 'active',
                'included' => str_contains((string) @file_get_contents(self::SMB_CONF), self::INCLUDE),
            ],
            'hostname' => gethostname(),
            'disks' => $disks,
            'other' => $other,
            'users' => $users,
        ];
    }
}
