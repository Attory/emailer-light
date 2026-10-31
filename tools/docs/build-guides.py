#!/usr/bin/env python3
"""Build matching offline HTML/PDF guides from reviewed JSON and real screenshots.
Maintainer-only requirements: Python 3.12+, reportlab, Pillow, DejaVu fonts.
No application credentials, application database, network or signing key needed.
"""
import base64
import html
import json
import hashlib
import subprocess
from functools import partial
from reportlab.pdfgen.canvas import Canvas
from pathlib import Path
import re
import sys
from pypdf import PdfReader, PdfWriter
from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import SimpleDocTemplate, Paragraph, Preformatted, Spacer, Image, Table, TableStyle, KeepTogether

root = Path(__file__).resolve().parents[2]
if len(sys.argv) != 2:
    sys.exit('Pass an explicit generated documentation folder outside the repository.')
output = Path(sys.argv[1]).resolve()
if output == root or output.is_relative_to(root):
    sys.exit('Keep rendered PDF/HTML output outside the source and runtime scan boundary.')
output.mkdir(parents=True, exist_ok=True)
fonts = Path('/usr/share/fonts/truetype/dejavu')
for name, filename in [('Guide', 'DejaVuSans.ttf'), ('Guide-Bold', 'DejaVuSans-Bold.ttf'), ('GuideCode', 'DejaVuSansMono.ttf')]:
    pdfmetrics.registerFont(TTFont(name, str(fonts / filename)))
pdfmetrics.registerFontFamily('Guide', normal='Guide', bold='Guide-Bold', italic='Guide', boldItalic='Guide-Bold')
styles = getSampleStyleSheet()
for name in ('Normal', 'BodyText', 'Heading1', 'Heading2', 'Heading3'):
    styles[name].keepWithNext = name.startswith('Heading')
    styles[name].fontName = 'Guide-Bold' if name.startswith('Heading') else 'Guide'
    styles[name].textColor = colors.HexColor('#233b37')
styles['BodyText'].fontSize = 10
styles['BodyText'].leading = 15
styles['BodyText'].spaceAfter = 9
styles['BodyText'].allowWidows = 0
styles['BodyText'].allowOrphans = 0
styles['Heading1'].fontSize = 27
styles['Heading1'].leading = 33
styles['Heading1'].spaceAfter = 12
styles['Heading2'].fontSize = 15
styles['Heading2'].leading = 20
styles['Heading2'].spaceBefore = 16
styles['Heading2'].spaceAfter = 9
styles.add(ParagraphStyle(name='CaptionGuide', fontName='Guide', fontSize=8, leading=12, textColor=colors.HexColor('#526762'), spaceAfter=12))
styles.add(ParagraphStyle(name='CodeGuide', fontName='GuideCode', fontSize=8, leading=12, backColor=colors.HexColor('#eef3f0'), borderPadding=10, spaceBefore=8, spaceAfter=14))
styles.add(ParagraphStyle(name='TableGuide', parent=styles['BodyText'], fontSize=8, leading=12, spaceAfter=0))

release_base = 'https://github.com/' + json.loads((root / 'composer.json').read_text())['name'] + '/releases/download/v1.2.13/'
local_guides = {path.stem + '.html' for path in (root / 'docs/guides').glob('*.json')}

def pdftext(text):
    # Individual PDFs work even when their sibling HTML files were not downloaded.
    text = re.sub(r'href="([^"]+)"', lambda match: 'href="' + release_base + match.group(1) + '"' if match.group(1) in local_guides else match.group(0), text)
    text = re.sub(r'<a href="([^"]+)">(.*?)</a>', r'<link href="\1" color="#216758">\2</link>', text)
    return text.replace('<code>', '<font name="GuideCode">').replace('</code>', '</font>').replace('<br>', '<br/>')

def footer(canvas, doc):
    canvas.saveState()
    canvas.setStrokeColor(colors.HexColor('#cad8d0'))
    canvas.line(44, 42, 551, 42)
    canvas.setFont('Guide', 8)
    canvas.setFillColor(colors.HexColor('#526762'))
    canvas.drawString(44, 28, doc.title + ' | 1.2.13')
    canvas.drawRightString(551, 28, str(doc.page))
    canvas.restoreState()

for source in sorted((root / 'docs/guides').glob('*.json')):
    data = json.loads(source.read_text())
    title = data['title']
    document = SimpleDocTemplate(str(output / (source.stem + '.pdf')), pagesize=(595.28, 841.89), rightMargin=44, leftMargin=44, topMargin=42, bottomMargin=58, title=title, author='Emailer', pageCompression=1)
    flow = [Paragraph(html.escape(title), styles['Heading1']), Paragraph(html.escape(data['subtitle']), styles['CaptionGuide'])]
    markup = [f'<h1>{html.escape(title)}</h1><p class="subtitle">{html.escape(data["subtitle"])}</p>']
    for block in data['blocks']:
        kind = block['type']
        if kind in ('p', 'h2', 'h3', 'callout'):
            text = block['text']
            style = styles['Heading2' if kind == 'h2' else 'Heading3' if kind == 'h3' else 'BodyText']
            flow.append(Paragraph(pdftext(text), style))
            tag = kind if kind in ('h2', 'h3') else 'p'
            markup.append(f'<{tag}' + (' class="callout"' if kind == 'callout' else '') + f'>{text}</{tag}>')
        elif kind in ('ul', 'ol'):
            markup.append(f'<{kind}>')
            for index, item in enumerate(block['items'], 1):
                prefix = str(index) + '. ' if kind == 'ol' else '- '
                flow.append(Paragraph(prefix + pdftext(item), styles['BodyText']))
                markup.append('<li>' + item + '</li>')
            markup.append(f'</{kind}>')
        elif kind == 'code':
            # Short lines retain copyable literal commands in both formats.
            for line in block['text'].splitlines():
                if len(line) > 96:
                    raise ValueError('Split long command at a shell continuation: ' + source.name)
            flow.append(KeepTogether([Preformatted(html.escape(block['text']), styles['CodeGuide']), Spacer(1, 5)]))
            markup.append('<pre><code>' + html.escape(block['text']) + '</code></pre>')
        elif kind == 'table':
            rows = [[Paragraph(pdftext(str(cell)), styles['TableGuide']) for cell in row] for row in [block['head'], *block['rows']]]
            widths = block.get('widths', [1] * len(rows[0]))
            table = Table(rows, colWidths=[507.28 * w / sum(widths) for w in widths], repeatRows=1, hAlign='LEFT')
            table.setStyle(TableStyle([('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#e0eae3')), ('VALIGN', (0, 0), (-1, -1), 'TOP'), ('LINEBELOW', (0, 0), (-1, -1), 0.4, colors.HexColor('#cad8d0')), ('LEFTPADDING', (0, 0), (-1, -1), 8), ('RIGHTPADDING', (0, 0), (-1, -1), 8), ('TOPPADDING', (0, 0), (-1, -1), 8), ('BOTTOMPADDING', (0, 0), (-1, -1), 8)]))
            flow.extend([table, Spacer(1, 10)])
            markup.append('<table><thead><tr>' + ''.join('<th>' + c + '</th>' for c in block['head']) + '</tr></thead><tbody>')
            markup.extend('<tr>' + ''.join('<td>' + str(c) + '</td>' for c in row) + '</tr>' for row in block['rows'])
            markup.append('</tbody></table>')
        elif kind == 'image':
            path = source.parent / 'images' / block['file']
            width, height = ImageReader(str(path)).getSize()
            scale = min(507.28 / width, 310 / height)
            flow.append(KeepTogether([Image(str(path), width=width * scale, height=height * scale, hAlign='LEFT'), Paragraph(html.escape(block['caption']), styles['CaptionGuide'])]))
            encoded = base64.b64encode(path.read_bytes()).decode()
            markup.append('<figure><img alt="' + html.escape(block['caption'], quote=True) + '" src="data:image/png;base64,' + encoded + '"><figcaption>' + html.escape(block['caption']) + '</figcaption></figure>')
        else:
            raise ValueError('Unknown document block: ' + kind)
    document.build(flow, onFirstPage=footer, onLaterPages=footer, canvasmaker=partial(Canvas, invariant=1))
    pdf_path = output / (source.stem + '.pdf')
    writer = PdfWriter(clone_from=str(pdf_path))
    writer.add_attachment('FONT-LICENSE.txt', (root / 'docs/guides/FONT-LICENSE.txt').read_bytes())
    with pdf_path.open('wb') as stream:
        writer.write(stream)
    css = '''body{font:17px/1.65 system-ui,sans-serif;color:#233b37;background:#f5f7f2;margin:0}main{max-width:920px;margin:0 auto;background:white;padding:48px}h1{font-size:40px;line-height:1.15}h2{font-size:25px;margin-top:40px}a{color:#216758}pre{padding:20px;background:#eef3f0;overflow-x:auto;white-space:pre-wrap;font-size:13px;line-height:1.7}code{font-family:ui-monospace,monospace}table{border-collapse:collapse;width:100%;font-size:15px}td,th{padding:12px;text-align:left;vertical-align:top;border-bottom:1px solid #cad8d0}th{background:#e0eae3}img{max-width:100%;height:auto;border:1px solid #cad8d0}figure{margin:24px 0}figcaption,.subtitle{font-size:14px;color:#526762}.callout{border-left:4px solid #216758;padding:12px 20px;background:#eef3f0}li{margin-bottom:12px}@media(max-width:600px){main{padding:24px}h1{font-size:30px}}@media print{main{padding:0}body{background:white}}'''
    page = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' + html.escape(title) + '</title><style>' + css + '</style><main>' + '\n'.join(markup) + '</main></html>'
    (output / (source.stem + '.html')).write_text(page)
    print(source.stem + ': PDF and self-contained HTML built')

# Bind every rendered file to the exact reviewed document sources and commit.
(output / 'FONT-LICENSE.txt').write_bytes((root / 'docs/guides/FONT-LICENSE.txt').read_bytes())
inputs = [root / 'composer.json', root / 'tools/docs/build-guides.py', root / 'tools/docs/check-guides.py', root / 'tools/docs/requirements.txt', root / 'tools/docs/verify-built-guides.php', root / 'docs/guides/FONT-LICENSE.txt', *(root / 'docs/guides').glob('*.json'), *(root / 'docs/guides').glob('*.md'), *(root / 'docs/guides/images').glob('*.png')]
manifest = {'format': 'emailer-docs-v1', 'source_commit': subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True).strip(), 'inputs': {str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest() for path in sorted(inputs)}, 'outputs': {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in sorted(output.iterdir()) if path.suffix in ('.pdf', '.html') or path.name == 'FONT-LICENSE.txt'}}
(output / 'DOCS-MANIFEST.json').write_text(json.dumps(manifest, indent=2) + '\n')
