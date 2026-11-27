"""Builds the Mahaveer logo SVGs (concept C "scoop heart") with the wordmark as outlined paths.

Usage: python -I build_logo.py <fonts-dir> <out-dir>
Latin text only (no shaping needed); Marathi lines stay live text on the website.
"""
import os
import sys

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

FONTS, OUT = sys.argv[1], sys.argv[2]
os.makedirs(OUT, exist_ok=True)

INK = '#1F1A17'
CREAM = '#F4EEE6'
PAPER = '#FBF8F3'
RASP = '#B4325A'
PINK = '#F2B8C6'
MANGO = '#E9B45A'
MANGO_DARK = '#C98E35'


# ---------- text → path (with GPOS pair kerning) ----------

def pair_kerning(font):
    """{(left glyph, right glyph): x advance adjustment} from the GPOS 'kern' feature."""
    kern = {}
    if 'GPOS' not in font:
        return kern
    gpos = font['GPOS'].table
    lookup_ids = set()
    for rec in gpos.FeatureList.FeatureRecord:
        if rec.FeatureTag == 'kern':
            lookup_ids.update(rec.Feature.LookupListIndex)
    for i in sorted(lookup_ids):
        lookup = gpos.LookupList.Lookup[i]
        for sub in lookup.SubTable:
            if lookup.LookupType == 9:
                sub = sub.ExtSubTable
            if getattr(sub, 'LookupType', 2) != 2 and lookup.LookupType not in (2, 9):
                continue
            if not hasattr(sub, 'Format'):
                continue
            coverage = sub.Coverage.glyphs
            if sub.Format == 1:
                for idx, first in enumerate(coverage):
                    for rec in sub.PairSet[idx].PairValueRecord:
                        v = rec.Value1
                        if v is not None and getattr(v, 'XAdvance', 0):
                            kern.setdefault((first, rec.SecondGlyph), v.XAdvance)
            elif sub.Format == 2:
                c1 = sub.ClassDef1.classDefs
                c2 = sub.ClassDef2.classDefs
                for first in coverage:
                    k1 = c1.get(first, 0)
                    row = sub.Class1Record[k1].Class2Record
                    for second, k2 in list(c2.items()):
                        v = row[k2].Value1
                        if v is not None and getattr(v, 'XAdvance', 0):
                            kern.setdefault((first, second), v.XAdvance)
    return kern


class Face:
    def __init__(self, path):
        self.font = TTFont(path)
        self.upm = self.font['head'].unitsPerEm
        self.cmap = self.font.getBestCmap()
        self.glyphs = self.font.getGlyphSet()
        self.hmtx = self.font['hmtx']
        self.kern = pair_kerning(self.font)

    def text(self, s, size, x, baseline, tracking=0.0):
        """SVG path data for s at font size `size` (px), left edge x; returns (d, width)."""
        scale = size / self.upm
        pen = SVGPathPen(self.glyphs, ntos=lambda v: ("%.1f" % v).rstrip("0").rstrip("."))
        cursor = 0.0
        prev = None
        for ch in s:
            name = self.cmap.get(ord(ch))
            if name is None:
                raise ValueError('missing glyph for %r' % ch)
            if prev is not None:
                cursor += self.kern.get((prev, name), 0)
            if ch != ' ':
                tp = TransformPen(pen, (scale, 0, 0, -scale, x + cursor * scale, baseline))
                self.glyphs[name].draw(tp)
            cursor += self.hmtx[name][0] + tracking * self.upm
            prev = name
        width = (cursor - tracking * self.upm) * scale
        return pen.getCommands(), width


# ---------- the mark: two scoops on one cone (a heart) ----------

def mark(x, y, size, scoop=RASP, cone=MANGO, detail=True):
    """Mark drawn in a 48×48 box scaled to `size`, top-left at (x, y)."""
    s = size / 48.0
    parts = [
        '<g transform="translate(%.2f %.2f) scale(%.4f)">' % (x, y, s),
        '<path d="M6 21A9 9 0 0 1 24 21A9 9 0 0 1 42 21Z" fill="%s"/>' % scoop,
        '<path d="M7 24.5H41L24 46Z" fill="%s"/>' % cone,
    ]
    if detail:
        parts += [
            '<path d="M14.2 28.5 33.8 28.5M18 33.5 30 33.5" stroke="%s" stroke-width="1.4" stroke-linecap="round" opacity="0.55"/>' % MANGO_DARK,
            '<ellipse cx="12.5" cy="15.5" rx="3" ry="2" fill="#FFFFFF" opacity="0.35" transform="rotate(-30 12.5 15.5)"/>',
        ]
    parts.append('</g>')
    return ''.join(parts)


def svg(width, height, body, title='Mahaveer Ice Cream'):
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="%s">'
            '<title>%s</title>%s</svg>\n') % (width, height, width, height, title, title, body)


def write(name, content):
    with open(os.path.join(OUT, name), 'w', encoding='utf-8', newline='\n') as f:
        f.write(content)
    print('wrote', name)


face = Face(os.path.join(FONTS, 'cormorant-600.ttf'))


def lockup(text_color, sub_color, scoop):
    # mark 96px, wordmark 64px, sub line 15px tracked; laid out left to right
    pad = 8
    mark_size = 96
    gap = 22
    word_d, word_w = face.text('Mahaveer', 66, 0, 0)
    sub_d, sub_w = face.text('ICE CREAM · AMALNER', 15.5, 0, 0, tracking=0.32)
    text_x = pad + mark_size + gap
    width = int(text_x + max(word_w, sub_w) + pad + 2)
    height = mark_size + pad * 2
    word_d, _ = face.text('Mahaveer', 66, text_x, pad + 56)
    sub_d, _ = face.text('ICE CREAM · AMALNER', 15.5, text_x + 3, pad + 86, tracking=0.32)
    body = (mark(pad, pad, mark_size, scoop=scoop)
            + '<path d="%s" fill="%s"/>' % (word_d, text_color)
            + '<path d="%s" fill="%s"/>' % (sub_d, sub_color))
    return svg(width, height, body)


write('logo.svg', lockup(INK, '#6E625B', RASP))
write('logo-reverse.svg', lockup(CREAM, '#C9BDB3', PINK))
write('logo-mark.svg', svg(48, 48, mark(0, 0, 48)))

# app / favicon tile: white scoops, light mango cone on raspberry (full bleed; launchers round it)
def tile(size, mark_ratio, rounded):
    m = size * mark_ratio
    off = (size - m) / 2
    rx = size * 0.22 if rounded else 0
    body = ('<rect width="%d" height="%d" rx="%.1f" fill="%s"/>' % (size, size, rx, RASP)
            + mark(off, off + m * 0.02, m, scoop='#FFFFFF', cone='#F7D58C', detail=size >= 96))
    return svg(size, size, body)

write('icon.svg', tile(512, 0.62, False))
write('icon-maskable.svg', tile(512, 0.5, False))   # inside the 80% safe zone
write('favicon.svg', tile(64, 0.78, True))
# push badge: white silhouette on transparent (Android uses only the alpha)
write('badge.svg', svg(72, 72, mark(6, 6, 60, scoop='#FFFFFF', cone='#FFFFFF', detail=False)))
