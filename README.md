# BetterComment — 全能评论增强插件

Typecho 全能评论增强插件。

## 功能一览

| 功能 | 说明 |
|------|------|
| 🎨 **头像解析** | QQ 邮箱 → QQ 头像 CDN；其他邮箱 → `avatars/` 随机匹配；无头像文件 → SVG 首字母占位图 |
| 🌍 **IP 属地** | 评论区「时间 \| 位置 \| 点赞」行显示发评论者的 IP 地理位置（uapis.cn 主查 + ip-api 兜底，本地缓存） |
| 📧 **邮件通知** | 评论时通知文章作者，回复时通知被回复者，审核通过时通知评论者（带总开关，可一键关闭） |
| 🔑 **找回密码** | 登录页添加"忘记密码"链接，通过邮件发送重置密码链接 |
| ✉️ **三通道** | 支持 SMTP / Send Cloud / 阿里云推送 三种邮件发送方式 |
| 🐞 **Debug 模式** | 可开启邮件发送日志，方便排查问题 |

## 安装

1. 将 `BetterComment` 上传到 `usr/plugins/`
2. 后台 → 控制台 → 插件 → 启用 **BetterComment**
3. 进入设置页，按需配置 IP 属地、邮件接口、找回密码

## 配置说明

### 🌍 IP 属地

| 配置项 | 默认 | 说明 |
|--------|------|------|
| 显示评论 IP 属地 | 启用 | 评论区「时间 \| 位置 \| 点赞」行显示地理位置（由 VOID 主题渲染；未启用则不显示） |
| uapis 接口鉴权 | 空 | uapis.cn 接口 Authorization 头完整值（含鉴权前缀）；未配置时自动回退 ip-api.com |

> 查询链路：uapis.cn 优先 → ip-api.com 兜底，结果按 IP 本地缓存（`cache/ip_locations.json`，上限 5000 条，并发读写安全）。
> 运营商自动简写（中国移动 / China Mobile → 移动，联通 / 电信 / 铁通 / 广电 同理），显示无方括号。

### 📧 邮件通知

设置区顶部有「启用邮件通知」总开关（默认开启）：关闭后不发送任何邮件（新评论 / 回复 / 审核通过）；只有勾选启用时才校验发信接口、发件邮箱、回信邮箱必填。

需配置发信接口和发件人信息。三个接口任选其一：

- **SMTP**：通用，需 SMTP 服务器地址、端口、账号密码
- **Send Cloud**：第三方邮件推送服务，需 API_USER / API_KEY
- **阿里云推送**：需 AccessKey ID / Secret

支持 Debug 模式，开启后会在插件目录生成 `debug.txt` 记录发送日志。

### 🔑 找回密码

- 启用后在登录页出现"忘记密码"链接
- 用户输入注册邮箱，系统发送带重置链接的邮件
- 链接有效期可在设置中自定义（默认 10 分钟）

## 目录结构

```
BetterComment/
├── Plugin.php       # 主插件（头像 / IP / 邮件 / 找回密码）
├── Action.php       # 找回密码页面处理器
├── README.md        # 本文件
├── avatars/         # 头像图片文件（用户自行添加）
├── cache/           # IP 查询缓存
├── lib/             # PHPMailer 邮件库
└── theme/           # 邮件模板
    ├── approved.html   # 审核通过模板
    ├── author.html     # 文章评论通知模板
    ├── reply.html      # 回复通知模板
    └── forget.html     # 找回密码模板
```

## 自定义模板

邮件模板位于 `theme/` 目录，支持以下变量替换：

### approved.html / author.html
`{blogUrl}` `{blogName}` `{author}` `{permalink}` `{title}` `{text}`

### reply.html
`{blogUrl}` `{blogName}` `{author}` `{permalink}` `{title}` `{text}` `{replyAuthor}` `{replyText}` `{commentUrl}`

### forget.html
`{blogname}` `{blogurl}` `{mail}` `{sendtime}` `{resetlink}` `{expire}`

## 自定义头像

替换 `avatars/` 中的文件，支持 `.svg` `.png` `.jpg` `.gif` `.webp`，建议 100×100 像素。
按照文件名排序，根据邮箱哈希值固定匹配。

## 许可

GPL v2

## 作者

[FmCoral](https://github.com/FmCoral)
