#!/usr/bin/env python3
"""Build a clean upload archive without local state or development history."""
from pathlib import Path
import argparse,hashlib,zipfile,shutil,re,subprocess,sys
ROOT=Path(__file__).resolve().parents[1]
UPLOAD_DIRS=[('images',),('equip','images'),('equip','newimages'),('profili','pg'),('uploads',),('temp',),('sessione','log')]
def publish_files(include_vendor=False):
 for p in sorted(ROOT.rglob('*')):
  if not p.is_file() or p.is_symlink():continue
  rel=p.relative_to(ROOT);parts=rel.parts
  if any(x in {'.git','dist','__pycache__','runtime','node_modules'} for x in parts):continue
  if parts[0]=='vendor':
   if include_vendor:yield p
   continue
  if parts[0]=='private' and rel.as_posix()!='private/.htaccess':continue
  if rel.as_posix() in ['config/local.php','config/ai.local.php'] or p.name.endswith(('.log','.eml','.bak','.sql.gz','.pyc')):continue
  if any(parts[:len(d)]==d for d in UPLOAD_DIRS) and p.name!='.htaccess':continue
  yield p
if __name__=='__main__':
 args=argparse.ArgumentParser();args.add_argument('--include-vendor',action='store_true');args=args.parse_args()
 subprocess.run([sys.executable,str(ROOT/'bin/update-credit-bundle.py')],check=True)
 if args.include_vendor and not (ROOT/'vendor/autoload.php').is_file():raise SystemExit('Run composer install --no-dev first.')
 out=ROOT/'dist';out.mkdir(exist_ok=True);target=out/('AlphanoGdr-hosting.zip' if args.include_vendor else 'AlphanoGdr-source.zip')
 with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
  for p in publish_files(args.include_vendor):archive.write(p,p.relative_to(ROOT).as_posix())
 version=(ROOT/'VERSION').read_text().strip()
 if not re.fullmatch(r'V[0-9]+(?:\.[0-9]+){1,2}',version):raise SystemExit('Invalid release version.')
 versioned=out/('AlphanoGdr-'+version+('-hosting.zip' if args.include_vendor else '-source.zip'))
 shutil.copyfile(target,versioned)
 print(target);print(versioned);print('SHA256',hashlib.sha256(target.read_bytes()).hexdigest())
