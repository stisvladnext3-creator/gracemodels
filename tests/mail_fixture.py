"""Local-only mail transport fixtures. Never contacts external mail servers."""
from pathlib import Path
import socketserver,sys,uuid

def capture(directory,content):
    directory=Path(directory)
    if (directory/'fail').exists():raise OSError('Fixture rejects mail')
    directory.mkdir(parents=True,exist_ok=True)
    path=directory/(uuid.uuid4().hex+'.eml')
    with path.open('xb') as output:output.write(content)
    path.chmod(0o600)

class SMTPHandler(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 localhost fixture\r\n')
        while True:
            line=self.rfile.readline()
            if not line:return
            verb=line.split(b' ',1)[0].strip().upper()
            if verb in [b'EHLO',b'HELO']:self.wfile.write(b'250-localhost\r\n250 8BITMIME\r\n')
            elif verb in [b'MAIL',b'RCPT',b'RSET',b'NOOP']:self.wfile.write(b'250 OK\r\n')
            elif verb==b'DATA':
                self.wfile.write(b'354 End with dot\r\n');data=[]
                while True:
                    chunk=self.rfile.readline()
                    if not chunk:return
                    if chunk in [b'.\r\n',b'.\n']:break
                    data.append(chunk[1:] if chunk.startswith(b'..') else chunk)
                try:capture(self.server.capture_directory,b''.join(data));self.wfile.write(b'250 Queued\r\n')
                except OSError:self.wfile.write(b'451 Temporary failure\r\n')
            elif verb==b'QUIT':self.wfile.write(b'221 Bye\r\n');return
            else:self.wfile.write(b'502 Unsupported command\r\n')

class SMTPServer(socketserver.ThreadingTCPServer):
    allow_reuse_address=True
    daemon_threads=True

if __name__=='__main__':
    try:capture(sys.argv[1],sys.stdin.buffer.read())
    except OSError:raise SystemExit(1)
