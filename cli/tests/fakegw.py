#!/usr/bin/env python3
"""Atrapa bramek do hermetycznych testów wysyłek: HTTP (SMS, GET /sms?phone=&text=) i SMTP.
Opcjonalnie 4. argument = port mini-Redisa (RESP: PING/AUTH/SELECT/GET/SET[EX]/DEL/INCR/SCAN/INFO) do testów cache.
Użycie: fakegw.py <plik_zdarzeń.jsonl> <port_http> <port_smtp> [port_redis]. Każde zdarzenie = linia JSON: {"sms": {...}} albo {"mail": {"to": [...], "raw": "..."}}."""
import http.server, socketserver, threading, json, sys, urllib.parse

LOG, HTTP_PORT, SMTP_PORT = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
REDIS_PORT = int(sys.argv[4]) if len(sys.argv) > 4 else 0
KV = {}
LOCK = threading.Lock()

def log(o):
    with LOCK:
        open(LOG, 'a').write(json.dumps(o) + '\n')

class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        log({'sms': urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query)})
        self.send_response(200); self.end_headers(); self.wfile.write(b'OK')
    def log_message(self, *a): pass

class S(socketserver.StreamRequestHandler):
    def handle(self):
        def w(s): self.wfile.write((s + '\r\n').encode()); self.wfile.flush()
        w('220 fake'); data = False; buf = []; to = []
        while True:
            l = self.rfile.readline()
            if not l: break
            t = l.decode(errors='replace').rstrip('\r\n')
            if data:
                if t == '.':
                    data = False; log({'mail': {'to': to, 'raw': '\n'.join(buf)}}); buf = []; w('250 ok')
                else: buf.append(t)
                continue
            u = t.upper()
            if u.startswith(('EHLO', 'HELO')): w('250 fake')
            elif u.startswith('MAIL'): w('250 ok'); to = []
            elif u.startswith('RCPT'): to.append(t); w('250 ok')
            elif u == 'DATA': data = True; w('354 go')
            elif u == 'QUIT': w('221 bye'); break
            else: w('250 ok')

class R(socketserver.StreamRequestHandler):
    def handle(self):
        def rd():
            l = self.rfile.readline()
            if not l: return None
            n = int(l[1:].strip()); a = []
            for _ in range(n):
                ln = int(self.rfile.readline()[1:].strip()); a.append(self.rfile.read(ln + 2)[:-2].decode())
            return a
        def w(b): self.wfile.write(b); self.wfile.flush()
        bulk = lambda v: b'$-1\r\n' if v is None else ('$%d\r\n%s\r\n' % (len(v.encode()), v)).encode()
        while True:
            a = rd()
            if a is None: break
            c = a[0].upper()
            if c in ('PING',): w(b'+PONG\r\n')
            elif c in ('AUTH', 'SELECT'): w(b'+OK\r\n')
            elif c == 'GET': w(bulk(KV.get(a[1])))
            elif c == 'SET': KV[a[1]] = a[2]; log({'redis': {'set': a[1]}}); w(b'+OK\r\n')
            elif c == 'DEL': w((':%d\r\n' % (1 if KV.pop(a[1], None) is not None else 0)).encode())
            elif c == 'INCR': KV[a[1]] = str(int(KV.get(a[1], '0')) + 1); w((':%s\r\n' % KV[a[1]]).encode())
            elif c == 'SCAN': w(b'*2\r\n$1\r\n0\r\n*0\r\n')
            elif c == 'INFO': w(bulk('redis_version:fake\r\nused_memory_human:1K\r\n'))
            else: w(b'+OK\r\n')

class TS(socketserver.ThreadingTCPServer): allow_reuse_address = True
if REDIS_PORT: threading.Thread(target=lambda: TS(('127.0.0.1', REDIS_PORT), R).serve_forever(), daemon=True).start()
threading.Thread(target=lambda: http.server.ThreadingHTTPServer(('127.0.0.1', HTTP_PORT), H).serve_forever(), daemon=True).start()
TS(('127.0.0.1', SMTP_PORT), S).serve_forever()
