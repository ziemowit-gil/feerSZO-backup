#!/usr/bin/env python3
"""Atrapa bramek do hermetycznych testów wysyłek: HTTP (SMS, GET /sms?phone=&text=) i SMTP.
Użycie: fakegw.py <plik_zdarzeń.jsonl> <port_http> <port_smtp>. Każde zdarzenie = linia JSON: {"sms": {...}} albo {"mail": {"to": [...], "raw": "..."}}."""
import http.server, socketserver, threading, json, sys, urllib.parse

LOG, HTTP_PORT, SMTP_PORT = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
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

class TS(socketserver.ThreadingTCPServer): allow_reuse_address = True
threading.Thread(target=lambda: http.server.ThreadingHTTPServer(('127.0.0.1', HTTP_PORT), H).serve_forever(), daemon=True).start()
TS(('127.0.0.1', SMTP_PORT), S).serve_forever()
