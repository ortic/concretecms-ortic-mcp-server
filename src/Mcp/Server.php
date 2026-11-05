<?php

namespace Ortic\McpServer\Mcp;

use Concrete\Core\Error\UserMessageException;
use Ortic\McpServer\Audit\AuditLog;

/**
 * JSON-RPC 2.0 message handling for the MCP methods this server supports.
 */
class Server
{
    public const SERVER_NAME = 'ortic-concretecms-mcp';
    public const SERVER_VERSION = '0.1.0';

    /** Newest first; the first one is offered when the client asks for an unknown version. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private const PARSE_ERROR = -32700;
    private const INVALID_REQUEST = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS = -32602;
    private const INTERNAL_ERROR = -32603;

    public function __construct(private ToolRegistry $registry, private AuditLog $audit)
    {
    }

    /**
     * @param mixed $payload decoded JSON body
     *
     * @return array|null response (object or batch array), or null when nothing must be returned
     */
    public function handlePayload($payload, ToolContext $context): ?array
    {
        if (is_array($payload) && array_is_list($payload) && $payload !== []) {
            $responses = [];
            foreach ($payload as $message) {
                $response = $this->handleMessage($message, $context);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses === [] ? null : $responses;
        }

        return $this->handleMessage($payload, $context);
    }

    public static function parseError(): array
    {
        return self::error(null, self::PARSE_ERROR, 'Parse error: the request body is not valid JSON.');
    }

    private function handleMessage($message, ToolContext $context): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error(null, self::INVALID_REQUEST, 'Invalid JSON-RPC 2.0 message.');
        }
        $hasId = array_key_exists('id', $message) && (is_string($message['id']) || is_int($message['id']));
        $id = $hasId ? $message['id'] : null;
        if (!isset($message['method'])) {
            // A response to a server-initiated request; this server sends none, so ignore it.
            return null;
        }
        if (!is_string($message['method'])) {
            return self::error($id, self::INVALID_REQUEST, 'The method must be a string.');
        }
        $params = $message['params'] ?? [];
        if (!is_array($params)) {
            return $hasId ? self::error($id, self::INVALID_PARAMS, 'params must be an object.') : null;
        }
        if (!$hasId) {
            // Notifications (notifications/initialized, notifications/cancelled, ...) need no answer.
            return null;
        }

        try {
            $result = $this->dispatch($message['method'], $params, $context);
        } catch (JsonRpcError $e) {
            return self::error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return self::error($id, self::INTERNAL_ERROR, 'Internal error: ' . $e->getMessage());
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function dispatch(string $method, array $params, ToolContext $context)
    {
        switch ($method) {
            case 'initialize':
                return $this->initialize($params, $context);
            case 'ping':
                return new \stdClass();
            case 'tools/list':
                return ['tools' => array_values(array_map(fn (Tool $tool) => $tool->definition(), $this->registry->available($context)))];
            case 'tools/call':
                return $this->callTool($params, $context);
            case 'resources/list':
                return ['resources' => []];
            case 'resources/templates/list':
                return ['resourceTemplates' => []];
            case 'prompts/list':
                return ['prompts' => []];
            case 'logging/setLevel':
                return new \stdClass();
            default:
                throw new JsonRpcError('Method not found: ' . $method, self::METHOD_NOT_FOUND);
        }
    }

    private function initialize(array $params, ToolContext $context): array
    {
        $requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];
        $siteName = (string) app('site')->getSite()->getSiteName();

        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'title' => 'Concrete CMS: ' . $siteName,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => $this->instructions($siteName, $context),
        ];
    }

    private function callTool(array $params, ToolContext $context): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new JsonRpcError('tools/call requires a tool name.', self::INVALID_PARAMS);
        }
        $arguments = $params['arguments'] ?? [];
        if ($arguments instanceof \stdClass) {
            $arguments = (array) $arguments;
        }
        if (!is_array($arguments)) {
            throw new JsonRpcError('Tool arguments must be an object.', self::INVALID_PARAMS);
        }
        $tool = $this->registry->find($name, $context);
        if ($tool === null) {
            throw new JsonRpcError('Unknown or disabled tool: ' . $name, self::INVALID_PARAMS);
        }

        $started = microtime(true);
        $isError = false;
        try {
            $result = ($tool->handler)(new Args($arguments), $context);
            $text = is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $message = null;
        } catch (ToolError | UserMessageException $e) {
            $isError = true;
            $text = $message = $e->getMessage();
        } catch (\Throwable $e) {
            $isError = true;
            $text = $message = sprintf('%s: %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage());
        }

        $this->audit->log(
            'tool_call',
            (int) $context->user->getUserID(),
            $context->access->tokenId,
            $name,
            $arguments,
            !$isError,
            $message,
            (int) round((microtime(true) - $started) * 1000),
            $context->ip
        );

        return [
            'content' => [['type' => 'text', 'text' => (string) $text]],
            'isError' => $isError,
        ];
    }

    private function instructions(string $siteName, ToolContext $context): string
    {
        $lines = [
            sprintf('You are connected to the Concrete CMS site "%s" as the user "%s". Every tool runs with that user\'s Concrete permissions.', $siteName, $context->user->getUserName()),
            'Pages are versioned: tools that change page content or properties write to the newest unapproved version, which visitors do not see until it is approved. Pass approve=true, or call approve_page_version, to publish. Tell the user when changes are still unapproved.',
            'To edit a page, call get_page with version="recent" to see its areas and block IDs, then use update_block, add_block or delete_block.',
            'Deleting a page moves it to the trash. Deleting files and users is permanent: confirm with the user first.',
        ];
        if ($context->developerAllowed) {
            $lines[] = 'Developer tools are enabled: you can read and change code in the directories allowed by the site owner (dev_* tools). Every change is backed up and can be reverted with dev_restore_file. PHP files are syntax-checked before they are saved, but a runtime error can still break the site, so make small changes, check the logs with dev_get_logs, and clear the cache with dev_clear_cache after changing templates or classes.';
        }

        return implode("\n\n", $lines);
    }

    private static function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
