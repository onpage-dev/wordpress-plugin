<?php



namespace OnPage\Services;



/**
 * Offers the latest GitHub release as a regular WordPress plugin update.
 *
 * The plugin is not on WordPress.org, so without this a site never learns that a new version
 * exists. `plugin.php` declares `Update URI: https://github.com/onpage-dev/wordpress-plugin`:
 * WordPress (5.8 and later) then skips WordPress.org for this plugin and asks the
 * `update_plugins_github.com` filter instead. WordPress compares the versions itself, so the
 * admin sees the usual "new version available" notice, updates with one click and can turn
 * automatic updates on.
 *
 * The package is the `onpage-X.Y.Z.zip` the release workflow attaches (see RELEASE.md). A
 * release without it yet, or a GitHub that does not answer, offers nothing.
 */
class Updater
{
    public const REPOSITORY = 'onpage-dev/wordpress-plugin';

    private const SLUG = 'onpage';

    private const CACHE_KEY = 'onpage_latest_release';

    public static function boot(): void
    {
        \add_filter('update_plugins_github.com', [self::class, 'offerUpdate'], 10, 3);
        \add_filter('plugins_api', [self::class, 'pluginInformation'], 10, 3);
    }

    /** `update_plugins_github.com`: the latest release, for this plugin only. */
    public static function offerUpdate(mixed $update, array $plugin_data, string $plugin_file): mixed
    {
        if ($plugin_file !== \plugin_basename(ONPAGE_PLUGIN_DIR . '/plugin.php')) {
            return $update;
        }

        $release = self::latestRelease();
        if ($release === null) {
            return $update;
        }

        return [
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
        ];
    }

    /**
     * `plugins_api`: the "View version details" link of the update notice asks WordPress.org
     * by slug, which has no `onpage` plugin. Answer it here, pointing at the release notes.
     */
    public static function pluginInformation(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || (is_object($args) ? ($args->slug ?? '') : '') !== self::SLUG) {
            return $result;
        }

        $release = self::latestRelease();
        if ($release === null) {
            return $result;
        }

        $notes = \esc_url($release['url']);

        return (object) [
            'name' => 'On Page®',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'homepage' => 'https://github.com/' . self::REPOSITORY,
            'download_link' => $release['package'],
            'sections' => [
                'changelog' => "<p><a href=\"{$notes}\" target=\"_blank\" rel=\"noopener\">Release notes on GitHub</a></p>",
            ],
        ];
    }

    /** @return array{version: string, url: string, package: string}|null */
    private static function latestRelease(): ?array
    {
        $cached = \get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = \wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);
        if (\is_wp_error($response) || (int) \wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode((string) \wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return null;
        }

        $version = ltrim((string) ($body['tag_name'] ?? ''), 'v');
        $package = null;
        foreach ((array) ($body['assets'] ?? []) as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === "onpage-{$version}.zip") {
                $package = (string) ($asset['browser_download_url'] ?? '');
            }
        }
        if ($version === '' || !$package) {
            return null;
        }

        $release = ['version' => $version, 'url' => (string) ($body['html_url'] ?? ''), 'package' => $package];
        // ponytail: one GitHub call per hour per site; GitHub allows 60 unauthenticated calls per hour per IP.
        \set_transient(self::CACHE_KEY, $release, HOUR_IN_SECONDS);

        return $release;
    }
}
