<?php

// Braced namespaces as in `FieldGroupMalformedFields.php`: the WordPress stubs must live in the
// global namespace, where `Updater` looks them up when there is no WordPress around.

namespace {

    define('ONPAGE_PLUGIN_DIR', '/plugins/onpage');
    define('HOUR_IN_SECONDS', 3600);

    /** State of the WordPress double: the GitHub answer, the calls made, the transients. */
    $GLOBALS['wp_double'] = ['response' => null, 'calls' => 0, 'transients' => []];

    function plugin_basename(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
    }

    function get_transient(string $key): mixed
    {
        return $GLOBALS['wp_double']['transients'][$key] ?? false;
    }

    function set_transient(string $key, mixed $value, int $expiration): bool
    {
        $GLOBALS['wp_double']['transients'][$key] = $value;

        return true;
    }

    function wp_remote_get(string $url, array $args = []): array
    {
        $GLOBALS['wp_double']['calls']++;

        return $GLOBALS['wp_double']['response'];
    }

    function is_wp_error(mixed $thing): bool
    {
        return false;
    }

    function wp_remote_retrieve_response_code(array $response): int
    {
        return $response['code'];
    }

    function wp_remote_retrieve_body(array $response): string
    {
        return $response['body'];
    }

    function esc_url(string $url): string
    {
        return $url;
    }
}

namespace OnPage\Tests {

    use OnPage\Services\Updater;

    require_once dirname(__DIR__) . '/Services/Updater.php';

    /**
     * Offline check for `Updater`: WordPress learns of a new release only through it, and it
     * must never offer a release for another plugin, or one whose archive is not attached yet.
     *
     * Needs no WordPress, no site and no `.env`: WordPress is replaced by the stubs above.
     *
     * Run with: php src/Tests/UpdaterLatestRelease.php
     */
    class UpdaterLatestRelease
    {
        private const OWN_FILE = 'onpage/plugin.php';

        private static function github(int $code, array $release): void
        {
            $GLOBALS['wp_double'] = [
                'response' => ['code' => $code, 'body' => json_encode($release)],
                'calls' => 0,
                'transients' => [],
            ];
        }

        private static function release(string $tag, array $asset_names): array
        {
            return [
                'tag_name' => $tag,
                'html_url' => "https://github.com/onpage-dev/wordpress-plugin/releases/tag/$tag",
                'assets' => array_map(fn(string $name): array => [
                    'name' => $name,
                    'browser_download_url' => "https://github.com/onpage-dev/wordpress-plugin/releases/download/$tag/$name",
                ], $asset_names),
            ];
        }

        private static function caseOffersTheAttachedArchive(): array
        {
            self::github(200, self::release('v1.0.4', ['onpage-1.0.4.zip']));
            $update = Updater::offerUpdate(false, [], self::OWN_FILE);

            $failures = [];
            if (($update['version'] ?? null) !== '1.0.4') {
                $failures[] = 'version: expected 1.0.4 without the "v", got ' . var_export($update['version'] ?? null, true);
            }
            if (($update['package'] ?? null) !== 'https://github.com/onpage-dev/wordpress-plugin/releases/download/v1.0.4/onpage-1.0.4.zip') {
                $failures[] = 'package: expected the attached onpage-1.0.4.zip, got ' . var_export($update['package'] ?? null, true);
            }

            Updater::offerUpdate(false, [], self::OWN_FILE);
            if ($GLOBALS['wp_double']['calls'] !== 1) {
                $failures[] = "cache: expected one GitHub call for two checks, got {$GLOBALS['wp_double']['calls']}";
            }

            return $failures;
        }

        private static function caseLeavesOtherPluginsAlone(): array
        {
            self::github(200, self::release('v1.0.4', ['onpage-1.0.4.zip']));
            $update = Updater::offerUpdate(false, [], 'another/another.php');

            $failures = [];
            if ($update !== false) {
                $failures[] = 'another plugin: expected the filter input back, got ' . var_export($update, true);
            }
            if ($GLOBALS['wp_double']['calls'] !== 0) {
                $failures[] = 'another plugin: GitHub must not be called';
            }

            return $failures;
        }

        private static function caseNoArchiveYet(): array
        {
            // The release workflow attaches the archive a few minutes after the release.
            self::github(200, self::release('v1.0.4', ['notes.txt']));
            $update = Updater::offerUpdate(false, [], self::OWN_FILE);

            return $update === false ? [] : ['no archive: expected nothing offered, got ' . var_export($update, true)];
        }

        private static function caseGithubDown(): array
        {
            // A release-shaped body, so only the status can tell it apart.
            self::github(503, self::release('v1.0.4', ['onpage-1.0.4.zip']));
            $update = Updater::offerUpdate(false, [], self::OWN_FILE);

            $failures = [];
            if ($update !== false) {
                $failures[] = 'GitHub down: expected nothing offered, got ' . var_export($update, true);
            }
            if ($GLOBALS['wp_double']['transients'] !== []) {
                $failures[] = 'GitHub down: a failure must not be cached';
            }

            return $failures;
        }

        public static function run(): int
        {
            $cases = [
                'offers the archive attached to the latest release, once per hour' => self::caseOffersTheAttachedArchive(...),
                'leaves other plugins alone' => self::caseLeavesOtherPluginsAlone(...),
                'offers nothing while the archive is not attached' => self::caseNoArchiveYet(...),
                'offers nothing when GitHub does not answer' => self::caseGithubDown(...),
            ];

            $failures = [];

            foreach ($cases as $title => $case) {
                $errors = $case();
                $outcome = $errors === [] ? 'ok' : 'FAILED';
                echo "  [$outcome] $title\n";

                foreach ($errors as $error) {
                    echo "         $error\n";
                }

                $failures = array_merge($failures, $errors);
            }

            $total = count($cases);

            if ($failures !== []) {
                fwrite(STDERR, "\nFAILED: " . count($failures) . " assertion(s) across $total cases\n");

                return 1;
            }

            echo "\nPASSED: $total of $total cases\n";

            return 0;
        }
    }

    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        exit(UpdaterLatestRelease::run());
    }
}
