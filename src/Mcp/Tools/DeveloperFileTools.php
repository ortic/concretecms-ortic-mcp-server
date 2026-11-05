<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\User\UserInfoRepository;
use Ortic\McpServer\Developer\FileHistory;
use Ortic\McpServer\Developer\PathGuard;
use Ortic\McpServer\Developer\PhpLinter;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolContext;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\ToolProviderInterface;
use Ortic\McpServer\Settings;

/**
 * Developer mode: read and change code outside the Concrete core.
 */
class DeveloperFileTools implements ToolProviderInterface
{
    private const MAX_LIST_ENTRIES = 1000;
    private const MAX_SEARCH_FILES = 20000;
    private const MAX_SEARCH_SECONDS = 20;

    public function __construct(
        private PathGuard $guard,
        private FileHistory $history,
        private Settings $settings,
        private UserInfoRepository $users
    ) {
    }

    public function getTools(): array
    {
        $path = ['type' => 'string', 'description' => 'Path relative to the web root, e.g. "application/themes/my_theme/css/main.css".'];

        return [
            new Tool(
                name: 'dev_list_files',
                group: 'developer_files',
                title: 'List files',
                description: 'List files and directories. Without a path, lists the directories developer mode can access (application/, packages/ and, read-only, concrete/).',
                properties: [
                    'path' => ['type' => 'string', 'description' => 'Directory relative to the web root. Default: top level.'],
                    'recursive' => ['type' => 'boolean', 'description' => 'Include subdirectories (default false, max 1000 entries; vendor/ and node_modules/ are skipped).'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->listFiles($a),
                idempotent: true
            ),
            new Tool(
                name: 'dev_read_file',
                group: 'developer_files',
                title: 'Read file',
                description: 'Read a text file. Use start_line/end_line for large files.',
                properties: [
                    'path' => $path,
                    'start_line' => ['type' => 'integer', 'description' => 'First line (1-based).'],
                    'end_line' => ['type' => 'integer', 'description' => 'Last line (inclusive).'],
                ],
                required: ['path'],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->readFile($a),
                idempotent: true
            ),
            new Tool(
                name: 'dev_search_files',
                group: 'developer_files',
                title: 'Search in files',
                description: 'Search text (or a regular expression) in files, like grep. Returns matching lines with line numbers.',
                properties: [
                    'pattern' => ['type' => 'string', 'description' => 'Text to find, or a PCRE pattern without delimiters if regex=true.'],
                    'regex' => ['type' => 'boolean', 'description' => 'Treat pattern as a regular expression (default false).'],
                    'case_sensitive' => ['type' => 'boolean', 'description' => 'Default false.'],
                    'path' => ['type' => 'string', 'description' => 'Directory or file to search. Default: application/ and packages/.'],
                    'file_pattern' => ['type' => 'string', 'description' => 'Only file names matching this glob, e.g. "*.php".'],
                    'max_results' => ['type' => 'integer', 'description' => 'Default 100, max 500.'],
                ],
                required: ['pattern'],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->searchFiles($a),
                idempotent: true
            ),
            new Tool(
                name: 'dev_write_file',
                group: 'developer_files',
                title: 'Write file',
                description: 'Create or overwrite a file with the given content (directories are created as needed). PHP files are syntax-checked and not saved if they contain errors. The previous content is kept in the file history.',
                properties: [
                    'path' => $path,
                    'content' => ['type' => 'string', 'description' => 'The complete new file content.'],
                ],
                required: ['path', 'content'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a, ToolContext $c) => $this->writeFile($a, $c),
                idempotent: true
            ),
            new Tool(
                name: 'dev_edit_file',
                group: 'developer_files',
                title: 'Edit file',
                description: 'Replace an exact piece of text in an existing file. old_text must match exactly once (including whitespace) unless replace_all=true. Prefer this over dev_write_file for small changes.',
                properties: [
                    'path' => $path,
                    'old_text' => ['type' => 'string', 'description' => 'Exact text to replace.'],
                    'new_text' => ['type' => 'string', 'description' => 'Replacement text.'],
                    'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence (default false).'],
                ],
                required: ['path', 'old_text', 'new_text'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a, ToolContext $c) => $this->editFile($a, $c)
            ),
            new Tool(
                name: 'dev_delete_file',
                group: 'developer_files',
                title: 'Delete file',
                description: 'Delete a file. Its content is kept in the file history and can be restored with dev_restore_file.',
                properties: ['path' => $path],
                required: ['path'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a, ToolContext $c) => $this->deleteFile($a, $c)
            ),
            new Tool(
                name: 'dev_list_file_history',
                group: 'developer_files',
                title: 'List file changes',
                description: 'List the changes made with developer tools, newest first, optionally for one file or directory.',
                properties: [
                    'path' => ['type' => 'string', 'description' => 'File or directory prefix to filter by.'],
                    'limit' => ['type' => 'integer', 'description' => 'Default 25.'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->listHistory($a),
                idempotent: true
            ),
            new Tool(
                name: 'dev_restore_file',
                group: 'developer_files',
                title: 'Undo file change',
                description: 'Undo one change from the file history: the file gets back the content it had before that change (a file created by that change is deleted).',
                properties: [
                    'history_id' => ['type' => 'integer', 'description' => 'ID from dev_list_file_history or from a write/edit/delete result.'],
                ],
                required: ['history_id'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a, ToolContext $c) => $this->history->restore($a->requireInt('history_id'), (int) $c->user->getUserID(), $c->access->tokenId)
            ),
        ];
    }

    private function listFiles(Args $args): array
    {
        $relative = $this->guard->normalize((string) $args->string('path', ''));
        if ($relative === '') {
            $entries = [];
            foreach ($this->guard->readableRoots() as $root) {
                if (is_dir($this->guard->getBase() . '/' . $root)) {
                    $entries[] = [
                        'path' => $root,
                        'type' => 'dir',
                        'writable' => in_array($root, $this->guard->writableRoots(), true),
                    ];
                }
            }

            return ['base' => 'web root', 'entries' => $entries];
        }
        $absolute = $this->guard->resolveForRead($relative);
        if (!is_dir($absolute)) {
            throw new ToolError(sprintf('"%s" is not a directory.', $relative));
        }
        $entries = [];
        $truncated = false;
        $this->collect($absolute, $args->bool('recursive'), $entries, $truncated);
        usort($entries, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return ['path' => $relative, 'entries' => $entries, 'truncated' => $truncated];
    }

    private function collect(string $dir, bool $recursive, array &$entries, bool &$truncated): void
    {
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.mcp-')) {
                continue;
            }
            if (count($entries) >= self::MAX_LIST_ENTRIES) {
                $truncated = true;

                return;
            }
            $absolute = $dir . '/' . $item;
            $relative = $this->guard->relative($absolute);
            if ($this->guard->isSecret($relative)) {
                continue;
            }
            $isDir = is_dir($absolute);
            $entry = ['path' => $relative, 'type' => $isDir ? 'dir' : 'file'];
            if (!$isDir) {
                $entry['size'] = (int) @filesize($absolute);
                $entry['modified'] = date(DATE_ATOM, (int) @filemtime($absolute));
            }
            if (is_link($absolute)) {
                $entry['symlink'] = true;
            }
            $entries[] = $entry;
            if ($isDir && $recursive && !is_link($absolute) && !in_array($item, PathGuard::SKIPPED_DIRECTORIES, true)) {
                $this->collect($absolute, true, $entries, $truncated);
            }
        }
    }

    private function readFile(Args $args): array
    {
        $absolute = $this->guard->resolveForRead($args->requireString('path'));
        if (!is_file($absolute)) {
            throw new ToolError('Not a file.');
        }
        $size = (int) filesize($absolute);
        if ($size > max($this->settings->getMaxFileSize(), 2 * 1024 * 1024)) {
            throw new ToolError('The file is too large to read.');
        }
        $content = (string) file_get_contents($absolute);
        if (str_contains(substr($content, 0, 8000), "\0")) {
            throw new ToolError('This is a binary file.');
        }
        $lines = preg_split('/\r\n|\n|\r/', $content);
        $total = count($lines);
        $start = $args->int('start_line');
        $end = $args->int('end_line');
        $result = [
            'path' => $this->guard->relative($absolute),
            'size' => $size,
            'modified' => date(DATE_ATOM, (int) filemtime($absolute)),
            'total_lines' => $total,
            'writable' => $this->isWritable($absolute),
        ];
        if ($start !== null || $end !== null) {
            $start = max(1, (int) $start);
            $end = min($total, $end ?? $total);
            $result['start_line'] = $start;
            $result['end_line'] = $end;
            $result['content'] = implode("\n", array_slice($lines, $start - 1, max(0, $end - $start + 1)));
        } else {
            $result['content'] = $content;
        }

        return $result;
    }

    private function searchFiles(Args $args): array
    {
        $pattern = $args->string('pattern', null, true);
        if ($pattern === '') {
            throw new ToolError('pattern must not be empty.');
        }
        $flags = $args->bool('case_sensitive') ? 'u' : 'iu';
        $regex = $args->bool('regex') ? '~' . str_replace('~', '\~', $pattern) . '~' . $flags : '~' . preg_quote($pattern, '~') . '~' . $flags;
        if (@preg_match($regex, '') === false) {
            throw new ToolError('Invalid regular expression.');
        }
        $glob = $args->string('file_pattern');
        $max = max(1, min(500, (int) $args->int('max_results', 100)));
        $paths = $args->has('path')
            ? [$this->guard->resolveForRead((string) $args->string('path'))]
            : array_map(fn ($root) => $this->guard->resolveForRead($root), array_values(array_diff($this->guard->readableRoots(), ['concrete'])));

        $matches = [];
        $scanned = 0;
        $started = microtime(true);
        $stopReason = null;
        foreach ($paths as $start) {
            $files = is_file($start) ? [new \SplFileInfo($start)] : new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS),
                    fn (\SplFileInfo $file) => !($file->isDir() && (in_array($file->getFilename(), PathGuard::SKIPPED_DIRECTORIES, true) || $file->isLink()))
                        && !$this->guard->isSecret($this->guard->relative($file->getPathname()))
                )
            );
            foreach ($files as $file) {
                if (!$file->isFile() || ($glob !== null && $glob !== '' && !fnmatch($glob, $file->getFilename()))) {
                    continue;
                }
                if (++$scanned > self::MAX_SEARCH_FILES || microtime(true) - $started > self::MAX_SEARCH_SECONDS) {
                    $stopReason = 'Search stopped early (too many files or too slow): narrow it down with path or file_pattern.';
                    break 2;
                }
                if ($file->getSize() > 1024 * 1024) {
                    continue;
                }
                $content = @file_get_contents($file->getPathname());
                if ($content === false || str_contains(substr($content, 0, 8000), "\0") || !preg_match($regex, $content)) {
                    continue;
                }
                foreach (preg_split('/\r\n|\n|\r/', $content) as $index => $line) {
                    if (preg_match($regex, $line)) {
                        $matches[] = [
                            'path' => $this->guard->relative($file->getPathname()),
                            'line' => $index + 1,
                            'text' => mb_strimwidth(trim($line), 0, 300, '…'),
                        ];
                        if (count($matches) >= $max) {
                            $stopReason = sprintf('Stopped after %d matches.', $max);
                            break 3;
                        }
                    }
                }
            }
        }

        return ['matches' => $matches, 'files_scanned' => min($scanned, self::MAX_SEARCH_FILES), 'note' => $stopReason];
    }

    private function writeFile(Args $args, ToolContext $ctx): array
    {
        $absolute = $this->guard->resolveForWrite($args->requireString('path'));

        return $this->save($absolute, $args->string('content', null, true), $ctx);
    }

    private function editFile(Args $args, ToolContext $ctx): array
    {
        $absolute = $this->guard->resolveForWrite($args->requireString('path'));
        if (!is_file($absolute)) {
            throw new ToolError('The file does not exist. Use dev_write_file to create it.');
        }
        $content = (string) file_get_contents($absolute);
        $old = $args->string('old_text', null, true);
        $new = $args->string('new_text', null, true);
        if ($old === '') {
            throw new ToolError('old_text must not be empty.');
        }
        $count = substr_count($content, $old);
        if ($count === 0) {
            throw new ToolError('old_text was not found in the file. Read the file again and copy the text exactly, including indentation.');
        }
        if ($count > 1 && !$args->bool('replace_all')) {
            throw new ToolError(sprintf('old_text occurs %d times. Add surrounding lines to make it unique, or set replace_all=true.', $count));
        }
        $result = $this->save($absolute, str_replace($old, $new, $content), $ctx);
        $result['replacements'] = $count;

        return $result;
    }

    private function deleteFile(Args $args, ToolContext $ctx): array
    {
        $absolute = $this->guard->resolveForWrite($args->requireString('path'));
        if (!is_file($absolute)) {
            throw new ToolError('The file does not exist.');
        }
        $id = $this->history->apply($absolute, null, (int) $ctx->user->getUserID(), $ctx->access->tokenId);

        return ['path' => $this->guard->relative($absolute), 'action' => 'deleted', 'history_id' => $id];
    }

    private function save(string $absolute, string $content, ToolContext $ctx): array
    {
        if (strlen($content) > $this->settings->getMaxFileSize()) {
            throw new ToolError(sprintf('The content is larger than the limit of %d bytes set in the dashboard.', $this->settings->getMaxFileSize()));
        }
        $relative = $this->guard->relative($absolute);
        if ($this->settings->isPhpLintEnabled() && strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'php') {
            $error = PhpLinter::check($content);
            if ($error !== null) {
                throw new ToolError($error . '. The file was NOT saved.');
            }
        }
        if (is_file($absolute) && file_get_contents($absolute) === $content) {
            return ['path' => $relative, 'action' => 'unchanged'];
        }
        $existed = is_file($absolute);
        $id = $this->history->apply($absolute, $content, (int) $ctx->user->getUserID(), $ctx->access->tokenId);

        return [
            'path' => $relative,
            'action' => $existed ? 'updated' : 'created',
            'bytes' => strlen($content),
            'history_id' => $id,
        ];
    }

    private function isWritable(string $absolute): bool
    {
        try {
            $this->guard->resolveForWrite($this->guard->relative($absolute));

            return is_writable($absolute);
        } catch (ToolError $e) {
            return false;
        }
    }

    private function listHistory(Args $args): array
    {
        [$rows, $total] = $this->history->search($args->string('path'), $args->limit('limit', 25));
        $entries = [];
        foreach ($rows as $row) {
            $user = $row['uID'] ? $this->users->getByID((int) $row['uID']) : null;
            $entries[] = [
                'history_id' => (int) $row['id'],
                'date' => $row['createdAt'],
                'user' => $user ? $user->getUserName() : null,
                'path' => $row['path'],
                'action' => $row['action'],
                'new_size' => $row['newSize'] !== null ? (int) $row['newSize'] : null,
                'restored_from' => $row['restoredFromID'] !== null ? (int) $row['restoredFromID'] : null,
            ];
        }

        return ['total' => $total, 'entries' => $entries];
    }
}
