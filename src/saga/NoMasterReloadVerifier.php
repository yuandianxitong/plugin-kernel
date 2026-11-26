<?php

declare(strict_types=1);

namespace core\plugin\saga;

/**
 * 没有 master 在跑时的生效校验：没有 worker 装载任何插件，也就没有需要收敛的指纹，直接放行。
 * 只由 {@see PluginSagaRunner::reloadVerifier()} 在检测到 master 不存在时返回。
 */
final class NoMasterReloadVerifier extends ReloadVerifier
{
    public function __construct()
    {
    }

    public function waitUntilEffective(string $expected, ?int $timeoutSeconds = null): string
    {
        return $expected;
    }
}
