#!/usr/bin/env python3
"""Validate the customer guide sources and optionally synchronize readable Markdown."""
import html
import json
from pathlib import Path
import re
import sys
root = Path(__file__).resolve().parents[2]
guides = root / 'docs/guides'
sources = sorted(guides.glob('*.json'))
known = {p.stem + '.html' for p in sources}
def markdown(text):
    def link(match):
        target, label = match.groups()
        if not target.startswith('https://') and target not in known:
            raise ValueError('Missing local guide target: ' + target)
        if target in known:
            target = target[:-5] + '.md'
        return '[' + label + '](' + target + ')'
    text = re.sub(r'<a href="([^"]+)">(.*?)</a>', link, text)
    return html.unescape(text.replace('<b>', '**').replace('</b>', '**').replace('<code>', '`').replace('</code>', '`'))
for source in sources:
    data = json.loads(source.read_text())
    rendered = ['# ' + data['title'], data['subtitle']]
    for block in data['blocks']:
        kind = block['type']
        if kind in ('p', 'callout', 'h2', 'h3'):
            text = block['text']
            rendered.append(('#' * int(kind[1]) + ' ' if kind.startswith('h') else '') + markdown(text))
        elif kind == 'image':
            image = guides / 'images' / block['file']
            if image.parent != guides / 'images' or not image.read_bytes().startswith(b'\x89PNG\r\n\x1a\n'):
                raise ValueError('A real PNG screenshot is required: ' + str(image))
            rendered.append('![' + block['caption'] + '](images/' + block['file'] + ')')
        elif kind == 'code':
            if any(len(line) > 96 for line in block['text'].splitlines()):
                raise ValueError('Command too wide: ' + source.name)
            rendered.append('```' + ('csv' if block['text'].startswith('email,') else 'bash') + '\n' + block['text'] + '\n```')
        elif kind in ('ul', 'ol'):
            rendered.append('\n'.join((str(i) + '. ' if kind == 'ol' else '- ') + markdown(text) for i, text in enumerate(block['items'], 1)))
        elif kind == 'table':
            rendered.append('| ' + ' | '.join(markdown(x) for x in block['head']) + ' |\n| ' + ' | '.join('---' for x in block['head']) + ' |\n' + '\n'.join('| ' + ' | '.join(markdown(x) for x in row) + ' |' for row in block['rows']))
        else:
            raise ValueError('Unsupported guide block: ' + kind)
    text = '\n\n'.join(rendered) + '\n'
    target = source.with_suffix('.md')
    if '--write-markdown' in sys.argv:
        target.write_text(text)
    elif target.read_text() != text:
        raise ValueError('Guide Markdown differs from its reviewed source: ' + target.name)
print(str(len(sources)) + ' guides: screenshots, local links, command width and Markdown verified.')
