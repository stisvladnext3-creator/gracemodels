<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

const GRACE_VERSION = '2.5.2';
const GRACE_SCHEMA_VERSION = 7;
const GRACE_CONSENT_VERSION = '2026-11-23.1';
const GRACE_COOKIE = 'grace_models_admin';
const GRACE_COOKIE_AGE = 34560000;

final class GraceError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}
function grace_error(string $message, int $status = 400): never { throw new GraceError($message, $status); }
function grace_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function grace_id(): string {
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function grace_text(mixed $value, int $maximum = 400): string {
    if (!is_scalar($value) && $value !== null) return '';
    $text = trim((string)$value);
    if (!preg_match('//u', $text)) grace_error('Проверьте кодировку введенных данных.');
    if (function_exists('mb_substr')) return mb_substr($text, 0, $maximum, 'UTF-8');
    preg_match('/^.{0,'.$maximum.'}/us', $text, $match); return $match[0] ?? '';
}
function grace_escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function grace_headers(): void {
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: base-uri 'self'; form-action 'self'; object-src 'none'");
}
function grace_json(mixed $data, int $status = 200): never {
    http_response_code($status); grace_headers(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
}
function grace_html(string $html, int $status = 200): never {
    http_response_code($status); grace_headers(); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); echo $html; exit;
}
function grace_not_found(): never {
    grace_html('<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Страница не найдена — Grace Models</title><body style="font-family:Arial;padding:10vw;background:#f6f5f1"><h1>Этой страницы нет.</h1><a href="/">Вернуться на главную ↗</a></body></html>',404);
}
function grace_handle_exception(Throwable $exception): never {
    $known = $exception instanceof GraceError;
    $status = $known ? $exception->status : 500;
    $message = $known ? $exception->getMessage() : 'Не удалось выполнить действие. Попробуйте еще раз.';
    if (!$known) {
        error_log('Grace Models: '.$exception->getMessage());
        if ($exception instanceof PDOException && in_array((string)$exception->getCode(), ['23000','23505'], true)) {
            $status = 409; $message = 'Такой адрес анкеты уже используется. Измените его.';
        }
    }
    grace_json(['error'=>$message],$status);
}
function grace_config(): array {
    static $config;
    if ($config !== null) return $config;
    $file = GRACE_ROOT.'/config.php'; if (!is_file($file)) grace_error('Завершите установку сайта.',503);
    $loaded = require $file;
    if (!is_array($loaded) || empty($loaded['installed']) || empty($loaded['data_dir'])) grace_error('Проверьте настройки сайта.',503);
    return $config = $loaded;
}
function grace_normalize_origin(string $value): ?string {
    if (str_contains($value,"\n") || str_contains($value,"\r")) return null;
    try { $parts=parse_url($value); } catch (ValueError) { return null; }
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''),['http','https'],true) || empty($parts['host'])) return null;
    foreach (['user','pass','query','fragment'] as $part) if (array_key_exists($part,$parts)) return null;
    if (!in_array($parts['path'] ?? '',['','/'],true)) return null;
    $scheme=strtolower($parts['scheme']); $host=strtolower($parts['host']);
    if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/',$host)) return null;
    $port=(int)($parts['port'] ?? ($scheme==='https'?443:80));
    if ($port<1 || $port>65535) return null;
    return $scheme.'://'.$host.((($scheme==='https' && $port===443)||($scheme==='http' && $port===80))?'':':'.$port);
}
function grace_https_origin(string $origin): string {
    return str_starts_with($origin,'http://')?(grace_normalize_origin('https://'.substr($origin,7)) ?? $origin):$origin;
}
function grace_origin_allowed(string $candidate, string $expected): bool {
    $candidate=grace_normalize_origin($candidate); $expected=grace_normalize_origin($expected);
    return $candidate!==null && $expected!==null && ($candidate===$expected || $candidate===grace_https_origin($expected));
}
function grace_request_https(): bool {
    if ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || (string)($_SERVER['SERVER_PORT'] ?? '')==='443') return true;
    // These signals only enable the Secure flag. They never authorize a host.
    return strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]))==='https' || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''))==='on';
}
function grace_origin(): string {
    if (is_file(GRACE_ROOT.'/config.php')) {
        $config = grace_config();
        if (!empty($config['site_url'])) {
            $origin=grace_normalize_origin((string)$config['site_url']);
            if ($origin===null) grace_error('Проверьте адрес сайта в config.php.',503);
            return $origin;
        }
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/',$host)) grace_error('Некорректный адрес сайта.');
    return (grace_request_https() ? 'https' : 'http').'://'.$host;
}
function grace_same_origin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $matches=true;
    if ($origin!=='') {
        if (is_file(GRACE_ROOT.'/config.php')) $matches=grace_origin_allowed($origin,grace_origin());
        else {
            $parts=parse_url($origin);
            $host=strtolower(($parts['host'] ?? '').(isset($parts['port'])?':'.$parts['port']:''));
            $matches=in_array($parts['scheme'] ?? '',['http','https'],true) && $host===strtolower($_SERVER['HTTP_HOST'] ?? '');
        }
    }
    if (!$matches || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') grace_error('Недопустимый источник запроса.',403);
}
function grace_remember_https_origin(): void {
    $stored=grace_origin(); $incoming=grace_normalize_origin((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if (!str_starts_with($stored,'http://') || $incoming!==grace_https_origin($stored)) return;
    // Only called after successful password verification; keep every other setting.
    $config=grace_config(); $config['site_url']=$incoming;
    if (!is_writable(GRACE_ROOT)) return;
    try { grace_atomic_php(GRACE_ROOT.'/config.php',$config); }
    catch (GraceError) { error_log('Grace Models: update site_url to HTTPS in config.php.'); }
}
function grace_read_json(int $limit = 131072): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0); if ($length > $limit) grace_error('Запрос слишком большой.',413);
    $raw = file_get_contents('php://input',false,null,0,$limit+1);
    if ($raw === false || strlen($raw) > $limit) grace_error('Запрос слишком большой.',413);
    try { $value = json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch (JsonException) { grace_error('Некорректный формат запроса.'); }
    if (!is_array($value) || array_is_list($value)) grace_error('Ожидается объект с данными.');
    return $value;
}
function grace_media_url(mixed $value): string {
    $url = grace_text($value,1200);
    if (str_starts_with($url,'/images/') || str_starts_with($url,'/media/')) return str_contains($url,'..') ? '' : $url;
    return str_starts_with($url,'https://') && filter_var($url,FILTER_VALIDATE_URL) ? $url : '';
}
function grace_atomic_php(string $file, array|string $value): void {
    $temporary = $file.'.'.bin2hex(random_bytes(5)).'.tmp';
    $body = "<?php\n// Generated on your hosting. Do not publish this file.\nreturn ".var_export($value,true).";\n";
    if (file_put_contents($temporary,$body,LOCK_EX) === false) grace_error('Не удалось сохранить настройки. Проверьте права записи PHP.');
    @chmod($temporary,0600);
    if (!rename($temporary,$file)) { @unlink($temporary); grace_error('Не удалось сохранить настройки сайта.'); }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file,true);
}
function grace_send_file(string $file, string $mime, ?string $downloadName = null, bool $private = false): never {
    if (!is_file($file)) grace_not_found();
    grace_headers(); header('Content-Type: '.$mime); header('Content-Length: '.filesize($file));
    header('Cache-Control: '.($private ? 'private, no-store' : ((str_starts_with($mime,'image/') || str_starts_with($mime,'font/'))?'public, max-age=86400':'no-cache')));
    if ($downloadName !== null) {
        $fallback = preg_replace('/[^a-zA-Z0-9._-]/','_', $downloadName) ?: 'attachment';
        header('Content-Disposition: attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($downloadName));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file); exit;
}
function grace_template(string $name, bool $private = false): never {
    $file = GRACE_ROOT.'/public/'.$name; if (!is_file($file)) grace_not_found();
    if ($private) header('X-Robots-Tag: noindex, nofollow');
    grace_html(strtr((string)file_get_contents($file),grace_chrome_template_values($private?'ru':grace_request_language())));
}
function grace_asset_file(string $relative): string {
    $relative=ltrim($relative,'/');
    $rootFile=GRACE_ROOT.'/'.$relative;
    return is_file($rootFile)?$rootFile:GRACE_ROOT.'/public/'.$relative;
}

require_once GRACE_ROOT.'/app/database.php';
require_once GRACE_ROOT.'/app/auth.php';
require_once GRACE_ROOT.'/app/mail.php';
require_once GRACE_ROOT.'/app/legal.php';
require_once GRACE_ROOT.'/app/localization.php';
require_once GRACE_ROOT.'/app/site-editor.php';
require_once GRACE_ROOT.'/app/install.php';
require_once GRACE_ROOT.'/app/api.php';

function grace_dispatch(): never {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/');
    $path = rtrim($path,'/') ?: '/';
    if (str_contains($path,"\0") || preg_match('#(?:^|/)\.\.(?:/|$)#',$path)) grace_not_found();
    $assets = ['app.js'=>'text/javascript; charset=utf-8','catalog.js'=>'text/javascript; charset=utf-8','form-fields.js'=>'text/javascript; charset=utf-8','site-locale.js'=>'text/javascript; charset=utf-8','chrome.css'=>'text/css; charset=utf-8','model-options.js'=>'text/javascript; charset=utf-8','cookies.js'=>'text/javascript; charset=utf-8','cookies.css'=>'text/css; charset=utf-8','legal.css'=>'text/css; charset=utf-8','style.css'=>'text/css; charset=utf-8','favicon.svg'=>'image/svg+xml','login.css'=>'text/css; charset=utf-8','login.js'=>'text/javascript; charset=utf-8','install.js'=>'text/javascript; charset=utf-8'];
    $name = ltrim($path,'/');
    if (isset($assets[$name])) grace_send_file(grace_asset_file($name),$assets[$name]);
    if (preg_match('#^/fonts/([a-zA-Z0-9._-]+\.(?:woff2|ttf))$#',$path,$font)) grace_send_file(grace_asset_file('fonts/'.$font[1]),str_ends_with($font[1],'.woff2')?'font/woff2':'font/ttf');
    if (preg_match('#^/images/([a-zA-Z0-9._-]+\.(?:jpg|jpeg|png|webp))$#i',$path,$matches)) {
        $extension = strtolower(pathinfo($matches[1],PATHINFO_EXTENSION));
        grace_send_file(grace_asset_file('images/'.$matches[1]),['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$extension]);
    }
    if ($path === '/install.php') grace_install_page();
    if ($path==='/consent') grace_legal_page('consent');
    if ($path==='/privacy') grace_legal_page('privacy');
    if ($path === '/cookies') grace_template('cookies.html');
    if ($path === '/robots.txt') { header('Content-Type: text/plain'); echo "User-agent: *\nDisallow: /s/\nDisallow: /api/\nDisallow: /app/\nDisallow: /public/\n"; exit; }
    if (!is_file(GRACE_ROOT.'/config.php')) {
        if (str_starts_with($path,'/api/')) grace_error('Завершите установку сайта.',503);
        grace_html('<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Grace Models</title><body style="font-family:Arial;padding:12vw;background:#f6f5f1"><h1>Grace Models</h1><p>Сайт готовится к открытию.</p></body></html>',503);
    }
    $config = grace_config();
    if ($path === $config['admin_path']) {
        $session = grace_admin_session(); if ($session) grace_renew_cookie($session['token']);
        grace_template($session ? 'admin.html' : 'login.html',true);
    }
    if (in_array($path,['/admin.js','/admin.css','/site-editor.js'],true)) {
        grace_require_admin(); header('X-Robots-Tag: noindex, nofollow');
        grace_send_file(grace_asset_file($path),str_ends_with($path,'.js')?'text/javascript; charset=utf-8':'text/css; charset=utf-8',null,true);
    }
    if (str_starts_with($path,'/api/')) grace_api($path);
    if (str_starts_with($path,'/media/')) {
        $key = substr($path,7); if (!str_starts_with($key,'public/')) grace_not_found();
        grace_send_file(grace_file_path($key),grace_file_mime($key));
    }
    if ($path === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (['/','/models','/about','/contacts','/become-a-model','/news'] as $uri) echo '<url><loc>'.grace_escape(grace_origin().$uri).'</loc></url>';
        echo '</urlset>'; exit;
    }
    if (in_array($path,['/','/index.php','/models','/about','/contacts','/become-a-model','/booking','/news','/privacy'],true) || preg_match('#^/(models|news)/[a-zA-Z0-9-]+$#',$path)) grace_template('index.html');
    grace_not_found();
}
