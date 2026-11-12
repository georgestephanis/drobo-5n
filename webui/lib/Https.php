<?php
/**
 * The dashboard's self-signed HTTPS certificate (made by drobo-webui-cert). The certificate is
 * public, so the web side reads it directly; only making a new one goes through the helper.
 */
declare(strict_types=1);

namespace DroboWebui;

final class Https
{
    public const CERT = '/etc/drobo-webui/tls/cert.pem';

    public static function pem(): ?string
    {
        $pem = @file_get_contents(self::CERT);
        return is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE') ? $pem : null;
    }

    /** Names, dates and fingerprint, plus whether this request came in over HTTPS. */
    public static function info(): array
    {
        $on = ($_SERVER['HTTPS'] ?? '') === 'on';
        $pem = self::pem();
        if ($pem === null || !function_exists('openssl_x509_parse') || !($x = openssl_x509_parse($pem))) {
            return ['https' => $on, 'cert' => null];
        }
        $names = [];
        foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $n) {
            $n = trim($n);
            if ($n !== '') {
                $names[] = preg_replace('/^(DNS|IP Address):/', '', $n);
            }
        }
        $fp = strtoupper((string) openssl_x509_fingerprint($pem, 'sha256'));
        return [
            'https' => $on,
            'cert' => [
                'names' => $names,
                'notBefore' => (int) $x['validFrom_time_t'],
                'notAfter' => (int) $x['validTo_time_t'],
                'sha256' => implode(':', str_split($fp, 2)),
            ],
        ];
    }
}
