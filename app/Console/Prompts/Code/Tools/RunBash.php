<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Tools;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final readonly class RunBash
{
    private const BLOCKED_COMMANDS = [
        'vim', 'nvim', 'nano', 'emacs', 'vi',
        'less', 'more', 'top', 'htop', 'btop',
        'ssh', 'telnet', 'ftp', 'sftp',
        'man', 'info',
        'screen', 'tmux',
        'sudo', 'su',
        'rm -rf /',
    ];

    private const MAX_OUTPUT_BYTES = 102400; // 100KB

    private const DEFAULT_TIMEOUT = 30;

    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'run_bash',
                'description' => 'Execute a bash command and return the output. Use for running tests, checking file status, installing dependencies, git operations, and other shell tasks.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => [
                            'type' => 'string',
                            'description' => 'The bash command to execute',
                        ],
                        'timeout' => [
                            'type' => 'integer',
                            'description' => 'Timeout in seconds (default: 30, max: 120)',
                        ],
                        'working_directory' => [
                            'type' => 'string',
                            'description' => 'Working directory for the command (default: project root)',
                        ],
                    ],
                    'required' => ['command'],
                ],
            ],
        ];
    }

    public function handle(string $command, ?int $timeout = null, ?string $workingDirectory = null): PromiseInterface
    {
        $deferred = new Deferred;
        $deferred->resolve($this->handleSync($command, $timeout, $workingDirectory));

        return $deferred->promise();
    }

    public function handleSync(string $command, ?int $timeout = null, ?string $workingDirectory = null): array
    {
        if ($this->isBlocked($command)) {
            return $this->error('Command blocked: interactive or dangerous commands are not allowed');
        }

        $timeout = min($timeout ?? self::DEFAULT_TIMEOUT, 120);
        $workingDirectory = $this->resolveWorkingDirectory($workingDirectory);

        if (! is_dir($workingDirectory)) {
            return $this->error("Working directory not found: {$workingDirectory}");
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open(
            ['bash', '-c', $command],
            $descriptors,
            $pipes,
            $workingDirectory,
            null,
        );

        if (! is_resource($process)) {
            return $this->error('Failed to start process');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                // Read remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            if ((time() - $startTime) >= $timeout) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 0, 200000)) {
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 8192);
                    if ($chunk !== false) {
                        if ($pipe === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }

            // Bail if output is too large
            if (strlen($stdout) + strlen($stderr) > self::MAX_OUTPUT_BYTES * 2) {
                proc_terminate($process, 9);
                $stdout = $this->truncateOutput($stdout);
                $stderr = $this->truncateOutput($stderr);
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $status['exitcode'] ?? proc_close($process);

        if ($timedOut) {
            return $this->error("Command timed out after {$timeout} seconds", [
                'command' => $command,
                'exit_code' => -1,
                'stdout' => $this->truncateOutput($stdout),
                'stderr' => $this->truncateOutput($stderr),
                'timed_out' => true,
            ]);
        }

        $stdout = $this->truncateOutput($stdout);
        $stderr = $this->truncateOutput($stderr);
        $outputLines = substr_count($stdout, "\n") + ($stdout !== '' ? 1 : 0);

        return [
            'success' => $exitCode === 0,
            'command' => $command,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output_lines' => $outputLines,
        ];
    }

    protected function isBlocked(string $command): bool
    {
        $normalized = trim(strtolower($command));

        foreach (self::BLOCKED_COMMANDS as $blocked) {
            if ($normalized === $blocked || str_starts_with($normalized, $blocked.' ')) {
                return true;
            }
        }

        // Block piped interactive commands
        $parts = preg_split('/\s*\|\s*/', $normalized);
        foreach ($parts as $part) {
            $firstWord = explode(' ', trim($part))[0];
            if (in_array($firstWord, ['vim', 'nvim', 'nano', 'less', 'more', 'top', 'htop', 'btop', 'ssh', 'tmux', 'screen'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveWorkingDirectory(?string $path): string
    {
        if ($path === null) {
            return getcwd() ?: '/';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return getcwd().'/'.$path;
    }

    protected function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES)
            ."\n... [output truncated at 100KB]";
    }

    protected function success(array $data): array
    {
        return [
            'success' => true,
            ...$data,
        ];
    }

    protected function error(string $message, array $extra = []): array
    {
        return [
            'success' => false,
            'error' => $message,
            ...$extra,
        ];
    }
}
