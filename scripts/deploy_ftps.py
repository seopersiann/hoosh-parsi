#!/usr/bin/env python3
"""Verified FTPS publication. Check mode is read-only; never delete existing host files."""
import argparse
import ftplib
import hashlib
import io
import json
import os
import ssl
import sys
import uuid
from pathlib import Path, PurePosixPath

RELEASE = Path(__file__).resolve().parent.parent / '_hosting_release'
MANIFEST = '.hoosh-parsi-deploy.json'


class VerifiedFTP(ftplib.FTP_TLS):
    # Reuse the verified control-channel TLS session for Pure-FTPd data channels.
    def ntransfercmd(self, cmd, rest=None):
        conn, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        if self._prot_p:
            conn = self.context.wrap_socket(conn, server_hostname=self.host, session=self.sock.session)
        return conn, size


def settings():
    names = ['DEPLOY_HOST', 'DEPLOY_PORT', 'DEPLOY_USER', 'DEPLOY_PASSWORD', 'DEPLOY_PATH']
    if any(not os.environ.get(n) for n in names):
        raise ValueError('Required DEPLOY_* repository secrets are missing.')
    values = {n: os.environ[n] for n in names}
    host = values['DEPLOY_HOST'].strip()
    target = values['DEPLOY_PATH'].strip()
    if host != 'server26e.irwebspace.com' or values['DEPLOY_USER'] != 'git@hoosh-parsi.ir':
        raise ValueError('The configured destination does not match the authorized hosting account.')
    if target not in {'./public_html/', 'public_html/', '/public_html/'}:
        raise ValueError('DEPLOY_PATH must select public_html inside the authorized FTP account.')
    if int(values['DEPLOY_PORT']) != 21:
        raise ValueError('Expected the verified explicit-FTPS port 21.')
    return host, 21, values['DEPLOY_USER'], values['DEPLOY_PASSWORD'], target


def existing_manifest(ftp):
    data = io.BytesIO()
    def receive(chunk):
        if data.tell() + len(chunk) > 2_000_000:
            raise ValueError('Deployment manifest exceeds the size limit.')
        data.write(chunk)
    try:
        ftp.retrbinary('RETR ' + MANIFEST, receive)
    except ftplib.error_perm as exc:
        if str(exc).startswith('550'):
            return {}
        raise
    try:
        document = json.loads(data.getvalue())
        return document.get('files', {}) if document.get('version') == 1 else {}
    except (ValueError, AttributeError):
        return {}


def backup_existing(ftp, relative):
    ftp.voidcmd('TYPE I')
    try:
        size = ftp.size(relative)
    except ftplib.error_perm as error:
        if str(error).startswith('550'):
            return False  # Not an existing readable regular file.
        raise
    if size is None or size > 50_000_000:
        raise ValueError('Cannot safely back up a destination file before replacing it.')
    target = RELEASE.parent / '_hosting_backup' / relative
    target.parent.mkdir(parents=True, exist_ok=True)
    with target.open('wb') as stream:
        ftp.retrbinary('RETR ' + relative, stream.write)
    if target.stat().st_size != size:
        raise ValueError('The previous file backup is incomplete; deployment stopped.')
    return True


def atomic_upload(ftp, root, relative, stream, directories):
    path = PurePosixPath(relative)
    if path.is_absolute() or '..' in path.parts or any('\n' in p or '\r' in p for p in path.parts):
        raise ValueError('Invalid release path.')
    parent = str(path.parent)
    if parent not in directories:
        ftp.cwd(root)
        for part in path.parent.parts:
            try:
                ftp.cwd(part)
            except ftplib.error_perm:
                ftp.mkd(part)
                ftp.cwd(part)
        directories.add(parent)
    else:
        ftp.cwd(root + ('/' + parent if parent != '.' else ''))
    temporary = '.hp-upload-' + uuid.uuid4().hex + '.tmp'
    ftp.storbinary('STOR ' + temporary, stream, blocksize=65536)
    # A failed rename leaves the existing destination intact; no delete fallback.
    ftp.rename(temporary, path.name)


def run(mode, ftp_factory=VerifiedFTP):
    host, port, user, password, target = settings()
    ftp = ftp_factory(context=ssl.create_default_context(), timeout=20, encoding='utf-8')
    try:
        print("FTPS_STAGE: connecting control socket", flush=True)
        ftp.connect(host, port)
        print("FTPS_STAGE: control connected; verifying TLS and login", flush=True)
        ftp.login(user, password)
        print("FTPS_STAGE: authenticated; protecting data channel", flush=True)
        ftp.prot_p()
        ftp.set_pasv(True)
        print("FTPS_STAGE: checking public_html", flush=True)
        ftp.cwd(target)
        root = ftp.pwd().rstrip('/')
        if not root.endswith('/public_html'):
            raise ValueError('The server did not confirm the expected public_html destination.')
        print("FTPS_STAGE: testing encrypted passive directory listing", flush=True)
        count = len(ftp.nlst())  # Exercise the encrypted passive data channel; do not log host filenames.
        print(f'CHECK_OK: verified FTPS login, encrypted data channel and public_html; {count} entries present.', flush=True)
        if mode == 'check':
            print('READ_ONLY_CHECK: no host files were created, changed or deleted.', flush=True)
            return
        files = sorted(p for p in RELEASE.rglob('*') if p.is_file())
        if not (RELEASE / 'index.html').is_file() or not files:
            raise ValueError('Build the public release before deployment.')
        old = existing_manifest(ftp)
        manifest = {}
        changed = skipped = backed_up = 0
        directories = {'.'}
        # Assets and styles arrive before HTML; index.html is the last page switched.
        files.sort(key=lambda p: (p.suffix == '.html', p == RELEASE / 'index.html', str(p)))
        for file in files:
            if file.is_symlink():
                raise ValueError('Symbolic links are not publishable.')
            name = file.relative_to(RELEASE).as_posix()
            digest = hashlib.sha256(file.read_bytes()).hexdigest()
            manifest[name] = digest
            ftp.cwd(root)
            if old.get(name) == digest:
                try:
                    ftp.voidcmd('TYPE I')
                    if ftp.size(name) == file.stat().st_size:
                        skipped += 1
                        continue
                except ftplib.error_perm:
                    pass
            if backup_existing(ftp, name):
                backed_up += 1
            with file.open('rb') as stream:
                atomic_upload(ftp, root, name, stream, directories)
            changed += 1
        document = {'version': 1, 'commit': os.environ.get('GITHUB_SHA', ''), 'files': manifest}
        ftp.cwd(root)
        atomic_upload(ftp, root, MANIFEST, io.BytesIO(json.dumps(document, ensure_ascii=False).encode()), directories)
        print(f'DEPLOY_OK: uploaded {changed}, unchanged {skipped}, previous files backed up {backed_up}; no existing host files deleted.', flush=True)
    finally:
        ftp.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--mode', choices=['check', 'deploy'], default='check')
    args = parser.parse_args()
    try:
        run(args.mode)
    except Exception as error:
        message = str(error)
        for key in ['DEPLOY_PASSWORD', 'DEPLOY_USER']:
            if os.environ.get(key):
                message = message.replace(os.environ[key], '[REDACTED]')
        print(f'Deployment stopped: {type(error).__name__}: {message}', file=sys.stderr)
        sys.exit(1)
