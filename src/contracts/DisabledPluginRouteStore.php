<?php

declare(strict_types=1);

namespace core\plugin\contracts;

interface DisabledPluginRouteStore extends PluginStore
{
    /** @return list<array<string, mixed>> */
    public function disabledRows(): array;
}
