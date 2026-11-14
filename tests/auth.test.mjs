import test from 'node:test';
import assert from 'node:assert/strict';
import { ADMIN_PATH } from '../worker/index.mjs';
import { hashAdminPassword, ADMIN_COOKIE_MAX_AGE, ADMIN_COOKIE_NAME } from '../worker/admin-auth.mjs';
import { fixture, origin, testLogin, testPassword } from './fixture.mjs';

test('hidden studio shows login; old address and admin assets do not expose the editor',async()=>{
  const {call}=fixture();
  const login=await call(ADMIN_PATH);assert.equal(login.status,200);assert.match(await login.text(),/Password login/);
  assert.equal(login.headers.get('x-robots-tag'),'noindex,nofollow');
  assert.equal((await call('/atelier/7d9f3b2c6a804e15')).status,404);
  assert.equal((await call('/admin.html')).status,404);
  assert.equal((await call('/login.html')).status,404);
  assert.equal((await call('/admin.js')).status,401);
  assert.equal((await call('/admin.css')).status,401);
});
test('correct credentials create a persistent HttpOnly cookie and only a hashed server token',async()=>{
  const {call,sqlite}=fixture();
  const response=await call('/api/studio/login',{method:'POST',body:{login:testLogin,password:testPassword}});
  assert.equal(response.status,200);
  const cookie=response.headers.get('set-cookie');
  assert.match(cookie,new RegExp('^'+ADMIN_COOKIE_NAME+'=[a-f0-9]{64};'));
  for(const flag of ['HttpOnly','Secure','SameSite=Lax','Path=/','Max-Age='+ADMIN_COOKIE_MAX_AGE])assert.ok(cookie.includes(flag));
  const token=cookie.split(';')[0].split('=')[1],stored=sqlite.prepare('SELECT * FROM admin_sessions').get();
  assert.notEqual(stored.token_hash,token);assert.equal(stored.token_hash.length,64);
  const sessionCookie=cookie.split(';')[0];
  const dashboard=await call(ADMIN_PATH,{headers:{cookie:sessionCookie}});assert.match(await dashboard.text(),/dashboard/);
  const data=await call('/api/studio/data',{headers:{cookie:sessionCookie}});assert.equal(data.status,200);assert.equal((await data.json()).user.login,testLogin);
});
test('browser reopen and old server creation dates do not terminate the admin session',async()=>{
  const {call,adminCookie,sqlite}=fixture();const cookie=await adminCookie();
  sqlite.prepare("UPDATE admin_sessions SET created_at='2000-01-01T00:00:00Z'").run();
  const first=await call('/api/studio/data',{headers:{cookie}});assert.equal(first.status,200);
  assert.ok(first.headers.get('set-cookie').includes('Max-Age='+ADMIN_COOKIE_MAX_AGE));
  const reopened=await call('/api/studio/data',{headers:{cookie}});assert.equal(reopened.status,200);
});
test('wrong login or password never creates a session and repeated failures are limited',async()=>{
  const {call,sqlite}=fixture();
  for(let i=0;i<6;i++){
    const response=await call('/api/studio/login',{method:'POST',body:{login:i===0?'unknown':testLogin,password:i===0?testPassword:'incorrect'}});
    assert.equal(response.status,401);assert.equal(response.headers.get('set-cookie'),null);
  }
  assert.equal(sqlite.prepare('SELECT count(*) AS count FROM admin_sessions').get().count,0);
  const blocked=await call('/api/studio/login',{method:'POST',body:{login:testLogin,password:testPassword}});
  assert.equal(blocked.status,429);assert.ok(Number(blocked.headers.get('retry-after'))>0);
});
test('ChatGPT identity headers and fabricated cookies cannot bypass password login',async()=>{
  const {call}=fixture();
  assert.equal((await call('/api/studio/data',{headers:{'oai-authenticated-user-id':'owner','oai-authenticated-user-email':'owner@example.test'}})).status,401);
  assert.equal((await call('/api/studio/data',{headers:{cookie:ADMIN_COOKIE_NAME+'='+'a'.repeat(64)}})).status,401);
});
test('logout revokes the current device without disconnecting other devices',async()=>{
  const {call,adminCookie}=fixture();const first=await adminCookie();
  const other=await call('/api/studio/login',{method:'POST',body:{login:testLogin,password:testPassword}});const second=other.headers.get('set-cookie').split(';')[0];
  assert.notEqual(first,second);
  const logout=await call('/api/studio/logout',{method:'POST',headers:{cookie:first,origin}});assert.equal(logout.status,200);assert.ok(logout.headers.get('set-cookie').includes('Max-Age=0'));
  assert.equal((await call('/api/studio/data',{headers:{cookie:first}})).status,401);
  assert.equal((await call('/api/studio/data',{headers:{cookie:second}})).status,200);
});
test('credential changes invalidate existing sessions and cross-origin login is rejected',async()=>{
  const {call,env,adminCookie}=fixture();const cookie=await adminCookie();
  env.ADMIN_PASSWORD_HASH=await hashAdminPassword('another-local-fixture-password');
  assert.equal((await call('/api/studio/data',{headers:{cookie}})).status,401);
  assert.equal((await call('/api/studio/login',{method:'POST',body:{login:testLogin,password:testPassword},headers:{origin:'https://other.example'}})).status,403);
});
test('complete website archives can be uploaded and downloaded only after admin login',async()=>{
  const {call}=fixture();const zip=new Uint8Array([80,75,3,4,1,2,3]);
  assert.equal((await call('/api/studio/archive',{method:'POST',body:zip})).status,401);
  const login=await call(ADMIN_PATH+'/download');assert.match(await login.text(),/Password login/);
  assert.equal((await call('/api/studio/archive',{admin:true,method:'POST',body:new Uint8Array([1,2,3,4])})).status,400);
  assert.equal((await call('/api/studio/archive',{admin:true,method:'POST',body:zip})).status,200);
  const download=await call(ADMIN_PATH+'/download',{admin:true});assert.equal(download.status,200);assert.equal(download.headers.get('content-type'),'application/zip');assert.match(download.headers.get('content-disposition'),/attachment/);
  assert.deepEqual(new Uint8Array(await download.arrayBuffer()),zip);
  assert.equal((await call('/media/private/exports/grace-models-complete.zip')).status,404);
});
