<?php

namespace GameAP\Whmcs\Tests;

use WHMCS\Module\Server\Gameap\HttpClient;
use WHMCS\Module\Server\Gameap\ModuleException;

/**
 * Stands in for the real transport. Responses are queued per "METHOD /path"
 * so a test states exactly what the panel answers, and every call is recorded
 * so a test can assert the request the module would actually send — which is
 * where the interesting bugs live.
 */
class FakeHttpClient extends HttpClient
{
    /** @var array<string,array<int,mixed>> */
    private array $responses = [];

    /** @var array<int,array{method:string,path:string,body:?array,query:array}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('https://panel.test', 'test-token');
    }

    /**
     * Sets the answer for one endpoint, replacing anything set before. Tests
     * build a working baseline and then override one endpoint, so replacing
     * is the behaviour that reads correctly at the call site.
     *
     * @param array<mixed>|ModuleException $response
     */
    public function on(string $method, string $path, $response): self
    {
        $this->responses[$method . ' ' . $path] = [$response];

        return $this;
    }

    /**
     * Answers successive calls to the same endpoint differently — needed when
     * one flow queries the same path once per node.
     *
     * @param array<int,array<mixed>|ModuleException> $responses
     */
    public function onEach(string $method, string $path, array $responses): self
    {
        $this->responses[$method . ' ' . $path] = $responses;

        return $this;
    }

    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'query' => $query];

        $key = $method . ' ' . $path;

        if (!isset($this->responses[$key]) || $this->responses[$key] === []) {
            throw new \RuntimeException('Unexpected request: ' . $key);
        }

        $response = count($this->responses[$key]) > 1
            ? array_shift($this->responses[$key])
            : $this->responses[$key][0];

        if ($response instanceof ModuleException) {
            throw $response;
        }

        return $response;
    }

    /**
     * @return array<int,array{method:string,path:string,body:?array,query:array}>
     */
    public function callsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call) => $call['method'] === $method && $call['path'] === $path
        ));
    }

    public function called(string $method, string $path): bool
    {
        return $this->callsTo($method, $path) !== [];
    }
}
