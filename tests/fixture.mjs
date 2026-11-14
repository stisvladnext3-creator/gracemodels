import { DatabaseSync } from 'node:sqlite';
import { readFileSync } from 'node:fs';
import worker from '../worker/index.mjs';
import { hashAdminPassword } from '../worker/admin-auth.mjs';

export const testLogin = 'local-admin';
export const testPassword = 'fixture-password-for-tests';
const passwordHash = await hashAdminPassword(testPassword);
export const origin = 'https://agency.example';
globalThis.ASSETS = Object.fromEntries([
  ['/index.html','<html>FORMA</html>'],
  ['/admin.html','<html>Studio dashboard</html>'],
  ['/login.html','<html>Password login</html>'],
  ['/admin.js','// Protected editor'],
  ['/admin.css','/* Protected styles */']
].map(([path,content])=>[path,{type:path.endsWith('.html')?'text/html':'text/plain',body:Buffer.from(content).toString('base64')}]));

export function fixture() {
  const sqlite=new DatabaseSync(':memory:');
  sqlite.exec(readFileSync(new URL('../drizzle/0000_agency.sql',import.meta.url),'utf8'));
  sqlite.exec(readFileSync(new URL('../drizzle/0001_admin_sessions.sql',import.meta.url),'utf8'));
  const DB={prepare(sql){let values=[];return {bind(...args){values=args;return this;},async first(){return sqlite.prepare(sql).get(...values)||null;},async all(){return {results:sqlite.prepare(sql).all(...values)};},async run(){return sqlite.prepare(sql).run(...values);}};},async batch(statements){sqlite.exec('BEGIN');try{const out=[];for(const stmt of statements)out.push(await stmt.run());sqlite.exec('COMMIT');return out;}catch(e){sqlite.exec('ROLLBACK');throw e;}}};
  const files=new Map();
  const BUCKET={async put(key,body,metadata){files.set(key,{body,metadata});},async delete(key){files.delete(key);},async get(key){const f=files.get(key);return f?{body:f.body,writeHttpMetadata(headers){headers.set('content-type',f.metadata.httpMetadata.contentType);}}:null;}};
  const env={DB,BUCKET,ADMIN_LOGIN:testLogin,ADMIN_PASSWORD_HASH:passwordHash};
  let savedCookie;
  async function adminCookie(){
    if(!savedCookie){
      const response=await worker.fetch(new Request(origin+'/api/studio/login',{method:'POST',headers:{'content-type':'application/json',origin},body:JSON.stringify({login:testLogin,password:testPassword})}),env,{});
      if(response.status!==200)throw new Error('Fixture login failed: '+await response.text());
      savedCookie=response.headers.get('set-cookie').split(';')[0];
    }
    return savedCookie;
  }
  async function call(path,options={}){
    const headers={...(options.admin?{cookie:await adminCookie()}:{}),...(options.headers||{})};
    const init={method:options.method||'GET',headers};
    if(options.body instanceof FormData||options.body instanceof Uint8Array)init.body=options.body;
    else if(options.body!==undefined){headers['content-type']='application/json';init.body=JSON.stringify(options.body);}
    return worker.fetch(new Request(origin+path,init),env,{});
  }
  return {sqlite,env,call,files,adminCookie};
}
