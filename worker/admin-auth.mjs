export const ADMIN_COOKIE_NAME = '__Host-agency-admin';
export const ADMIN_COOKIE_MAX_AGE = 400 * 24 * 60 * 60;
const ADMIN_HASH_ITERATIONS = 100000;
const authEncoder = new TextEncoder();
const authFailure = (message, status = 400) => Object.assign(new Error(message), { status });
const authHex = bytes => Array.from(new Uint8Array(bytes), n => n.toString(16).padStart(2, '0')).join('');
const authFromHex = text => Uint8Array.from(text.match(/.{2}/g) || [], n => Number.parseInt(n, 16));
const authJson = (data, status = 200, headers = {}) => new Response(JSON.stringify(data), {
  status,
  headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store', 'x-content-type-options': 'nosniff', ...headers }
});

async function authDigest(value) {
  return authHex(await crypto.subtle.digest('SHA-256', authEncoder.encode(value)));
}

async function authDerive(password, salt, iterations) {
  const key = await crypto.subtle.importKey('raw', authEncoder.encode(password), 'PBKDF2', false, ['deriveBits']);
  return new Uint8Array(await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, key, 256));
}

export async function hashAdminPassword(password) {
  const salt = crypto.getRandomValues(new Uint8Array(24));
  const hash = await authDerive(password, salt, ADMIN_HASH_ITERATIONS);
  return JSON.stringify({ algorithm: 'pbkdf2-sha256', iterations: ADMIN_HASH_ITERATIONS, salt: authHex(salt), hash: authHex(hash) });
}

function authEqual(left, right) {
  let difference = left.length ^ right.length;
  for (let i = 0; i < Math.max(left.length, right.length); i++) difference |= (left[i] || 0) ^ (right[i] || 0);
  return difference === 0;
}

function authPasswordRecord(env) {
  let record;
  try { record = JSON.parse(env.ADMIN_PASSWORD_HASH || ''); } catch {}
  if (!env.ADMIN_LOGIN || record?.algorithm !== 'pbkdf2-sha256' ||
      !Number.isInteger(record.iterations) || record.iterations < 10000 || record.iterations > 100000 ||
      !/^[a-f0-9]{48}$/.test(record.salt || '') || !/^[a-f0-9]{64}$/.test(record.hash || '')) {
    throw authFailure('Вход администратора еще не настроен.', 503);
  }
  return record;
}

export function adminSessionCookie(token, clear = false) {
  return `${ADMIN_COOKIE_NAME}=${clear ? '' : token}; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=${clear ? 0 : ADMIN_COOKIE_MAX_AGE}`;
}

function authReadToken(request) {
  const cookies = request.headers.get('cookie') || '';
  const value = cookies.split(';').map(v => v.trim()).find(v => v.startsWith(ADMIN_COOKIE_NAME + '='))?.slice(ADMIN_COOKIE_NAME.length + 1);
  return value && /^[a-f0-9]{64}$/.test(value) ? value : null;
}

export async function getAdminSession(request, env) {
  const token = authReadToken(request);
  if (!token || !env.DB || !env.ADMIN_PASSWORD_HASH || !env.ADMIN_LOGIN) return null;
  const hash = await authDigest(token);
  const session = await env.DB.prepare('SELECT token_hash, credential_version FROM admin_sessions WHERE token_hash=?').bind(hash).first();
  const version = await authDigest(env.ADMIN_LOGIN + ':' + env.ADMIN_PASSWORD_HASH);
  if (!session || session.credential_version !== version) return null;
  return { token, token_hash: hash, login: env.ADMIN_LOGIN };
}

export function renewAdminSession(response, session) {
  const headers = new Headers(response.headers);
  headers.set('set-cookie', adminSessionCookie(session.token));
  headers.set('cache-control', 'private, no-store');
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

function authSameOrigin(request) {
  const origin = request.headers.get('origin');
  if (origin && origin !== new URL(request.url).origin) throw authFailure('Недопустимый источник запроса.', 403);
}

export async function loginAdmin(request, env) {
  authSameOrigin(request);
  const record = authPasswordRecord(env);
  if (Number(request.headers.get('content-length') || 0) > 4096) throw authFailure('Запрос слишком большой.', 413);
  let input;
  try { input = await request.json(); } catch { throw authFailure('Укажите логин и пароль.'); }
  if (typeof input?.login !== 'string' || typeof input?.password !== 'string' || input.login.length > 100 || input.password.length > 512) throw authFailure('Укажите логин и пароль.');
  const rateKey = 'admin-login:' + await authDigest(request.headers.get('cf-connecting-ip') || 'unknown-client');
  const now = Date.now();
  const rate = await env.DB.prepare('SELECT count, expires_at FROM request_limits WHERE key=?').bind(rateKey).first();
  if (rate && rate.expires_at > now && rate.count >= 6) {
    const seconds = Math.max(1, Math.ceil((rate.expires_at - now) / 1000));
    return authJson({ error: 'Много неудачных попыток. Попробуйте через 15 минут.' }, 429, { 'retry-after': String(seconds) });
  }
  const derived = await authDerive(input.password, authFromHex(record.salt), record.iterations);
  const passwordValid = authEqual(derived, authFromHex(record.hash));
  const loginValid = authEqual(authEncoder.encode(input.login.trim().toLowerCase()), authEncoder.encode(env.ADMIN_LOGIN.toLowerCase()));
  if (!passwordValid || !loginValid) {
    await env.DB.prepare('INSERT INTO request_limits (key,count,expires_at) VALUES (?,1,?) ON CONFLICT(key) DO UPDATE SET count=CASE WHEN expires_at<=? THEN 1 ELSE count+1 END,expires_at=CASE WHEN expires_at<=? THEN excluded.expires_at ELSE expires_at END').bind(rateKey, now + 15 * 60 * 1000, now, now).run();
    return authJson({ error: 'Неверный логин или пароль.' }, 401);
  }
  const token = authHex(crypto.getRandomValues(new Uint8Array(32)));
  await env.DB.batch([
    env.DB.prepare('DELETE FROM request_limits WHERE key=?').bind(rateKey),
    env.DB.prepare('INSERT INTO admin_sessions (token_hash, credential_version, created_at) VALUES (?,?,?)').bind(await authDigest(token), await authDigest(env.ADMIN_LOGIN + ':' + env.ADMIN_PASSWORD_HASH), new Date().toISOString())
  ]);
  return authJson({ ok: true }, 200, { 'set-cookie': adminSessionCookie(token) });
}

export async function logoutAdmin(request, env) {
  authSameOrigin(request);
  const token = authReadToken(request);
  if (token) await env.DB.prepare('DELETE FROM admin_sessions WHERE token_hash=?').bind(await authDigest(token)).run();
  return authJson({ ok: true }, 200, { 'set-cookie': adminSessionCookie('', true) });
}
