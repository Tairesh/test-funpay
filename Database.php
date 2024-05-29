<?php

namespace FpDbTest;

use Exception;
use mysqli;

class SkipValue {};

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @param string $query
     * @param array $args
     *
     * @return string
     *
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $re = '/(\?[dfa#]*)|({[^}]*})/m';
        preg_match_all($re, $query, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return $query;
        }

        $argsUsed = 0;
        foreach ($matches as $match) {
            $arg = $args[$argsUsed] ?? null;
            if ($arg instanceof SkipValue) {
                $query = preg_replace("/" . preg_quote($match[0], '/') . "/", '', $query, 1);
                continue;
            }

            if (str_starts_with($match[0], '{')) {
                $query = $this->handleConditionalBlock($query, $match[0], $args, $argsUsed);
            } else {
                $query = preg_replace("/" . preg_quote($match[0], '/') . "/", $this->formatArg($match[0], $arg), $query, 1);
                $argsUsed += 1;
            }
        }

        return $query;
    }

    /**
     * @return SkipValue
     */
    public function skip(): SkipValue
    {
        return new SkipValue();
    }

    /**
     * @throws Exception
     */
    private function handleConditionalBlock(string $query, string $block, array $args, int &$argsUsed): string
    {
        $blockContent = trim($block, '{}');
        preg_match_all('/\?[dfa#]*/', $blockContent, $blockMatches);
        foreach ($blockMatches[0] as $blockMatch) {
            $blockArg = $args[$argsUsed] ?? null;
            if ($blockArg instanceof SkipValue) {
                return preg_replace("/" . preg_quote($block, '/') . "/", '', $query, 1);
            }
            $blockContent = preg_replace("/" . preg_quote($blockMatch, '/') . "/", $this->formatArg($blockMatch, $blockArg), $blockContent, 1);
            $argsUsed += 1;
        }
        return preg_replace("/" . preg_quote($block, '/') . "/", $blockContent, $query, 1);
    }

    /**
     * @throws Exception
     */
    private function formatArg(string $placeholder, $arg): string
    {
        return match ($placeholder) {
            '?' => $this->sqlizeArg($arg),
            '?d' => $this->formatIntArg($arg),
            '?f' => $this->formatFloatArg($arg),
            '?a' => $this->formatArrayArg($arg),
            '?#' => $this->formatIdentifierArg($arg),
            default => throw new Exception("Unknown placeholder: {$placeholder}"),
        };
    }

    private function formatIntArg($arg): string
    {
        if (is_bool($arg)) {
            return $arg ? '1' : '0';
        }
        return strval(intval($arg));
    }

    private function formatFloatArg($arg): string
    {
        return strval(floatval($arg));
    }

    /**
     * @throws Exception
     */
    private function formatArrayArg($arg): string
    {
        if (!is_array($arg)) {
            throw new Exception("Expected array for ?a placeholder");
        }
        if (array_keys($arg) !== range(0, count($arg) - 1)) {
            // associative array
            return implode(', ', array_map(function ($k, $v) {
                return '`' . mysqli_real_escape_string($this->mysqli, $k) . '` = ' . $this->sqlizeArg($v);
            }, array_keys($arg), $arg));
        } else {
            // non-associative array
            return implode(', ', array_map([$this, 'sqlizeArg'], $arg));
        }
    }

    /**
     * @throws Exception
     */
    private function formatIdentifierArg($arg): string
    {
        if (!is_string($arg) && !is_array($arg)) {
            throw new Exception("Expected string or array for ?# placeholder");
        }
        if (is_array($arg)) {
            return implode(', ', array_map(fn($item) => '`' . mysqli_real_escape_string($this->mysqli, $item) . '`', $arg));
        } else {
            return '`' . mysqli_real_escape_string($this->mysqli, $arg) . '`';
        }
    }

    /**
     * @throws Exception
     */
    private function sqlizeArg($arg): string
    {
        if (is_numeric($arg)) {
            return $arg;
        }
        if (is_bool($arg)) {
            return $arg ? '1' : '0';
        }
        if (is_null($arg)) {
            return 'NULL';
        }
        if (is_string($arg)) {
            return "'" . mysqli_real_escape_string($this->mysqli, $arg) . "'";
        }

        throw new Exception("Unsupported argument type: " . gettype($arg));
    }
}