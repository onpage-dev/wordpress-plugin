<?php



namespace OnPage;



use OnPage\Exceptions\HttpException;



class Router
{
    private array $routes = [];



    /**
     * @param string $name    REST namespace first segment (e.g. `onpage`).
     * @param string $version API version segment (e.g. `v1`).
     */
    public function __construct(public string $name, public string $version) {}



    /**
     * Replaces `{param}` path segments with string named capture groups.
     */
    private static function set_placeholders(string $path): string
    {
        return (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $path);
    }

    /**
     * Invokes a route callback (closure, callable, or [class, method]) with the REST request.
     *
     * @param callable|array{class-string|object, string}|null $callback Route handler.
     * @param \WP_REST_Request|null                            $request  Current request (null for optional permission callbacks).
     */
    private static function dispatch(callable|array|null $callback, ?\WP_REST_Request $request = null): mixed
    {
        try {
            if (!$callback) {
                return null;
            }

            if (is_array($callback)) {
                [$class, $method] = $callback;

                if (is_string($class)) {
                    return (new $class())->$method($request);
                }

                return $class->$method($request);
            }

            if (is_callable($callback)) {
                return $callback($request);
            }
        } catch (HttpException $e) {
            return new \WP_Error(
                $e->error_code,
                $e->getMessage(),
                ['status' => $e->status_code]
            );
        }

        return null;
    }



    /**
     * Registers a route definition on this router instance (flushed on `resolve()`).
     *
     * @param string                      $method     HTTP method.
     * @param string                      $path       Path under the version, may include `{id}` placeholders.
     * @param callable|array              $callback   Handler returning a REST response.
     * @param class-string|null           $middleware Optional middleware class with `handle(\WP_REST_Request $request)`.
     */
    public function bind(string $method, string $path, callable|array $callback, ?string $middleware = null): self
    {
        $this->routes[] = [
            'method' => $method,
            'path' => self::set_placeholders($path),
            'callback' => $callback,
            'permission_callback' => $middleware ? [$middleware, 'handle'] : null,
        ];

        return $this;
    }

    /**
     * Hooks into `rest_api_init` and registers all bound routes with WordPress.
     */
    public function resolve(): void
    {
        \add_action('rest_api_init', function () {
            foreach ($this->routes as $route) {
                \register_rest_route("{$this->name}/{$this->version}", $route['path'], [
                    'methods' => $route['method'],
                    'callback' => function (\WP_REST_Request $request) use ($route) {
                        return self::dispatch($route['callback'], $request);
                    },
                    'permission_callback' => function (\WP_REST_Request $request) use ($route) {
                        return self::dispatch($route['permission_callback'], $request);
                    },
                ]);
            }
        });
    }
}
