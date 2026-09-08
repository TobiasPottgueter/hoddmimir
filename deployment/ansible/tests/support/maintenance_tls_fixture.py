"""Local read-only TLS fixture; never forwards traffic or logs credentials."""
from __future__ import annotations

import http.server
import json
import ssl
import subprocess
import threading
import time
import urllib.parse
from pathlib import Path


class MaintenanceTlsFixture:
    def __init__(self, directory: Path):
        self.directory = directory
        self.host = '172.31.249.1'
        self.port = 0
        self.busy = False
        self.server = None
        self.thread = None
        self.methods = []
        self.task_queries = []
        self.authenticated_reads = 0
        subprocess.run([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', str(directory / 'fixture-key.pem'), '-out', str(directory / 'fixture-cert.pem'),
            '-days', '1', '-subj', '/CN=local-maintenance-fixture',
            '-addext', 'subjectAltName=IP:' + self.host,
        ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, umask=0o077)

    def start(self):
        outer = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                outer.methods.append('GET')
                if self.headers.get('Authorization', '').startswith('PVEAPIToken=maintenance@pve!collector='):
                    outer.authenticated_reads += 1
                path = urllib.parse.urlsplit(self.path)
                if path.path == '/api2/json/version':
                    data = {'version': '9.2.3', 'release': '9.2', 'repoid': '9000000c'}
                elif path.path == '/api2/json/access/permissions':
                    data = {'/': {'Sys.Audit': 1}, '/nodes': {'Sys.Audit': 1},
                            '/nodes/pve9-a': {'Sys.Audit': 1},
                            '/storage': {'Datastore.Audit': 1}, '/vms': {'VM.Audit': 1}}
                elif path.path == '/api2/json/cluster/status':
                    data = [{'id': 'node/pve9-a', 'type': 'node', 'name': 'pve9-a', 'nodeid': 0,
                             'online': True, 'local': True, 'ip': outer.host, 'level': ''}]
                elif path.path == '/api2/json/nodes/pve9-a/tasks':
                    outer.task_queries.append(urllib.parse.parse_qs(path.query))
                    now = int(time.time())
                    data = [] if not outer.busy else [{
                        'upid': f'UPID:pve9-a:0000003E:100000000:{now:08X}:vzdump:401:foreign-user@pve:',
                        'node': 'pve9-a', 'pid': 62, 'pstart': 4294967296, 'starttime': now,
                        'type': 'vzdump', 'id': '401', 'user': 'foreign-user@pve', 'status': 'RUNNING',
                    }]
                else:
                    self.send_error(404)
                    return
                body = json.dumps({'data': data}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def do_POST(self):
                outer.methods.append('POST')
                self.send_error(405)

            def do_DELETE(self):
                outer.methods.append('DELETE')
                self.send_error(405)

        self.server = http.server.ThreadingHTTPServer((self.host, self.port), Handler)
        self.port = self.server.server_port
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(self.directory / 'fixture-cert.pem', self.directory / 'fixture-key.pem')
        self.server.socket = context.wrap_socket(self.server.socket, server_side=True)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def stop(self):
        if self.server is not None:
            self.server.shutdown()
            self.server.server_close()
            self.thread.join()
            self.server = None
            self.thread = None

    def configuration(self):
        return {'host': self.host, 'port': self.port, 'ca': (self.directory / 'fixture-cert.pem').read_text()}
