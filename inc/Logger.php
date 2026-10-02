<?php

/**
 * Logger - A simple static file logger
 *
 * Usage:
 *   Logger::info('Application started');
 *   Logger::warn('Low memory', 'system');
 *   Logger::error('Database connection failed', 'db');
 *   Logger::debug('Query executed', 'db');
 */
class Logger
{
    // ─────────────────────────────────────────
    //  Configuration
    // ─────────────────────────────────────────

    /** Directory where log files will be stored (with trailing slash) */
    private const LOG_PATH = __DIR__ . '/../logs/';

    /** Log file name */
    private const LOG_FILE = 'app.log';

    /** Default channel used when none is specified */
    private const DEFAULT_CHANNEL = 'main';

    // ─────────────────────────────────────────
    //  Severity levels
    // ─────────────────────────────────────────

    public const DEBUG   = 'DEBUG';
    public const INFO    = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR   = 'ERROR';

    // ─────────────────────────────────────────
    //  Public static API
    // ─────────────────────────────────────────

    public static function debug(string $message, string $channel = self::DEFAULT_CHANNEL): void
    {
        self::write(self::DEBUG, $message, $channel);
    }

    public static function info(string $message, string $channel = self::DEFAULT_CHANNEL): void
    {
        self::write(self::INFO, $message, $channel);
    }

    public static function warn(string $message, string $channel = self::DEFAULT_CHANNEL): void
    {
        self::write(self::WARNING, $message, $channel);
    }

    public static function error(string $message, string $channel = self::DEFAULT_CHANNEL): void
    {
        self::write(self::ERROR, $message, $channel);
    }

    // ─────────────────────────────────────────
    //  Core writer
    // ─────────────────────────────────────────

    /**
     * Formats and appends a log entry to the log file.
     *
     * Format: [dd/mm/yyyy hh:mm:ss] (SEVERITY) (channel) message
     */
    private static function write(string $severity, string $message, string $channel): void
    {
        $timestamp = date('d/m/Y H:i:s');
        $line      = "[{$timestamp}] ({$severity}) ({$channel}) {$message}" . PHP_EOL;

        $filePath = rtrim(self::LOG_PATH, '/\\') . DIRECTORY_SEPARATOR . self::LOG_FILE;

        // Create the log directory if it doesn't exist yet
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Append the entry (FILE_APPEND + LOCK_EX prevents race conditions)
        if (file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log("Logger: could not write to log file: {$filePath}");
        }
    }

    // Prevent instantiation
    private function __construct() {}
}
