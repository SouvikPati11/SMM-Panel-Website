<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dependency-free mailer: SMTP (SSL or STARTTLS + AUTH LOGIN), PHP mail(), or log-only.
 * Works with Hostinger/cPanel mailboxes.
 */
final class Mailer
{
    /** @var list<array{to:string,subject:string,html:string}> captured when driver=array (tests) */
    public static array $sent = [];

    /** @param array{driver:string,host?:string,port?:int,encryption?:string,username?:string,password?:string,from:string,from_name?:string} $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function send(string $to, string $subject, string $html): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid recipient');
        }
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $driver = $this->cfg['driver'] ?? 'log';

        match ($driver) {
            'smtp' => $this->sendSmtp($to, $subject, $html),
            'mail' => $this->sendMail($to, $subject, $html),
            'array' => self::$sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html],
            default => Logger::info("Mail (log driver) to {$to}: {$subject}", [], 'mail'),
        };
    }

    private function from(): string
    {
        $from = (string) ($this->cfg['from'] ?? '');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Mail "from" address is not configured.');
        }
        return $from;
    }

    private function headers(string $to, string $subject, bool $includeToSubject): string
    {
        $name = str_replace(['"', "\r", "\n"], '', (string) ($this->cfg['from_name'] ?? ''));
        $h = [];
        $h[] = 'From: ' . ($name !== '' ? '=?UTF-8?B?' . base64_encode($name) . '?= ' : '') . '<' . $this->from() . '>';
        if ($includeToSubject) {
            $h[] = 'To: <' . $to . '>';
            $h[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        $h[] = 'Date: ' . date('r');
        $h[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url((string) Config::get('url'), PHP_URL_HOST) ?: 'localhost') . '>';
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: text/html; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        return implode("\r\n", $h);
    }

    private function sendMail(string $to, string $subject, string $html): void
    {
        $ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($html)), $this->headers($to, $subject, false), '-f' . $this->from());
        if (!$ok) {
            throw new \RuntimeException('mail() returned false');
        }
    }

    private function sendSmtp(string $to, string $subject, string $html): void
    {
        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int) ($this->cfg['port'] ?? 587);
        $enc = strtolower((string) ($this->cfg['encryption'] ?? 'tls'));
        if ($host === '') {
            throw new \RuntimeException('SMTP host not configured');
        }
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, 20);
        try {
            $this->expect($fp, [220]);
            $ehloHost = parse_url((string) Config::get('url'), PHP_URL_HOST) ?: 'localhost';
            $this->cmd($fp, "EHLO {$ehloHost}", [250]);
            if ($enc === 'tls') {
                $this->cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }
                $this->cmd($fp, "EHLO {$ehloHost}", [250]);
            }
            $user = (string) ($this->cfg['username'] ?? '');
            if ($user !== '') {
                $this->cmd($fp, 'AUTH LOGIN', [334]);
                $this->cmd($fp, base64_encode($user), [334]);
                $this->cmd($fp, base64_encode((string) ($this->cfg['password'] ?? '')), [235]);
            }
            $this->cmd($fp, 'MAIL FROM:<' . $this->from() . '>', [250]);
            $this->cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd($fp, 'DATA', [354]);
            $data = $this->headers($to, $subject, true) . "\r\n\r\n" . chunk_split(base64_encode($html));
            $data = preg_replace('/^\./m', '..', $data);
            $this->cmd($fp, $data . "\r\n.", [250]);
            $this->cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }

    private function cmd($fp, string $line, array $codes): string
    {
        fwrite($fp, $line . "\r\n");
        return $this->expect($fp, $codes);
    }

    private function expect($fp, array $codes): string
    {
        $resp = '';
        while (($line = fgets($fp, 515)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('SMTP error: ' . trim($resp));
        }
        return $resp;
    }
}
