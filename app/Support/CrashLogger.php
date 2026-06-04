<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Minimal, dependency-free diagnostic logger.
 *
 * laravel-zero binds the framework logger to a NullLogger, so the normal
 * logging stack is unavailable — and worse, routing a PHP deprecation through
 * it turns a harmless notice into a fatal error. This writes plain lines to a
 * file under the user's home directory so the TUI always has somewhere to
 * record problems without touching the framework log manager.
 *
 * Every operation is guarded: logging must never be the thing that crashes
 * the app.
 */
final class CrashLogger
{
    private static ?string $path = null;

    /**
     * Absolute path to the log file. Override with the TOBY_LOG_PATH env var.
     */
    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        $override = getenv('TOBY_LOG_PATH');
        if (is_string($override) && $override !== '') {
            return self::$path = $override;
        }

        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());

        return self::$path = rtrim($home, '/').'/.tobias/toby.log';
    }

    public static function message(string $level, string $message): void
    {
        self::write(strtoupper($level), $message);
    }

    public static function exception(string $context, Throwable $e): void
    {
        self::write('ERROR', sprintf(
            '%s: %s: %s in %s:%d',
            $context,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));
        self::write('TRACE', $e->getTraceAsString());
    }

    private static function write(string $level, string $message): void
    {
        try {
            $path = self::path();
            $dir = dirname($path);

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            @file_put_contents(
                $path,
                sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $level, $message),
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            // Logging must never crash the caller.
        }
    }
}
