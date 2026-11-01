"""Verify configurable terminology through installation and the real settings handler."""
import html,json

def exercise(admin,guest,app,sql,form,check):
 settings=app/'private/settings.json'
 check('installer persists the chosen currency name',json.loads(settings.read_text())['currency_name']=='Euro')
 for path in ['/main.php','/trasferimenti/','/profili/?Id=1','/classifica/?categoria=corone','/admin/ferite.php']:
  code,body,_=admin.request(path)
  check('configured currency is visible on '+path,code==200 and 'Euro' in body and '>Corone<' not in body)
 body=admin.request('/main.php')[1]
 check('client receives an escaped currency label before page scripts','name="alphano-currency" content="Euro"' in body and '/assets/currency.js' in body)
 before=sql('SELECT id,denari FROM utenti ORDER BY id')
 name="Monete d'oro & Argento"
 payload={**form,'_csrf':admin.csrf('/admin/settings.php'),'base_url':admin.base,'currency_name':name,'registration_enabled':'','mail_transport':'disabled'}
 check('only the manager can change the currency name',guest.request('/admin/settings.php',payload)[0]==403)
 check('currency settings require CSRF',admin.request('/admin/settings.php',{**payload,'_csrf':'bad'})[0]==403)
 for invalid in ['', 'x'*61, '<script>alert(1)</script>', 'Euro\nAltro']:
  check('currency settings reject invalid text '+repr(invalid[:20]),admin.request('/admin/settings.php',{**payload,'currency_name':invalid})[0]==422 and json.loads(settings.read_text())['currency_name']=='Euro')
 check('manager can save punctuation and symbols without changing money',admin.request('/admin/settings.php',payload)[0]==200 and json.loads(settings.read_text())['currency_name']==name and sql('SELECT id,denari FROM utenti ORDER BY id')==before)
 for path in ['/main.php','/trasferimenti/','/profili/?Id=1','/classifica/?categoria=corone']:
  code,body,_=admin.request(path)
  check('currency text is escaped without breaking markup on '+path,code==200 and html.escape(name,quote=True).replace("&#x27;","&#039;") in body)
 check('renaming currency preserves ranking protocol keys','categoria=corone' in admin.request('/classifica/?categoria=corone')[1])
 # Leave the browser preview with an ordinary customized name.
 check('currency can be changed again without reinstalling',admin.request('/admin/settings.php',{**payload,'currency_name':'Sterline'})[0]==200 and json.loads(settings.read_text())['currency_name']=='Sterline')
