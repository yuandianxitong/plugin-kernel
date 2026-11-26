<?php

declare(strict_types=1);

namespace core\plugin;

use core\plugin\contracts\DisabledPluginRouteStore;
use core\plugin\contracts\FlatPluginRoutes;
use core\plugin\contracts\PluginStore;
use core\plugin\contracts\RouteBuckets;
use core\plugin\contracts\RouteGenerationStore;
use support\Container;
use support\Log;
use Webman\App;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 宿主路由未命中时，按插件代码段分发已启用插件的属性路由。
 * 内存表与 {@see RouteGenerationStore::read()} 的版本号对齐；读不到版本且还没有表时直接未命中。
 */
final class PluginRouteDispatcher
{
    /** @var array<string, array{manifest: array<string, mixed>, definitions: list<array{bucket: string, method: string, path: string, controller: string, action: string, apiAuth: bool, availableWhenDisabled?: bool}>}>|null */
    private static ?array $table = null;

    private static ?int $builtGeneration = null;

    public function __construct(
        private readonly RouteGenerationStore $generation,
        private readonly PluginRouteScanner $scanner,
        private readonly PluginStore $plugins,
        private readonly PluginLoader $loader,
        private readonly RouteBuckets $buckets,
    ) {
    }

    public function dispatch(Request $request): ?Response
    {
        $parsed = $this->parsePath($request->path());
        if ($parsed === null || !$this->ensureTable() || self::$table === null) {
            return null;
        }

        $definitions = [];
        $entries = $parsed['code'] === null
            ? self::$table
            : array_intersect_key(self::$table, [$parsed['code'] => true]);
        foreach ($entries as $code => $entry) {
            foreach ($this->definitionsFor($parsed['prefix'], $entry['definitions']) as $route) {
                $definitions[] = $route + ['pluginCode' => (string) $code];
            }
        }
        $match = PluginRouteMatch::find($request->method(), $parsed['relative'], $definitions);
        if ($match === null) {
            return null;
        }

        $code = $match['pluginCode'];
        $request->controller = (string) $match['controller'];
        $request->action = (string) $match['action'];

        return $this->invoke(
            $request,
            $this->buckets->chain((string) $match['bucket'], $code, $entries[$code]['manifest']),
            (array) ($match['params'] ?? []),
        );
    }

    /**
     * @return list<class-string|object>
     */
    public static function chainForTest(string $bucket, string $code, string $entitlement): array
    {
        return Container::get(RouteBuckets::class)->chain($bucket, $code, ['entitlement' => $entitlement]);
    }

    private function ensureTable(): bool
    {
        $current = $this->generation->read();
        if ($current === null) {
            return self::$table !== null;
        }
        if (self::$table !== null && self::$builtGeneration === $current) {
            return true;
        }

        $this->rebuild($current);

        return true;
    }

    private function rebuild(int $generation): void
    {
        $table = [];
        $enabled = $this->plugins->enabledRows();
        $disabled = $this->plugins instanceof DisabledPluginRouteStore ? $this->plugins->disabledRows() : [];
        foreach ([...$enabled, ...$disabled] as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            try {
                $dir = PluginPaths::dir($code);
                $manifest = ManifestReader::tryRead($dir);
                if ($manifest === null) {
                    throw new \RuntimeException(is_file(ManifestReader::path($dir))
                        ? "插件 {$code} plugin.json 无效"
                        : "插件 {$code} plugin.json 不存在");
                }
                $this->loader->registerPsr4($manifest, $dir);
                $definitions = $this->scanner->scan($dir, $manifest);
                if ((int) ($row['status'] ?? PluginStatus::ENABLED) === PluginStatus::DISABLED) {
                    $definitions = array_values(array_filter(
                        $definitions,
                        static fn (array $route): bool => $route['availableWhenDisabled'] ?? false,
                    ));
                }
                $table[$code] = ['manifest' => $manifest, 'definitions' => $definitions];
            } catch (\Throwable $e) {
                Log::error(sprintf('[plugin:%s] 注解路由扫描失败，已跳过：%s', $code, $e->getMessage()));
            }
        }

        self::$table = $table;
        self::$builtGeneration = $generation;
    }

    /**
     * @return array{prefix: string, code: ?string, relative: string}|null
     */
    private function parsePath(string $path): ?array
    {
        $query = strpos($path, '?');
        if ($query !== false) {
            $path = substr($path, 0, $query);
        }
        $prefixes = implode('|', array_map(
            static fn (string $prefix): string => preg_quote($prefix, '#'),
            $this->buckets->prefixes(),
        ));
        if ($this->buckets instanceof FlatPluginRoutes) {
            if (!preg_match('#^/(' . $prefixes . ')(/.*)?$#', $path, $matches)) {
                return null;
            }

            return ['prefix' => $matches[1], 'code' => null, 'relative' => $matches[2] ?? '/'];
        }
        if (!preg_match('#^/(' . $prefixes . ')/([^/]+)(/.*)?$#', $path, $matches)) {
            return null;
        }
        $relative = $matches[3] ?? '';
        if ($relative === '') {
            $relative = '/';
        }

        return [
            'prefix' => $matches[1],
            'code' => $matches[2],
            'relative' => $relative,
        ];
    }

    /**
     * @param list<array{bucket: string, method: string, path: string, controller: string, action: string, apiAuth: bool, availableWhenDisabled?: bool}> $definitions
     * @return list<array{bucket: string, method: string, path: string, controller: string, action: string, apiAuth: bool, availableWhenDisabled?: bool}>
     */
    private function definitionsFor(string $prefix, array $definitions): array
    {
        $buckets = $this->buckets->bucketsForPrefix($prefix);
        $filtered = [];
        foreach ($definitions as $row) {
            if (in_array((string) $row['bucket'], $buckets, true)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * 动作调用交给 webman 的 getCallback：按参数名绑定路由参数、做依赖注入。
     * 不带全局中间件（宿主中间件链由 RouteBuckets::chain() 提供）；动作返回值与 webman 一样规整成 Response。
     *
     * @param list<class-string|object> $chain
     * @param array<string, string> $params
     */
    private function invoke(Request $request, array $chain, array $params = []): Response
    {
        $controller = (string) $request->controller;
        $action = (string) $request->action;
        $callback = App::getCallback('', '', [$controller, $action], $params, false);
        $next = static function (Request $request) use ($callback) {
            return self::normalize($callback($request));
        };
        foreach (array_reverse($chain) as $middleware) {
            $next = static function (Request $request) use ($middleware, $next): Response {
                $instance = is_string($middleware) ? Container::get($middleware) : $middleware;

                return $instance->process($request, $next);
            };
        }

        return $next($request);
    }

    /**
     * 与 {@see App::getCallback()} 中间件分支一致：非 Response 的返回值转成字符串响应。
     */
    private static function normalize(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (!is_string($result)) {
            $result = match (gettype($result)) {
                'boolean' => $result ? 'true' : 'false',
                'NULL' => 'NULL',
                'array' => 'Array',
                'object' => method_exists($result, '__toString') ? (string) $result : 'Object',
                default => (string) $result,
            };
        }

        return new \support\Response(200, [], $result);
    }
}
