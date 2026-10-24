"""Loopback-only TLS adapter for the disposable internal browser network."""
import http.client
import http.server
import ipaddress
import json
from pathlib import Path
import ssl
import sys

config = json.loads(Path(sys.argv[1]).read_text())
address = ipaddress.ip_address(config['upstream'])
if not address.is_private or address.is_loopback:
    raise SystemExit('The browser upstream must be its disposable container address.')


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *args):
        pass

    def do_HEAD(self):
        self.forward()

    def do_GET(self):
        self.forward()

    def do_POST(self):
        self.forward()

    def forward(self):
        if not self.path.startswith('/') or self.path.startswith('//'):
            self.send_error(400)
            return
        try:
            size = int(self.headers.get('Content-Length', '0'))
            if size < 0 or size > 12 * 1024 * 1024:
                self.send_error(413)
                return
            body = self.rfile.read(size) if size else None
            excluded = {'host', 'connection', 'transfer-encoding', 'x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-host'}
            headers = {key: value for key, value in self.headers.items() if key.lower() not in excluded}
            headers.update({'Host': 'emailer.example.test', 'X-Forwarded-Proto': 'https', 'X-Forwarded-Port': '443', 'Connection': 'close'})
            connection = http.client.HTTPConnection(str(address), 8080, timeout=60)
            connection.request(self.command, self.path, body, headers)
            response = connection.getresponse()
            data = response.read()
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ('connection', 'transfer-encoding', 'content-length', 'date', 'server'):
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(data)))
            self.send_header('Connection', 'close')
            self.end_headers()
            if self.command != 'HEAD':
                self.wfile.write(data)
            connection.close()
        except Exception:
            self.close_connection = True


class Server(http.server.ThreadingHTTPServer):
    def handle_error(self, *args):
        pass


server = Server(('127.0.0.1', 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(config['certificate'], config['key'])
server.socket = context.wrap_socket(server.socket, server_side=True)
print(server.server_address[1], flush=True)
server.serve_forever()
