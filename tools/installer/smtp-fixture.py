"""Synthetic TLS/AUTH SMTP sink, for isolated acceptance networks only."""
import base64
import hmac
import json
import os
from pathlib import Path
import socketserver
import ssl
import threading

lock = threading.Lock()
state = {'authenticated': 0, 'messages': 0, 'recipients': []}
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain('/fixture/server.crt', '/fixture/server.key')


class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.connection.settimeout(10)
        secure = False
        authenticated = False
        recipient = None
        self.wfile.write(b'220 smtp-fixture.test acceptance only\r\n')
        while True:
            line = self.rfile.readline(16384)
            if not line:
                return
            command, _, value = line.rstrip(b'\r\n').partition(b' ')
            command = command.upper()
            if command in (b'EHLO', b'HELO'):
                self.wfile.write(b'250-smtp-fixture.test\r\n' + (b'250 AUTH PLAIN\r\n' if secure else b'250 STARTTLS\r\n'))
            elif command == b'STARTTLS' and not secure:
                self.wfile.write(b'220 Begin TLS\r\n')
                self.wfile.flush()
                self.connection = context.wrap_socket(self.connection, server_side=True)
                self.rfile = self.connection.makefile('rb')
                self.wfile = self.connection.makefile('wb', buffering=0)
                secure = True
            elif command == b'AUTH' and secure:
                mechanism, _, encoded = value.partition(b' ')
                if not encoded:
                    self.wfile.write(b'334 \r\n')
                    encoded = self.rfile.readline(16384).strip()
                try:
                    decoded = base64.b64decode(encoded, validate=True)
                    accepted = mechanism.upper() == b'PLAIN' and any(hmac.compare_digest(decoded, expected) for expected in (b'\x00synthetic\x00synthetic-acceptance-only', b'synthetic\x00synthetic\x00synthetic-acceptance-only'))
                except ValueError:
                    accepted = False
                authenticated = accepted
                if accepted:
                    with lock:
                        state['authenticated'] += 1
                self.wfile.write(b'235 Authenticated\r\n' if accepted else b'535 Credentials rejected\r\n')
            elif command == b'MAIL' and authenticated:
                self.wfile.write(b'250 Sender accepted\r\n')
            elif command == b'RCPT' and authenticated:
                # Never relay or contact another server. Permit one synthetic address only.
                recipient = value.lower() == b'to:<requested-test@example.test>'
                self.wfile.write(b'250 Recipient accepted\r\n' if recipient else b'550 Synthetic destination required\r\n')
            elif command == b'DATA' and authenticated and recipient:
                self.wfile.write(b'354 End with dot\r\n')
                total = 0
                while True:
                    data = self.rfile.readline(16384)
                    if not data or data == b'.\r\n':
                        break
                    total += len(data)
                    if total > 65536:
                        return
                with lock:
                    state['messages'] += 1
                    state['recipients'].append('requested-test@example.test')
                    temporary = Path('/evidence/state.tmp')
                    temporary.write_text(json.dumps(state))
                    os.replace(temporary, '/evidence/state.json')
                self.wfile.write(b'250 Synthetic message retained as count only\r\n')
            elif command == b'QUIT':
                self.wfile.write(b'221 Bye\r\n')
                return
            elif command == b'RSET':
                recipient = None
                self.wfile.write(b'250 Reset\r\n')
            else:
                self.wfile.write(b'530 Authentication and TLS required\r\n')


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


with Server(('0.0.0.0', 2525), SMTP) as server:
    Path('/evidence/ready').write_text('ready')
    server.serve_forever()
