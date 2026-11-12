<?php
declare(strict_types=1);

namespace DroboWebui;

use RuntimeException;

/**
 * A small SMTP client for alert mail, so the board needs no mail server.
 * Supports plain, STARTTLS and SSL (implicit TLS) connections and AUTH LOGIN/PLAIN.
 */
final class Smtp
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(private array $cfg)
    {
    }

    public static function send(array $alertsCfg, string $subject, string $body): void
    {
        (new self($alertsCfg))->deliver($subject, $body);
    }

    private function deliver(string $subject, string $body): void
    {
        $c = $this->cfg;
        $host = $c['server'];
        $from = $c['from'] !== '' ? $c['from'] : 'drobo@' . (gethostname() ?: 'localhost');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $url = ($c['security'] === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $c['port'];
        $this->sock = @stream_socket_client($url, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) {
            throw new RuntimeException("Can't connect to $host:{$c['port']}: $errstr");
        }
        stream_set_timeout($this->sock, 20);
        try {
            $this->expect(220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'drobo');
            $caps = $this->cmd($ehlo, 250);
            if ($c['security'] === 'starttls') {
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                $caps = $this->cmd($ehlo, 250);
            }
            if ($c['username'] !== '') {
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $caps)) {
                    $this->cmd('AUTH PLAIN ' . base64_encode("\0{$c['username']}\0{$c['password']}"), 235);
                } else {
                    $this->cmd('AUTH LOGIN', 334);
                    $this->cmd(base64_encode($c['username']), 334);
                    $this->cmd(base64_encode($c['password']), 235);
                }
            }
            $this->cmd("MAIL FROM:<$from>", 250);
            $this->cmd("RCPT TO:<{$c['to']}>", [250, 251]);
            $this->cmd('DATA', 354);
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                "From: Drobo 5N <$from>",
                "To: <{$c['to']}>",
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (gethostname() ?: 'drobo') . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $text = str_replace(["\r\n", "\r"], "\n", $body);
            $text = preg_replace('/^\./m', '..', $text);  // dot-stuffing
            $this->cmd(implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $text) . "\r\n.", 250);
            $this->cmd('QUIT', 221);
        } finally {
            fclose($this->sock);
            $this->sock = null;
        }
    }

    /** @param int|list<int> $want */
    private function cmd(string $line, int|array $want): string
    {
        fwrite($this->sock, $line . "\r\n");
        return $this->expect($want);
    }

    /** @param int|list<int> $want */
    private function expect(int|array $want): string
    {
        $resp = '';
        while (($l = fgets($this->sock, 2048)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, (array) $want, true)) {
            throw new RuntimeException('Mail server said: ' . trim($resp ?: 'nothing (connection closed)'));
        }
        return $resp;
    }
}
