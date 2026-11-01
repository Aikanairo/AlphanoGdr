"""Behavioural checks run inside smoke.py's private PHP/MySQL installation."""
import base64,hashlib,json,re,urllib.request,urllib.error,uuid

def exercise(env,admin,player,guest,room_key,check):
 app=env['app'];sql=env['sql'];path='/admin/appearance.php';token=admin.csrf(path)
 def revision():
  p=app/'private/appearance.json';return hashlib.sha256(p.read_bytes() if p.exists() else b'').hexdigest()
 def save(section,target='',**values):
  return admin.request(path+'?section='+section+'&target='+target,dict(_csrf=token,revision=revision(),section=section,target=target,**values))
 def multipart(section,field,data,filename='image.png',mime='image/png',target=''):
  boundary='alphano-'+uuid.uuid4().hex;parts=[]
  values=dict(_csrf=token,revision=revision(),section=section,target=target)
  for k,v in values.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
  parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+data+b'\r\n');parts.append(f'--{boundary}--\r\n'.encode())
  req=urllib.request.Request(env['base']+path+'?section='+section+'&target='+target,data=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
  try:
   with admin.opener.open(req) as response:return response.status,response.read().decode()
  except urllib.error.HTTPError as e:return e.code,e.read().decode()
 check('appearance requires full admin',guest.request(path)[0]==403 and player.request(path)[0]==403 and admin.request(path)[0]==200)
 check('engine version is shown on homepage and legal page','V1.0' in guest.request('/')[1] and 'V1.0' in guest.request('/legal/?type=credits')[1])
 check('removed introductory cards are absent','portal-chapters' not in guest.request('/')[1] and 'Una parola alla volta' not in guest.request('/')[1])
 home=guest.request('/')[1]
 check('public homepage includes the author repository and linked example',all(x in home for x in ['Programmato da','Alfano Gaetano','https://github.com/Aikanairo','https://github.com/Aikanairo/AlphanoGdr','https://gothik.it/','/legal/?type=credits']))
 credits=guest.request('/legal/?type=credits')[1]
 check('public legal page explains author attribution separately from operation',all(x in credits for x in ['Alfano Gaetano','AGPL-3.0-only','gestore','https://gothik.it/','controllo','ripristino']))
 failure=type(guest)(env['base']);bad_password='Incorrect_Test_Password_2026'
 code,body,response=failure.request('/checkuser.php',dict(_csrf=failure.csrf('/'),username='DemoAdmin',password=bad_password))
 check('invalid password returns a visible styled message',code==200 and 'role="alert"' in body and 'Accesso non riuscito' in body and 'portal-login-error' in body and response.geturl().endswith('/?login=failed#accesso'))
 check('failed login retains only escaped character name and no password','value="DemoAdmin"' in body and bad_password not in body and bad_password not in response.geturl() and 'type="password" name="password" value=' not in body)
 check('invalid password never authenticates',failure.request('/admin/settings.php')[0]==403)
 check('login error is one-use and does not reappear on refresh','id="portal-login-error"' not in failure.request('/')[1])
 code,unknown,_=failure.request('/checkuser.php',dict(_csrf=failure.csrf('/'),username='<script>bad</script>',password=bad_password))
 check('unknown characters use the same message and escape input',code==200 and 'Personaggio o password non validi.' in unknown and '&lt;script&gt;bad&lt;/script&gt;' in unknown and '<script>bad</script>' not in unknown)
 check('appearance mutations require CSRF',admin.request(path,dict(section='defaults',target=''))[0]==403)
 artwork=json.loads((app/'config/header-art.json').read_text());art_paths={a['path'] for a in artwork['assets']}
 default_home=guest.request('/')[1]
 check('homepage includes a local scenic hero and clear access',all(x in default_home for x in ['/assets/headers/panorama.webp','portal-masthead','portal-access','fetchpriority="high"','portal-registration','/checkuser.php','/adduser.php']))
 catalog=json.loads((app/'config/appearance-pages.json').read_text())
 headers=[json.loads(guest.request('/api/appearance.php?page='+urllib.parse.quote(url,safe=''))[1])['page']['header'] for _,_,url,_ in catalog]
 check('every catalogue page has a bundled default header',len(headers)>100 and all(h in art_paths and (app/h.lstrip('/')).is_file() for h in headers))
 for url,key in [('/manuali/','library'),('/posta/showmsg.php','messages'),('/corporazioni/catalogo.php','workshop'),('/chat/?chat='+room_key,'forest'),('/admin/setup.php','observatory')]:
  check('appropriate default header '+url,json.loads(guest.request('/api/appearance.php?page='+urllib.parse.quote(url,safe=''))[1])['page']['header']=='/assets/headers/'+key+'.webp')
 check('header catalogue shows actual default previews','/assets/headers/' in admin.request(path+'?section=pages')[1])
 check('page catalog includes all configured places',all(name in admin.request(path+'?section=pages')[1] for name in ['Homepage','Piazza','Locanda','Catalogo','Scacchi e dama','Template e homepage']))
 check('submenu catalog includes individually editable entries',all(name in admin.request(path+'?section=items')[1] for name in ['Bacheca Org','Admin Stato Soc.','Tesoreria corporazione','Cronaca e notifiche']))
 check('unknown appearance target is rejected',save('pages','missing',background='/assets/placeholder.svg')[0]==422)
 check('invalid palette is rejected',save('defaults',theme='unsafe',sidebar_position='left',header_position='bottom')[0]==422)
 check('unsafe image path is rejected',save('groups','gioco',image='https://external.invalid/image.svg')[0]==422)
 check('image traversal is rejected',save('groups','gioco',image='/uploads/../private/settings.json')[0]==422)
 check('SVG upload is rejected',multipart('groups','image',b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','icon.svg','image/svg+xml','gioco')[0]==422)
 png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6xN8AAAAASUVORK5CYII=')
 check('bitmap image upload works without GD',multipart('groups','image',png,'pretend.php','image/png','gioco')[0]==200)
 appearance=json.loads((app/'private/appearance.json').read_text());image=appearance['groups']['gioco']['image']
 check('upload receives a generated bitmap extension',image.startswith('/uploads/appearance/') and image.endswith('.png') and (app/image.lstrip('/')).read_bytes()==png)
 status=json.loads(admin.request('/api/status.php')[1]);check('main menu image is delivered by status API',status['menuGroups']['gioco']['image']==image)
 item=next(i for i in status['menu'] if i['label']=='Mappa');key=item['appearance_key']
 check('submenu image can be saved independently',save('items',key,image=image)[0]==200 and next(i for i in json.loads(admin.request('/api/status.php')[1])['menu'] if i['label']=='Mappa')['image']==image)
 check('appearance rejects stale concurrent edits',admin.request(path+'?section=groups&target=gioco',dict(_csrf=token,revision='0'*64,section='groups',target='gioco',image=''))[0]==422 and json.loads((app/'private/appearance.json').read_text())['groups']['gioco']['image']==image)
 check('page background and header are stored',save('pages','profili',background=image,header=image)[0]==200)
 html=admin.request('/profili/?Id=1')[1];check('page images are applied to real profile', 'data-custom-header="1"' in html and 'data-custom-background="1"' in html and 'url("'+image+'")' in html)
 check('palette and bottom layout are configurable together',save('defaults',theme='purple',sidebar_position='left',header_position='bottom',background='',header='')[0]==200)
 for page in ['/main.php','/regole/','/profili/?Id=1','/chat/index.php?chat='+room_key,'/admin/settings.php']:
  code,html,_=admin.request(page);check('shared purple palette '+page,code==200 and 'data-palette="purple"' in html and '/assets/theme.php?v=' in html)
 public=json.loads(guest.request('/api/appearance.php?page=%2Fprofili%2F')[1]);check('public appearance endpoint contains presentation only',set(public)=={'theme','sidebar','header','page','stylesheet'} and public['theme']=='purple' and public['page']['header']==image)
 old=public['stylesheet'];css=app/'css/theme/chat.css';original=css.read_text();css.write_text(original+'\n/* cache revision test */');check('editing any CSS module invalidates the whole bundle',json.loads(guest.request('/api/appearance.php')[1])['stylesheet']!=old);css.write_text(original)
 bundle=guest.request(old)[1];check('CSS bundle has no stale child imports','@import url(' not in bundle and '--custom-header' in bundle)
 section=sql('SELECT id FROM sezioniregole ORDER BY id LIMIT 1')
 if not section:sql("INSERT INTO sezioniregole(nome,pos) VALUES('Guide di prova',1)");section=sql('SELECT MAX(id) FROM sezioniregole')
 sql(f"INSERT INTO regole(sezione,pos,oggetto,spiegazione,audience,summary) VALUES({section},1,'Guida pubblica in home','Testo pubblico.','public','Una guida per tutti.'),({section},2,'Guida riservata esclusa','Testo riservato.','admin','Riservato.')")
 public_id=sql("SELECT id FROM regole WHERE oggetto='Guida pubblica in home'");private_id=sql("SELECT id FROM regole WHERE oggetto='Guida riservata esclusa'")
 check('staff manuals cannot be pinned publicly',save('home',headline='Portale di prova',intro='Presentazione.',**{'manuals[]':[private_id]})[0]==422)
 check('homepage identity and public manuals are configurable',save('home',headline='Portale <script>non eseguibile</script>',intro='Presentazione della comunità.',logo=image,banner=image,background=image,**{'manuals[]':[public_id]})[0]==200)
 code,feedback,_=guest.request('/admin/settings.php')
 check('permission errors use the shared visual feedback card',code==403 and 'account-recovery-card' in feedback and 'Accesso da verificare' in feedback)
 code,feedback,_=guest.request('/install/',{})
 check('closed installer uses styled feedback without reopening setup',code==404 and 'Installazione già completata' in feedback and 'account-recovery-card' in feedback)
 recovery=guest.request('/recuperopasswd.php')[1]
 check('direct recovery has a complete styled card and explicit POST target',all(x in recovery for x in ['account-recovery-page','account-recovery-card','action="/recuperopasswd.php"','name="_csrf"']))
 invalid=guest.request('/recuperopasswd.php?token='+'0'*64)[1]
 check('expired reset links keep the styled card and safe feedback','account-recovery-card' in invalid and 'già stato utilizzato' in invalid and 'role="alert"' in invalid)
 check('homepage includes accessible account assistance modal','id="portal-account-modal"' in guest.request('/')[1] and 'aria-label="Assistenza accesso"' in guest.request('/')[1])
 home=guest.request('/')[1];check('homepage shows logo banner and pinned guide',all(x in home for x in ['portal-logo','portal-banner','Guida pubblica in home','/manuali/?section='+public_id,'&lt;script&gt;non eseguibile&lt;/script&gt;']) and 'Guida riservata esclusa' not in home)
 sql("UPDATE regole SET audience='admin' WHERE id="+public_id);check('a manual made private disappears from homepage','Guida pubblica in home' not in guest.request('/')[1]);sql("UPDATE regole SET audience='public' WHERE id="+public_id)
 # Reproduce the browser JSON payload, including default automatic addressing with AI off.
 ptoken=player.csrf('/main.php');payload=dict(token=ptoken,msg='// Verifica del percorso JSON predefinito.',requestId=str(uuid.uuid4()),ai_auto=True,ai_request=False,ai_npc_id=0,rpg_ability_id=0,rpg_item_instance=0,rpg_item_operation=None,rpg_item_provider=None,work_id=0,work_quote='',work_confirmed=False)
 req=urllib.request.Request(env['base']+'/chat/messages.php?chat='+room_key,data=json.dumps(payload).encode(),headers={'Content-Type':'application/json'})
 with player.opener.open(req) as response:result=json.loads(response.read());check('default browser chat payload persists a message with AI off',response.status==200 and result['ok'] and result['id']>0)
 check('chat page has explicit connection feedback','chat-connection-status' in player.request('/chat/index.php?chat='+room_key)[1])
 check('AI disclosure is hidden when AI is disabled','data-ai-disclosure' not in player.request('/chat/index.php?chat='+room_key)[1])
 check('bundled menu artwork is selectable',save('groups','gioco',preset_image='/assets/default-nav-compass.svg')[0]==200 and json.loads(admin.request('/api/status.php')[1])['menuGroups']['gioco']['image']=='/assets/default-nav-compass.svg')
 check('default artwork picker rejects unlisted assets',save('groups','gioco',preset_image='/assets/placeholder.svg')[0]==422)
 check('bundled scenic header can replace an individual page',save('pages','posta',preset_header='/assets/headers/library.webp')[0]==200 and json.loads(guest.request('/api/appearance.php?page=%2Fposta%2F')[1])['page']['header']=='/assets/headers/library.webp')
 check('removing a header override restores its scenic default',save('pages','posta',remove_header='1')[0]==200 and json.loads(guest.request('/api/appearance.php?page=%2Fposta%2F')[1])['page']['header']=='/assets/headers/messages.webp')
 check('new chat destinations inherit the section header',save('pages','chat',preset_header='/assets/headers/community.webp')[0]==200 and json.loads(guest.request('/api/appearance.php?page='+urllib.parse.quote('/chat/?chat='+room_key,safe=''))[1])['page']['header']=='/assets/headers/community.webp')
 check('one chat can override its section header',save('pages','chat-'+room_key,preset_header='/assets/headers/games.webp')[0]==200 and json.loads(guest.request('/api/appearance.php?page='+urllib.parse.quote('/chat/?chat='+room_key,safe=''))[1])['page']['header']=='/assets/headers/games.webp')
 check('removing a chat override restores section inheritance',save('pages','chat-'+room_key,remove_header='1')[0]==200 and json.loads(guest.request('/api/appearance.php?page='+urllib.parse.quote('/chat/?chat='+room_key,safe=''))[1])['page']['header']=='/assets/headers/community.webp')
 save('pages','chat',remove_header='1')
 check('shared header override takes priority over bundled defaults',save('defaults',theme='purple',sidebar_position='left',header_position='bottom',preset_header='/assets/headers/guilds.webp')[0]==200 and json.loads(guest.request('/api/appearance.php?page=%2Fcitta%2F')[1])['page']['header']=='/assets/headers/guilds.webp')
 save('defaults',theme='blue',sidebar_position='right',header_position='top',remove_header='1')
 check('homepage library illustration is replaceable',save('home',headline='Alphano Demo',intro='Un mondo da inventare insieme.',preset_scene_library='/assets/headers/chronicles.webp')[0]==200 and '/assets/headers/chronicles.webp' in guest.request('/')[1])
 # Keep a readable demo identity for browser inspection.
 save('home',headline='Alphano Demo',intro='Un mondo da inventare insieme.',logo='',banner='',background='',**{'manuals[]':[public_id]})
