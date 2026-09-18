#!/usr/bin/env python3
"""生成 hydra-ai 的翻译文件：.pot 模板与各语言翻译（po + mo）。

源语言为简体中文（代码内的文字即中文），英文等语言由本目录下的翻译文件提供。
新增语言时：在 LOCALES 中补充对应条目与翻译字典即可。
"""
import importlib.util
import struct
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LANG = ROOT / 'languages'

POT_HEADER = """\
msgid ""
msgstr ""
"Project-Id-Version: Hydra AI 1.0.0\\n"
"Report-Msgid-Bugs-To: https://example.com/hydra-ai/issues\\n"
"Last-Translator: \\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: 2026-09-19 00:00+0800\\n"
"X-Generator: hydra-ai-build\\n"
"X-Domain: hydra-ai\\n"
"""

# 语言环境 → (PO 头部, 翻译字典)
# 源语言为中文，因此每个语言的字典必须覆盖全部字符串，否则构建报错。
LOCALES = {
    'en_US': (
        """\
msgid ""
msgstr ""
"Project-Id-Version: Hydra AI 1.0.0\\n"
"Last-Translator: \\n"
"Language-Team: English (United States)\\n"
"Language: en_US\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Domain: hydra-ai\\n"
""",
        {
            'API Key': 'API Key',
            'API Key 优先级：条目密钥 > WordPress 连接中保存的 Hydra AI 密钥 > 常量 HYDRA_AI_API_KEY。':
                'API key precedence: the entry key > the Hydra AI key saved in WordPress Connections > the HYDRA_AI_API_KEY constant.',
            'Anthropic Messages': 'Anthropic Messages',
            'Chat Completions': 'Chat Completions',
            'Chat Completions（OpenAI 兼容）': 'Chat Completions (OpenAI-compatible)',
            'Hydra AI': 'Hydra AI',
            'Hydra AI 尚未配置任何已启用的供应商，请前往“设置 → Hydra AI”添加。':
                'Hydra AI has no enabled providers yet. Add one under Settings → Hydra AI.',
            'Responses API': 'Responses API',
            'Responses API（OpenAI 官方）': 'Responses API (official OpenAI)',
            '优先级': 'Priority',
            '使用说明': 'Usage notes',
            '使用连接器密钥': 'Using connector key',
            '例如：OpenAI 官方 / 某中转站': 'e.g. OpenAI official / a relay service',
            '供应商': 'Provider',
            '供应商按列表顺序从上到下依次请求，失败时自动切换到下一个；拖动行可调整优先级。':
                'Providers are requested in list order from top to bottom, falling back to the next one on failure. Drag rows to change priority.',
            '保存': 'Save',
            '保存失败：%s': 'Save failed: %s',
            '删除': 'Delete',
            '协议': 'Protocol',
            '协议类型无效。': 'Invalid protocol type.',
            '取消': 'Cancel',
            '名称': 'Name',
            '启用供应商': 'Enable provider',
            '启用该供应商': 'Enable this provider',
            '填写基础地址，最终请求 {端点}/chat/completions':
                'Enter the base URL. The final request goes to {endpoint}/chat/completions.',
            '填写基础地址，最终请求 {端点}/messages':
                'Enter the base URL. The final request goes to {endpoint}/messages.',
            '填写基础地址，最终请求 {端点}/responses':
                'Enter the base URL. The final request goes to {endpoint}/responses.',
            '已设置密钥': 'Key set',
            '当前 WordPress 未加载 AI 客户端（需要 WordPress 7.0+ 且未禁用 AI 功能），供应商注册与故障转移能力不可用。':
                'The WordPress AI client is not loaded (requires WordPress 7.0+ with AI features enabled). Provider registration and failover are unavailable.',
            '拖动调整优先级': 'Drag to change priority',
            '排序保存失败，请刷新页面重试。': 'Failed to save the new order. Refresh the page and try again.',
            '排序数据与条目数量不一致。': 'The order data does not match the number of entries.',
            '排序数据包含未知条目。': 'The order data contains unknown entries.',
            '操作': 'Actions',
            '故障转移：请求按列表顺序从上到下尝试，任一环节失败（网络错误、鉴权失败、响应无效等）即切换到下一个供应商。':
                'Failover: requests are attempted in list order from top to bottom. Any failure (network error, authentication failure, invalid response, etc.) switches to the next provider.',
            '最多支持 %d 个供应商条目。': 'Up to %d provider entries are supported.',
            '最近一次故障转移（全部失败）：': 'Most recent failover (all attempts failed):',
            '最近一次故障转移（已成功）：': 'Most recent failover (succeeded):',
            '服务端返回了未知错误。': 'The server returned an unknown error.',
            '未设置密钥': 'No key set',
            '未配置 API Key（条目与连接器中均未提供）。':
                'No API key configured (provided by neither the entry nor the connector).',
            '权限不足。': 'Insufficient permissions.',
            '条目不存在或已被删除。': 'The entry does not exist or has been deleted.',
            '模型': 'Model',
            '每个条目的模型会以 Hydra AI 供应商的名义暴露给 WordPress AI 客户端，其他插件可直接使用。':
                "Each entry's model is exposed to the WordPress AI client under the Hydra AI provider and can be used directly by other plugins.",
            '测试': 'Test',
            '测试中…': 'Testing…',
            '添加供应商': 'Add provider',
            '状态': 'Status',
            '留空则使用 WordPress 连接中保存的 Hydra AI 密钥；编辑时留空表示保持不变。':
                'Leave empty to use the Hydra AI key saved in WordPress Connections. When editing, empty means keep unchanged.',
            '确定要删除供应商“%s”吗？删除后不可恢复。':
                'Delete the provider "%s"? This cannot be undone.',
            '端点为基础地址，插件会自动拼接协议路径（chat/completions、responses 或 messages）。例如填 https://api.openai.com/v1 即可。':
                'The endpoint is a base URL; the plugin appends the protocol path (chat/completions, responses, or messages) automatically. For example, enter https://api.openai.com/v1.',
            '端点地址无效。': 'Invalid endpoint URL.',
            '端点（基础地址）': 'Endpoint (base URL)',
            '编辑': 'Edit',
            '编辑供应商': 'Edit provider',
            '自定义 AI 供应商端点，支持 Chat Completions、Responses 与 Anthropic 三种协议，并提供按优先级的故障转移。':
                'Custom AI provider endpoints with Chat Completions, Responses, and Anthropic protocols, plus priority-based failover.',
            '设置': 'Settings',
            '请填写供应商名称。': 'Please enter the provider name.',
            '请填写模型名称。': 'Please enter the model name.',
            '请求失败，请检查网络后重试。': 'The request failed. Check your network and try again.',
            '还没有供应商。点击右上角“添加供应商”，配置你的第一个 AI 端点。':
                'No providers yet. Click "Add provider" in the top right to configure your first AI endpoint.',
            '连接失败：%s': 'Connection failed: %s',
            '连接成功。': 'Connection successful.',
            '连接成功：%s': 'Connected: %s',
        },
    ),
}


def po_quote(text: str) -> str:
    """按 PO 语法转义字符串。"""
    return text.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def render_entry(msgid: str, msgstr: str, refs, template: bool) -> str:
    """渲染一条 PO 条目。"""
    lines = []
    for ref in refs:
        lines.append(f'#: {ref}')
    lines.append(f'msgid "{po_quote(msgid)}"')
    lines.append(f'msgstr "{po_quote("" if template else msgstr)}"')
    return '\n'.join(lines)


def write_mo(path: Path, entries: list) -> None:
    """把 (msgid, msgstr) 列表编译为 GNU MO 二进制文件。"""
    # msgfmt 按 msgid 字节序排序
    entries = sorted(entries, key=lambda item: item[0].encode('utf-8'))
    keystart = 28 + len(entries) * 8 * 2

    # 先计算所有字符串的偏移
    offsets = []
    cursor = keystart
    for msgid, msgstr in entries:
        k = msgid.encode('utf-8')
        v = msgstr.encode('utf-8')
        offsets.append((len(k), cursor, len(v), cursor + len(k) + 1))
        cursor += len(k) + 1 + len(v) + 1

    body = bytearray()
    for msgid, msgstr in entries:
        body += msgid.encode('utf-8') + b'\x00' + msgstr.encode('utf-8') + b'\x00'

    with path.open('wb') as fh:
        fh.write(struct.pack('<7I', 0x950412de, 0, len(entries),
                             28, 28 + len(entries) * 8, 0, 28))
        for klen, koff, vlen, voff in offsets:
            fh.write(struct.pack('<2I', klen, koff))
        for klen, koff, vlen, voff in offsets:
            fh.write(struct.pack('<2I', vlen, voff))
        fh.write(body)


def extract_po_header(po_text: str) -> str:
    """取 PO 头部条目的 msgstr 内容（用于写入 MO 头部）。"""
    first_msgstr = po_text.index('msgstr ""') + len('msgstr ""')
    return po_text[first_msgstr:].lstrip('\n')


def main() -> None:
    spec = importlib.util.spec_from_file_location(
        'extract', Path(__file__).with_name('extract_strings.py')
    )
    extract = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(extract)
    found = extract.found

    LANG.mkdir(exist_ok=True)

    # .pot 模板（msgstr 留空，供翻译者填写新语言）
    pot_entries = [render_entry(msgid, '', refs, True) for msgid, refs in sorted(found.items())]
    (LANG / 'hydra-ai.pot').write_text(
        POT_HEADER + '\n' + '\n\n'.join(pot_entries) + '\n', encoding='utf-8'
    )
    print(f'hydra-ai.pot：{len(found)} 条字符串')

    # 各语言 po + mo
    for locale, (header, translations) in LOCALES.items():
        missing = set(found) - set(translations)
        extra = set(translations) - set(found)
        if missing:
            raise SystemExit(f'{locale} 缺少 {len(missing)} 条翻译：{sorted(missing)[:3]}')
        if extra:
            raise SystemExit(f'{locale} 存在 {len(extra)} 条多余翻译（代码中已不存在）：{sorted(extra)[:3]}')

        po_entries = [render_entry(msgid, translations[msgid], refs, False)
                      for msgid, refs in sorted(found.items())]
        po_path = LANG / f'hydra-ai-{locale}.po'
        po_path.write_text(
            header + '\n' + '\n\n'.join(po_entries) + '\n', encoding='utf-8'
        )
        write_mo(LANG / f'hydra-ai-{locale}.mo', [('', extract_po_header(header))] + list(translations.items()))
        print(f'hydra-ai-{locale}.po / .mo：{len(translations)} 条翻译')


if __name__ == '__main__':
    main()
