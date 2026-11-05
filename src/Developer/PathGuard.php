<?php

namespace Ortic\McpServer\Developer;

use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Settings;

/**
 * Decides which files developer mode may read and change.
 *
 * Paths are relative to the web root (the directory containing application/, packages/ and concrete/).
 * This is a guard against mistakes, not a security boundary: whoever can write PHP files can run any code.
 */
class PathGuard
{
    /** Never readable or writable: credentials, uploaded files and caches. */
    private const SECRET_PATHS = [
        'application/config/database.php',
        'application/config/generated_overrides',
        'application/files',
    ];

    /** Readable but never writable. */
    private const READ_ONLY_PATHS = [
        'application/config/doctrine',
        'packages/ortic_mcp_server',
    ];

    /** Directory names that are never writable anywhere (and skipped when listing/searching recursively). */
    public const SKIPPED_DIRECTORIES = ['.git', '.svn', '.hg', 'node_modules', 'vendor'];

    private string $base;

    public function __construct(private Settings $settings)
    {
        $this->base = rtrim(str_replace('\\', '/', DIR_BASE), '/');
    }

    public function getBase(): string
    {
        return $this->base;
    }

    /** @return string[] roots that may be read */
    public function readableRoots(): array
    {
        $roots = $this->settings->getDeveloperRoots();
        if ($this->settings->canDeveloperReadCore()) {
            $roots[] = 'concrete';
        }

        return array_values(array_unique($roots));
    }

    /** @return string[] roots that may be written */
    public function writableRoots(): array
    {
        return $this->settings->canDeveloperWrite() ? array_values(array_diff($this->settings->getDeveloperRoots(), ['concrete'])) : [];
    }

    /**
     * Normalize a user supplied relative path ("application/themes/x/../y.php" is rejected, not resolved).
     */
    public function normalize(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new ToolError('Invalid path.');
        }
        $path = str_replace('\\', '/', trim($path));
        if (str_starts_with($path, $this->base . '/')) {
            $path = substr($path, strlen($this->base) + 1);
        }
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path)) {
            throw new ToolError('Use a path relative to the web root, e.g. "application/themes/my_theme/page.php".');
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new ToolError('Paths must not contain "..".');
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * @return string absolute path of a readable file or directory
     */
    public function resolveForRead(string $path, bool $mustExist = true): string
    {
        $relative = $this->normalize($path);
        if ($relative === '') {
            return $this->base;
        }
        $this->assertInRoot($relative, $this->readableRoots(), 'read');
        if ($this->matches($relative, self::SECRET_PATHS)) {
            throw new ToolError(sprintf('"%s" is protected (credentials, uploaded files or caches) and cannot be accessed with developer tools.', $relative));
        }
        $absolute = $this->base . '/' . $relative;
        if ($mustExist && !file_exists($absolute)) {
            throw new ToolError(sprintf('"%s" does not exist.', $relative));
        }
        $this->assertRealPathInside($absolute, $relative);

        return $absolute;
    }

    /**
     * @return string absolute path of a file that may be created, changed or deleted
     */
    public function resolveForWrite(string $path): string
    {
        $relative = $this->normalize($path);
        if ($relative === '') {
            throw new ToolError('A file path is required.');
        }
        $this->assertInRoot($relative, $this->writableRoots(), 'write');
        if ($this->matches($relative, array_merge(self::SECRET_PATHS, self::READ_ONLY_PATHS))) {
            throw new ToolError(sprintf('"%s" is protected and cannot be changed with developer tools.', $relative));
        }
        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, self::SKIPPED_DIRECTORIES, true)) {
                throw new ToolError(sprintf('Files inside "%s" directories cannot be changed.', $segment));
            }
            if ($segment === '.env' || str_starts_with($segment, '.env.')) {
                throw new ToolError('Environment files cannot be changed.');
            }
        }
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $allowed = $this->settings->getDeveloperExtensions();
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new ToolError(sprintf('Files with the extension ".%s" cannot be changed. Allowed: %s.', $extension, implode(', ', $allowed)));
        }
        $absolute = $this->base . '/' . $relative;
        if (is_dir($absolute)) {
            throw new ToolError(sprintf('"%s" is a directory.', $relative));
        }
        $this->assertRealPathInside($absolute, $relative);

        return $absolute;
    }

    /**
     * For reverting a recorded change from the dashboard: works even when developer mode is off.
     */
    public function resolveForRestore(string $path): string
    {
        $relative = $this->normalize($path);
        $this->assertInRoot($relative, array_values(array_diff($this->settings->getDeveloperRoots(), ['concrete'])), 'restore');
        if ($this->matches($relative, array_merge(self::SECRET_PATHS, self::READ_ONLY_PATHS))) {
            throw new ToolError(sprintf('"%s" is protected.', $relative));
        }
        $absolute = $this->base . '/' . $relative;
        $this->assertRealPathInside($absolute, $relative);

        return $absolute;
    }

    public function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);

        return ltrim(str_starts_with($absolute, $this->base) ? substr($absolute, strlen($this->base)) : $absolute, '/');
    }

    public function isSecret(string $relative): bool
    {
        return $this->matches($relative, self::SECRET_PATHS);
    }

    public function isReadable(string $relative): bool
    {
        $root = explode('/', $relative)[0];

        return in_array($root, $this->readableRoots(), true) && !$this->isSecret($relative);
    }

    private function assertInRoot(string $relative, array $roots, string $operation): void
    {
        foreach ($roots as $root) {
            if ($relative === $root || str_starts_with($relative, $root . '/')) {
                return;
            }
        }
        if ($roots === []) {
            throw new ToolError($operation === 'write' ? 'Developer mode does not allow changing files on this site.' : 'No directories are readable.');
        }
        throw new ToolError(sprintf('Developer tools can only %s files in: %s.', $operation, implode('/, ', $roots) . '/'));
    }

    /**
     * Symbolic links must not lead outside the root the path starts in.
     */
    private function assertRealPathInside(string $absolute, string $relative): void
    {
        $root = explode('/', $relative)[0];
        $rootReal = realpath($this->base . '/' . $root);
        if ($rootReal === false) {
            throw new ToolError(sprintf('The directory "%s" does not exist.', $root));
        }
        $existing = $absolute;
        while (!file_exists($existing) && $existing !== $this->base) {
            $existing = dirname($existing);
        }
        $real = realpath($existing);
        $rootReal = str_replace('\\', '/', $rootReal);
        $real = $real === false ? '' : str_replace('\\', '/', $real);
        if ($real !== $rootReal && !str_starts_with($real, $rootReal . '/')) {
            throw new ToolError(sprintf('"%s" resolves to a location outside %s/.', $relative, $root));
        }
    }

    private function matches(string $relative, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
