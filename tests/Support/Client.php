<?php
declare(strict_types=1);

namespace Tests\Support;

/** Einfacher HTTP-Client mit Cookies und wählbarer Besucher-IP (X-Forwarded-For, nur in Tests vertrauenswürdig). */
final class Client
{
    private array $cookies = [];

    public function __construct(private string $ip = '203.0.113.1')
    {
    }

    public function withIp(string $ip): self
    {
        $c = clone $this;
        $c->ip = $ip;
        return $c;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function get(string $path, array $headers = []): Response
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** POST als Formular (Array) oder roher Body (String); Origin der eigenen Seite ist voreingestellt. */
    public function post(string $path, array|string $data = [], array $headers = []): Response
    {
        return $this->request('POST', $path, $data, array_merge(['Origin: ' . Stack::base()], $headers));
    }

    public function postJson(string $path, array $data): Response
    {
        return $this->post($path, json_encode($data), ['Content-Type: application/json', 'Accept: application/json']);
    }

    public function request(string $method, string $path, array|string|null $data = null, array $headers = []): Response
    {
        $ch = curl_init(Stack::base() . $path);
        $headers[] = 'X-Forwarded-For: ' . $this->ip;
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))][] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        foreach ($respHeaders['set-cookie'] ?? [] as $c) {
            [$pair] = explode(';', $c, 2);
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if ($v === '' || str_contains(strtolower($c), 'max-age=0') || preg_match('/expires=[^;]*1970/i', $c)) {
                unset($this->cookies[$k]);
            } else {
                $this->cookies[$k] = $v;
            }
        }
        return new Response($status, $respHeaders, $body);
    }
}
