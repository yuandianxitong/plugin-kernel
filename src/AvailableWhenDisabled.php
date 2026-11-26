<?php

declare(strict_types=1);

namespace core\plugin;

use Attribute;

/** 停用后可继续处理已有业务的动作。身份权限和资源归属仍由宿主和插件检查。 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AvailableWhenDisabled
{
}
