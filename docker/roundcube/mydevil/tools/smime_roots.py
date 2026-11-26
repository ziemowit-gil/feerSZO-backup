"""Z certdata.txt (Mozilla NSS) wybiera główne CA zaufane do poczty (S/MIME) i zapisuje PEM.
Pomija: brak TRUSTED_DELEGATOR dla EMAIL_PROTECTION, ustawione CKA_NSS_EMAIL_DISTRUST_AFTER, wygasłe."""
import sys, base64, subprocess, datetime

src, dst = sys.argv[1], sys.argv[2]
objs, cur, key, buf = [], None, None, None
for line in open(src, encoding='utf-8'):
    line = line.rstrip('\n')
    if buf is not None:
        if line == 'END':
            cur[key] = bytes(buf); buf = None
        else:
            for o in line.split('\\')[1:]:
                buf.append(int(o, 8))
        continue
    if line.startswith('#') or not line.strip():
        continue
    if line.startswith('CKA_CLASS'):
        cur = {'class': line.split()[2]}; objs.append(cur); continue
    if cur is None or not line.startswith('CKA_'):
        continue
    parts = line.split(None, 2)
    k, typ = parts[0], parts[1] if len(parts) > 1 else ''
    if typ == 'MULTILINE_OCTAL':
        key, buf = k, []
    else:
        cur[k] = parts[2] if len(parts) > 2 else typ

certs = {(o.get('CKA_ISSUER'), o.get('CKA_SERIAL_NUMBER')): o for o in objs if o['class'] == 'CKO_CERTIFICATE'}
out, skipped = [], []
for t in objs:
    if t['class'] != 'CKO_NSS_TRUST' or t.get('CKA_TRUST_EMAIL_PROTECTION') != 'CKT_NSS_TRUSTED_DELEGATOR':
        continue
    c = certs.get((t.get('CKA_ISSUER'), t.get('CKA_SERIAL_NUMBER')))
    if not c or 'CKA_VALUE' not in c:
        continue
    label = c.get('CKA_LABEL', '').strip('"')
    if isinstance(c.get('CKA_NSS_EMAIL_DISTRUST_AFTER'), bytes):
        skipped.append(label + ' (email distrust-after)'); continue
    pem = '-----BEGIN CERTIFICATE-----\n' + '\n'.join(
        base64.b64encode(c['CKA_VALUE']).decode()[i:i+64] for i in range(0, len(base64.b64encode(c['CKA_VALUE'])), 64)
    ) + '\n-----END CERTIFICATE-----\n'
    r = subprocess.run(['openssl', 'x509', '-noout', '-checkend', '0'], input=pem.encode(), capture_output=True)
    if r.returncode != 0:
        skipped.append(label + ' (wygasły)'); continue
    out.append('# ' + label + '\n' + pem)

with open(dst, 'w') as f:
    f.write('# Główne CA zaufane do poczty (S/MIME) wg Mozilla NSS certdata.txt, '
            + datetime.date.today().isoformat() + ' — dla mimeshield_ca_bundle\n')
    f.write('\n'.join(out))
print(len(out), 'CA; pominięte:', len(skipped))
for s in skipped: print('  -', s)
