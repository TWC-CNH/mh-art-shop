<?php
declare(strict_types=1);

namespace Tests\Support;

final class Response
{
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function json(): array
    {
        return json_decode($this->body, true) ?? [];
    }
}
