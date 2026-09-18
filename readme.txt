=== Hydra AI ===
Contributors: hydra-ai
Tags: ai, openai, anthropic, connector, failover
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

在 WordPress 连接中注册一个自定义 AI 供应商，支持三种协议与多供应商故障转移。

== Description ==

WordPress 7.0 内置的 AI 供应商集成只支持 OpenAI、Gemini、Anthropic 三家。Hydra AI 在 WordPress 连接（Connectors）中注册一个 "Hydra AI" 供应商，并允许你自定义任意端点：

* 三种协议：Chat Completions（OpenAI 兼容）、Responses API（OpenAI 官方）、Anthropic Messages。
* 在“设置 → Hydra AI”中以列表方式管理供应商，右上角“添加供应商”即可新增条目。
* 拖动调整优先级：请求从上到下依次尝试，任一环节失败自动切换到下一个供应商。
* 每个条目的模型会暴露给 WordPress AI 客户端（AiClient），其他插件可直接使用。
* 支持连通性测试、启用/禁用开关、密钥回退（条目密钥 > 连接器密钥 > 常量 HYDRA_AI_API_KEY）。
* 文件输入按各协议原生能力全量支持：图片（JPEG/PNG/GIF/WebP）、PDF 文档、纯文本文档与音频（WAV/MP3）；远端文件在不支持 URL 的协议下自动下载内联（上限 25MB），不支持的类型自动故障转移。

== Installation ==

1. 将插件上传到 `wp-content/plugins/hydra-ai` 并启用。
2. 前往“设置 → Hydra AI”，点击“添加供应商”配置端点、密钥与模型。
3. （可选）在“设置 → 连接”中为 Hydra AI 连接器保存一个默认 API Key。

== i18n ==

插件源语言为简体中文，界面文字直接以中文写在代码中；其他语言由 `languages/` 下的翻译文件提供：

* `hydra-ai.pot`：翻译模板，新增语言时以它为底稿。
* `hydra-ai-en_US.po/.mo`：英文翻译，站点语言为 English (United States) 时自动生效。
* 新增其他语言：复制 en_US 的条目、填入译文并编译为 .mo（或用 Loco Translate 等插件操作），命名格式为 `hydra-ai-{语言代码}.po/.mo`。

== Changelog ==

= 1.0.1 =
* 补齐三协议文件输入：音频（WAV/MP3）、PDF 与纯文本文档；远端文件在不支持 URL 的协议下自动下载内联。

= 1.0.0 =
* 首个版本：连接器注册、三协议端点管理、拖拽排序与故障转移。
