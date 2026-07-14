# CommentAvatar — 评论头像 + IP 属地

Typecho 插件：QQ 邮箱自动使用 QQ 头像，其他邮箱随机匹配预设头像，评论旁显示 IP 地理位置。

## 功能

| 功能 | 说明 |
|------|------|
| 🎨 头像解析 | QQ 邮箱 → QQ 头像 CDN；其他邮箱 → `avatars/` 随机匹配 |
| 🌍 IP 属地 | 评论头像旁显示 IP 地理位置（如"广东 · 深圳"） |
| 🔀 双 API | ip-api.com / pconline 可选，国内推荐 pconline |

## 安装

1. 将 `CommentAvatar` 上传到 `usr/plugins/`
2. 后台 → 控制台 → 插件 → 启用 **CommentAvatar**
3. 进入设置页勾选"显示评论 IP 属地"并选择查询服务

## 配置

| 配置项 | 默认 | 说明 |
|--------|------|------|
| 显示评论 IP 属地 | 启用 | 头像旁显示发评论者的地理位置 |
| IP 查询服务 | ip-api.com | ip-api.com（国际）/ pconline（国内·中文·免费） |

> 💡 若已安装 IpAccessLog 插件，CommentAvatar 会直接复用其 IP 缓存，无需额外配置。

## 自定义头像

替换 `avatars/` 中的文件，支持 `.svg` `.png` `.jpg` `.gif` `.webp`，建议 100×100 像素。

## 许可

GPL v2

## 作者

[Coral](https://github.com/FmCoral)
