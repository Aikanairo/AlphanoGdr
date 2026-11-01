"""Real HTTP settings/uploads for privacy and proportional maps in the private demo."""
import json,re,uuid,urllib.request,urllib.error,hashlib

def exercise(admin,guest,app,sql,check):
 def multipart(path,values,file_field=None,filename='map.png',data=None,mime='image/png'):
  boundary='atlas-'+uuid.uuid4().hex;parts=[]
  for k,v in values.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
  if file_field:parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+data+b'\r\n')
  parts.append(f'--{boundary}--\r\n'.encode());request=urllib.request.Request(admin.base+path,data=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
  try:
   with admin.opener.open(request) as r:return r.status,r.read().decode()
  except urllib.error.HTTPError as e:return e.code,e.read().decode()
 def revision(name):
  p=app/'private'/f'{name}.json';return hashlib.sha256(p.read_bytes() if p.exists() else b'').hexdigest()
 check('privacy and map editors reject anonymous visitors',guest.request('/admin/privacy.php')[0]==403 and guest.request('/admin/world-map.php')[0]==403)
 check('privacy and map editors require CSRF',admin.request('/admin/privacy.php',{})[0]==403 and admin.request('/admin/world-map.php',{})[0]==403)
 token=admin.csrf('/admin/privacy.php');code,body,_=admin.request('/admin/privacy.php')
 check('privacy management offers field-specific examples and public previews',code==200 and 'data-copy-example="retention"' in body and '/legal/?type=cookies' in body)
 fields=dict(_csrf=token,revision=revision('privacy-policy'),controller='Gestore di esempio <script>alert(1)</script>',email='privacy@example.invalid',address='Indirizzo di esempio',purposes='Account e chat di prova',basis='Erogazione del servizio',retention='Log per 30 giorni, da verificare.',recipients='Hosting di esempio',transfers='Da verificare',rights='Contattare la gestione.',cookies_extra='Nessun servizio extra nella prova.')
 check('privacy rejects invalid contact email',admin.request('/admin/privacy.php',{**fields,'email':'invalid'})[0]==422)
 code,body,_=admin.request('/admin/privacy.php',fields)
 check('privacy policy publishes real fields while escaping all authored text',code==200 and 'Informative salvate' in body and 'Account e chat di prova' in guest.request('/legal/?type=privacy')[1] and '&lt;script&gt;alert(1)&lt;/script&gt;' in guest.request('/legal/?type=privacy')[1])
 check('stale privacy forms cannot overwrite another edit',admin.request('/admin/privacy.php',fields)[0]==422)
 cookies=guest.request('/legal/?type=cookies')[1]
 check('cookie policy describes actual cookie lifetime and optional media',all(s in cookies for s in ['alphano_privacy','180 giorni','alphano_*','facoltativi','Nessun servizio extra']))
 check('legacy cookie policy links resolve to the cookie page','Cookie e strumenti del browser' in guest.request('/legal/?document=cookies')[1])
 policy=app/'private/privacy-policy.json';check('privacy editor storage is private and does not contain credentials',policy.stat().st_mode&0o777==0o600 and 'password' not in json.dumps(json.loads(policy.read_text())))
 original_home=guest.request('/')[1];check('default supplied logo is kept in the hero with a management workspace','/assets/alphanogdr-logo.png' in original_home and 'portal-hero-logo' in original_home and 'data-management-shell' in admin.request('/admin/world-map.php')[1])
 with guest.opener.open(admin.base+'/assets/alphanogdr-logo.png') as r:check('provided logo is served as an actual local PNG',r.status==200 and r.read()[:8]==b'\x89PNG\r\n\x1a\n')
 room_count=sql('SELECT COUNT(*) FROM mappe')
 area=int(sql('SELECT id FROM world_areas LIMIT 1'));room=int(sql('SELECT id FROM mappe WHERE chat=1 LIMIT 1'));path='/admin/world-map.php';token=admin.csrf(path)
 def payload(markers,mode='visual'):return dict(_csrf=token,revision=revision('world-map'),mode=mode,markers=json.dumps(markers))
 points={str(area):dict(x=33.25,y=61.75,style='image')}
 art=(app/'assets/headers/panorama.webp').read_bytes() if (app/'assets/headers/panorama.webp').exists() else next((app/'assets/headers').glob('*.webp')).read_bytes()
 code,body=multipart(path,payload(points),'map_image','world.webp',art,'image/webp');check('world background and circular city marker save through a real upload',code==200 and 'Mappa salvata' in body)
 state=json.loads((app/'private/world-map.json').read_text());check('coordinates are stored as image percentages without viewport dimensions',state['maps']['world']['markers'][str(area)]==dict(x=33.25,y=61.75,style='image'))
 check('interactive map has no lower destinations or navigation strip','atlas-all-destinations' not in admin.request('/mappa.php')[1] and 'atlas-bottom' not in admin.request('/mappa.php')[1])
 page=admin.request('/mappa.php')[1];check('public map keeps cover zoom without a letterbox fit control','data-atlas-action="fit"' not in page and 'data-atlas-action="cover"' in page);match=re.search(r'<script type="application/json" data-atlas-config>(.*?)</script>',page,re.S);check('visual mode renders shared image and marker coordinates',match is not None and json.loads(match[1])['markers'][0]['x']==33.25 and 'atlas-toolbar' in page)
 check('card mode remains available without losing visual placements','world-card' in admin.request('/mappa.php?view=cards')[1] and (app/'private/world-map.json').stat().st_mode&0o777==0o600)
 check('out of bounds markers are rejected',admin.request(path,payload({str(area):dict(x=101,y=50,style='label')}))[0]==422)
 check('cross-map or nonexistent marker IDs are rejected',admin.request(path,payload({'999999':dict(x=50,y=50,style='label')}))[0]==422)
 old=payload(points);check('valid text-only city marker saves',admin.request(path,payload({str(area):dict(x=33.25,y=61.75,style='label')}))[0]==200)
 check('stale map editors cannot overwrite newer coordinates',admin.request(path,old)[0]==422)
 sub='/admin/world-map.php?area='+str(area);subpoints={str(room):dict(x=67.5,y=42.125,style='image')};code,body=multipart(sub,payload(subpoints),'map_image','city.webp',art,'image/webp')
 check('each city has an independent background and positioned chat markers',code==200 and 'Mappa salvata' in body and json.loads((app/'private/world-map.json').read_text())['maps']['area-'+str(area)]['markers'][str(room)]['x']==67.5)
 code,body,_=admin.request('/mappa.php?area='+str(area));check('city map points to the real authorized chat',code==200 and 'alphano_square' in body and 'data-atlas-config' in body)
 before=(app/'private/world-map.json').read_bytes();code,_=multipart(path,payload(points),'map_image','bad.php',b'<?php echo 1;?>','image/png');check('executable uploads cannot become map backgrounds',code==422 and (app/'private/world-map.json').read_bytes()==before)
 code,_,_=admin.request(path,{**payload(points),'remove_image':'1'});check('missing world background falls back to cards without deleting destinations',code==200 and 'world-card' in admin.request('/mappa.php')[1] and sql('SELECT COUNT(*) FROM mappe')==room_count)
 # Restore a useful visual preview, keeping this fake world isolated from real services.
 multipart(path,payload(points),'map_image','world.webp',art,'image/webp')
 check('unknown areas do not open an editor or a public map',admin.request('/admin/world-map.php?area=999999')[0]==404 and admin.request('/mappa.php?area=999999')[0]==404)
 # Test a city already in the game but not yet on the map.
 sql("INSERT INTO city(city,statuto,newstatuto) VALUES('Città di prova non collegata','','')");city=int(sql('SELECT MAX(id) FROM city'))
 check('existing cities can be added without recreating the city record',admin.request(path,dict(_csrf=token,action='add_city',city_id=city))[0]==200 and sql('SELECT COUNT(*) FROM world_areas WHERE city_id='+str(city))=='1')
 check('adding the same existing city twice does not duplicate its destination',admin.request(path,dict(_csrf=token,action='add_city',city_id=city))[0]==200 and sql('SELECT COUNT(*) FROM world_areas WHERE city_id='+str(city))=='1')
 sql('DELETE FROM world_areas WHERE city_id='+str(city));sql('DELETE FROM city WHERE id='+str(city))
