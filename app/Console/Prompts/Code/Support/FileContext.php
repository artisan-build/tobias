<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Support;

use Illuminate\Support\Str;

final class FileContext
{
    public readonly int $totalLines;

    public readonly string $relativePath;

    public function __construct(
        public readonly string $path,
        public readonly ?int $startLine = null,
        public readonly ?int $endLine = null,
        ?string $basePath = null,
    ) {
        $this->totalLines = $this->countLines();
        $this->relativePath = $basePath
            ? Str::after($this->path, rtrim($basePath, '/').'/')
            : basename($this->path);
    }

    public static function fromReference(string $reference, ?string $basePath = null): static
    {
        $reference = ltrim($reference, '~@');
        $basePath ??= getcwd();

        // Parse line range if present (e.g., "file.php:10-20" or "file.php:15")
        $startLine = null;
        $endLine = null;

        if (preg_match('/^(.+):(\d+)(?:-(\d+))?$/', $reference, $matches)) {
            $reference = $matches[1];
            $startLine = (int) $matches[2];
            $endLine = isset($matches[3]) ? (int) $matches[3] : $startLine;
        }

        $path = Str::startsWith($reference, '/')
            ? $reference
            : rtrim($basePath, '/').'/'.$reference;

        return new self($path, $startLine, $endLine, $basePath);
    }

    public function hasLineRange(): bool
    {
        return $this->startLine !== null;
    }

    public function getLineRange(): ?string
    {
        if (! $this->hasLineRange()) {
            return null;
        }

        return $this->startLine === $this->endLine
            ? "L{$this->startLine}"
            : "L{$this->startLine}-{$this->endLine}";
    }

    public function getSelectedLineCount(): int
    {
        if (! $this->hasLineRange()) {
            return $this->totalLines;
        }

        return $this->endLine - $this->startLine + 1;
    }

    public function exists(): bool
    {
        return file_exists($this->path) && is_readable($this->path);
    }

    public function getContent(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            return null;
        }

        if (! $this->hasLineRange()) {
            return $content;
        }

        return collect(explode("\n", $content))
            ->slice($this->startLine - 1, $this->getSelectedLineCount())
            ->join("\n");
    }

    public function getFirstLine(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $handle = fopen($this->path, 'r');

        if (! $handle) {
            return null;
        }

        if ($this->hasLineRange()) {
            $lineNum = 1;

            while (($line = fgets($handle)) !== false && $lineNum < $this->startLine) {
                $lineNum++;
            }

            $firstLine = $line !== false ? trim($line) : null;
        } else {
            $firstLine = trim(fgets($handle) ?: '');
        }

        fclose($handle);

        return $firstLine;
    }

    public function toDisplayString(int $maxLength = 50): string
    {
        $firstLine = $this->getFirstLine() ?? '';
        $truncated = Str::length($firstLine) > $maxLength
            ? Str::substr($firstLine, 0, $maxLength - 3).'...'
            : $firstLine;

        $lineInfo = $this->hasLineRange()
            ? " ({$this->getLineRange()}, {$this->getSelectedLineCount()} lines)"
            : " ({$this->totalLines} lines)";

        return "{$this->relativePath}: {$truncated}{$lineInfo}";
    }

    public function toCompactString(): string
    {
        $range = $this->hasLineRange() ? ":{$this->startLine}-{$this->endLine}" : '';

        return "~{$this->relativePath}{$range}";
    }

    public function toContextMessage(): array
    {
        $content = $this->getContent() ?? '[File not readable]';
        $header = $this->hasLineRange()
            ? "File: {$this->relativePath} (lines {$this->startLine}-{$this->endLine})"
            : "File: {$this->relativePath}";

        return [
            'role' => 'user',
            'content' => "```\n{$header}\n---\n{$content}\n```",
        ];
    }

    protected function countLines(): int
    {
        if (! file_exists($this->path) || ! is_readable($this->path)) {
            return 0;
        }

        $handle = fopen($this->path, 'r');

        if (! $handle) {
            return 0;
        }

        $lines = 0;

        while (! feof($handle)) {
            $buffer = fread($handle, 8192);
            $lines += substr_count($buffer, "\n");
        }

        fclose($handle);

        return $lines;
    }
}
