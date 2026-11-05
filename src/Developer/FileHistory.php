<?php

namespace Ortic\McpServer\Developer;

use Concrete\Core\Database\Connection\Connection;
use Doctrine\DBAL\ParameterType;
use Ortic\McpServer\Mcp\ToolError;

/**
 * Stores the previous content of every file changed in developer mode, and applies changes atomically.
 */
class FileHistory
{
    private const TABLE = 'OrticMcpFileHistory';

    public function __construct(private Connection $db, private PathGuard $guard)
    {
    }

    /**
     * Write (or delete, when $content is null) a file and record the change.
     *
     * @return int history entry ID
     */
    public function apply(string $absolute, ?string $content, ?int $userId, ?int $tokenId, ?int $restoredFrom = null): int
    {
        $previous = is_file($absolute) ? file_get_contents($absolute) : null;
        if ($previous === false) {
            throw new ToolError('The current file content could not be read.');
        }
        if ($content === null) {
            if ($previous === null) {
                throw new ToolError('The file does not exist.');
            }
            if (!@unlink($absolute)) {
                throw new ToolError('The file could not be deleted (check the file permissions).');
            }
            $action = 'delete';
        } else {
            $this->write($absolute, $content);
            $action = $previous === null ? 'create' : 'update';
        }
        if ($restoredFrom !== null) {
            $action = 'restore';
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($absolute, true);
        }

        $this->db->insert(self::TABLE, [
            'createdAt' => date('Y-m-d H:i:s'),
            'uID' => $userId,
            'tokenID' => $tokenId,
            'path' => $this->guard->relative($absolute),
            'action' => $action,
            'previousContent' => $previous,
            'previousHash' => $previous !== null ? hash('sha256', $previous) : null,
            'newHash' => $content !== null ? hash('sha256', $content) : null,
            'newSize' => $content !== null ? strlen($content) : null,
            'restoredFromID' => $restoredFrom,
        ], [
            'previousContent' => $previous === null ? ParameterType::NULL : ParameterType::LARGE_OBJECT,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function get(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
        if ($row && is_resource($row['previousContent'])) {
            $row['previousContent'] = stream_get_contents($row['previousContent']);
        }

        return $row ?: null;
    }

    /**
     * Undo one change: put back the content the file had before it.
     */
    public function restore(int $id, ?int $userId, ?int $tokenId, bool $fromDashboard = false): array
    {
        $entry = $this->get($id);
        if (!$entry) {
            throw new ToolError('History entry not found.');
        }
        $absolute = $fromDashboard ? $this->guard->resolveForRestore($entry['path']) : $this->guard->resolveForWrite($entry['path']);
        $previous = $entry['previousContent'];
        if ($previous === null) {
            if (!is_file($absolute)) {
                throw new ToolError('The file was created by this change and has already been removed.');
            }
            $newId = $this->apply($absolute, null, $userId, $tokenId, $id);

            return ['path' => $entry['path'], 'result' => 'deleted (the change being undone had created it)', 'history_id' => $newId];
        }
        $newId = $this->apply($absolute, (string) $previous, $userId, $tokenId, $id);

        return ['path' => $entry['path'], 'result' => 'restored', 'history_id' => $newId];
    }

    public function search(?string $path, int $limit, int $offset = 0): array
    {
        $where = '';
        $params = [];
        if ($path !== null && $path !== '') {
            $where = ' WHERE path LIKE ?';
            $params[] = str_replace(['%', '_'], ['\%', '\_'], $path) . '%';
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, createdAt, uID, tokenID, path, action, previousHash, newHash, newSize, restoredFromID, previousContent IS NOT NULL AS hasPrevious FROM '
            . self::TABLE . $where . ' ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . $where, $params);

        return [$rows, $total];
    }

    private function write(string $absolute, string $content): void
    {
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ToolError('The directory could not be created (check the file permissions).');
        }
        $tmp = $dir . '/.mcp-' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            throw new ToolError('The file could not be written (check the file permissions).');
        }
        @chmod($tmp, is_file($absolute) ? (fileperms($absolute) & 0777) : 0664);
        if (!@rename($tmp, $absolute)) {
            @unlink($tmp);
            throw new ToolError('The file could not be replaced (check the file permissions).');
        }
        clearstatcache(true, $absolute);
    }
}
