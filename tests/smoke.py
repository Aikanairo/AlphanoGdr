#!/usr/bin/env python3
"""Install from zero against a private MariaDB process and a copied web root.
Never reads a running game's database, config, socket, credentials or HTTP service.
Set PHP_BIN, MYSQL_BIN, MYSQLD_BIN and MYSQL_INSTALL_DB if not on PATH.
"""
from pathlib import Path
import os,sys,re,json,shutil,subprocess,tempfile,time,socket,urllib.request,urllib.parse,http.cookiejar,contextlib,threading,socketserver,email,email.policy
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN',shutil.which('php') or '')
MYSQL=os.environ.get('MYSQL_BIN',shutil.which('mariadb') or shutil.which('mysql') or '')
MYSQLD=os.environ.get('MYSQLD_BIN',shutil.which('mariadbd') or shutil.which('mysqld') or '')
INSTALL_DB=os.environ.get('MYSQL_INSTALL_DB',shutil.which('mariadb-install-db') or shutil.which('mysql_install_db') or '')
checks=[]
def check(name,ok):
 if not ok:raise AssertionError(name)
 checks.append(name);print('PASS',name,flush=True)
check('atlas camera covers changing viewports and preserves marker coordinates',subprocess.run(['node',str(ROOT/'tests/atlas-camera.cjs')],check=False).returncode==0)
def free_port():
 with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
class Client:
 def __init__(self,base):self.base=base;self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
 def request(self,path,body=None,headers=None):
  data=urllib.parse.urlencode({k:('1' if v is True else v) for k,v in body.items() if v is not False},doseq=True).encode() if body is not None else None
  req=urllib.request.Request(self.base+path,data=data,headers=headers or {})
  try:
   with self.opener.open(req,timeout=30) as r:return r.status,r.read().decode(),r
  except urllib.error.HTTPError as e:return e.code,e.read().decode(),e
 def csrf(self,path='/'):
  status,body,_=self.request(path);assert status==200,(status,body[:500]);m=re.search(r'name="_csrf" value="([^"]+)"',body) or re.search(r'name="csrf-token" content="([^"]+)"',body);assert m,body[:300];return m[1]
 def login(self,name,password):
  status,body,_=self.request('/checkuser.php',{'username':name,'password':password,'_csrf':self.csrf()});check('login '+name,status==200 and 'main.php' in body)
 def api(self,path,data):
  return self.request(path,json.dumps(data).encode() if False else data)
@contextlib.contextmanager
def environment():
 with tempfile.TemporaryDirectory(prefix='alphano-isolated-') as directory:
  folder=Path(directory);app=folder/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','local.php','private','dist','__pycache__','runtime'));(app/'private').mkdir();(app/'private/.htaccess').write_text('Require all denied\n')
  datadir=folder/'mysql';datadir.mkdir();sock=str(folder/'mysql.sock');basedir=str(Path(MYSQLD).resolve().parents[1]);db=None;web=None
  with open(folder/'init.log','w') as log:
   p=subprocess.run([INSTALL_DB,'--no-defaults','--basedir='+basedir,'--datadir='+str(datadir),'--auth-root-authentication-method=normal','--skip-test-db'],stdout=log,stderr=subprocess.STDOUT)
   if p.returncode:raise RuntimeError((folder/'init.log').read_text())
  try:
   db=subprocess.Popen([MYSQLD,'--no-defaults','--basedir='+basedir,'--datadir='+str(datadir),'--socket='+sock,'--pid-file='+str(folder/'mysql.pid'),'--log-error='+str(folder/'mysql.log'),'--skip-networking','--innodb-buffer-pool-size=64M','--max-connections=20'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   def sql(q,database='alphano_test'):
    return subprocess.check_output([MYSQL,'--no-defaults','--socket='+sock,'-uroot','-N','-B',database,'-e',q],text=True).strip()
   for _ in range(150):
    try:subprocess.run([MYSQL,'--no-defaults','--socket='+sock,'-uroot','-e','SELECT 1'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True);break
    except subprocess.CalledProcessError:
     if db.poll() is not None:raise RuntimeError((folder/'mysql.log').read_text())
     time.sleep(.1)
   else:raise RuntimeError('Private MariaDB did not start')
   subprocess.run([MYSQL,'--no-defaults','--socket='+sock,'-uroot','-e','CREATE DATABASE alphano_test CHARACTER SET utf8mb4; CREATE DATABASE alphano_busy;CREATE TABLE alphano_busy.sentinel(id INT);INSERT INTO alphano_busy.sentinel VALUES(17)'],check=True)
   (folder/'sendmail-test').write_text("#!/usr/bin/env python3\nimport pathlib,sys\npathlib.Path(__file__).with_name('native.eml').write_bytes(sys.stdin.buffer.read())\n");(folder/'sendmail-test').chmod(0o700)
   port=free_port();base=f'http://127.0.0.1:{port}';log=open(folder/'web.log','w');env={k:v for k,v in os.environ.items() if not k.startswith(('ALPHANO_',))}
   web=subprocess.Popen([PHP,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-d','opcache.jit=0','-d','sendmail_path='+str(folder/'sendmail-test'),'-d','display_errors=0','-d','log_errors=1','-d','error_log='+str(folder/'php.log'),'-S','127.0.0.1:'+str(port),'-t',str(app),str(app/'tests/router.php')],stdout=log,stderr=subprocess.STDOUT,env=env)
   for _ in range(100):
    try:urllib.request.urlopen(base+'/install/',timeout=1);break
    except OSError:time.sleep(.05)
   yield dict(folder=folder,app=app,sql=sql,base=base,sock=sock)
  except BaseException:
   if (folder/'php.log').exists():print((folder/'php.log').read_text(),flush=True)
   raise
  finally:
   if web:web.terminate();web.wait(timeout=10)
   if db:db.terminate();db.wait(timeout=20)
with environment() as env:
 app=env['app'];sql=env['sql'];admin=Client(env['base']);guest=Client(env['base'])
 class NoRedirect(urllib.request.HTTPRedirectHandler):
  def redirect_request(self,*args,**kwargs):return None
 for entry in ['/', '/index.php']:
  try:urllib.request.build_opener(NoRedirect()).open(env['base']+entry);redirected=False
  except urllib.error.HTTPError as response:redirected=response.code==303 and response.headers.get('Location')=='/install/'
  check('uninstalled entry automatically opens installer '+entry,redirected)
 check('initial browser visit does not create database tables',sql('SHOW TABLES')=='')
 csrf=admin.csrf('/')

 check('installer needs no ownership key','name="setup_key"' not in admin.request('/install/')[1])
 runtime=subprocess.check_output([PHP,'-r','echo PHP_VERSION;'],text=True).strip()
 needs_warning=runtime.startswith('8.4.') and int(runtime.split('.')[2])<27 or runtime.startswith('8.5.') and int(runtime.split('.')[2])<12
 installer_page=admin.request('/install/')[1]
 check('installer offers a currency name with the legacy default','name="currency_name"' in installer_page and 'value="Corone"' in installer_page)
 check('compatible runtime shows a nonblocking patch recommendation only when needed',('id="runtime-warning-title"' in installer_page)==needs_warning and 'Crea la struttura e continua' in installer_page)
 form=dict(accept_license='1',license_hash=__import__('hashlib').sha256((app/'LICENSE').read_bytes()).hexdigest(),accept_php_risk='1',_csrf=csrf,db_host='localhost',db_socket=env['sock'],db_port='3306',db_name='alphano_test',db_user='root',db_pass='',game_name='Alphano Demo',game_description='Un mondo di prova',base_url=env['base'],owner_name='Gestione Demo',support_email='staff@example.invalid',admin_name='DemoAdmin',admin_password='Test_Only_Password_2026',admin_password_confirm='Test_Only_Password_2026',city_name='Alphaland',chat_0='Piazza',chat_1='Locanda',chat_2='Giardino',chat_3='Biblioteca',theme='blue',sidebar_position='right',header_position='top',mail_transport='log',mail_from='staff@example.invalid',smtp_host='',smtp_user='',smtp_pass='',smtp_security='starttls',smtp_port=587,abilities_enabled=1,registration_enabled=1,privacy_text='Informativa dimostrativa del gioco.',terms_text='Condizioni dimostrative della comunità.',welcome_subject='Benvenuto in {game}',welcome_body='Ciao {name}, visita {url}.')
 if needs_warning:
  check('unpatched runtime requires explicit risk consent before any database changes',admin.request('/install/',{**form,'accept_php_risk':''})[0]==422 and sql('SHOW TABLES')=='')
 check('installer requires explicit current license acceptance',admin.request('/install/',{**form,'accept_license':''})[0]==422 and admin.request('/install/',{**form,'license_hash':'0'*64})[0]==422 and sql('SHOW TABLES')=='')
 check('installer requires CSRF',admin.request('/install/',{**form,'_csrf':'bad'})[0]==403)
 vendor=app/'vendor';missing=app/'vendor-unavailable';vendor.rename(missing)
 try:
  status,body,_=admin.request('/install/',form)
  check('source-only upload reports missing vendor without partial installation',status==422 and 'vendor/autoload.php' in body and sql('SHOW TABLES')=='' and not (app/'config/local.php').exists())
 finally:missing.rename(vendor)

 check('installer refuses weak password',admin.request('/install/',{**form,'admin_password':'short','admin_password_confirm':'short'})[0]==422)
 check('installer refuses occupied database',admin.request('/install/',{**form,'db_name':'alphano_busy'})[0]==422 and sql('SELECT id FROM sentinel','alphano_busy')=='17')
 no_redirect=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(admin.jar),NoRedirect())
 try:no_redirect.open(env['base']+'/install/',data=urllib.parse.urlencode(form).encode());posted=False
 except urllib.error.HTTPError as response:posted=response.code==303 and response.headers.get('Location')=='/install/complete.php'
 check('successful installer redirects from POST to a separate summary',posted)
 status,body,response=admin.request('/install/complete.php',headers={'Sec-Fetch-Dest':'document'})
 check('unchecked sample leaves no private map preset',not (app/'private/world-map.json').exists())
 check('fresh installation succeeds',status==200 and 'Installazione completata' in body)
 check('completion summary stays outside the game frame and links to the homepage',response.geturl().endswith('/install/complete.php') and 'data-install-complete' in body and 'href="/?preview=1"' in body and '/assets/install-complete.js' in body)
 check('completion summary excludes passwords and database secrets',form['admin_password'] not in body and 'db_pass' not in body and 'smtp_pass' not in body)
 check('completion summary is private to the administrator',guest.request('/install/complete.php')[0]==403)
 installed_hash=sql('SELECT password FROM utenti WHERE id=1')
 check('refreshing completion never repeats installation',admin.request('/install/complete.php')[0]==200 and sql('SELECT COUNT(*) FROM utenti')=='1' and sql('SELECT password FROM utenti WHERE id=1')==installed_hash)
 check('completion homepage target displays the real homepage for its signed-in admin','portal-homepage' in admin.request('/?preview=1')[1])
 check('bootstrap has no active races or game world',all(sql('SELECT COUNT(*) FROM '+t)=='0' for t in ['razze','rpg_races','etnie','city','mappe','organizzazioni','corporazioni']) and sql('SELECT COUNT(*) FROM utenti')=='1')
 check('admin sheet works before choosing any race',admin.request('/profili/?Id=1')[0]==200)
 check('wizard resumes after bootstrap',admin.request('/admin/setup.php?step=chats')[0]==200)
 token=admin.csrf('/admin/setup.php')
 check('all later steps are optional',all(admin.request('/admin/setup.php?step='+step)[0]==200 for step in ['chats','races','ethnicities','cities','organizations','corporations','appearance','rules','review']))
 check('wizard needs CSRF',admin.request('/admin/setup.php?step=races',dict(step='races',action='skip'))[0]==403)
 check('skip remains on checklist',admin.request('/admin/setup.php?step=ethnicities',dict(_csrf=token,step='ethnicities',action='skip'))[0]==200 and sql("SELECT status FROM engine_setup_steps WHERE step_key='ethnicities'")=='skipped')
 check('only general stage cannot be skipped',admin.request('/admin/setup.php?step=general',dict(_csrf=token,step='general',action='skip'))[0]==422)
 status,body,_=admin.request('/admin/setup.php?step=chats',dict(_csrf=token,step='chats',action='demo_chats',area_title='Alphaland'))
 check('optional four-chat demo succeeds',status==200 and sql('SELECT COUNT(*) FROM mappe')=='4')
 room_key=sql('SELECT chat_key FROM mappe ORDER BY id LIMIT 1')
 keys=['strength','dexterity','constitution','intelligence','wisdom','charisma','perception','willpower']
 race=dict(_csrf=token,step='races',action='save',id=0,name='Esploratore',description='Una razza creata durante il setup.',**{f'stats[{k}]':10 for k in keys},**{f'transformation_stats[{k}]':15 for k in keys})
 check('new race starts inactive',admin.request('/admin/setup.php?step=races',race)[0]==200 and sql('SELECT enabled FROM rpg_races')=='0')
 race_id=int(sql('SELECT id FROM razze LIMIT 1'));race.update(id=race_id,enabled=1,human_default=1,transformation_enabled=1,transformation_human_text='{nome} torna normale.',transformation_special_text='{nome} assume la forma di {razza}.')
 check('custom race supports initial form and transformation',admin.request('/admin/setup.php?step=races',race)[0]==200 and race_id>=100 and sql('SELECT razza FROM utenti WHERE id=1')==str(race_id))
 special={**race,'id':0,'name':'Custode','special':1};special.pop('human_default')
 check('special race can be created',admin.request('/admin/setup.php?step=races',special)[0]==200 and sql('SELECT COUNT(*) FROM rpg_races WHERE special=1')=='1')
 bad_race={**race,'stats[strength]':101};check('race stats are validated',admin.request('/admin/setup.php?step=races',bad_race)[0]==422)
 check('ethnicities are optional and editable',admin.request('/admin/setup.php?step=ethnicities',dict(_csrf=token,step='ethnicities',action='save',name='Origine di prova',description='Una origine personalizzata.'))[0]==200)
 check('city connects to existing card without elections',admin.request('/admin/setup.php?step=cities',dict(_csrf=token,step='cities',action='save',name='Alphaland',description='Città di prova.',area_id=1))[0]==200 and sql('SELECT COUNT(*) FROM world_areas')=='1' and sql('SELECT elections_enabled FROM city')=='0')
 for stage in ['organizations','corporations']:
  check(stage+' can be enabled',admin.request('/admin/setup.php?step='+stage,dict(_csrf=token,step=stage,action='toggle',feature_enabled=1))[0]==200)
  check(stage+' sample can be created',admin.request('/admin/setup.php?step='+stage,dict(_csrf=token,step=stage,action='save',name='Gruppo di prova',description='Descrizione propria.',city_id=1))[0]==200)
 check('registration explicitly opens after race setup',admin.request('/admin/setup.php?step=review',dict(_csrf=token,step='review',action='save',registration_enabled=1))[0]==200)
 check('setup changes persist after login',sql('SELECT COUNT(*) FROM razze')=='2' and sql('SELECT COUNT(*) FROM city')=='1')
 check('no production conversations or NPCs',all(sql('SELECT COUNT(*) FROM '+t)=='0' for t in ['chat','mail','ai_npcs','ai_jobs','historical_sessions','logs_ingressi']))
 check('installation is locked',guest.request('/install/',{})[0]==404 and admin.request('/install/',form)[0]==404)
 check('anonymous return to a closed installer opens homepage',guest.request('/install/')[2].geturl().endswith('/') and 'portal-homepage' in guest.request('/install/')[1])
 check('returning administrator sees completion instead of the installation form','Installazione completata' in admin.request('/install/',headers={'Sec-Fetch-Dest':'document'})[1])
 check('AI starts disabled',not json.loads((app/'private/settings.json').read_text())['ai_enabled'])
 for path in ['/private/settings.json','/config/local.php','/database/schema.sql','/.git/config','/tests/router.php','/composer.lock']:check('private path protected '+path,guest.request(path)[0]==403)
 check('browser login accepts configured origin',admin.request('/checkuser.php',dict(username='DemoAdmin',password=form['admin_password'],_csrf=admin.csrf('/')),headers={'Origin':env['base']})[0]==200)
 check('browser login rejects foreign origin',admin.request('/checkuser.php',dict(username='DemoAdmin',password=form['admin_password'],_csrf=admin.csrf('/')),headers={'Origin':'https://foreign.example.invalid'})[0]==403)
 admin.login('DemoAdmin',form['admin_password']);token=admin.csrf('/main.php')
 check('empty resource catalog does not open welcome wheel',not json.loads(admin.request('/api/fortune.php',dict(_csrf=token,action='welcome'))[1])['show_welcome'])
 check('session cookie isolated',any(c.name.startswith('alphano_') and not c.name.startswith('alphano_setup') for c in admin.jar))
 status,body,_=admin.request('/mappa.php');check('world has city cards',status==200 and 'Alphaland' in body and 'world-card' in body)
 status,body,_=admin.request('/mappa.php?area=1');check('city has four chat cards',status==200 and body.count('class="world-card"')==4)
 check('unknown area returns 404',admin.request('/mappa.php?area=999')[0]==404)
 check('guest cannot administer',guest.request('/admin/settings.php')[0]==403 and guest.request('/admin/world.php')[0]==403)
 check('world mutation needs CSRF',admin.request('/admin/world.php',{'entity':'area','title':'Unauthorized'})[0]==403)
 status,body,_=admin.request('/api/status.php');check('status API works',status==200);status_data=json.loads(body)
 check('settings in navigation',any(x['url']=='/admin/settings.php' for x in status_data['menu']))
 # Registration works without innate abilities, artwork or a human race row.
 player=Client(env['base']);registration={'_csrf':player.csrf(),'pgName':'DemoPlayer','mail':'player@example.invalid','passwd':'Player_Test_Password_2026','passwdConfirm':'Player_Test_Password_2026','sesso':'Femmina','adult':'1','terms':'1',**{f'allocation[{k}]':3 if i<4 else 2 for i,k in enumerate(['strength','dexterity','constitution','intelligence','wisdom','charisma','perception','willpower'])}}
 status,body,_=player.request('/adduser.php',registration);check('player registration succeeds',status==200 and 'personaggio è pronto' in body)
 check('password stored as modern hash',sql("SELECT password FROM utenti WHERE nome='DemoPlayer'").startswith('$2'))
 files=list((app/'private/mail').glob('*.eml'));check('welcome email is rendered',len(files)==1 and 'Test_Password' not in files[0].read_text())
 msg=email.message_from_bytes(files[0].read_bytes(),policy=email.policy.default);check('welcome uses game name and player',msg['Subject']=='Benvenuto in Alphano Demo' and 'DemoPlayer' in msg.get_body(preferencelist=('plain',)).get_content())
 player.login('DemoPlayer',registration['passwd']);check('ordinary player cannot administer',player.request('/admin/world.php')[0]==403 and player.request('/admin/settings.php')[0]==403 and player.request('/admin/setup.php')[0]==403)
 status,body,_=player.request('/chat/index.php?chat='+room_key);check('chat opens',status==200)
 status,body,_=player.request('/chat/messages.php?chat='+room_key+'&initial=1');check('chat polling works',status==200)
 # A real action and a retry must persist exactly once with AI completely disabled.
 ptoken=player.csrf('/main.php');action=dict(_csrf=ptoken,msg='+ Osserva la piazza e saluta con calma i presenti.',requestId='demo-action-request-00001',ai_auto='0')
 status,body,_=player.request('/chat/messages.php?chat='+room_key,action);check('real chat action is saved',status==200)
 count=sql('SELECT COUNT(*) FROM chat');check('chat retry is idempotent',player.request('/chat/messages.php?chat='+room_key,action)[0]==200 and sql('SELECT COUNT(*) FROM chat')==count)
 check('AI disabled creates no jobs',sql('SELECT COUNT(*) FROM ai_jobs')=='0')
 before_stats=json.loads(player.request('/api/rpg.php')[1])['stats']
 status,body,_=player.request('/api/transformation.php',dict(_csrf=ptoken,form='native'))
 after_stats=json.loads(player.request('/api/rpg.php')[1])['stats']
 check('arbitrary race transformation changes configured stats',status==200 and after_stats['strength']==before_stats['strength']+5 and sql("SELECT razza FROM utenti WHERE nome='DemoPlayer'")==str(race_id))
 check('transformation message is saved in the real chat','assume la forma' in admin.request('/chat/messages.php?chat='+room_key+'&initial=1')[1])
 check('transformation returns to normal form',player.request('/api/transformation.php',dict(_csrf=ptoken,form='human'))[0]==200 and json.loads(player.request('/api/rpg.php')[1])['stats']==before_stats)
 for stage,path in [('organizations','/organizzazioni/'),('corporations','/corporazioni/')]:
  admin.request('/admin/setup.php?step='+stage,dict(_csrf=token,step=stage,action='toggle',feature_enabled=0))
  check(stage+' disabled blocks module',player.request(path)[0]==403)
  admin.request('/admin/setup.php?step='+stage,dict(_csrf=token,step=stage,action='toggle',feature_enabled=1))
 check('all optional steps can be marked complete',all(admin.request('/admin/setup.php',dict(_csrf=token,step=stage,action='complete'))[0]==200 for stage in ['chats','races','ethnicities','cities','organizations','corporations','appearance','rules','review']) and 'Configurazione del gioco da completare' not in admin.request('/main.php')[1])
 check('completed stage can be reopened',admin.request('/admin/setup.php',dict(_csrf=token,step='rules',action='reopen'))[0]==200 and 'Configurazione del gioco da completare' in admin.request('/main.php')[1])
 check('chat action visible to another player','piazza' in admin.request('/chat/messages.php?chat='+room_key+'&initial=1')[1])
 letter=dict(_csrf=ptoken,action='send',person=1,subject='Prova di missiva',message='Una comunicazione interna di prova.',request_key='demo-letter-request-0001')
 status,body,_=player.request('/api/mail-thread.php',letter);check('internal mail sends',status==200 and sql("SELECT COUNT(*) FROM mail WHERE oggetto='Prova di missiva'")=='1')
 check('internal mail retry is idempotent',player.request('/api/mail-thread.php',letter)[0]==200 and sql("SELECT COUNT(*) FROM mail WHERE oggetto='Prova di missiva'")=='1')
 # Password recovery is delivered through the same transport with a one-use token.
 reset=Client(env['base']);rt=reset.csrf('/recuperopasswd.php');status,body,_=reset.request('/recuperopasswd.php',dict(_csrf=rt,user='DemoPlayer',mail='player@example.invalid'))
 check('password reset request succeeds',status==200 and sql('SELECT COUNT(*) FROM password_resets')=='1')
 resetmail=sorted((app/'private/mail').glob('*.eml'),key=lambda p:p.stat().st_mtime_ns)[-1];message=email.message_from_bytes(resetmail.read_bytes(),policy=email.policy.default);plain=message.get_body(preferencelist=('plain',)).get_content();match=re.search(r'token=([a-f0-9]{64})',plain);check('reset email contains token and no password',match is not None and registration['passwd'] not in plain)
 url='/recuperopasswd.php?token='+match[1];rt=reset.csrf(url);newpass='Recovered_Test_Password_2026';status,body,_=reset.request(url,dict(_csrf=rt,reset_token=match[1],password=newpass,password_confirm=newpass))
 check('reset token updates password once',status==200 and sql('SELECT COUNT(*) FROM password_resets')=='0' and 'già stato utilizzato' in reset.request(url)[1])
 rules=dict(_csrf=token,step='rules',action='save',initial_points=12,abilities_enabled=1,privacy_text='Informativa personalizzata.',terms_text='Condizioni personalizzate.',**{f'stat_names[{stat["key"]}]':stat['name'] for stat in json.loads(admin.request('/api/rpg.php?action=catalog')[1])['stats']})
 check('initial points can be customized',admin.request('/admin/setup.php?step=rules',rules)[0]==200 and json.loads(admin.request('/api/rpg.php?action=creation_catalog')[1])['points']==12)
 admin.request('/admin/setup.php?step=ethnicities',dict(_csrf=token,step='ethnicities',action='toggle',feature_enabled=1))
 extra=Client(env['base']);extra_form={**registration,'_csrf':extra.csrf(),'pgName':'CustomPlayer','mail':'custom@example.invalid',**{f'allocation[{key}]':2 if i<4 else 1 for i,key in enumerate(keys)}}
 check('ethnicity required only when enabled',extra.request('/adduser.php',extra_form)[0]==422)
 extra_form['ethnicity']=1
 check('registration uses custom points and ethnicity',extra.request('/adduser.php',extra_form)[0]==200 and sql("SELECT etnia FROM utenti WHERE nome='CustomPlayer'")=='1')
 admin.request('/admin/setup.php?step=ethnicities',dict(_csrf=token,step='ethnicities',action='toggle',feature_enabled=0))
 rules['initial_points']=20;admin.request('/admin/setup.php?step=rules',rules)
 # Full page and API coverage catches schema/config dependencies in the extracted engine.
 pages=['/main.php','/admin/rpg.php','/razze/','/profili/?Id=1','/equip/equip.php','/organizzazioni/','/corporazioni/','/citta/','/governo/?scope=citta&id=1','/posta/','/manuali/','/regole/','/presenti/','/api/profile.php','/api/rpg.php','/api/registration.php','/sessione/viewlog.php?azione=storico','/citta/elezioni.php?city=1','/admin/chats.php','/admin/lavori.php','/admin/utenti.php']
 for path in pages:
  status,body,_=admin.request(path);check('module '+path,status<500)
 status,body,_=admin.request('/api/manuali.php',dict(_csrf=token,azione='insert-section',gruppo_select=1,audience='public',titolo_sezione='Guida personalizzata',spiegazione_edit='<p>Contenuto originale di prova.</p>',summary='Una guida propria.'))
 check('staff can create manuals without an artwork library',status==200 and sql("SELECT COUNT(*) FROM regole WHERE oggetto='Guida personalizzata'")=='1')
 if (app/'vendor/autoload.php').exists():
  with admin.opener.open(env['base']+'/regole/pdf.php',timeout=30) as response:check('manual PDF export works',response.status==200 and response.read().startswith(b'%PDF-'))
 result=subprocess.run([PHP,'bin/maintenance.php'],cwd=app,text=True,capture_output=True);check('optional email cron works',result.returncode==0)
 result=subprocess.run([PHP,'bin/ai-worker.php'],cwd=app,text=True,capture_output=True);check('disabled AI worker exits without inference',result.returncode==0 and 'disabilitato' in result.stdout)
 settings=json.loads((app/'private/settings.json').read_text());settings.update(_csrf=token,ai_url='',ai_model='')
 for palette in ['blue','black','brown','red','purple']:
  for side in ['left','right']:
   for header in ['top','bottom']:
    data={**settings,'theme':palette,'sidebar_position':side,'header_position':header}
    status,body,_=admin.request('/admin/settings.php',data);assert status==200,(status,re.findall(r'<p class="error"[^>]*>(.*?)</p>',body))
    body=admin.request('/main.php')[1];check('layout '+palette+'/'+side+'/'+header,all(x in body for x in [f'data-palette="{palette}"',f'data-sidebar="{side}"',f'data-header="{header}"']))
 settings.update(theme='blue',sidebar_position='right',header_position='top');admin.request('/admin/settings.php',settings)
 # Create a new destination and chat, then verify both through the public browser flow.
 area=dict(_csrf=token,entity='area',id=0,title='Territorio di prova',description='Descrizione originale',position=1,enabled=1,city_id=0,image='/assets/placeholder.svg')
 check('admin creates destination',admin.request('/admin/world.php',area)[0]==200)
 area_id=int(sql('SELECT MAX(id) FROM world_areas'));room=dict(_csrf=token,entity='chat',id=0,title='Nuova chat',description='Descrizione della nuova chat',position=0,enabled=1,area_id=area_id,image='/assets/placeholder.svg')
 check('admin creates chat',admin.request('/admin/world.php',room)[0]==200)
 check('created chat appears in destination','Nuova chat' in player.request('/mappa.php?area='+str(area_id))[1])
 check('new area and room have no inference jobs',sql('SELECT COUNT(*) FROM ai_jobs')=='0')
 # Hiding a room or its destination also closes direct HTTP access.
 room_id=int(sql('SELECT MAX(id) FROM mappe'));key=sql('SELECT chat_key FROM mappe WHERE id='+str(room_id));hidden={k:v for k,v in room.items() if k!='enabled'};hidden['id']=room_id
 check('chat can be hidden without deleting history',admin.request('/admin/world.php',hidden)[0]==200 and player.request('/chat/index.php?chat='+key)[0]==404 and player.request('/chat/messages.php?chat='+key)[0]==404)
 hidden_area={k:v for k,v in area.items() if k!='enabled'};hidden_area['id']=area_id
 check('destination can be hidden',admin.request('/admin/world.php',hidden_area)[0]==200 and player.request('/mappa.php?area='+str(area_id))[0]==404)
 check('settings form never exposes saved secrets','value="'+form['admin_password']+'"' not in admin.request('/admin/settings.php')[1])
 # A local SMTP receiver verifies the wire protocol without sending external mail.
 class SMTPHandler(socketserver.StreamRequestHandler):
  def handle(self):
   self.wfile.write(b'220 local.test ESMTP\r\n');self.wfile.flush();auth=0;data=False;lines=[]
   while True:
    line=self.rfile.readline()
    if not line:break
    if data:
     if line==b'.\r\n':self.server.messages.append(b''.join(lines));self.wfile.write(b'250 accepted\r\n');data=False
     else:lines.append(line);continue
    elif auth:auth-=1;self.wfile.write(b'334 password\r\n' if auth else b'235 authenticated\r\n')
    elif line.startswith(b'EHLO'):self.wfile.write(b'250-local.test\r\n250 AUTH LOGIN\r\n')
    elif line.startswith(b'AUTH'):auth=2;self.wfile.write(b'334 username\r\n')
    elif line.startswith((b'MAIL',b'RCPT')):self.wfile.write(b'250 ok\r\n')
    elif line.startswith(b'DATA'):data=True;self.wfile.write(b'354 continue\r\n')
    elif line.startswith(b'QUIT'):self.wfile.write(b'221 bye\r\n');self.wfile.flush();break
    else:self.wfile.write(b'500 unexpected\r\n')
    self.wfile.flush()
 with socketserver.TCPServer(('127.0.0.1',0),SMTPHandler) as smtp:
  smtp.messages=[];thread=threading.Thread(target=smtp.serve_forever,daemon=True);thread.start()
  smtp_settings={**settings,'smtp_host':'127.0.0.1','smtp_port':smtp.server_address[1],'smtp_security':'none','smtp_user':'localtest','smtp_pass':'smtp-test-only','mail_transport':'smtp'}
  check('SMTP settings save',admin.request('/admin/settings.php',smtp_settings)[0]==200)
  status,body,_=admin.request('/admin/settings.php',dict(_csrf=token,action='test_email'));check('SMTP wire delivery and AUTH LOGIN',status==200 and len(smtp.messages)==1 and b'To: staff@example.invalid' in smtp.messages[0])
  smtp.shutdown();thread.join(timeout=5)
 admin.request('/admin/settings.php',settings)
 native_settings={**settings,'mail_transport':'mail'}
 check('provider mail transport can be configured',admin.request('/admin/settings.php',native_settings)[0]==200)
 check('native PHP mail produces a valid MIME message',admin.request('/admin/settings.php',dict(_csrf=token,action='test_email'))[0]==200 and (env['folder']/'native.eml').exists() and b'MIME-Version: 1.0' in (env['folder']/'native.eml').read_bytes())
 admin.request('/admin/settings.php',settings)
 from appearance_checks import exercise
 exercise(env,admin,player,guest,room_key,check)
 from forum_checks import exercise as forum_exercise
 forum_exercise(env,admin,player,guest,check)
 from demo_checks import exercise as demo_exercise
 demo_exercise(env,Client,form,check,PHP)
 # Preserve an installed preview optionally, with processes and data kept isolated.
 errors=(env['folder']/'php.log').read_text() if (env['folder']/'php.log').exists() else ''
 print('PHP_LOG',errors,flush=True)
 check('no PHP errors or warnings',not errors.strip())
 print(json.dumps({'passed':len(checks),'source_game_modified':False,'isolated_mysql_socket':True},indent=2),flush=True)
 if '--serve' in sys.argv:
  print('PREVIEW',env['base'],flush=True);input('Enter closes the isolated preview.\n')
