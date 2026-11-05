<?php

namespace Ortic\McpServer\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait JsonResponses
{
    protected function json($data, int $status = 200, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * CORS for endpoints meant to be called by browser-based clients without cookies.
     */
    protected function withPublicCors(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, MCP-Protocol-Version');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    protected function clientIp(): string
    {
        return (string) $this->request->getClientIp();
    }
}
