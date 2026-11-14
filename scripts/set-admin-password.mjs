import { spawnSync } from 'node:child_process';
import { hashAdminPassword } from '../worker/admin-auth.mjs';
if(!process.stdin.isTTY)throw new Error('Запустите команду в обычном интерактивном терминале.');
process.stdout.write('Пароль администратора (ввод скрыт): ');
const password=await new Promise((resolve,reject)=>{
  let value='';process.stdin.setRawMode(true);process.stdin.resume();process.stdin.setEncoding('utf8');
  const finish=()=>{process.stdin.off('data',receive);process.stdin.setRawMode(false);process.stdin.pause();process.stdout.write('\n');};
  function receive(input){for(const char of input){if(char==='\u0003'){finish();reject(new Error('Отменено.'));return;}if(char==='\r'||char==='\n'){finish();resolve(value);return;}if(char==='\u007f'||char==='\b')value=value.slice(0,-1);else value+=char;}}
  process.stdin.on('data',receive);
});
if(password.length<10)throw new Error('Выберите пароль длиной не менее 10 символов.');
const record=await hashAdminPassword(password);
const result=spawnSync(process.platform==='win32'?'npx.cmd':'npx',['wrangler','secret','put','ADMIN_PASSWORD_HASH','--config','wrangler.jsonc'],{input:record+'\n',stdio:['pipe','inherit','inherit']});
process.exitCode=result.status??1;
