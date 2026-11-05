<?php

namespace Ortic\McpServer\Audit;

use Concrete\Core\Database\Connection\Connection;
use Ortic\McpServer\Settings;

class AuditLog
{
    private const TABLE = 'OrticMcpAuditLog';
    private const MAX_ARGUMENT_LENGTH = 4000;
    private const SENSITIVE_KEYS = ['password', 'secret', 'token', 'content_base64', 'client_secret'];

    public function __construct(private Connection $db, private Settings $settings)
    {
    }

    public function log(string $event, ?int $userId, ?int $tokenId, ?string $tool = null, $arguments = null, bool $success = true, ?string $message = null, ?int $durationMs = null, string $ip = ''): void
    {
        if (!$this->settings->isAuditEnabled()) {
            return;
        }
        try {
            $this->db->insert(self::TABLE, [
                'createdAt' => date('Y-m-d H:i:s'),
                'uID' => $userId,
                'tokenID' => $tokenId,
                'event' => $event,
                'tool' => $tool,
                'arguments' => $arguments === null ? null : $this->encodeArguments($arguments),
                'success' => $success ? 1 : 0,
                'message' => $message !== null ? mb_substr($message, 0, 2000) : null,
                'durationMs' => $durationMs,
                'ip' => substr($ip, 0, 64),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break a tool call.
        }
    }

    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . $where . ' ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . $where, $params);

        return [$rows, $total];
    }

    public function purgeOlderThan(int $days): int
    {
        return (int) $this->db->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE createdAt < ?', [date('Y-m-d H:i:s', time() - $days * 86400)]);
    }

    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];
        if (!empty($filters['tool'])) {
            $conditions[] = 'tool = ?';
            $params[] = $filters['tool'];
        }
        if (!empty($filters['uID'])) {
            $conditions[] = 'uID = ?';
            $params[] = (int) $filters['uID'];
        }
        if (isset($filters['success']) && $filters['success'] !== '') {
            $conditions[] = 'success = ?';
            $params[] = $filters['success'] ? 1 : 0;
        }
        if (!empty($filters['event'])) {
            $conditions[] = 'event = ?';
            $params[] = $filters['event'];
        }

        return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    private function encodeArguments($arguments): string
    {
        $json = json_encode($this->redact($arguments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            return '';
        }
        if (strlen($json) > self::MAX_ARGUMENT_LENGTH) {
            $json = mb_strcut($json, 0, self::MAX_ARGUMENT_LENGTH) . '…';
        }

        return $json;
    }

    private function redact($value, ?string $key = null)
    {
        if ($key !== null) {
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    return '[redacted]';
                }
            }
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->redact($v, is_string($k) ? $k : null);
            }

            return $result;
        }
        if (is_string($value) && strlen($value) > 500) {
            return mb_strcut($value, 0, 500) . '… (' . strlen($value) . ' bytes)';
        }

        return $value;
    }
}
