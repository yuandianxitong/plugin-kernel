<?php

declare(strict_types=1);

namespace core\plugin;

use core\exception\BusinessException;
use core\plugin\contracts\PluginStore;
use core\runtime\RuntimePaths;
use ZipArchive;

/** 验证并暂存原位升级包；宿主在同一数据库事务中推进状态和登记 saga。 */
final class PluginUpgradePackage
{
    public function __construct(
        private readonly PluginPackageInstaller $installer,
        private readonly PluginManifestValidator $validator,
        private readonly PluginStore $plugins,
    ) {
    }

    /** @return array{staged_zip:string,staged_hash:string,from_version:string,to_version:string,host_sdk_version:string,core_version:string} */
    public function prepare(string $code, string $fromVersion, string $path): array
    {
        $manifest = $this->installer->inspect($path);
        $errors = $this->validator->validate($manifest);
        if ($errors !== []) {
            throw new BusinessException(implode('；', $errors), 422);
        }
        if ($manifest['code'] !== $code || !PluginVersion::isUpgrade($fromVersion, $manifest['version'])) {
            throw new BusinessException('升级包必须属于当前插件，且版本高于已安装版本', 422);
        }

        $coreVersion = (string) config('version.version');
        $hostSdkVersion = (string) config('version.host_sdk');
        PluginRequirements::assertPlatform($manifest, $coreVersion, PluginRequirements::enabledCodes($this->plugins));
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new BusinessException('无法读取插件升级包', 422);
        }
        try {
            HostSdkCompat::assertZipCompatible($zip, $code, $hostSdkVersion);
        } finally {
            $zip->close();
        }

        $folder = RuntimePaths::pluginPackagesDir(base_path()) . '/staging';
        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new BusinessException('无法创建插件升级包暂存目录', 500);
        }
        $staged = $folder . '/' . $code . '-' . bin2hex(random_bytes(12)) . '.zip';
        if (!@copy($path, $staged) || ($hash = hash_file('sha256', $staged)) === false) {
            @unlink($staged);
            throw new BusinessException('无法暂存并计算插件升级包的校验值', 500);
        }

        return [
            'staged_zip' => $staged,
            'staged_hash' => $hash,
            'from_version' => $fromVersion,
            'to_version' => $manifest['version'],
            'host_sdk_version' => $hostSdkVersion,
            'core_version' => $coreVersion,
        ];
    }
}
