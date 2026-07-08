#!/usr/bin/env python3
"""Minimal, exact LESS compiler for the chameleon-blue skin.

The skin's styles.less is FLAT CSS (no nesting/mixins/guards/arithmetic).
It only needs:
  - variable substitution (vars from colors.less + @box-padding)
  - four color functions matching less.js semantics: lighten, darken, fade, screen
This implements less.js's exact HSL math and rounding so colors match `lessc`.
"""
import re, sys

# ---- color model -----------------------------------------------------------
class Color:
    __slots__ = ('r', 'g', 'b', 'a')
    def __init__(self, r, g, b, a=1.0):
        self.r, self.g, self.b, self.a = r, g, b, a

def parse_hex(h):
    h = h.lstrip('#')
    if len(h) == 3:
        h = ''.join(c*2 for c in h)
    return Color(int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16), 1.0)

def clamp01(x):
    return max(0.0, min(1.0, x))

# less.js: rgb (0-255) -> hsl (h in 0..360, s/l in 0..1)
def to_hsl(c):
    r, g, b = c.r/255.0, c.g/255.0, c.b/255.0
    mx, mn = max(r, g, b), min(r, g, b)
    l = (mx + mn) / 2.0
    d = mx - mn
    if d == 0:
        h = s = 0.0
    else:
        s = d/(2.0-mx-mn) if l > 0.5 else d/(mx+mn)
        if mx == r:
            h = (g-b)/d + (6 if g < b else 0)
        elif mx == g:
            h = (b-r)/d + 2
        else:
            h = (r-g)/d + 4
        h /= 6.0
    return h*360.0, s, l, c.a

# less.js hsla -> rgb
def from_hsl(h, s, l, a):
    h = (h % 360) / 360.0
    def hue(hh):
        if hh < 0: hh += 1
        if hh > 1: hh -= 1
        if hh*6 < 1: return m1 + (m2-m1)*hh*6
        if hh*2 < 1: return m2
        if hh*3 < 2: return m1 + (m2-m1)*(2/3.0-hh)*6
        return m1
    m2 = l*(s+1) if l <= 0.5 else l+s-l*s
    m1 = l*2 - m2
    r = round(hue(h + 1/3.0) * 255)
    g = round(hue(h) * 255)
    b = round(hue(h - 1/3.0) * 255)
    return Color(int(r), int(g), int(b), a)

def f_lighten(c, amt):
    h, s, l, a = to_hsl(c)
    return from_hsl(h, s, clamp01(l + amt/100.0), a)

def f_darken(c, amt):
    h, s, l, a = to_hsl(c)
    return from_hsl(h, s, clamp01(l - amt/100.0), a)

def f_fade(c, amt):
    return Color(c.r, c.g, c.b, clamp01(amt/100.0))

def f_screen(c1, c2):
    # less.js multiply/screen blend, per channel; alpha from first color
    def ch(a, b):
        return round(255 - (255-a)*(255-b)/255.0)
    return Color(int(ch(c1.r, c2.r)), int(ch(c1.g, c2.g)), int(ch(c1.b, c2.b)), c1.a)

def fmt_num(x):
    # trim trailing zeros like less.js (0.40 -> 0.4, 1.0 -> 1)
    s = ('%.3f' % x).rstrip('0').rstrip('.')
    return s if s else '0'

def render(val):
    if isinstance(val, Color):
        if val.a >= 1.0:
            return '#%02x%02x%02x' % (val.r, val.g, val.b)
        return 'rgba(%d, %d, %d, %s)' % (val.r, val.g, val.b, fmt_num(val.a))
    return str(val)

# ---- variable resolution ----------------------------------------------------
raw_vars = {}   # name -> raw expression string
_cache = {}

VAR_DEF = re.compile(r'^\s*@([A-Za-z][\w-]*)\s*:\s*(.+?)\s*;\s*$')

def load_vars(text):
    for line in text.splitlines():
        m = VAR_DEF.match(line)
        if m:
            raw_vars[m.group(1)] = m.group(2)

FUNC = re.compile(r'^(lighten|darken|fade|screen)\((.+)\)$')

def eval_expr(expr):
    expr = expr.strip()
    m = FUNC.match(expr)
    if m:
        fn, args = m.group(1), m.group(2)
        parts = split_args(args)
        if fn == 'screen':
            return f_screen(as_color(parts[0]), as_color(parts[1]))
        c = as_color(parts[0])
        amt = float(parts[1].strip().rstrip('%'))
        return {'lighten': f_lighten, 'darken': f_darken, 'fade': f_fade}[fn](c, amt)
    if expr.startswith('@'):
        return resolve(expr[1:])
    if re.match(r'^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$', expr):
        return parse_hex(expr)
    return expr  # plain literal (e.g. "12px")

def split_args(s):
    # top-level comma split (no nested funcs here, but be safe)
    out, depth, cur = [], 0, ''
    for ch in s:
        if ch == '(':
            depth += 1; cur += ch
        elif ch == ')':
            depth -= 1; cur += ch
        elif ch == ',' and depth == 0:
            out.append(cur); cur = ''
        else:
            cur += ch
    if cur.strip():
        out.append(cur)
    return out

def as_color(x):
    v = eval_expr(x.strip())
    if isinstance(v, Color):
        return v
    raise ValueError('expected color from %r -> %r' % (x, v))

def resolve(name):
    if name in _cache:
        return _cache[name]
    if name not in raw_vars:
        raise KeyError('undefined variable @' + name)
    _cache[name] = eval_expr(raw_vars[name])
    return _cache[name]

# ---- main -------------------------------------------------------------------
def main(colors_path, styles_path, out_path):
    colors = open(colors_path, encoding='utf-8').read()
    styles = open(styles_path, encoding='utf-8').read()
    load_vars(colors)
    load_vars(styles)

    out_lines = []
    for line in styles.splitlines():
        # drop the @import and top-level variable-definition lines
        if '@import' in line:
            continue
        if VAR_DEF.match(line):
            continue
        out_lines.append(line)
    body = '\n'.join(out_lines)

    # 1) replace function calls first (they contain @vars as args)
    func_re = re.compile(r'(lighten|darken|fade|screen)\(([^()]*)\)')
    def sub_func(m):
        return render(eval_expr(m.group(0)))
    # loop in case (there are no nested calls, one pass is enough, but be safe)
    prev = None
    while prev != body:
        prev = body
        body = func_re.sub(sub_func, body)

    # 2) replace remaining bare @var references (longest name first to avoid
    #    prefix collisions), only for KNOWN variables — leaves @media/@supports/etc.
    for name in sorted(raw_vars, key=len, reverse=True):
        body = re.sub(r'@' + re.escape(name) + r'\b', render(resolve(name)), body)

    open(out_path, 'w', encoding='utf-8').write(body)

    # report any leftover known-var tokens (should be none)
    leftovers = set(re.findall(r'@[A-Za-z][\w-]*', body))
    known_leftover = leftovers & set(raw_vars)
    print('compiled -> %s (%d bytes)' % (out_path, len(body)))
    print('at-rule/other @tokens left (expected, e.g. @media/@supports):',
          sorted(leftovers))
    if known_leftover:
        print('!! UNRESOLVED KNOWN VARS:', known_leftover); sys.exit(2)

if __name__ == '__main__':
    main(*sys.argv[1:4])
