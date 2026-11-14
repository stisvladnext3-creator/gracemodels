import { readFile, mkdir, writeFile, readdir, cp } from 'node:fs/promises';
import path from 'node:path';
const base = process.cwd();
const publicDir = path.join(base, 'public');
const assets = {};
async function collect(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const filename = path.join(dir, entry.name);
    if (entry.isDirectory()) await collect(filename);
    else if (!entry.name.endsWith('.json')) {
      const extension = path.extname(filename);
      const types = { '.html':'text/html; charset=utf-8', '.css':'text/css; charset=utf-8', '.js':'application/javascript; charset=utf-8', '.jpg':'image/jpeg', '.webp':'image/webp', '.png':'image/png', '.svg':'image/svg+xml', '.woff2':'font/woff2', '.ttf':'font/ttf' };
      assets['/' + path.relative(publicDir, filename).split(path.sep).join('/')] = { type:types[extension] || 'application/octet-stream', body:(await readFile(filename)).toString('base64') };
    }
  }
}
await collect(publicDir);
await mkdir(path.join(base, 'dist/server'), {recursive:true});
await mkdir(path.join(base, 'dist/.openai'), {recursive:true});
await mkdir(path.join(base, 'dist/drizzle/meta'), {recursive:true});
const server = await readFile(path.join(base, 'worker/index.mjs'), 'utf8');
const seed = await readFile(path.join(base, 'worker/seed.mjs'), 'utf8');
const auth = await readFile(path.join(base, 'worker/admin-auth.mjs'), 'utf8');
const output = 'const ASSETS = ' + JSON.stringify(assets) + ';\n' + seed.replaceAll('export ', '') + '\n' + auth.replaceAll('export ', '') + '\n' + server.replace("import { seedModels, seedNews, defaultSettings } from './seed.mjs';", '').replace("import { getAdminSession, loginAdmin, logoutAdmin, renewAdminSession } from './admin-auth.mjs';", '').replace(/export (?=(?:async )?function|const)/g, '');
await writeFile(path.join(base, 'dist/server/index.js'), output);
await writeFile(path.join(base, 'dist/.openai/hosting.json'), await readFile(path.join(base, '.openai/hosting.json')));
await cp(path.join(base, 'drizzle'), path.join(base, 'dist/drizzle'), {recursive:true});
console.log(JSON.stringify({built:true,assets:Object.keys(assets).length,worker_bytes:Buffer.byteLength(output)}));
