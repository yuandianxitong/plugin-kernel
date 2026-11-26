<?php

declare(strict_types=1);

namespace core\plugin\contracts;

interface TenantLifecycleHook
{
    /** 在租户启用事务内初始化租户数据。 */
    public function enable(int $tenantId): void;

    /** 清理该租户的缓存等可重试资源。 */
    public function disable(int $tenantId): void;
}
