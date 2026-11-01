"""Exercise the checked demo package and disclosed credit repair in a second private app."""
from pathlib import Path
import shutil,subprocess,socket,time,json,hashlib,urllib.request,sys,re

def exercise(env,Client,form,check,php):
 root=Path(__file__).resolve().parents[1];app=env['folder']/'demo-app'
 shutil.copytree(root,app,ignore=shutil.ignore_patterns('.git','local.php','private','dist','__pycache__','runtime'))
 (app/'private').mkdir();(app/'private/.htaccess').write_text('Require all denied\n')
 env['sql']('CREATE DATABASE alphano_sample CHARACTER SET utf8mb4')
 sql=lambda q:env['sql'](q,'alphano_sample')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 base=f'http://127.0.0.1:{port}';logpath=env['folder']/'demo-php.log'
 with (env['folder']/'demo-web.log').open('w') as output:
  server=subprocess.Popen([php,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-d','display_errors=0','-d','log_errors=1','-d','error_log='+str(logpath),'-S','127.0.0.1:'+str(port),'-t',str(app),str(app/'tests/router.php')],stdout=output,stderr=subprocess.STDOUT)
  try:
   for _ in range(100):
    try:urllib.request.urlopen(base+'/install/',timeout=1);break
    except OSError:time.sleep(.05)
   admin=Client(base);guest=Client(base);initial=guest.request('/install/')[1]
   check('sample data is selected by default in the browser form','name="sample_data" value="1" checked' in initial)
   payload={**form,'_csrf':admin.csrf('/install/'),'db_name':'alphano_sample','base_url':base,'sample_data':'1','mail_transport':'disabled','currency_name':'Euro'}
   code,body,_=admin.request('/install/',payload)
   check('checked demo package installs successfully',code==200 and 'Installazione completata' in body)
   check('demo has one city four rooms one ordinary human and one ethnicity',all(sql('SELECT COUNT(*) FROM '+t)=='1' for t in ['city','world_areas','razze','rpg_races','etnie']) and sql('SELECT COUNT(*) FROM mappe')=='4' and sql('SELECT city FROM city')=='City Alphano' and sql('SELECT nome FROM razze')=='Umano' and sql('SELECT nome FROM etnie')=='Italiano')
   atlas=json.loads((app/'private/world-map.json').read_text())
   area=sql('SELECT id FROM world_areas');rooms=set(sql('SELECT id FROM mappe').splitlines())
   check('sample enables visual maps with only its fresh city and chat IDs',atlas['mode']=='visual' and set(atlas['maps'])=={'world','area-'+area} and set(atlas['maps']['world']['markers'])=={area} and set(atlas['maps']['area-'+area]['markers'])==rooms)
   for key,path in [('world','/assets/maps/world.png'),('area-'+area,'/assets/maps/city.png')]:
    check('sample map uses bundled artwork '+key,atlas['maps'][key]['image']==path and (app/path.lstrip('/')).is_file())
    with guest.opener.open(base+path) as picture:check('sample map image is served '+key,picture.status==200 and hashlib.sha256(picture.read()).hexdigest()==hashlib.sha256((app/path.lstrip('/')).read_bytes()).hexdigest())
   check('sample has no extra destination',sql('SELECT title FROM world_areas')=='City Alphano' and sql('SELECT image FROM world_areas')=='/assets/maps/city.png')
   for suffix in ['', '?area='+area]:
    html=admin.request('/mappa.php'+suffix)[1]
    match=re.search(r'<script type="application/json" data-atlas-config>(.*?)</script>',html,re.S)
    check('sample opens a ready visual map '+suffix,'world-visual' in html and match is not None and json.loads(match[1])['image']==atlas['maps']['area-'+area if suffix else 'world']['image'])
   check('demo room belongs to its city',sql('SELECT COUNT(*) FROM mappe m JOIN world_areas a ON a.id=m.area_id JOIN city c ON c.id=a.city_id')=='4')
   seed=json.loads((app/'config/demo-data.json').read_text())
   check('four example rooms match the seed names',set(sql('SELECT title FROM mappe').splitlines())=={'Piazza','Locanda','Giardino','Biblioteca'})
   for chat in seed['chats']:
    room=sql("SELECT id FROM mappe WHERE chat_key='"+chat['key']+"'")
    check('sample places and opens '+chat['name'],atlas['maps']['area-'+area]['markers'][room]==chat['marker'] and admin.request('/chat/index.php?chat='+chat['key'])[0]==200)
   check('citizen is first rank and administrator is mayor',sql("SELECT COUNT(*) FROM araldiche WHERE nome='Cittadino' AND prima='1'")=='1' and sql("SELECT COUNT(*) FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE u.id=1 AND u.sindaco='1' AND a.nome='Sindaco'")=='1')
   resources=json.loads((app/'config/demo-data.json').read_text())['materials']
   check('all 23 raw materials and gathering examples are available',sql('SELECT COUNT(*) FROM equip')=='23' and sql('SELECT COUNT(*) FROM lavori')=='23' and sql('SELECT COUNT(*) FROM chat_work_options WHERE enabled=1')=='23' and set(sql('SELECT materiaprima FROM equip').splitlines())==set(resources))
   settings=json.loads((app/'private/settings.json').read_text())
   check('demo human is active without opening registrations',settings['initial_race']==100 and settings['ethnicities_enabled'] and not settings['registration_enabled'] and sql('SELECT human_default FROM rpg_races')=='1')
   check('demo contains no extra accounts histories groups or owned items',sql('SELECT COUNT(*) FROM utenti')=='1' and all(sql('SELECT COUNT(*) FROM '+t)=='0' for t in ['chat','mail','equippg','organizzazioni','corporazioni']))
   check('demo map and chat are usable',admin.request('/mappa.php')[0]==200 and admin.request('/chat/index.php?chat=alphano_square')[0]==200)
   check('fresh installs have no shared public demo accounts',sql("SELECT COUNT(*) FROM utenti WHERE nome IN ('test','admin')")=='0' and 'Demo pubblica · AlphanoGdr V1.0' not in guest.request('/')[1])
   local=app/'config/local.php';saved_local=local.read_text();saved_settings=(app/'private/settings.json').read_bytes()
   try:
    assert 'return array (' in saved_local
    local.write_text(saved_local.replace('return array (',"return array (\n 'public_demo' => true,",1))
    for palette in ['blue','black','brown','red','purple']:
     preview=guest.request('/?preview=1&palette='+palette)[1]
     check('public demo offers request-local '+palette+' palette','data-palette="'+palette+'"' in preview and 'Demo pubblica · AlphanoGdr V1.0' in preview)
    check('palette previews preserve the stored configuration',(app/'private/settings.json').read_bytes()==saved_settings)
    invalid=guest.request('/?palette=%3Cscript%3E')[1]
    check('demo ignores unknown palettes','data-palette="blue"' in invalid)
   finally:local.write_text(saved_local)
   equipment=admin.request('/equip/equip.php?pgid=1')[1]
   check('equipment uses the bundled slot images and visible linked attribution','/assets/equipment/guanti.jpg' in equipment and '/assets/equipment/mantello.jpg' in equipment and 'Grafica ispirata da' in equipment and 'https://gothik.it/' in equipment)
   with guest.opener.open(base+'/assets/materials/ferro.webp') as picture:check('bundled material artwork is served',picture.status==200 and picture.read().startswith(b'RIFF'))
   acceptance=json.loads((app/'private/license-acceptance.json').read_text())
   check('explicit license acceptance is private and contains no password',acceptance['sha256']==hashlib.sha256((app/'LICENSE').read_bytes()).hexdigest() and form['admin_password'] not in json.dumps(acceptance) and 'ip' not in acceptance)
   sql('UPDATE meteo SET ora=MOD(HOUR(NOW())+12,24)')
   code,environment,_=admin.request('/api/experience.php')
   check('first preferences request can update stale weather from function scope',code==200 and isinstance(json.loads(environment)['environment']['temperature'],int))
   sql('DELETE FROM meteo')
   code,environment,_=admin.request('/api/experience.php')
   check('preferences can initialize empty weather without global-scope warnings',code==200 and sql('SELECT COUNT(*) FROM meteo')=='1')
   from currency_checks import exercise as currency_exercise
   currency_exercise(admin,guest,app,sql,form,check)
   from credit_policy_checks import exercise as credit_exercise
   credit_exercise(admin,guest,Client,base,app,sql,form,check)
   from world_privacy_checks import exercise as world_privacy_exercise
   world_privacy_exercise(admin,guest,app,sql,check)
   from management_checks import exercise as management_exercise
   management_exercise(admin,guest,app,sql,check)
   errors=logpath.read_text() if logpath.exists() else ''
   # The deliberately rejected corrupt bundle is logged, not exposed as PHP diagnostics.
   check('demo and repair have no PHP warnings',not any(x in errors for x in ['PHP Warning','PHP Fatal','PHP Deprecated','PHP Parse']))
   if '--serve-demo' in sys.argv:
    print('DEMO_PREVIEW',base,flush=True);print('DEMO_APP',app,flush=True);input('Enter closes the isolated demo.\n')
  finally:
   if sys.exc_info()[0] and logpath.exists():print('DEMO_DIAGNOSTICS '+logpath.read_text()[-6000:],flush=True)
   server.terminate();server.wait(timeout=10)
