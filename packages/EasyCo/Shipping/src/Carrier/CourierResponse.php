<?php

namespace EasyCo\Shipping\Carrier;

/** A courier's answer: a status and a body. The body may carry personal data, so it is never logged by the client. */
final class CourierResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed>|null null when the body is not a JSON object or array */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Never prints the body. */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'bytes' => strlen($this->body)];
    }
}
