<?php

declare(strict_types=1);

namespace core\plugin;

final class PluginFiles
{
    /** 取最近一次被 @ 抑制的文件操作失败原因，去掉前半截的函数与路径。 */
    public static function lastErrorReason(): string
    {
        $last = error_get_last();
        $msg = $last === null ? '' : trim($last['message']);
        if ($msg === '') {
            return '原因未知';
        }
        $pos = strrpos($msg, '): ');

        return $pos === false ? $msg : substr($msg, $pos + 3);
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $children = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($children as $child) {
            if ($child->isDir() && !$child->isLink()) {
                @rmdir($child->getPathname());
            } else {
                @unlink($child->getPathname());
            }
        }
        @rmdir($path);
    }

    public static function backupDirName(string $code, string $version): string
    {
        return "{$code}-{$version}-" . time();
    }
}
