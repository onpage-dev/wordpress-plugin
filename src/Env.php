<?php



namespace OnPage;



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
     * Reads a value from the plugin `.env` file, or returns default if missing.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::instance()->values[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Whether a key exists in the loaded `.env` data.
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::instance()->values);
    }
}
