<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('GRACE_ROOT',dirname(__DIR__));
require GRACE_ROOT.'/app/bootstrap.php';
try {
    $password=getenv('GRACE_NEW_ADMIN_PASSWORD');
    if (!is_string($password) || strlen($password)<10 || strlen($password)>72 || str_contains($password,"\0")) throw new RuntimeException('Передайте новый пароль длиной 10–72 байта в переменной окружения GRACE_NEW_ADMIN_PASSWORD.');
    $config=grace_config(); $config['admin_hash']=password_hash($password,PASSWORD_DEFAULT);
    grace_atomic_php(GRACE_ROOT.'/config.php',$config);
    echo "Пароль обновлен. На устройствах потребуется войти заново.\nПанель: ".$config['site_url'].$config['admin_path']."\n";
} catch (Throwable $exception) { fwrite(STDERR,$exception->getMessage()."\n"); exit(1); }
