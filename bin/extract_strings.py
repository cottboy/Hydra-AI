#!/usr/bin/env python3
"""从 PHP 源码提取 hydra-ai 文本域的翻译字符串，列出 msgid 与引用位置。"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# 匹配 __()、_e()、esc_html__() 等函数中的单引号字符串 + hydra-ai 文本域
PATTERN = re.compile(
    r"""(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\s*\(\s*"""
    r"""'((?:[^'\\]|\\.)*)'\s*(?:,[^,()]+)?,\s*'hydra-ai'\s*\)"""
)

found = {}
for php in sorted(ROOT.rglob('*.php')):
    if 'includes' not in php.parts and php.name != 'hydra-ai.php':
        continue
    text = php.read_text(encoding='utf-8')
    for m in PATTERN.finditer(text):
        msgid = m.group(1).replace("\\'", "'").replace('\\', '\\')
        line = text[:m.start()].count('\n') + 1
        ref = f"{php.relative_to(ROOT).as_posix()}:{line}"
        found.setdefault(msgid, []).append(ref)

for msgid, refs in sorted(found.items()):
    print(repr(msgid))
    for r in refs:
        print('   ', r)
print(f"\n共 {len(found)} 条字符串")
