"""Local contract fixture; no provider credentials or outbound calls required."""
import json
from http.server import BaseHTTPRequestHandler, HTTPServer

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def respond(self, data, status=200):
        body = json.dumps({'status': status, 'data': data}).encode()
        self.send_response(status)
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        payload = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        assert self.path == '/api/v1/videos'
        assert payload['video_subject'] == 'A short video about the ocean'
        assert payload['video_count'] == 1 and payload['video_aspect'] == '9:16'
        self.respond({'task_id': 'job-1'})

    def do_GET(self):
        if self.path == '/tasks/job-1/final-1.mp4':
            self.send_response(200)
            self.end_headers()
            self.wfile.write(b'fixture-video' * 1000)
            return
        task = self.path.rsplit('/', 1)[-1]
        if task == 'missing':
            self.respond({}, 404)
            return
        self.respond({'task_id': task, 'state': {'processing': 4, 'failed': -1, 'malformed': 99}.get(task, 1),
                      'progress': 30, 'videos': ['/tasks/job-1/final-1.mp4']})

HTTPServer(('127.0.0.1', 8091), Handler).serve_forever()
