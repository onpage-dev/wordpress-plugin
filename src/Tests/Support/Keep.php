<?php



namespace OnPage\Tests\Support;



use OnPage\Env;



require_once dirname(__DIR__, 2) . '/Env.php';



/**
 * Whether a test should leave its data on the site once it is done.
 *
 * Off by default: every end-to-end test removes what it created, even when an assertion
 * fails. With `ONPAGE_TEST_KEEP=1` the final cleanup is skipped, so the products, terms
 * and posts can be inspected in wp-admin after the run. The cleanup a test performs
 * before building its fixture always runs, and so does `src/Tests/Gate.php`: the next
 * run still starts from a clean site.
 *
 * The process environment wins over `.env`, so a single run can opt in with
 * `ONPAGE_TEST_KEEP=1 ./bin/test-launcher` without editing the file.
 */
class Keep
{
    /** Values read as "on"; anything else, empty included, is "off". */
    private const TRUTHY = ['1', 'true', 'yes', 'on'];



    public static function enabled(): bool
    {
        $value = getenv('ONPAGE_TEST_KEEP');
        if (!is_string($value) || $value === '') {
            $value = Env::get('ONPAGE_TEST_KEEP', '');
        }

        return in_array(strtolower(trim((string) $value)), self::TRUTHY, true);
    }
}
