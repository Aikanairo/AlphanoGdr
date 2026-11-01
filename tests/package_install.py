#!/usr/bin/env python3
"""Verify the upload ZIP by installing only its extracted contents, without Composer."""
from pathlib import Path, PurePosixPath
import argparse, os, stat, subprocess, sys, tempfile, zipfile

ROOT=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('archive',nargs='?',type=Path,default=ROOT/'dist/AlphanoGdr-hosting.zip')
args=parser.parse_args()
archive=args.archive.resolve()
if not archive.is_file():raise SystemExit('Build the complete hosting ZIP before running this test.')
with tempfile.TemporaryDirectory(prefix='alphano-package-') as directory:
 destination=Path(directory)/'site';destination.mkdir()
 with zipfile.ZipFile(archive) as source:
  names=set(source.namelist())
  for entry in source.infolist():
   name=PurePosixPath(entry.filename)
   if name.is_absolute() or '..' in name.parts or '\\' in entry.filename or stat.S_ISLNK(entry.external_attr>>16):raise SystemExit('Unsafe archive entry: '+entry.filename)
  required=['VERSION','LICENSE','NOTICE','index.php','.htaccess','install/index.php','install/installer.php','config/app.php','private/.htaccess','uploads/.htaccess','vendor/autoload.php','database/schema.sql','tests/smoke.py','assets/default-board.svg']
  missing=[name for name in required if name not in names]
  if missing:raise SystemExit('Incomplete hosting package: '+', '.join(missing))
  forbidden=['config/local.php','config/ai.local.php','private/settings.json','private/appearance.json','private/installed.lock']
  if any(name in names for name in forbidden) or any(name.startswith('.git/') for name in names):raise SystemExit('Package contains installation state or Git history.')
  if source.read('VERSION').decode().strip()!='V1.0':raise SystemExit('Wrong release version.')
  if not source.read('LICENSE').decode().startswith('GNU AFFERO GENERAL PUBLIC LICENSE'):raise SystemExit('Missing disclosed attribution license.')
  source.extractall(destination)
 print('PASS complete upload package: root entry, installer, bundled dependencies and no installed state',flush=True)
 result=subprocess.run([sys.executable,str(destination/'tests/smoke.py')],cwd=destination,env=os.environ.copy())
 raise SystemExit(result.returncode)
