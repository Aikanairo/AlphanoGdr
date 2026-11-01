#!/usr/bin/env python3
"""Build trusted, static recovery footers; no authored page is locked or copied."""
from pathlib import Path
import json,hashlib,re,html
ROOT=Path(__file__).resolve().parents[1]
style='display:block!important;visibility:visible!important;opacity:1!important;position:static!important;font-size:12px!important;line-height:1.8!important;color:#d8e7f3!important;background:#0c1a2a!important;padding:14px!important;margin:18px 0!important;text-align:center!important;height:auto!important;max-height:none!important;overflow:visible!important;clip:auto!important;clip-path:none!important;transform:none!important'
link='<a href="https://gothik.it/" style="color:#aad3f5!important;text-decoration:underline!important">Gothik.it</a>'
version=html.escape((ROOT/'VERSION').read_text().strip())
fragments={'home':f'<footer class="alphano-credit" data-credit-recovery style="{style}">Motore AlphanoGdr {version} · Programmato da Alfano Gaetano · {link}</footer>', 'equipment':f'<footer class="alphano-credit" data-credit-recovery style="{style}">Grafica ispirata da {link} · Motore di Alfano Gaetano</footer>'}
payload=(json.dumps({'version':2,'fragments':fragments},ensure_ascii=False,indent=2)+'\n').encode()
(ROOT/'config/credit-originals.json').write_bytes(payload)
p=ROOT/'libs/credits-guard.inc.php';s=p.read_text();s=re.sub(r"const ALPHANO_CREDIT_BUNDLE_HASH = '[^']*';","const ALPHANO_CREDIT_BUNDLE_HASH = '"+hashlib.sha256(payload).hexdigest()+"';",s);p.write_text(s)
print('Credit recovery: two static footers; no source files locked or overwritten')
