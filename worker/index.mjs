import { seedModels, seedNews, defaultSettings } from './seed.mjs';
import { getAdminSession, loginAdmin, logoutAdmin, renewAdminSession } from './admin-auth.mjs';

export const ADMIN_PATH = '/s/9f27c6a40be34d58a17e6c92';
const modelFields = ['slug','name','name_en','gender','category','city','height','bust','waist','hips','shoe','hair','eyes','cover','gallery','bio','bio_en','published','featured','demo','sort_order'];
const newsFields = ['title','title_en','summary','summary_en','body','body_en','image','published','published_at'];
const statuses = ['new','review','contacted','accepted','rejected','archived'];
const json = (data,status=200) => new Response(JSON.stringify(data),{status,headers:{'content-type':'application/json; charset=utf-8','cache-control':'no-store','x-content-type-options':'nosniff'}});
const stamp = () => new Date().toISOString();
const clean = (value,max=400) => String(value ?? '').trim().slice(0,max);
const error = (message,status=400) => Object.assign(new Error(message),{status});

async function requireAdmin(request,env) {
  const session=await getAdminSession(request,env);
  if(!session)throw error('Войдите в панель администратора.',401);
  return session;
}
function sameOrigin(request) {
  const origin=request.headers.get('origin');
  if(origin && origin!==new URL(request.url).origin) throw error('Недопустимый источник запроса.',403);
}
function mediaUrl(value) {
  const v=clean(value,1200);
  if(v.startsWith('/images/') || v.startsWith('/media/')) return v;
  if(v.startsWith('https://')) return v;
  return '';
}
export function validateModel(input) {
  const out={};
  out.name=clean(input.name,100); if(!out.name) throw error('Укажите имя модели.');
  out.slug=clean(input.slug,100).toLowerCase(); if(!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(out.slug)) throw error('Адрес анкеты: латинские буквы, цифры и дефисы.');
  out.name_en=clean(input.name_en,100); out.city=clean(input.city,100);
  out.gender=['women','men'].includes(input.gender)?input.gender:'women';
  out.category=['main','new','international','direct'].includes(input.category)?input.category:'main';
  const bounds={height:[100,230],bust:[40,160],waist:[35,160],hips:[40,160],shoe:[20,55],sort_order:[0,9999]};
  for(const [k,[min,max]] of Object.entries(bounds)){
    const n=Number(input[k]??(k==='sort_order'?0:k==='height'?175:k==='shoe'?39:80));
    if(!Number.isFinite(n)||n<min||n>max) throw error('Проверьте поле «'+k+'».'); out[k]=Math.round(n);
  }
  out.hair=['blonde','brown','black','red','other'].includes(input.hair)?input.hair:'brown';
  out.eyes=['blue','brown','green','gray','other'].includes(input.eyes)?input.eyes:'brown';
  out.cover=mediaUrl(input.cover);
  out.gallery=JSON.stringify((Array.isArray(input.gallery)?input.gallery:[]).slice(0,24).map(mediaUrl).filter(Boolean));
  out.bio=clean(input.bio,5000);out.bio_en=clean(input.bio_en,5000);
  for(const k of ['published','featured','demo']) out[k]=input[k]?1:0;
  if(out.published&&!out.cover) throw error('Добавьте обложку перед публикацией.');
  return out;
}
function modelRow(row){return {...row,gallery:JSON.parse(row.gallery||'[]'),published:Boolean(row.published),featured:Boolean(row.featured),demo:Boolean(row.demo)};}
async function initialize(env) {
  if(!env.DB) throw error('База данных еще не подключена.',503);
  const existing=await env.DB.prepare("SELECT value FROM settings WHERE key='initialized'").first();
  if(existing) return;
  const now=stamp();
  const statements=[];
  for(const m of seedModels){
    const v=validateModel(m);const keys=['id',...modelFields,'created_at','updated_at'];
    statements.push(env.DB.prepare(`INSERT OR IGNORE INTO models (${keys.join(',')}) SELECT ${keys.map(()=>'?').join(',')} WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key='initialized')`).bind(m.id,...modelFields.map(k=>v[k]),now,now));
  }
  for(const n of seedNews){
    const keys=['id',...newsFields,'created_at','updated_at'];
    statements.push(env.DB.prepare(`INSERT OR IGNORE INTO news (${keys.join(',')}) SELECT ${keys.map(()=>'?').join(',')} WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key='initialized')`).bind(n.id,...newsFields.map(k=>n[k]),now,now));
  }
  statements.push(env.DB.prepare("INSERT OR IGNORE INTO settings (key,value) VALUES ('agency',?)").bind(JSON.stringify(defaultSettings)));
  statements.push(env.DB.prepare("INSERT OR IGNORE INTO settings (key,value) VALUES ('initialized','1')"));
  await env.DB.batch(statements);
}
async function getSettings(env){ const row=await env.DB.prepare("SELECT value FROM settings WHERE key='agency'").first();return {...defaultSettings,...JSON.parse(row?.value||'{}')}; }
async function audit(env,action,subject){await env.DB.prepare('INSERT INTO activity (id,action,subject,created_at) VALUES (?,?,?,?)').bind(crypto.randomUUID(),action,clean(subject,180),stamp()).run();}
async function publicData(env){
  const [models,news,settings]=await Promise.all([env.DB.prepare('SELECT * FROM models WHERE published=1 ORDER BY sort_order,created_at').all(),env.DB.prepare('SELECT * FROM news WHERE published=1 ORDER BY published_at DESC').all(),getSettings(env)]);
  return {models:models.results.map(modelRow),news:news.results,settings};
}
function fileType(bytes,mime){
  if(mime==='image/jpeg'&&bytes[0]===255&&bytes[1]===216&&bytes[2]===255)return 'jpg';
  if(mime==='image/png'&&bytes[0]===137&&bytes[1]===80&&bytes[2]===78&&bytes[3]===71)return 'png';
  if(mime==='image/webp'&&String.fromCharCode(...bytes.slice(0,4))==='RIFF'&&String.fromCharCode(...bytes.slice(8,12))==='WEBP')return 'webp';
  if(mime==='application/pdf'&&String.fromCharCode(...bytes.slice(0,5))==='%PDF-')return 'pdf';
  return null;
}
async function putFile(env,file,prefix,allowPdf=false){
  if(!env.BUCKET) throw error('Хранилище фотографий еще не подключено.',503);
  if(file.size>5*1024*1024)throw error('Максимальный размер одного файла — 5 МБ.');
  const bytes=new Uint8Array(await file.arrayBuffer());const extension=fileType(bytes,file.type);
  if(!extension||(!allowPdf&&extension==='pdf'))throw error('Поддерживаются JPG, PNG, WebP'+(allowPdf?' и PDF.':'.'));
  const key=prefix+'/'+crypto.randomUUID()+'.'+extension;
  await env.BUCKET.put(key,bytes,{httpMetadata:{contentType:file.type},customMetadata:{filename:clean(file.name,160)}});
  return {key,name:clean(file.name,160),type:file.type,size:file.size};
}
async function rateLimit(request,env){
  const ip=request.headers.get('cf-connecting-ip');if(!ip)return;
  const raw=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(ip));const key=Array.from(new Uint8Array(raw),b=>b.toString(16).padStart(2,'0')).join('');
  const now=Date.now();
  await env.DB.prepare('DELETE FROM request_limits WHERE expires_at < ?').bind(now).run();
  await env.DB.prepare('INSERT INTO request_limits (key,count,expires_at) VALUES (?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1').bind(key,now+3600000).run();
  const row=await env.DB.prepare('SELECT count FROM request_limits WHERE key=?').bind(key).first();
  if(row.count>12)throw error('Слишком много заявок. Попробуйте позднее.',429);
}
export async function createInquiry(request,env){
  sameOrigin(request);
  const max=Number(request.headers.get('content-length')||0);if(max>17*1024*1024)throw error('Общий размер файлов не должен превышать 15 МБ.',413);
  const form=await request.formData();
  if(form.get('website'))throw error('Не удалось отправить заявку.');
  const kind=form.get('kind');if(!['application','booking'].includes(kind))throw error('Неверный тип заявки.');
  const name=clean(form.get('name'),100),email=clean(form.get('email'),200).toLowerCase(),phone=clean(form.get('phone'),60);
  if(!name||!/^\S+@\S+\.\S+$/.test(email)||!phone)throw error('Укажите имя, телефон и корректный email.');
  if(form.get('consent')!=='yes')throw error('Нужно согласие на обработку данных.');
  const age=kind==='application'?Number(form.get('age')):null;
  if(kind==='application'&&(!Number.isInteger(age)||age<18||age>80))throw error('В первой версии анкета доступна совершеннолетним кандидатам от 18 лет.');
  const height=kind==='application'?Number(form.get('height')):null;
  if(kind==='application'&&(!Number.isFinite(height)||height<100||height>230))throw error('Укажите рост в сантиметрах.');
  const modelId=clean(form.get('model_id'),100);let modelName='';
  if(modelId){const model=await env.DB.prepare('SELECT name FROM models WHERE id=? AND published=1').bind(modelId).first();if(!model)throw error('Анкета модели недоступна. Выберите другую модель.');modelName=model.name;}
  const files=form.getAll('photos').filter(f=>typeof f!=='string'&&f.size>0);
  if(files.length>3||files.reduce((s,f)=>s+f.size,0)>15*1024*1024)throw error('Добавьте не более 3 файлов, до 5 МБ каждый.');
  await rateLimit(request,env);
  const id=crypto.randomUUID(),attachments=[];
  try {
    for(const file of files)attachments.push(await putFile(env,file,'private/inquiries/'+id,true));
    const now=stamp();
    await env.DB.prepare('INSERT INTO inquiries (id,kind,name,email,phone,city,age,height,instagram,model_id,model_name,company,shooting_date,message,attachments,status,internal_note,consent,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)').bind(id,kind,name,email,phone,clean(form.get('city'),100),age,height,clean(form.get('instagram'),200),modelId,modelName,clean(form.get('company'),200),clean(form.get('shooting_date'),40),clean(form.get('message'),5000),JSON.stringify(attachments),'new','',1,now,now).run();
    return json({ok:true,id,reference:id.slice(0,8).toUpperCase()},201);
  }catch(e){if(env.BUCKET)await Promise.allSettled(attachments.map(f=>env.BUCKET.delete(f.key)));throw e;}
}
async function downloadArchive(env){
  const file=await env.BUCKET?.get('private/exports/grace-models-complete.zip');
  if(!file)throw error('Архив еще не подготовлен.',404);
  return new Response(file.body,{headers:{'content-type':'application/zip','content-disposition':'attachment; filename="grace-models-complete.zip"','cache-control':'private,no-store','x-content-type-options':'nosniff'}});
}
async function api(request,env,path,session){
  if(path==='/api/public'&&request.method==='GET')return json(await publicData(env));
  if(path==='/api/inquiries'&&request.method==='POST')return createInquiry(request,env);
  if(!path.startsWith('/api/studio/'))return json({error:'Не найдено.'},404);
  if(!session)throw error('Войдите в панель администратора.',401);
  if(request.method!=='GET')sameOrigin(request);
  const parts=path.slice('/api/studio/'.length).split('/');const resource=parts[0],id=decodeURIComponent(parts.slice(1).join('/'));
  if(resource==='archive'&&request.method==='GET')return downloadArchive(env);
  if(resource==='archive'&&request.method==='POST'){
    if(!env.BUCKET)throw error('Хранилище не подключено.',503);
    const size=Number(request.headers.get('content-length')||0);if(size>20*1024*1024)throw error('Максимальный размер архива — 20 МБ.',413);
    const body=new Uint8Array(await request.arrayBuffer());
    if(body.length>20*1024*1024||body.length<4||body[0]!==80||body[1]!==75||body[2]!==3||body[3]!==4)throw error('Требуется ZIP-архив.');
    await env.BUCKET.put('private/exports/grace-models-complete.zip',body,{httpMetadata:{contentType:'application/zip'}});
    return json({ok:true,bytes:body.length});
  }
  if(resource==='data'&&request.method==='GET'){
    const [models,inquiries,news,activity,settings]=await Promise.all([env.DB.prepare('SELECT * FROM models ORDER BY sort_order,created_at').all(),env.DB.prepare('SELECT * FROM inquiries ORDER BY created_at DESC LIMIT 2000').all(),env.DB.prepare('SELECT * FROM news ORDER BY published_at DESC').all(),env.DB.prepare('SELECT * FROM activity ORDER BY created_at DESC LIMIT 40').all(),getSettings(env)]);
    return json({models:models.results.map(modelRow),inquiries:inquiries.results.map(x=>({...x,attachments:JSON.parse(x.attachments||'[]')})),news:news.results,activity:activity.results,settings,user:{login:session.login}});
  }
  if(resource==='models'&&['POST','PUT'].includes(request.method)){
    const input=await request.json();const values=validateModel(input);const now=stamp();
    if(request.method==='POST'){
      const newId=crypto.randomUUID();const keys=['id',...modelFields,'created_at','updated_at'];
      await env.DB.prepare(`INSERT INTO models (${keys.join(',')}) VALUES (${keys.map(()=>'?').join(',')})`).bind(newId,...modelFields.map(k=>values[k]),now,now).run();await audit(env,'Анкета создана',values.name);return json({id:newId},201);
    }
    const row=await env.DB.prepare('SELECT id FROM models WHERE id=?').bind(id).first();if(!row)throw error('Анкета не найдена.',404);
    await env.DB.prepare(`UPDATE models SET ${modelFields.map(k=>k+'=?').join(',')},updated_at=? WHERE id=?`).bind(...modelFields.map(k=>values[k]),now,id).run();await audit(env,'Анкета обновлена',values.name);return json({ok:true});
  }
  if(resource==='models'&&request.method==='DELETE'){
    const row=await env.DB.prepare('SELECT name FROM models WHERE id=?').bind(id).first();if(!row)throw error('Анкета не найдена.',404);
    await env.DB.prepare('DELETE FROM models WHERE id=?').bind(id).run();await audit(env,'Анкета удалена',row.name);return json({ok:true});
  }
  if(resource==='inquiries'&&request.method==='PUT'){
    const input=await request.json();if(!statuses.includes(input.status))throw error('Неверный статус.');
    const found=await env.DB.prepare('SELECT name FROM inquiries WHERE id=?').bind(id).first();if(!found)throw error('Заявка не найдена.',404);
    await env.DB.prepare('UPDATE inquiries SET status=?,internal_note=?,updated_at=? WHERE id=?').bind(input.status,clean(input.internal_note,10000),stamp(),id).run();await audit(env,'Заявка обновлена',found.name);return json({ok:true});
  }
  if(resource==='inquiries'&&request.method==='DELETE'){
    const row=await env.DB.prepare('SELECT attachments FROM inquiries WHERE id=?').bind(id).first();if(!row)throw error('Заявка не найдена.',404);
    if(env.BUCKET)for(const file of JSON.parse(row.attachments||'[]'))await env.BUCKET.delete(file.key);
    await env.DB.prepare('DELETE FROM inquiries WHERE id=?').bind(id).run();await audit(env,'Заявка удалена',id.slice(0,8));return json({ok:true});
  }
  if(resource==='upload'&&request.method==='POST'){
    if(Number(request.headers.get('content-length')||0)>6*1024*1024)throw error('Максимальный размер — 5 МБ.',413);
    const form=await request.formData();const file=form.get('file');if(!file||typeof file==='string')throw error('Выберите файл.');
    const stored=await putFile(env,file,'public/photos');return json({url:'/media/'+stored.key},201);
  }
  if(resource==='files'&&request.method==='GET'){
    if(!id.startsWith('private/inquiries/')||id.includes('..'))throw error('Файл не найден.',404);
    const file=await env.BUCKET?.get(id);if(!file)throw error('Файл не найден.',404);
    const headers=new Headers({'cache-control':'private, no-store','x-content-type-options':'nosniff','content-disposition':'attachment; filename="attachment.'+(id.split('.').pop()||'bin')+'"'});file.writeHttpMetadata(headers);return new Response(file.body,{headers});
  }
  if(resource==='settings'&&request.method==='PUT'){
    const input=await request.json();const settings={...await getSettings(env)};
    for(const k of Object.keys(defaultSettings))if(k in input){
      if(k==='demo_mode')settings[k]=Boolean(input[k]);
      else if(k.startsWith('hero_'))settings[k]=mediaUrl(input[k]);
      else if(['telegram','instagram','vk'].includes(k))settings[k]=clean(input[k],500).startsWith('https://')?clean(input[k],500):'';
      else settings[k]=clean(input[k],k.startsWith('about')?10000:500);
    }
    if(!settings.name)throw error('Укажите название агентства.');
    await env.DB.prepare("UPDATE settings SET value=? WHERE key='agency'").bind(JSON.stringify(settings)).run();await audit(env,'Настройки сохранены',settings.name);return json({ok:true});
  }
  if(resource==='news'&&['POST','PUT'].includes(request.method)){
    const input=await request.json();const values={};for(const k of newsFields)values[k]=k==='published'?(input[k]?1:0):k==='image'?mediaUrl(input[k]):clean(input[k],k.startsWith('body')?15000:k.startsWith('summary')?2000:300);
    if(!values.title)throw error('Укажите заголовок.');values.published_at=values.published_at||stamp().slice(0,10);
    const now=stamp();
    if(request.method==='POST'){
      const keys=['id',...newsFields,'created_at','updated_at'];const newId=crypto.randomUUID();await env.DB.prepare(`INSERT INTO news (${keys.join(',')}) VALUES (${keys.map(()=>'?').join(',')})`).bind(newId,...newsFields.map(k=>values[k]),now,now).run();
    }else{const row=await env.DB.prepare('SELECT id FROM news WHERE id=?').bind(id).first();if(!row)throw error('Публикация не найдена.',404);await env.DB.prepare(`UPDATE news SET ${newsFields.map(k=>k+'=?').join(',')},updated_at=? WHERE id=?`).bind(...newsFields.map(k=>values[k]),now,id).run();}
    await audit(env,'Публикация сохранена',values.title);return json({ok:true});
  }
  if(resource==='news'&&request.method==='DELETE'){await env.DB.prepare('DELETE FROM news WHERE id=?').bind(id).run();await audit(env,'Публикация удалена',id);return json({ok:true});}
  return json({error:'Не найдено.'},404);
}
function asset(path,headers={}){
  const item=ASSETS[path];if(!item)return new Response('Not found',{status:404});
  const raw=atob(item.body);const bytes=Uint8Array.from(raw,c=>c.charCodeAt(0));
  return new Response(bytes,{headers:{'content-type':item.type,'cache-control':path.startsWith('/images/')?'public,max-age=86400':'no-cache','x-content-type-options':'nosniff',...headers}});
}
export default {async fetch(request,env,ctx){
  const url=new URL(request.url);const path=url.pathname.replace(/\/$/,'')||'/';
  try{
    if(path===ADMIN_PATH||path===ADMIN_PATH+'/download'){
      const session=await getAdminSession(request,env);
      if(session&&path===ADMIN_PATH+'/download')return renewAdminSession(await downloadArchive(env),session);
      const response=asset(session?'/admin.html':'/login.html',{'cache-control':'private,no-store','x-robots-tag':'noindex,nofollow','content-security-policy':"form-action 'self'; base-uri 'none'; object-src 'none'"});
      return session?renewAdminSession(response,session):response;
    }
    if(path==='/admin.html'||path==='/login.html')return new Response('Not found',{status:404});
    if(path==='/admin.js'||path==='/admin.css'){await requireAdmin(request,env);return asset(path,{'cache-control':'private,no-store','x-robots-tag':'noindex,nofollow'});}
    if(path.startsWith('/api/')){
      if(path==='/api/studio/login'&&request.method==='POST')return await loginAdmin(request,env);
      if(path==='/api/studio/logout'&&request.method==='POST')return await logoutAdmin(request,env);
      const session=path.startsWith('/api/studio/')?await requireAdmin(request,env):null;
      await initialize(env);
      const response=await api(request,env,path,session);
      return session?renewAdminSession(response,session):response;
    }
    if(path.startsWith('/media/')){
      const key=decodeURIComponent(path.slice(7));if(!key.startsWith('public/')||key.includes('..'))return new Response('Not found',{status:404});
      const file=await env.BUCKET?.get(key);if(!file)return new Response('Not found',{status:404});const headers=new Headers({'cache-control':'public,max-age=3600','x-content-type-options':'nosniff'});file.writeHttpMetadata(headers);return new Response(file.body,{headers});
    }
    if(path==='/robots.txt')return new Response('User-agent: *\nDisallow: /s/\nDisallow: /atelier/\nDisallow: /api/\n');
    if(path==='/sitemap.xml')return new Response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'+['/','/models','/about','/contacts','/become-a-model'].map(p=>'<url><loc>'+url.origin+p+'</loc></url>').join('')+'</urlset>',{headers:{'content-type':'application/xml'}});
    if(ASSETS[path])return asset(path);
    if(path==='/'||['/models','/about','/contacts','/become-a-model','/booking','/news','/privacy'].includes(path)||/^\/(models|news)\/[a-zA-Z0-9-]+$/.test(path))return asset('/index.html');
    return new Response('<!doctype html><html lang="ru"><meta charset="utf-8"><title>Страница не найдена — FORMA</title><body style="font-family:Arial;padding:10vw;background:#f7f6f2"><h1>Этой страницы нет.</h1><a href="/">Вернуться на главную ↗</a></body></html>',{status:404,headers:{'content-type':'text/html; charset=utf-8'}});
  }catch(e){console.error(e.message);const message=e.message?.includes('UNIQUE')?'Такой адрес анкеты уже используется. Измените его.':e.status?e.message:'Не удалось выполнить действие. Попробуйте еще раз.';return json({error:message},e.status||500);}
}};
