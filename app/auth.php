<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

function grace_auth_version(): string { $c=grace_config(); return hash('sha256',$c['admin_login'].':'.$c['admin_hash']); }
function grace_cookie(string $token, bool $clear = false): void {
    $source=grace_normalize_origin((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    setcookie(GRACE_COOKIE,$clear?'':$token,[
        'expires'=>$clear?time()-3600:time()+GRACE_COOKIE_AGE,'path'=>'/',
        'secure'=>grace_request_https() || str_starts_with(grace_origin(),'https://') || ($source!==null && str_starts_with($source,'https://') && grace_origin_allowed($source,grace_origin())),'httponly'=>true,'samesite'=>'Lax'
    ]);
}
function grace_renew_cookie(string $token): void { grace_cookie($token); header('Cache-Control: private, no-store'); }
function grace_admin_session(): ?array {
    $token=$_COOKIE[GRACE_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/',$token)) return null;
    $row=grace_first('SELECT token_hash,credential_version FROM '.grace_table('admin_sessions').' WHERE token_hash=?',[hash('sha256',$token)]);
    if (!$row || !hash_equals(grace_auth_version(),$row['credential_version'])) return null;
    return ['token'=>$token,'token_hash'=>$row['token_hash'],'login'=>grace_config()['admin_login']];
}
function grace_require_admin(): array {
    $session=grace_admin_session(); if (!$session) grace_error('Войдите в панель администратора.',401);
    grace_renew_cookie($session['token']); return $session;
}
function grace_limiter_key(string $purpose): string { return $purpose.':'.hash('sha256',$_SERVER['REMOTE_ADDR'] ?? 'unknown'); }
function grace_limiter_check(string $key, int $maximum): void {
    $row=grace_first('SELECT attempt_count,expires_at FROM '.grace_table('request_limits').' WHERE limiter_key=?',[$key]);
    if ($row && (int)$row['expires_at']>time() && (int)$row['attempt_count']>=$maximum) {
        header('Retry-After: '.max(1,(int)$row['expires_at']-time()));
        grace_error('Слишком много попыток. Попробуйте позднее.',429);
    }
}
function grace_limiter_add(string $key, int $seconds): void {
    $table=grace_table('request_limits'); $now=time(); $expires=$now+$seconds;
    if (grace_config()['database']['driver']==='mysql') {
        grace_query("INSERT INTO $table (limiter_key,attempt_count,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempt_count=IF(expires_at<=?,1,attempt_count+1),expires_at=IF(expires_at<=?,VALUES(expires_at),expires_at)",[$key,$expires,$now,$now]);
    } else {
        grace_query("INSERT INTO $table (limiter_key,attempt_count,expires_at) VALUES (?,1,?) ON CONFLICT(limiter_key) DO UPDATE SET attempt_count=CASE WHEN expires_at<=? THEN 1 ELSE attempt_count+1 END,expires_at=CASE WHEN expires_at<=? THEN excluded.expires_at ELSE expires_at END",[$key,$expires,$now,$now]);
    }
}
function grace_login(): never {
    grace_same_origin(); $input=grace_read_json(4096);
    $login=$input['login'] ?? null; $password=$input['password'] ?? null;
    if (!is_string($login) || !is_string($password) || strlen($login)>100 || strlen($password)>72 || str_contains($password,"\0")) grace_error('Укажите логин и пароль.');
    $key=grace_limiter_key('login'); grace_limiter_check($key,6); $config=grace_config();
    $valid=password_verify($password,$config['admin_hash']);
    if (!hash_equals($config['admin_login'],strtolower(trim($login))) || !$valid) {
        grace_limiter_add($key,900); grace_error('Неверный логин или пароль.',401);
    }
    grace_query('DELETE FROM '.grace_table('request_limits').' WHERE limiter_key=?',[$key]);
    grace_remember_https_origin();
    $token=bin2hex(random_bytes(32));
    grace_insert('admin_sessions',['token_hash'=>hash('sha256',$token),'credential_version'=>grace_auth_version(),'created_at'=>grace_now()]);
    grace_cookie($token); grace_json(['ok'=>true]);
}
function grace_logout(): never {
    grace_same_origin(); $token=$_COOKIE[GRACE_COOKIE] ?? '';
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/',$token)) grace_query('DELETE FROM '.grace_table('admin_sessions').' WHERE token_hash=?',[hash('sha256',$token)]);
    grace_cookie('',true); grace_json(['ok'=>true]);
}
