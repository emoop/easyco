<?php

namespace EasyCo\CourierEcont\Tests;

use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\HttpCall;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Shipping\Exceptions\CourierHttpException;

/** A CourierHttp that never touches a network: it answers from a queue and records what was asked. */
final class FakeCourierHttp implements CourierHttp
{
    /** @var list<CourierResponse|CourierHttpException> */
    private array $answers = [];

    /** @var list<array{method: string, host: string, path: string, query: array<string, mixed>, body: array<string, mixed>, call: HttpCall}> */
    public array $calls = [];

    public function answer(CourierResponse|CourierHttpException $answer): self
    {
        $this->answers[] = $answer;

        return $this;
    }

    public function postJson(string $host, string $path, array $body, HttpCall $call, array $headers = []): CourierResponse
    {
        return $this->next('POST', $host, $path, [], $body, $call);
    }

    public function get(string $host, string $path, array $query, HttpCall $call, array $headers = []): CourierResponse
    {
        return $this->next('GET', $host, $path, $query, [], $call);
    }

    private function next(string $method, string $host, string $path, array $query, array $body, HttpCall $call): CourierResponse
    {
        $this->calls[] = compact('method', 'host', 'path', 'query', 'body', 'call');
        $answer = array_shift($this->answers) ?? throw new \LogicException('FakeCourierHttp has no answer queued.');

        if ($answer instanceof CourierHttpException) {
            throw $answer;
        }

        return $answer;
    }
}
