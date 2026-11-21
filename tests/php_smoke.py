"""HTTP integration checks against a real Apache/PHP hosting layout.

No packages are required. Pass Apache's module directory and an optional
MySQL fixture connection. Runtime credentials and files stay in a temp dir.
"""
import argparse,base64,hashlib,http.cookiejar,json,os,re,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php');parser.add_argument('--apache',default='apache2');parser.add_argument('--modules',default='/usr/lib/apache2/modules');parser.add_argument('--ini',default='')
parser.add_argument('--source',default='');parser.add_argument('--upgrade-from',default='');parser.add_argument('--browser',action='store_true');parser.add_argument('--library-path',default='');parser.add_argument('--driver',choices=['sqlite','mysql'],default='sqlite');parser.add_argument('--mysql-host',default='127.0.0.1');parser.add_argument('--mysql-port',default='3306');parser.add_argument('--mysql-database',default='grace_php_test');parser.add_argument('--mysql-user',default='root')
args=parser.parse_args();root=Path(args.source).resolve() if args.source else Path(__file__).resolve().parents[1]
temporary=Path(tempfile.mkdtemp(prefix='grace-php-'));site=temporary/'site';site.mkdir();runtime=temporary/'apache';runtime.mkdir();(runtime/'locks').mkdir()
def copy_site(source):
    for filename in ['index.php','install.php','.htaccess','.user.ini']:
        shutil.copy2(source/filename,site/filename)
    for folder in ['app','public','images','fonts']:
        if (source/folder).is_dir():shutil.copytree(source/folder,site/folder,dirs_exist_ok=True)
    for asset in source.iterdir():
        if asset.is_file() and asset.suffix in ['.js','.css','.svg']:shutil.copy2(asset,site/asset.name)
copy_site(Path(args.upgrade_from).resolve() if args.upgrade_from else root)
env=os.environ.copy()
if args.library_path:env['LD_LIBRARY_PATH']=args.library_path+':'+env.get('LD_LIBRARY_PATH','')
with socket.socket() as reserve:reserve.bind(('127.0.0.1',0));port=reserve.getsockname()[1]
origin=f'http://127.0.0.1:{port}'
modules=Path(args.modules)
php_modules=list(modules.glob('libphp*.so'))
assert php_modules,'Apache PHP module is required'
configuration=f'''ServerRoot "{runtime}"
PidFile "{runtime/'server.pid'}"
DefaultRuntimeDir "{runtime}"
Mutex file:{runtime/'locks'} default
Listen 127.0.0.1:{port}
ServerName 127.0.0.1
LoadModule mpm_prefork_module "{modules/'mod_mpm_prefork.so'}"
LoadModule authz_core_module "{modules/'mod_authz_core.so'}"
LoadModule authz_host_module "{modules/'mod_authz_host.so'}"
LoadModule dir_module "{modules/'mod_dir.so'}"
LoadModule mime_module "{modules/'mod_mime.so'}"
LoadModule env_module "{modules/'mod_env.so'}"
LoadModule alias_module "{modules/'mod_alias.so'}"
TypesConfig /etc/mime.types
LoadModule rewrite_module "{modules/'mod_rewrite.so'}"
LoadModule php_module "{php_modules[0]}"
PHPIniDir "{Path(args.ini).parent if args.ini else '/etc/php'}"
ErrorLog "{runtime/'error.log'}"
LogLevel warn
DocumentRoot "{site}"
<Directory "{site}">
  AllowOverride All
  Require all granted
  Options FollowSymLinks
</Directory>
<FilesMatch "\\.php$">
  SetHandler application/x-httpd-php
</FilesMatch>
DirectoryIndex index.php
StartServers 1
MinSpareServers 1
MaxSpareServers 3
MaxRequestWorkers 8
'''
config_file=runtime/'httpd.conf';config_file.write_text(configuration)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):return None
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect)
server=None;checks=[]
def start():
    global server
    server=subprocess.Popen([args.apache,'-f',str(config_file),'-DFOREGROUND'],env=env,stdout=(runtime/'stdout.log').open('ab'),stderr=subprocess.STDOUT,start_new_session=True)
    for _ in range(100):
        if server.poll() is not None:raise RuntimeError('Apache failed: '+(runtime/'stdout.log').read_text()+'\n'+((runtime/'error.log').read_text() if (runtime/'error.log').exists() else ''))
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2):return
        except OSError:time.sleep(.05)
    raise RuntimeError('Apache did not start')
def stop():
    global server
    if server and server.poll() is None:server.terminate();server.wait(timeout=10)
def request(path,method='GET',data=None,cookie=None,headers=None):
    supplied={'Origin':origin,**(headers or {})}
    if cookie:supplied['Cookie']=cookie
    if isinstance(data,dict):data=json.dumps(data,ensure_ascii=False).encode();supplied['Content-Type']='application/json'
    req=urllib.request.Request(origin+path,data=data,method=method,headers=supplied)
    try:response=opener.open(req,timeout=15)
    except urllib.error.HTTPError as error:response=error
    body=response.read();return response.code,response.headers,body
def check(name,condition):
    if not condition:raise AssertionError(name)
    checks.append(name);print('PASS '+name,flush=True)
def expect(path,status=200,**kwargs):
    actual,headers,body=request(path,**kwargs)
    if actual!=status:raise AssertionError(f'{path}: expected {status}, got {actual}: '+body[:2000].decode(errors='replace'))
    return headers,body
def api(path,**kwargs):
    _,body=expect(path,**kwargs);return json.loads(body)
def multipart(fields,files=()):
    boundary='----GraceTests'+os.urandom(10).hex();parts=[]
    for key,value in fields.items():parts.extend([f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()])
    for key,name,mime,content in files:parts.extend([f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode(),content,b'\r\n'])
    parts.append(f'--{boundary}--\r\n'.encode());return b''.join(parts),{'Content-Type':'multipart/form-data; boundary='+boundary}
def upload(path,fields,files,cookie=None,status=201):
    body,headers=multipart(fields,files);return api(path,status=status,method='POST',data=body,headers=headers,cookie=cookie)
password='php-fixture-password-123';admin='gracemodels'
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z34sAAAAASUVORK5CYII=')
try:
    start()
    expect('/api/public',status=503)
    _,installer=expect('/install.php');check('installer shows Grace Models',b'Grace Models' in installer)
    key_text=(site/'install-key.php').read_text();key=re.search(r"return '([a-f0-9]+)'",key_text).group(1)
    check('installation key cannot be fetched',request('/install-key.php')[0] in [403,404])
    fields={'install_key':'incorrect','admin_login':admin,'admin_password':password,'admin_password_repeat':password,'agency_name':'Grace Models','site_url':origin,'db_driver':args.driver,'db_prefix':'test_'+os.urandom(4).hex()+'_','data_dir':str(temporary/'private-data'),'demo_mode':'on','db_host':args.mysql_host,'db_port':args.mysql_port,'db_name':args.mysql_database,'db_user':args.mysql_user,'db_password':os.environ.get('MYSQL_TEST_PASSWORD','')}
    def installation(fields):return request('/install.php',method='POST',data=urllib.parse.urlencode(fields).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
    check('installer rejects wrong ownership key',installation(fields)[0]==403)
    fields['install_key']=key;code,headers,body=installation(fields)
    if code!=303:raise AssertionError('Installation failed: '+str(code)+' '+body[:3000].decode(errors='replace')+'\n'+(runtime/'error.log').read_text())
    admin_path=headers['Location'];check('admin address is individually generated',bool(re.fullmatch(r'/s/[a-f0-9]{24}',admin_path)))
    if args.upgrade_from:
        before=api('/api/public');saved_config=(site/'config.php').read_bytes()
        previous_headers,_=expect('/api/studio/login',method='POST',data={'login':admin,'password':password})
        previous_cookie=previous_headers['Set-Cookie'].split(';')[0]
        legacy=upload('/api/inquiries',{'kind':'booking','name':'Legacy inquiry','email':'legacy@example.test','phone':'+70000000000','consent':'yes','consent_version':'2026-11-21'},[])
        previous=next(i for i in api('/api/studio/data',cookie=previous_cookie)['inquiries'] if i['id']==legacy['id'])
        copy_site(root)
        after=api('/api/studio/data',cookie=previous_cookie)
        check('upgrade preserves the active login and all models',[m['id'] for m in after['models']]==[m['id'] for m in before['models']])
        old=next(i for i in after['inquiries'] if i['id']==legacy['id'])
        check('upgrade preserves the exact consent evidence of old inquiries',old['consent'] and old['consent_version']==previous.get('consent_version','') and old['consent_at']==previous.get('consent_at',''))
        check('upgrade adds candidate fields without inventing data',old['telegram']=='' and old['birth_date']=='' and old['bust'] is None and old['file_link']=='')
        check('upgrade preserves hosting credentials and admin URL',(site/'config.php').read_bytes()==saved_config)
        check('new fields appear after automatic migration',all('bust_cup' in m for m in after['models']))
        api('/api/studio/inquiries/'+legacy['id'],method='DELETE',cookie=previous_cookie)
    check('installer locks after setup',request('/install.php')[0]==404)
    check('private files are outside document root',(temporary/'private-data').is_dir() and not (site/'private-data').exists())
    public=api('/api/public');check('Grace Models name and demo catalogue load',public['settings']['name']=='Grace Models' and len(public['models'])==12)
    check('numeric model parameters remain numeric',isinstance(public['models'][0]['shoe'],int))
    if args.browser:
        from playwright.sync_api import sync_playwright
        with sync_playwright() as automation:
            browser=automation.chromium.launch(headless=True,executable_path=shutil.which('chromium'),args=['--no-sandbox'])
            page=browser.new_page(viewport={'width':1440,'height':1000})
            errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
            page.goto(origin,wait_until='domcontentloaded');page.wait_for_selector('.hero-title');page.locator('[data-cookie-choice=necessary]').click()
            page.evaluate('() => document.fonts.ready')
            check('desktop Grace Models branding',page.locator('.hero-title').inner_text()=='GRACE MODELS')
            check('desktop has no horizontal overflow',page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
            page.screenshot(path=str(temporary/'desktop.png'))
            page.goto(origin+'/models',wait_until='domcontentloaded');page.wait_for_selector('.model-card')
            page.locator('#model-search').fill('alina');check('browser model search works',page.locator('.model-card').count()==1)
            page.locator('.reset-filters').first.click();page.locator('[data-category="new"]').click();check('browser category filters work',page.locator('.model-card').count()==4)
            page.locator('.model-card a').first.click();page.wait_for_selector('.profile-details')
            page.locator('[data-lightbox]').first.click();check('portfolio lightbox opens',page.locator('#lightbox').evaluate('(element)=>element.open'))
            page.locator('.lightbox-close').click()
            page.set_viewport_size({'width':375,'height':812});page.goto(origin,wait_until='domcontentloaded');page.wait_for_selector('.hero-title');page.evaluate('() => document.fonts.ready')
            check('mobile has no horizontal overflow',page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
            page.screenshot(path=str(temporary/'mobile.png'))
            page.locator('.menu-toggle').click();check('mobile menu opens',page.locator('.menu-toggle').get_attribute('aria-expanded')=='true')
            page.goto(origin+'/booking',wait_until='domcontentloaded');page.wait_for_selector('#inquiry-form')
            page.locator('#form-name').fill('Browser fixture');page.locator('#form-email').fill('browser@example.test');page.locator('#form-phone').fill('+70000000000');page.locator('input[name="consent"]').check()
            with page.expect_response(lambda response:response.url.endswith('/api/inquiries') and response.request.method=='POST') as submitted:
                page.locator('#inquiry-form button[type=submit]').click()
            booking_id=submitted.value.json()['id'];page.wait_for_selector('.form-success');check('browser booking form reaches PHP',True)
            page.goto(origin+admin_path,wait_until='domcontentloaded');page.locator('#login').fill(admin);page.locator('#password').fill(password);page.locator('#login-form button[type=submit]').click();page.wait_for_selector('.studio-layout')
            check('browser password login works','Grace Models' in page.locator('.studio-logo').inner_text())
            status=page.evaluate("async id => (await fetch('/api/studio/inquiries/'+id,{method:'DELETE'})).status",booking_id);check('browser test inquiry is removed',status==200)
            page.locator('[data-tab="models"]').first.click();page.locator('[data-new-model]').first.click();page.wait_for_selector('#model-form')
            page.locator('#edit-name').fill('Browser Test');page.locator('[data-editor-tab="photos"]').click()
            fixture=temporary/'browser-photo.png';fixture.write_bytes(png);page.locator('#model-photo-upload').set_input_files(str(fixture));page.wait_for_function("document.querySelector('#photo-progress').textContent.includes('загружены')")
            page.locator('[data-editor-tab="publication"]').click();page.locator('input[name="published"]').check();page.locator('#model-form button[type=submit]').click();page.wait_for_selector('#editor',state='hidden')
            row=page.locator('#model-rows tr[data-model]').filter(has_text='Browser Test');check('browser photo and profile editor save',row.count()==1)
            row.click();page.locator('[data-editor-tab="publication"]').click();page.once('dialog',lambda dialog:dialog.accept());page.locator('[data-delete-model]').click();page.wait_for_selector('#editor',state='hidden')
            check('browser profile deletion works',page.locator('#model-rows tr[data-model]').filter(has_text='Browser Test').count()==0)
            page.locator('#logout-admin').click();page.wait_for_selector('#login-form');check('browser logout returns to login',True)
            check('browser has no JavaScript exceptions',not errors)
            browser.close()
    for path in ['/models','/models/alina','/about','/contacts','/become-a-model','/booking','/news','/']:
        _,page=expect(path);check('public route '+path,b'Grace Models' in page and b'FORMA' not in page)
    _,legal=expect('/privacy');legal_text=legal.decode()
    check('full consent names the operator, address and withdrawal email',all(value in legal_text for value in ['7706548391','1047796616471','Брестская','Info@grace-models.com','10 (десяти) рабочих дней','3 (трех) лет','бронирование','grace-models.ru']))
    _,cookies=expect('/cookies');check('cookie policy explains actual cookies and local storage',all(value in cookies.decode() for value in ['grace_cookie_choice','grace_models_admin','forma-language','forma-shortlist','Настройки cookie']))
    for path in ['/cookies.js','/cookies.css','/model-options.js','/legal.css','/images/grace-models-logo.png']:expect(path)
    check('new public assets are accessible',True)
    for path in ['/config.php','/app/bootstrap.php','/app/seed.json','/public/admin.html','/.git/config','/backup/data.json']:
        check('blocked '+path,request(path)[0] in [403,404])
    expect('/api/studio/data',status=401);expect('/admin.js',status=401)
    _,login_page=expect(admin_path);check('hidden page displays password login',b'login-form' in login_page)
    check('login page includes company requisites and consent link',all(value in login_page.decode() for value in ['7706548391','1047796616471','Брестская','Info@grace-models.com','href="/privacy"']))
    expect('/api/studio/login',status=401,method='POST',data={'login':admin,'password':'incorrect'})
    headers,_=expect('/api/studio/login',method='POST',data={'login':admin,'password':password})
    cookie=headers['Set-Cookie'].split(';')[0]
    check('persistent HttpOnly cookie is issued','HttpOnly' in headers['Set-Cookie'] and 'Max-Age=34560000' in headers['Set-Cookie'] and 'SameSite=Lax' in headers['Set-Cookie'])
    headers,_=expect('/api/studio/data',cookie=cookie);check('cookie is renewed on access','Max-Age=34560000' in headers['Set-Cookie'])
    data=api('/api/studio/data',cookie=cookie);check('admin data and login are accessible',data['user']['login']==admin and len(data['models'])==12)
    https_origin=origin.replace('http://','https://',1)
    original_config=(site/'config.php').read_bytes()
    secure_headers,_=expect('/api/studio/login',method='POST',data={'login':admin,'password':password},headers={'Origin':https_origin})
    secure_cookie=secure_headers['Set-Cookie'].split(';')[0]
    check('HTTPS login works after an HTTP installation','secure' in secure_headers['Set-Cookie'].lower() and 'HttpOnly' in secure_headers['Set-Cookie'])
    check('successful HTTPS login updates only the saved scheme',(site/'config.php').read_bytes()==original_config.replace(origin.encode(),https_origin.encode(),1))
    renewed,_=expect('/api/studio/data',cookie=secure_cookie,headers={'Origin':''})
    check('HTTPS remains secure when proxy requests omit Origin','secure' in renewed['Set-Cookie'].lower())
    expect('/api/studio/login',method='POST',data={'login':admin,'password':password},headers={'Origin':origin},status=403)
    expect('/api/studio/login',method='POST',data={'login':admin,'password':password},headers={'Origin':https_origin,'Sec-Fetch-Site':'cross-site'},status=403)
    expect('/api/studio/login',method='POST',data={'login':admin,'password':password},headers={'Origin':'https://evil.example','X-Forwarded-Proto':'https'},status=403)
    check('TLS support still rejects downgrade and foreign origins',True)
    (site/'config.php').write_bytes(original_config)
    draft={**public['models'][0],'slug':'php-test-model','name':'Алёна Смирнова','bust_cup':'C','hair':'light_brown','eyes':'hazel','published':False,'demo':False}
    created=api('/api/studio/models',method='POST',data=draft,cookie=cookie,status=201);model_id=created['id']
    check('drafts are hidden from visitors',not any(m['id']==model_id for m in api('/api/public')['models']))
    draft['published']=True;api('/api/studio/models/'+model_id,method='PUT',data=draft,cookie=cookie)
    check('published model is visible',any(m['id']==model_id for m in api('/api/public')['models']))
    expect('/api/studio/models',method='POST',data=draft,cookie=cookie,status=409)
    invalid={**draft,'slug':'bad-model','height':999};expect('/api/studio/models',method='POST',data=invalid,cookie=cookie,status=400)
    expect('/api/studio/models',method='POST',data={**draft,'slug':'invalid-cup','bust_cup':'ZZ'},cookie=cookie,status=400)
    photo=upload('/api/studio/upload',{},[('file','portrait.png','image/png',png)],cookie)
    _,image=expect(photo['url']);check('uploaded image is served correctly',image==png)
    upload('/api/studio/upload',{},[('file','evil.jpg','image/jpeg',b'<?php echo 1;')],cookie,status=400)
    draft['cover']=photo['url'];draft['gallery']=[photo['url']];api('/api/studio/models/'+model_id,method='PUT',data=draft,cookie=cookie)
    stored=next(m for m in api('/api/public')['models'] if m['id']==model_id);check('gallery changes persist',stored['cover']==photo['url'] and stored['gallery']==[photo['url']])
    check('cup, hair, eyes and full name persist independently',stored['bust_cup']=='C' and stored['hair']=='light_brown' and stored['eyes']=='hazel' and stored['name']=='Алёна Смирнова' and stored['bust']==draft['bust'])
    base={'name':'Тестовая заявка','email':'fixture@example.test','phone':'+70000000000','consent':'yes','consent_version':'2026-11-21.1','message':'Проверка PHP'}
    upload('/api/inquiries',{**base,'kind':'booking','consent':'no'},[],status=400)
    upload('/api/inquiries',{**base,'kind':'booking','consent_version':'outdated'},[],status=409)
    check('server rejects absent consent and outdated consent text',not api('/api/studio/data',cookie=cookie)['inquiries'])
    booking=upload('/api/inquiries',{**base,'kind':'booking','model_id':model_id},[])
    candidate_fields={**base,'kind':'application','age':'14','city':'Тест','telegram':'@fixture_candidate','height':'177','bust':'84','bust_cup':'B','waist':'61','hips':'90','shoe':'39','hair':'light_brown','eyes':'hazel','file_link':'https://files.example.test/portfolio'}
    for required in ['name','city','telegram','age','phone','email','consent']:
        upload('/api/inquiries',{**candidate_fields,required:''},[],status=400)
    for invalid in [{'age':'13'},{'age':'14.5'},{'shoe':'999'},{'hair':'unknown'},{'file_link':'javascript:alert(1)'}]:
        upload('/api/inquiries',{**candidate_fields,**invalid},[],status=400)
    upload('/api/inquiries',candidate_fields,[('photos[]','too-large.png','image/png',b'X'*(3*1024*1024+1))],status=400)
    check('candidate required fields, age and photo limit are enforced on server',len(api('/api/studio/data',cookie=cookie)['inquiries'])==1)
    application=upload('/api/inquiries',candidate_fields,[('photos[]','front.png','image/png',png),('photos[]','profile.png','image/png',png)])
    received=api('/api/studio/data',cookie=cookie)['inquiries'];candidate=next(i for i in received if i['id']==application['id'])
    check('booking and candidate inquiries are stored',len(received)==2 and len(candidate['attachments'])==2)
    check('14-year-old candidate has all form fields saved',candidate['age']==14 and candidate['birth_date']=='' and candidate['telegram']=='@fixture_candidate' and all(candidate[key]==int(candidate_fields[key]) for key in ['height','bust','waist','hips','shoe']) and all(candidate[key]==candidate_fields[key] for key in ['bust_cup','hair','eyes','file_link']))
    for age in [15,16,17]:
        values={**base,'kind':'application','city':'Тест','telegram':'@fixture_candidate','age':str(age)}
        accepted=upload('/api/inquiries',values,[])
        stored_candidate=next(i for i in api('/api/studio/data',cookie=cookie)['inquiries'] if i['id']==accepted['id'])
        check('candidate age '+str(age)+' is accepted without optional measurements',stored_candidate['age']==age and stored_candidate['height'] is None and stored_candidate['bust'] is None)
        api('/api/studio/inquiries/'+accepted['id'],method='DELETE',cookie=cookie)
    check('consent version and acceptance time are recorded',all(i['consent'] and i['consent_version']=='2026-11-21.1' and i['consent_at']==i['created_at'] for i in received))
    api('/api/studio/settings',method='PUT',data={'applications_enabled':False},cookie=cookie)
    check('applications setting is a stored boolean',api('/api/public')['settings']['applications_enabled'] is False)
    upload('/api/inquiries',{**base,'kind':'application','age':'21','height':'177'},[],status=403)
    off_booking=upload('/api/inquiries',{**base,'kind':'booking'},[])
    api('/api/studio/inquiries/'+off_booking['id'],method='DELETE',cookie=cookie)
    check('closed applications block candidates but keep bookings working',len(api('/api/studio/data',cookie=cookie)['inquiries'])==2)
    api('/api/studio/settings',method='PUT',data={'applications_enabled':True},cookie=cookie)
    check('applications can be enabled again',api('/api/public')['settings']['applications_enabled'] is True)
    key=candidate['attachments'][0]['key'];private_path='/api/studio/files/'+key
    expect(private_path,status=401);expect('/media/'+key,status=404)
    headers,attachment=expect(private_path,cookie=cookie);check('candidate files stay private and downloadable',attachment==png and 'attachment;' in headers.get('Content-Disposition',''))
    api('/api/studio/inquiries/'+application['id'],method='PUT',data={'status':'review','internal_note':'Заметка только команды'},cookie=cookie)
    public_text=json.dumps(api('/api/public'),ensure_ascii=False);check('public API does not reveal contacts or notes',all(value not in public_text for value in ['fixture@example.test','Заметка только команды','@fixture_candidate','files.example.test/portfolio']))
    def parallel_booking(number):return upload('/api/inquiries',{**base,'kind':'booking','company':'Concurrent '+str(number)},[])
    with ThreadPoolExecutor(max_workers=4) as pool:parallel=list(pool.map(parallel_booking,range(4)))
    check('concurrent submissions are not lost',len(api('/api/studio/data',cookie=cookie)['inquiries'])==6)
    api('/api/studio/inquiries/'+application['id'],method='DELETE',cookie=cookie)
    check('deleting inquiries also removes private files',not (temporary/'private-data/uploads'/key).exists())
    story={'title':'PHP новость','summary':'Кратко','body':'Текст','published':False,'published_at':'2026-11-14','image':photo['url']}
    api('/api/studio/news',method='POST',data=story,cookie=cookie);private_story=next(n for n in api('/api/studio/data',cookie=cookie)['news'] if n['title']=='PHP новость')
    check('news drafts are private',not any(n['title']=='PHP новость' for n in api('/api/public')['news']))
    story['published']=True;api('/api/studio/news/'+private_story['id'],method='PUT',data=story,cookie=cookie)
    check('news publication works',any(n['title']=='PHP новость' for n in api('/api/public')['news']))
    api('/api/studio/news/'+private_story['id'],method='DELETE',cookie=cookie)
    api('/api/studio/settings',method='PUT',data={'name':'Grace Models','city':'Проверенный город','telegram':'javascript:alert(1)'},cookie=cookie)
    settings=api('/api/public')['settings'];check('settings persist and unsafe links are removed',settings['city']=='Проверенный город' and settings['telegram']=='')
    expect('/api/studio/settings',method='PUT',data={'name':'Wrong'},cookie=cookie,headers={'Origin':'https://other.example'},status=403)
    headers,body=expect('/api/studio/export',cookie=cookie);backup=json.loads(body);check('backup export excludes credentials','admin_hash' not in body.decode() and 'token_hash' not in body.decode() and backup['settings']['name']=='Grace Models')
    stop();start()
    check('session and data survive server restart',api('/api/studio/data',cookie=cookie)['settings']['city']=='Проверенный город')
    other_headers,_=expect('/api/studio/login',method='POST',data={'login':admin,'password':password});other=other_headers['Set-Cookie'].split(';')[0]
    expect('/api/studio/logout',method='POST',cookie=cookie,data={});expect('/api/studio/data',cookie=cookie,status=401)
    check('logging out one device preserves another device',api('/api/studio/data',cookie=other)['user']['login']==admin)
    for m in api('/api/studio/data',cookie=other)['models']:api('/api/studio/models/'+m['id'],method='DELETE',cookie=other)
    check('deleted demo models never reappear',api('/api/public')['models']==[])
    for _ in range(6):expect('/api/studio/login',method='POST',data={'login':admin,'password':'wrong'},status=401)
    expect('/api/studio/login',method='POST',data={'login':admin,'password':password},status=429)
    check('password brute force is rate-limited',True)
    print(json.dumps({'backend':args.driver,'checks':len(checks),'passed':True,'php':args.php,'artifacts_directory':str(temporary)}),flush=True)
except Exception:
    if (runtime/'error.log').exists():print((runtime/'error.log').read_text()[-6000:],flush=True)
    raise
finally:stop()
