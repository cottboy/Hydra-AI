=== Hydra AI ===
Contributors: hydra-ai
Tags: ai, openai, anthropic, connector, failover
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

在 WordPress 连接中注册多个自定义 AI 供应商，适配三种通用协议，并支持多接口故障转移。

== Description ==

WordPress 7 的 AI Client 通过供应商协议连接外部 AI 服务。Hydra AI 只实现协议层，不把请求地址锁定到某一家厂商：

* Chat Completions
* Responses
* Anthropic Messages

每个接口都要填写完整的请求地址，插件不会追加也不会改写任何路径。例如 Chat Completions 填 `https://api.openai.com/v1/chat/completions`，Anthropic Messages 填 `https://api.anthropic.com/v1/messages`。

在“设置 → Hydra AI”右上方可以管理多个命名配置。安装后自动有“Hydra AI”配置；输入名称并点击“新增”即可创建新的独立配置。每个配置都会在 WordPress“连接”中注册为同名 AI 供应商，删除或重命名时同步更新连接。

每个配置可以添加多个接口，按列表顺序尝试，失败后自动切换到下一个接口。每个接口可单独设置协议、端点、模型和 API Key；端点要填完整请求地址。WordPress“连接”页面中的密钥只用于完成连接，不参与接口请求。

协议层支持 WordPress AI Client 能表达的文本、多模态输入、工具调用、结构化输出、推理内容、拒答、停止原因、Token 用量、服务端扩展数据与 SSE 流式响应。协议不包含任何厂商专属的模型发现、嵌入、图片模型或语音模型接口。

文件输入按协议能力处理图片、PDF、纯文本文档和音频；不支持的文件类型会让当前接口失败并触发下一个接口。

== 参考与许可 ==

本插件的协议适配实现参考并改写了以下 WordPress 官方插件公开的 GPL-2.0-or-later 实现：

* AI Provider for OpenAI：https://wordpress.org/plugins/ai-provider-for-openai/
* AI Provider for Anthropic：https://wordpress.org/plugins/ai-provider-for-anthropic/
* AI Provider for Google：https://wordpress.org/plugins/ai-provider-for-google/ 。其 Gemini 专有协议不属于本插件的三种协议范围。

实现依据：

* WordPress AI Client：https://github.com/WordPress/php-ai-client
* WordPress 连接注册规则：WordPress 7 的 wp_connectors_init 与 AI Client 自动发现机制。
* Chat Completions：https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create
* Responses：https://developers.openai.com/api/reference/resources/responses/methods/create
* Anthropic Messages：https://platform.claude.com/docs/en/api/messages/create

== 协议能力与边界 ==

* 三种协议都支持对话记录、图片与文档输入、函数调用与结果回传、结构化输出、用量统计和扩展请求字段。
* Chat Completions 还支持多候选、音频输入与输出、输出概率和标准网页搜索参数。
* Responses 还支持加密推理内容往返、推理用量、输出概率、网页搜索和协议内的图片生成工具。
* Messages 还支持带签名的思考、隐藏思考与服务端工具状态保留、缓存用量、搜索域名筛选，以及 pause_turn 自动续接（最多续接 5 次）。纯文本文档按 text 来源发送，PDF 按 URL 或 base64 来源发送。
* SSE 响应在 WordPress 收完请求后合并为完整结果；不会逐字推送到页面。
* 调用方可使用 WordPress AI Client 的 customOptions 传入三种协议的其他请求字段，例如推理强度、工具选择、缓存控制和会话标识。插件不按厂商名称或模型名称猜测功能、添加私有规则。
* 具体模型是否能处理图片、音频、工具等能力，取决于用户配置的服务。协议有对应字段，不代表所有模型都支持；接口拒绝或返回错误时按配置顺序切换。
* 接口地址只接受 HTTP 或 HTTPS，不接受嵌入账号、查询参数或片段。仅管理员保存的接口地址允许访问内网，普通文件下载使用 WordPress 的安全限制，文件大小上限为 25MB。

== Installation ==

1. 将插件上传到 `wp-content/plugins/hydra-ai` 并启用。
2. 前往“设置 → Hydra AI”，选择“Hydra AI”配置并点击“添加接口”。
3. 填写协议、完整的端点地址、模型和密钥并保存。
4. （可选）在“设置 → 连接”中为对应配置填写任意 API Key 完成连接；实际请求只使用 Hydra AI 设置中保存的接口密钥。

== i18n ==

插件源语言为简体中文，界面文字通过 `languages/` 下的翻译文件提供：

* `hydra-ai.pot`：翻译模板。
* `hydra-ai-en_US.po/.mo`：英文翻译。

== Changelog ==

= 2.0.0 =
* 支持多个命名配置，并在 WordPress 连接中分别注册、删除和重命名供应商。
* 将配置和接口密钥按配置隔离，连接页密钥不参与请求。
* 补齐 Responses 加密推理内容往返、输出概率和长度限制识别。
* 补齐 Messages 自动续接、完整文本合并、思考签名、隐藏状态与思考用量。
* 修复带命名空间的 WordPress 工具名称、无格式音频返回、非法地址与重复排序校验。
* 修复翻译文件生成并补齐配置管理界面的中英文文字。
* 更新三种协议的公开协议能力说明和官方插件参考声明。

= 1.1.0 =
* 接入 WordPress AI Client 的多模态、工具调用、结构化输出和流式响应能力。

= 1.0.0 =
* 首个版本：连接器注册、三协议端点管理、拖拽排序与故障转移。
