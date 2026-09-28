<?php



namespace OnPage\Tests\Support;



/**
 * Append-only audit log of every HTTP call a test makes.
 *
 * Lives in `src/Tests/Support/` and not next to the tests because `bin/test-launcher`
 * treats every `src/Tests/*.php` as a test to run; support code must stay out of that
 * glob.
 *
 * The launcher decides the destination and exports it as `ONPAGE_TEST_AUDIT_LOG`,
 * truncating the file once per run and writing the per-test headers itself. A test run
 * on its own, without the launcher, still logs: the path then defaults to
 * `logs/audit.log` under the plugin root and the file is appended to.
 *
 * Logging must never be the reason a test fails, so every failure to write is swallowed
 * and simply disables the log for the rest of the process.
 */
class Audit
{
    /** Bodies longer than this are cut: an audit trail has to stay readable. */
    private const MAX_BODY = 4000;

    private static ?string $path = null;
    private static bool $resolved = false;



    /**
     * Destination of the log, or null when logging is off.
     *
     * Resolved once: a directory that cannot be created disables the log for good
     * rather than being retried on every single call.
     */
    private static function path(): ?string
    {
        if (self::$resolved) {
            return self::$path;
        }

        self::$resolved = true;

        $path = getenv('ONPAGE_TEST_AUDIT_LOG');
        if (!is_string($path) || $path === '') {
            $path = dirname(__DIR__, 3) . '/logs/audit.log';
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return self::$path = null;
        }

        return self::$path = $path;
    }

    /** Appends one raw line, doing nothing when the log is unavailable. */
    public static function line(string $text): void
    {
        $path = self::path();
        if ($path === null) {
            return;
        }

        @file_put_contents($path, $text . "\n", FILE_APPEND);
    }

    /**
     * Current time with real milliseconds, in the launcher's own timezone.
     *
     * `date()` is built on a whole-second timestamp, so its milliseconds are always
     * `.000`; only a DateTimeImmutable carries them. And PHP defaults to UTC unless
     * `date.timezone` is set, which would put two different clocks in one file, since
     * the launcher writes its headers with the shell's local time. The launcher passes
     * its UTC offset so both agree.
     */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now', self::timezone()))->format('Y-m-d H:i:s.v');
    }

    /** The launcher's timezone as a numeric offset, falling back to PHP's default. */
    private static function timezone(): \DateTimeZone
    {
        $offset = getenv('ONPAGE_TEST_TZ');
        if (is_string($offset) && preg_match('/^[+-]\d{4}$/', $offset) === 1) {
            return new \DateTimeZone(substr($offset, 0, 3) . ':' . substr($offset, 3));
        }

        return new \DateTimeZone(date_default_timezone_get());
    }

    /**
     * Renders a body for the log.
     *
     * JSON is pretty-printed and indented: these payloads are nested repeaters and
     * language maps, and reading them back on a single line is what makes a debugging
     * session slow. Anything that is not JSON — a PHP fatal, an HTML error page — is
     * kept verbatim, because that is exactly what one needs to see.
     */
    private static function body(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '        (no body)';
        }

        $decoded = json_decode($raw, true);
        $text = json_last_error() === JSON_ERROR_NONE
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $raw;

        if (strlen($text) > self::MAX_BODY) {
            $text = substr($text, 0, self::MAX_BODY) . "\n… truncated, " . strlen($raw) . ' bytes in total';
        }

        return '        ' . str_replace("\n", "\n        ", $text);
    }

    /**
     * Logs an outgoing request.
     *
     * The bearer token is deliberately not written: this file is meant to be read,
     * attached to a ticket and passed around.
     */
    public static function request(string $method, string $url, ?string $body): void
    {
        self::line('[req] ' . self::now() . " $method $url");
        self::line('        Authorization: Bearer <hidden>');
        self::line(self::body($body));
    }

    /** Logs the response to the request logged just before. */
    public static function response(int $status, ?string $body, float $seconds): void
    {
        self::line(sprintf('[res] %s HTTP %d in %.3fs', self::now(), $status, $seconds));
        self::line(self::body($body));
        self::line('');
    }

    /** Logs a call that never got a response. */
    public static function failure(string $message, float $seconds): void
    {
        self::line(sprintf('[res] %s NO RESPONSE after %.3fs', self::now(), $seconds));
        self::line('        ' . $message);
        self::line('');
    }
}
