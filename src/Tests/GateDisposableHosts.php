<?php

namespace OnPage\Tests;

require_once __DIR__ . '/Gate.php';

/**
 * Offline: the sites `Gate` agrees to wipe. Local ones always, any other host only when
 * `WP_TEST_DISPOSABLE_HOSTS` lists it, so the gate never empties a shared or customer site
 * by mistake.
 *
 * Run with: php src/Tests/GateDisposableHosts.php
 */
$cases = [
    // [WP_TEST_URL, WP_TEST_DISPOSABLE_HOSTS, may wipe]
    ['http://localhost:8040', '', true],
    ['http://127.0.0.1:8040', '', true],
    ['http://[::1]:8040', '', true],
    ['http://LOCALHOST:8040', '', true],
    ['https://wp-plugin.nebula.onpage.it', '', false],
    ['https://wp-plugin.nebula.onpage.it', 'other.test, WP-Plugin.nebula.onpage.it', true],
    ['https://shop.example.com', 'wp-plugin.nebula.onpage.it', false],
    ['https://localhost.example.com', '', false],
    ['localhost', '', false],
    ['not a url', ',', false],
];

$failures = 0;
foreach ($cases as [$url, $hosts, $expected]) {
    $got = Gate::isDisposable($url, $hosts);
    $outcome = $got === $expected ? 'ok' : 'FAILED';
    echo "  [$outcome] $url with '$hosts': " . ($got ? 'wiped' : 'refused') . "\n";
    $failures += $got === $expected ? 0 : 1;
}

if ($failures > 0) {
    fwrite(STDERR, "\nFAILED: $failures of " . count($cases) . " cases\n");
    exit(1);
}

echo "\nPASSED: " . count($cases) . ' of ' . count($cases) . " cases\n";
