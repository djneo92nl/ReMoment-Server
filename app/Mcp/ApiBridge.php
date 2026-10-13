<?php

namespace App\Mcp;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Calls the REST API in-process, so MCP tools get exactly its validation, capability checks, error mapping and
 * `admin.token` guard instead of a second implementation. The caller's bearer token is forwarded.
 */
class ApiBridge
{
    public function __construct(private Router $router) {}

    /** @return array{0: int, 1: array} HTTP status and decoded JSON body */
    public function call(string $method, string $path, array $params = []): array
    {
        $original = app('request');
        $method = strtoupper($method);

        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($token = $original->bearerToken()) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $request = $method === 'GET'
            ? Request::create('/api/'.ltrim($path, '/'), 'GET', $params, [], [], $server)
            : Request::create('/api/'.ltrim($path, '/'), $method, [], [], [], $server, json_encode($params, JSON_UNESCAPED_UNICODE));

        try {
            app()->instance('request', $request);
            $response = $this->router->dispatch($request);
        } catch (\Throwable $e) {
            $response = app(ExceptionHandler::class)->render($request, $e);
        } finally {
            app()->instance('request', $original);
        }

        $body = json_decode($response->getContent() ?: '[]', true);

        return [$response->getStatusCode(), is_array($body) ? $body : ['data' => $body]];
    }
}
