# CommentAvatar — 评论头像自动解析

Typecho 插件：根据评论者邮箱自动替换头像。QQ 邮箱使用 QQ 个性头像，其他邮箱随机匹配预设头像。

## 功能

| 邮箱类型 | 头像来源 |
|----------|----------|
| QQ 邮箱（`数字@qq.com`） | QQ 官方头像 CDN（`q1.qlogo.cn`） |
| 其他邮箱 | 插件 `avatars/` 文件夹随机匹配（确定性分配） |
| 降级（无水龙头像文件） | 动态生成 SVG 首字母头像 |

## 安装

1. 将 `CommentAvatar` 文件夹上传到 `usr/plugins/`
2. 后台 → 控制台 → 插件 → 启用 **CommentAvatar**
3. 无需配置，立即生效

## 自定义头像

替换 `avatars/` 中的 SVG 文件即可，支持 `.svg` `.png` `.jpg` `.gif` `.webp`，建议 100×100 像素。

## 实现原理

- 钩子：`Widget\Base\Comments->gravatar`
- QQ 邮箱正则 `/^\d+@qq\.com$/i` → `q1.qlogo.cn/g?b=qq&nk={QQ}&s=100`
- 非 QQ 邮箱 `crc32(email) % N` → 确定性选取头像文件
- 无水龙头像时动态生成 HSL 配色 + 首字母 SVG Data URI

## 许可

GPL v2

## 作者

[Coral](https://github.com/FmCoral)
