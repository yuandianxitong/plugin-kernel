# 元点插件内核

`yuandianxitong/plugin-kernel` 为元点 framework-webman、Saas 和 Shop 提供插件清单校验、ZIP 安装、生命周期、路由和前端资源发布能力，使用 MIT 许可证。

三个宿主在服务端的 `composer.json` 中使用公开的 GitHub HTTPS 仓库地址，检出宿主后执行 `composer install` 即可安装锁定版本。

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/yuandianxitong/plugin-kernel"
    }
  ],
  "require": {
    "yuandianxitong/plugin-kernel": "^1.0"
  }
}
```

## 宿主集成

PHP 命名空间为 `core\plugin`。包面向 Webman 宿主，运行时使用宿主提供的 `core\context`、`core\diy`、`core\exception`、`core\runtime`、`core\saga` 和 `core\support` 能力，以及路由属性和依赖注入配置。插件存储、权限、菜单和租户启停策略由各宿主实现并绑定到本包契约。

## 版本维护

源码位于 `src/`。修改后在三个宿主运行插件回归测试，并用 Git 标签发布版本，例如 `v1.0.1`。各宿主用 `composer update yuandianxitong/plugin-kernel` 更新锁文件。

framework-webman 的宿主实现见 [元点框架仓库](https://github.com/yuandianxitong/ydadmin)。
