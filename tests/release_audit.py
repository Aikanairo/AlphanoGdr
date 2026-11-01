#!/usr/bin/env python3
"""Audit the exact source allowlist used by the release packager."""
from pathlib import Path
import importlib.util,re,json,sys,hashlib,ipaddress
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('package',ROOT/'bin/package.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
files=list(module.publish_files());errors=[]
art=json.loads((ROOT/'config/header-art.json').read_text())
trusted_art={a['path'].lstrip('/'):a for a in art['assets']}
assert len(trusted_art)==11 and art['license']=='MIT'
for rel,asset in trusted_art.items():
 data=(ROOT/rel).read_bytes()
 assert re.fullmatch(r'assets/headers/[a-z-]+\.webp',rel) and asset['license']=='MIT'
 assert hashlib.sha256(data).hexdigest()==asset['sha256']
 assert data[:4]==b'RIFF' and data[8:12]==b'WEBP' and len(data)<1024*1024
equipment=json.loads((ROOT/'config/equipment-art.json').read_text())
trusted_equipment={a['path'].lstrip('/'):a for a in equipment['assets']}
assert len(trusted_equipment)==39
for rel,a in trusted_equipment.items():
 assert hashlib.sha256((ROOT/rel).read_bytes()).hexdigest()==a['sha256']
brand={a['path']:a for a in json.loads((ROOT/'config/brand-art.json').read_text())['assets']}
assert set(brand)=={'assets/alphanogdr-logo.png','favicon.png'}
for name,a in brand.items():assert hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==a['sha256']
maps={a['path']:a for a in json.loads((ROOT/'config/map-art.json').read_text())['assets']}
assert set(maps)=={'assets/maps/world.png','assets/maps/city.png'}
for name,a in maps.items():
 data=(ROOT/name).read_bytes()
 assert a['license']=='AGPL-3.0-only' and hashlib.sha256(data).hexdigest()==a['sha256'] and data.startswith(b'\x89PNG\r\n\x1a\n') and len(data)<=6*1024*1024
showcase={a['path']:a for a in json.loads((ROOT/'config/showcase-art.json').read_text())['assets']}
assert set(showcase)=={'assets/showcase/'+n+'.jpg' for n in ['home-blue','home-black','home-brown','home-red','home-purple','map-world','map-city','admin']}
for name,a in showcase.items():
 data=(ROOT/name).read_bytes()
 assert hashlib.sha256(data).hexdigest()==a['sha256'] and data.startswith(b'\xff\xd8\xff') and data.endswith(b'\xff\xd9') and len(data)<3*1024*1024
for p in files:
 rel=p.relative_to(ROOT).as_posix()
 if rel in trusted_art or rel in trusted_equipment or rel in brand or rel in maps or rel in showcase:continue
 if p.suffix.lower() in {'.png','.jpg','.jpeg','.webp','.gif','.ico','.ttf','.woff','.woff2','.sqlite','.db'}:errors.append('Unexpected binary/artwork: '+rel)
 try:text=p.read_text()
 except UnicodeError:errors.append('Unexpected binary: '+rel);continue
 # Routable network addresses must not leak from an installation-specific blocklist.
 if rel!='composer.lock':
  for candidate in re.findall(r'(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])',text):
   try:address=ipaddress.ip_address(candidate)
   except ValueError:continue
   if address.is_global:errors.append('Public network address in distributed source: '+rel)
 # These expressions are written in fragments so the audit does not match itself.
 forbidden=['go'+'thik', 'bra'+'sov','Bra'+'șov','ful'+'minis','Va'+'lacco','Cronache della '+'Frattura','rovine'+'dimenticate','fag'+'aras','cortile'+'dracula','chiesa'+'nera']
 credit_files={'docs/ANNUNCIO-FORUM.md','docs/ANNUNCIO-FORUM.bbcode.txt','NOTICE','LICENSE','ATTRIBUTION.md','README.md','index.php','legal/index.php','config/legal.php','docs/TEMPLATE.md','docs/HOSTING.md','tests/appearance_checks.py','config/credits.php','config/credit-originals.json','libs/credits-guard.inc.php','config/equipment-art.json','templates/credits/home.php','templates/credits/equipment.php','credits/restore.php','install/license.php','install/index.php','bin/update-credit-bundle.py','THIRD_PARTY.md','ATTRIBUTION.md','tests/demo_checks.py','tests/credit_policy_checks.py'}
 audit_text=text
 if rel in credit_files:
  # Only the explicitly requested public credit is exempt, never world content.
  audit_text=re.sub(re.escape('go'+'thik.it'),'',audit_text,flags=re.I)
 if any(x.casefold() in (rel+' '+audit_text).casefold() for x in forbidden):errors.append('Source-world reference: '+rel)
 if '/ima'+'ges/' in text or '/equip/'+'magic.jpg' in text or '/equip/'+'approved.jpg' in text:errors.append('Retired media path: '+rel)
 if re.search(r'-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}',text):errors.append('Possible credential: '+rel)
 if p.suffix=='.sql' and re.search(r'\b(?:INSERT\s+INTO|REPLACE\s+INTO|DEFINER\s*=|CREATE\s+USER|GRANT\s)\b',text,re.I):errors.append('Schema contains data or user grants: '+rel)
 if rel.startswith(('dev/','config/storage/')) or rel.endswith('local.php'):errors.append('Private/development file: '+rel)
assert (ROOT/'LICENSE').is_file()
assert (ROOT/'VERSION').read_text().strip()=='V1.0'
assert json.loads((ROOT/'composer.json').read_text())['license']=='AGPL-3.0-only'
assert (ROOT/'LICENSE').read_text().startswith('GNU AFFERO GENERAL PUBLIC LICENSE')
assert (ROOT/'NOTICE').is_file()
assert (ROOT/'js/vendor/quill/LICENSE').is_file()
assert (ROOT/'third_party/onspli-chess/LICENSE').is_file()
assert not json.loads((ROOT/'config/rpg-seed.json').read_text())['races']
assert not json.loads((ROOT/'config/rpg-seed.json').read_text())['abilities']
# Bundled third-party files are public dependencies, not runtime or world data.
for p in (ROOT/'vendor').rglob('*'):
 if not p.is_file():continue
 rel=p.relative_to(ROOT).as_posix();data=p.read_bytes()
 if p.name in {'auth.json','.env','local.php'} or p.suffix in {'.sql','.eml','.log','.sqlite','.db'}:errors.append('Private vendor artifact: '+rel)
 if re.search(rb'-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----\r?\n[A-Za-z0-9+/=]{20,}',data) or re.search(rb'gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}',data):errors.append('Possible vendor secret: '+rel)
if errors:print('\n'.join(errors));sys.exit(1)
print(f'PASS release audit: {len(files)} source files, {len(trusted_art)} verified original illustrations; only allowlisted artwork and clean example catalogs; no player data, credentials or Git history.')
