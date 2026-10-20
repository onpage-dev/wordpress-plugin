<?php



namespace OnPage;



/**
 * Reads the test settings: the process environment first, then the plugin `.env` file.
 * Test-only: the tests in `src/Tests` require this file themselves, and `plugin.php` never
 * loads it, so production requests never read `.env`.
 */
class Env
{
    private static ?self $instance = null;
    private array $values = [];



    /**
     * Loads optional `.env` from the plugin directory as key/value pairs.
     */
    private function __construct()
    {
        $env_path = dirname(__DIR__) . '/.env';
        if (!is_readable($env_path)) {
            return;
        }

        $values = parse_ini_file($env_path, false, INI_SCANNER_RAW);
        if (is_array($values)) {
            $this->values = $values;
        }
    }


    
    /**
     * Returns the singleton holding parsed environment values.
     */
    private static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reads a value from the process environment, else from the plugin `.env` file, else
     * returns the default.
     *
     * The environment wins so that one run can target another site without editing `.env`,
     * for example `WP_TEST_URL=http://localhost:8040 php src/Tests/TrashRestore.php`. An
     * empty variable counts as unset.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $process_value = getenv($key);
        if (is_string($process_value) && trim($process_value) !== '') {
            return trim($process_value);
        }

        $value = self::instance()->values[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Whether a key is set in the process environment or in the loaded `.env` data.
     */
    public static function has(string $key): bool
    {
        $process_value = getenv($key);
        if (is_string($process_value) && trim($process_value) !== '') {
            return true;
        }

        return array_key_exists($key, self::instance()->values);
    }
}
