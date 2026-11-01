#!/usr/bin/env python3
from pathlib import Path
import os,shutil,subprocess,sys
ROOT=Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN',shutil.which('php') or 'php');errors=[];count=0
for path in ROOT.rglob('*'):
 if not path.is_file() or any(p in path.parts for p in ['vendor','.git','dist']):continue
 if path.suffix=='.php':result=subprocess.run([php,'-l',str(path)],capture_output=True,text=True)
 elif path.suffix in ['.js','.mjs','.cjs']:result=subprocess.run(['node','--input-type=module','--check'],input=path.read_text(),capture_output=True,text=True)
 else:continue
 count+=1
 if result.returncode:errors.append(str(path.relative_to(ROOT))+': '+result.stdout+result.stderr)
if errors:print('\n'.join(errors));sys.exit(1)
print(f'PASS syntax: {count} PHP and JavaScript files')
