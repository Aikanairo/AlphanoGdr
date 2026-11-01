"""HTTP regressions for the shared administration workspace and global favicon."""
import re,json

def exercise(admin,guest,app,sql,check):
 for path in ['/','/legal/?type=cookies','/recuperopasswd.php','/install/license.php']:
  code,body,_=guest.request(path);check('favicon is present on public page '+path,code==200 and 'rel="icon"' in body and '/favicon.png?v=' in body)
 with guest.opener.open(guest.base+'/favicon.png') as r:check('the supplied favicon is delivered unchanged',r.status==200 and r.read()==(app/'favicon.png').read_bytes())
 code,body,_=guest.request('/admin/settings.php');check('permission errors retain the favicon without exposing staff navigation',code==403 and '/favicon.png?v=' in body and 'data-management-shell' not in body)
 pages=['/admin/setup.php','/admin/settings.php','/admin/appearance.php','/admin/privacy.php','/admin/world.php','/admin/world-map.php','/admin/rpg.php','/admin/utenti.php','/admin/assistenza.php','/admin/welcome.php','/admin/weather-assets.php','/admin/giochi.php','/admin/lavori.php','/admin/chats.php','/admin/malus.php','/admin/ferite.php','/admin/blocco.php','/admin/city.php','/admin/cariche.php','/admin/bacheche.php','/sessione/sessione.php']
 for path in pages:
  code,body,_=admin.request(path)
  check('administrative page has the shared workspace '+path,code==200 and 'data-management-shell=' in body and 'id="management-navigation"' in body and '/assets/management.js' in body and '/favicon.png?v=' in body)
 check('administrative documents no longer include the logo quick-links bar','data-management-brand' not in admin.request('/admin/appearance.php')[1])
 home=guest.request('/')[1];masthead=re.search(r'<header class="portal-masthead".*?</header>',home,re.S);check('homepage masthead uses only the compact A emblem',masthead is not None and 'src="/favicon.png"' in masthead[0] and 'width="44" height="44"' in masthead[0] and 'alphanogdr-logo.png' not in masthead[0])
 code,body,_=admin.request('/admin/appearance.php?section=home');check('appearance forms and file fields are preserved inside the workspace','name="section" value="home"' in body and 'name="logo"' in body and 'name="revision"' in body and 'enctype="multipart/form-data"' in body)
 # A Master sees only the navigation matching their permissions; backend access stays enforced.
 sql("UPDATE utenti SET admin='',fatepower='1' WHERE id=1")
 try:
  code,body,_=admin.request('/admin/ferite.php');rail=re.search(r'<aside class="management-rail".*?</aside>',body,re.S)
  check('master workspace does not advertise manager-only configuration',code==200 and rail is not None and '/admin/privacy.php' not in rail[0] and '/admin/settings.php' not in rail[0] and '/admin/ferite.php' in rail[0])
  check('a Master still cannot access manager settings',admin.request('/admin/settings.php')[0]==403)
 finally:sql("UPDATE utenti SET admin='1',fatepower='' WHERE id=1")
 code,body,response=admin.request('/api/status.php');check('JSON APIs are never wrapped in management HTML',code==200 and response.headers.get_content_type()=='application/json' and 'data-management-shell' not in body and isinstance(json.loads(body),dict))
