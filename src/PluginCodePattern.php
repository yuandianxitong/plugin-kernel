<?php

declare(strict_types=1);

namespace core\plugin;

final class PluginCodePattern
{
    /** plugin.json 的 code 与 entitlement。 */
    public const MANIFEST = '/^(?=.{2,64}$)[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    public const ASSET = self::MANIFEST;

    public const ICON_FILE = '/^[a-z0-9][a-z0-9._-]{0,63}\.(png|jpg|jpeg|svg|webp|ico|gif)$/iD';
}
