<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\ApiBridge;
use Laravel\Mcp\Response;

trait CallsApi
{
    /** Runs one REST API call and answers with its JSON, or its error as a tool error (same codes as the API). */
    protected function api(string $method, string $path, array $params = []): Response
    {
        [$status, $body] = app(ApiBridge::class)->call($method, $path, $params);

        if ($status >= 200 && $status < 300) {
            return Response::json($body);
        }

        $code = $body['error'] ?? 'http_'.$status;
        $message = $body['message'] ?? 'The request failed.';
        $details = isset($body['errors']) ? ' '.json_encode($body['errors'], JSON_UNESCAPED_UNICODE) : '';

        return Response::error("{$code} ({$status}): {$message}{$details}");
    }

    /** Removes null values, so optional arguments that were not given are not sent. */
    protected function filled(array $params): array
    {
        return array_filter($params, fn ($v) => $v !== null);
    }
}
