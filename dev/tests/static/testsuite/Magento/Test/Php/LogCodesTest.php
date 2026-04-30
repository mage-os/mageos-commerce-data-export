<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Test\Php;

use Magento\TestFramework\Utility\FilesSearch;

/**
 * Static test that enforces the MDEE log-code convention documented in
 * commerce-data-export/docs/log-codes.md.
 *
 * Runs only against files listed in
 *   testsuite/Magento/Test/_files/changed_files*
 * and only in the scope of Adobe Commerce (Magento) PR runs that ship MDEE
 * code, so no repo-membership check is needed - any changed .php file is
 * inspected.
 *
 * Verified rules:
 *   1. Every error/warning/critical logger call must start its message with
 *      a CDE<group>-<id> code (e.g. "CDE01-02"). Pass-through calls whose
 *      first argument is a plain variable are exempt because the upstream
 *      builder owns the code.
 *   2. The code must be registered in docs/log-codes.md.
 *   3. The registry entry's level must match the logger method.
 *   4. If docs/log-codes.md is part of the PR changes, the registry itself
 *      must have unique codes, well-formed rows, and messages prefixed with
 *      their own code.
 */
class LogCodesTest extends \PHPUnit\Framework\TestCase
{
    private const LOG_CODE_REGEX = '/\bCDE(\d{2})-(\d{2})\b/';
    private const LOG_LEVELS = ['error', 'warning', 'critical'];

    public function testChangedFilesHaveValidLogCodes(): void
    {
        $changedFiles = $this->collectChangedFiles();
        if ($changedFiles === []) {
            self::markTestSkipped('No changed files provided; skipping log-code check.');
        }

        $registry = $this->loadRegistryByCode($changedFiles);
        if ($registry === null) {
            self::markTestSkipped(
                'commerce-data-export/docs/log-codes.md could not be located. '
                . 'Set LOG_CODES_REGISTRY or ensure the file is reachable from the changed file paths.'
            );
        }

        $violations = [];
        foreach ($changedFiles as $absolutePath) {
            if (substr($absolutePath, -4) !== '.php' || !is_readable($absolutePath)) {
                continue;
            }

            foreach ($this->inspectFile($absolutePath, $registry) as $violation) {
                $violations[] = $violation;
            }
        }

        // Verify messages in md files
        foreach ($registry as $entry) {
            if (!in_array($entry['level'], self::LOG_LEVELS, true)) {
                $violations[] = sprintf(
                    'Invalid level "%s" for code %s (only error, warning, critical are allowed)',
                    $entry['level'],
                    $entry['code']
                );
            }
            if (!preg_match('/^' . preg_quote($entry['code'], '/') . '\b/', $entry['message'])) {
                $violations[] = sprintf(
                    'Message for code %s must start with the code itself (got: %s)',
                    $entry['code'],
                    $entry['message']
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Log code / log-codes.md violations:\n - " . implode("\n - ", $violations)
                . "\n\nSee docs/log-codes.md for the convention."
        );
    }

    /**
     * @return array<string,string> displayPath => absolutePath
     */
    private function collectChangedFiles(): array
    {
        return FilesSearch::getFilesFromListFile(
            __DIR__ . '/..',
            'changed_files*',
            function () {
                // if no list files, probably, this is the dev environment
                // phpcs:disable Generic.PHP.NoSilencedErrors,Magento2.Security.InsecureFunction
                @exec('git diff --name-only', $changedFiles);
                @exec('git diff --cached --name-only', $addedFiles);
                // phpcs:enable
                $changedFiles = array_unique(array_merge($changedFiles, $addedFiles));
                return $changedFiles;
            }
        );
    }

    /**
     * @return array<string,array{code:string,level:string,message:string,logical_path:string,line:int}>|null
     */
    private function loadRegistryByCode(): ?array
    {
        $registryPath =  BP . '/dev/tests/log-codes.md';

        $entries = $this->parseRegistry((string) file_get_contents($registryPath));
        $index = [];
        foreach ($entries as $entry) {
            $index[$entry['code']] = $entry;
        }
        return $index;
    }

    /**
     * @return list<array{code:string,level:string,message:string,logical_path:string,line:int}>
     */
    private function parseRegistry(string $markdown): array
    {
        $entries = [];
        $rowPattern = '/^\|\s*(CDE\d{2}-\d{2})\s*\|\s*([a-zA-Z]+)\s*\|\s*`([^`]+)`\s*\|\s*`([^`]+)`\s*\|\s*$/m';
        if (!preg_match_all($rowPattern, $markdown, $rows, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($rows as $row) {
            [, $code, $level, $message, $location] = $row;
            if (!preg_match('/^(.+):(\d+)$/', trim($location), $loc)) {
                continue;
            }
            $entries[] = [
                'code' => $code,
                'level' => strtolower($level),
                'message' => $message,
                'logical_path' => $loc[1],
                'line' => (int) $loc[2],
            ];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function inspectFile(string $absolutePath, array $registry): array
    {
        $content = (string) file_get_contents($absolutePath);
        if ($content === '') {
            return [];
        }

        $calls = $this->extractLoggerCalls($content);
        $violations = [];

        foreach ($calls as $call) {
            [$level, $lineNumber, $firstArgIsVariable, $stringLiterals] = [
                $call['level'],
                $call['line'],
                $call['first_arg_is_variable'],
                $call['string_literals'],
            ];

            $code = null;
            foreach ($stringLiterals as $literal) {
                if (preg_match(self::LOG_CODE_REGEX, $literal, $m)) {
                    $code = $m[0];
                    break;
                }
            }

            if ($code === null) {
                if ($firstArgIsVariable) {
                    continue;
                }
                $violations[] = sprintf(
                    '%s:%d Logger->%s() call is missing a CDE<group>-<id> code prefix.',
                    $absolutePath,
                    $lineNumber,
                    $level
                );
                continue;
            }

            $entry = $registry[$code] ?? null;

            if ($entry === null) {
                $violations[] = sprintf(
                    '%s:%d Log call uses code %s but it is not registered in docs/log-codes.md.'
                        . ' Add a row to the matching group table or run the /log-codes skill.',
                    $absolutePath,
                    $lineNumber,
                    $code
                );
                continue;
            }

            if ($entry['level'] !== $level) {
                $violations[] = sprintf(
                    '%s:%d Level mismatch for code %s: file uses "%s", registry declares "%s".',
                    $absolutePath,
                    $lineNumber,
                    $code,
                    $level,
                    $entry['level']
                );
            }

            if (!$this->messageStartsWithCode($stringLiterals, $code)) {
                $violations[] = sprintf(
                    '%s:%d Code %s must be the first token of the log message string.',
                    $absolutePath,
                    $lineNumber,
                    $code
                );
            }
        }

        return $violations;
    }

    /**
     * Tokenize the PHP source and return every error/warning/critical
     * method call with its line, first-argument variability, and the string
     * literals appearing inside its argument list.
     *
     * @return list<array{level:string,line:int,first_arg_is_variable:bool,string_literals:list<string>}>
     */
    private function extractLoggerCalls(string $content): array
    {
        $tokens = @token_get_all($content);
        $calls = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] !== T_OBJECT_OPERATOR
                && (!defined('T_NULLSAFE_OBJECT_OPERATOR') || $token[0] !== T_NULLSAFE_OBJECT_OPERATOR)
            ) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;
            }
            $methodName = strtolower($tokens[$j][1]);
            if (!in_array($methodName, self::LOG_LEVELS, true)) {
                continue;
            }
            $line = $tokens[$j][2];

            $k = $j + 1;
            while ($k < $count && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $k++;
            }
            if ($k >= $count || $tokens[$k] !== '(') {
                continue;
            }

            $depth = 1;
            $literals = [];
            $firstArgIsVariable = false;
            $seenFirstArgToken = false;
            for ($p = $k + 1; $p < $count && $depth > 0; $p++) {
                $t = $tokens[$p];
                if ($t === '(') {
                    $depth++;
                    continue;
                }
                if ($t === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }

                if (!$seenFirstArgToken && is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (!$seenFirstArgToken) {
                    $seenFirstArgToken = true;
                    if (is_array($t) && $t[0] === T_VARIABLE) {
                        $firstArgIsVariable = true;
                    }
                }

                if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literals[] = $this->unquotePhpString($t[1]);
                }
            }

            $calls[] = [
                'level' => $methodName,
                'line' => $line,
                'first_arg_is_variable' => $firstArgIsVariable,
                'string_literals' => $literals,
            ];

            $i = $p;
        }

        return $calls;
    }

    private function unquotePhpString(string $literal): string
    {
        if ($literal === '') {
            return '';
        }
        $quote = $literal[0];
        $inner = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }
        return stripcslashes($inner);
    }

    /**
     * @param list<string> $literals
     */
    private function messageStartsWithCode(array $literals, string $code): bool
    {
        foreach ($literals as $literal) {
            $trim = ltrim($literal);
            if ($trim === '') {
                continue;
            }
            return str_starts_with($trim, $code . ' ') || $trim === $code;
        }
        return false;
    }
}
