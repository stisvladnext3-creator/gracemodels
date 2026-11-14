import test from 'node:test';
import assert from 'node:assert/strict';
import { validateModel } from '../worker/index.mjs';
import { fixture, origin } from './fixture.mjs';
test('model edits persist, drafts stay private, published edits appear in public data',async()=>{
  const {call}=fixture();const initial=await (await call('/api/public')).json();assert.equal(initial.models.length,12);
  const draft={...initial.models[0],name:'Тестовая модель',slug:'test-model',published:false,demo:false};
  let response=await call('/api/studio/models',{admin:true,method:'POST',body:draft});assert.equal(response.status,201);const {id}=await response.json();
  assert.equal((await (await call('/api/public')).json()).models.some(m=>m.id===id),false);
  draft.published=true;draft.name='Обновленное имя';response=await call('/api/studio/models/'+id,{admin:true,method:'PUT',body:draft});assert.equal(response.status,200);
  assert.equal((await (await call('/api/public')).json()).models.find(m=>m.id===id).name,'Обновленное имя');
  assert.equal((await call('/api/studio/models/'+id,{admin:true,method:'DELETE'})).status,200);
  assert.equal((await (await call('/api/public')).json()).models.some(m=>m.id===id),false);
});
test('deleting the seeded catalogue does not bring demo models back',async()=>{
  const {call,sqlite}=fixture();await call('/api/public');sqlite.exec('DELETE FROM models');
  assert.equal((await (await call('/api/public')).json()).models.length,0);
});
test('public forms create durable inquiries; contacts and notes stay outside public API',async()=>{
  const {call}=fixture();const data=new FormData();for(const [k,v]of Object.entries({kind:'booking',name:'Тестовый клиент',email:'client@example.test',phone:'+70000000000',company:'Тест',model_id:'demo-alina',message:'Проверка формы',consent:'yes'}))data.set(k,v);
  const created=await call('/api/inquiries',{method:'POST',body:data,headers:{origin}});assert.equal(created.status,201);const {id}=await created.json();
  const studio=await (await call('/api/studio/data',{admin:true})).json();assert.equal(studio.inquiries[0].id,id);assert.equal(studio.inquiries[0].model_name,'Алина М.');
  assert.equal((await call('/api/studio/inquiries/'+id,{admin:true,method:'PUT',body:{status:'contacted',internal_note:'Только для команды'}})).status,200);
  const reloaded=await (await call('/api/studio/data',{admin:true})).json();assert.equal(reloaded.inquiries[0].internal_note,'Только для команды');
  const publicText=await (await call('/api/public')).text();assert.equal(publicText.includes('client@example.test'),false);assert.equal(publicText.includes('Только для команды'),false);
});
test('attachments remain private and are deleted with their inquiry',async()=>{
  const {call,files}=fixture();const data=new FormData();for(const [k,v]of Object.entries({kind:'application',name:'Кандидат',email:'candidate@example.test',phone:'+70000000000',city:'Москва',age:'21',height:'177',consent:'yes'}))data.set(k,v);
  data.append('photos',new File([new Uint8Array([255,216,255,224,0,1,2,3])],'portrait.jpg',{type:'image/jpeg'}));
  const response=await call('/api/inquiries',{method:'POST',body:data});assert.equal(response.status,201);const {id}=await response.json();assert.equal(files.size,1);
  const studio=await (await call('/api/studio/data',{admin:true})).json();const key=studio.inquiries[0].attachments[0].key;
  assert.equal((await call('/media/'+key)).status,404);assert.equal((await call('/api/studio/files/'+encodeURIComponent(key))).status,401);assert.equal((await call('/api/studio/files/'+encodeURIComponent(key),{admin:true})).status,200);
  assert.equal((await call('/api/studio/inquiries/'+id,{admin:true,method:'DELETE'})).status,200);assert.equal(files.size,0);
});
test('cross-origin writes and invalid submissions are rejected without records',async()=>{
  const {call,sqlite}=fixture();
  const data=new FormData();data.set('kind','booking');data.set('name','Client');data.set('email','bad');
  assert.equal((await call('/api/inquiries',{method:'POST',body:data,headers:{origin:'https://other.example'}})).status,403);
  assert.equal((await call('/api/inquiries',{method:'POST',body:data})).status,400);
  assert.equal(sqlite.prepare('SELECT count(*) as count FROM inquiries').get().count,0);
  assert.equal((await call('/api/studio/settings',{admin:true,method:'PUT',body:{name:'Changed'},headers:{origin:'https://other.example'}})).status,403);
});
test('published models require safe cover URLs and valid measurements',()=>{
  const base={name:'Example',slug:'example',height:175,bust:84,waist:61,hips:90,shoe:39};
  assert.throws(()=>validateModel({...base,published:true,cover:'javascript:alert(1)'}),/обложку/);
  assert.throws(()=>validateModel({...base,slug:'../../admin'}),/Адрес/);
  assert.throws(()=>validateModel({...base,height:300}),/height/);
});
test('site settings and publication CRUD persist across requests',async()=>{
  const {call}=fixture();await call('/api/public');
  assert.equal((await call('/api/studio/settings',{admin:true,method:'PUT',body:{name:'Новая форма',telegram:'javascript:bad',demo_mode:false}})).status,200);
  const {settings}=await (await call('/api/public')).json();assert.equal(settings.name,'Новая форма');assert.equal(settings.telegram,'');assert.equal(settings.demo_mode,false);
  assert.equal((await call('/api/studio/news',{admin:true,method:'POST',body:{title:'Заметка',body:'Текст',published:false,published_at:'2026-11-13'}})).status,200);
  assert.equal((await (await call('/api/public')).json()).news.some(n=>n.title==='Заметка'),false);
  const data=await (await call('/api/studio/data',{admin:true})).json(),n=data.news.find(n=>n.title==='Заметка');
  assert.equal((await call('/api/studio/news/'+n.id,{admin:true,method:'PUT',body:{...n,published:true}})).status,200);
  assert.equal((await (await call('/api/public')).json()).news.some(n=>n.title==='Заметка'),true);
});
