<?php

namespace Ortic\McpServer\Developer;

/**
 * Syntax check without a shell: token_get_all() with TOKEN_PARSE runs the real PHP parser.
 */
final class PhpLinter
{
    /**
     * @return string|null the syntax error, or null if the code parses
     */
    public static function check(string $code): ?string
    {
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return sprintf('PHP syntax error on line %d: %s', $e->getLine(), $e->getMessage());
        } catch (\Throwable $e) {
            return 'PHP syntax error: ' . $e->getMessage();
        }

        return null;
    }
}
