import { readFileSync, existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
const bucket=process.argv[2];if(!bucket||!/^[a-z0-9-]+$/.test(bucket))throw new Error('Укажите имя R2-хранилища: node scripts/restore-uploads.mjs bucket-name');
const manifest=JSON.parse(readFileSync('backup/upload-manifest.json','utf8'));
const types={jpg:'image/jpeg',png:'image/png',webp:'image/webp',pdf:'application/pdf'};
for(const item of manifest){
  if(!/^(public|private)\//.test(item.key)||item.key.split('/').includes('..'))throw new Error('Недопустимый ключ файла.');
  const file=path.resolve('backup/uploads',item.key);if(!existsSync(file))throw new Error('Файл отсутствует: '+item.key);
  const type=types[item.key.split('.').pop()]||'application/octet-stream';
  const result=spawnSync(process.platform==='win32'?'npx.cmd':'npx',['wrangler','r2','object','put',bucket+'/'+item.key,'--file',file,'--content-type',type,'--remote','--config','wrangler.jsonc'],{stdio:'inherit'});
  if(result.status!==0)process.exit(result.status??1);
}
console.log('Восстановлено файлов: '+manifest.length);
