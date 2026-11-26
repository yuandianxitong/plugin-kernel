<?php

declare(strict_types=1);

namespace core\plugin\contracts;

interface IdentifiedPluginHostProfile extends PluginHostProfile
{
    public function hostId(): string;
}
