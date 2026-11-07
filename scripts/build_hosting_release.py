#!/usr/bin/env python3
"""Export tracked public website files only; never export repository/connection secrets."""
import json
import shutil
import subprocess
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / '_hosting_release'


def public_file(name):
    path = PurePosixPath(name)
    if not path.parts or any(part.startswith('.') for part in path.parts):
        return False
    if path.parts[0] in {'scripts', 'deliverables', 'tests', 'deploy'}:
        return False
    if path.parts[0] == 'assets':
        return True
    return path.suffix in {'.html', '.css', '.js', '.xml'} or name == 'robots.txt'


def build():
    names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    selected = [name for name in names if name and public_file(name)]
    if 'index.html' not in selected or 'robots.txt' not in selected:
        raise RuntimeError('The tracked website entry point or robots.txt is missing.')
    if OUTPUT.exists():
        shutil.rmtree(OUTPUT)
    OUTPUT.mkdir()
    for name in selected:
        source = ROOT / name
        if source.is_symlink() or not source.is_file():
            raise RuntimeError('A public source is missing or is a symbolic link.')
        target = OUTPUT / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)
    print(json.dumps({'release_files': len(selected), 'release_bytes': sum((OUTPUT / n).stat().st_size for n in selected)}))


if __name__ == '__main__':
    build()
