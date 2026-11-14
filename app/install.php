<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

function grace_install_page(): never {
    if (is_file(GRACE_ROOT.'/config.php')) grace_not_found();
    header('X-Robots-Tag: noindex, nofollow');
    $drivers=class_exists('PDO')?PDO::getAvailableDrivers():[];
    $requirements=['PHP 8.1 или новее'=>PHP_VERSION_ID>=80100,'PDO: MySQL или SQLite'=>(in_array('mysql',$drivers,true)||in_array('sqlite',$drivers,true)),'Проверка типов файлов (Fileinfo)'=>extension_loaded('fileinfo'),'Запись настроек PHP'=>is_writable(GRACE_ROOT)];
    $ready=!in_array(false,$requirements,true); $failure='';
    $keyFile=GRACE_ROOT.'/install-key.php';
    if (!is_file($keyFile) && is_writable(GRACE_ROOT)) {
        $handle=@fopen($keyFile,'x');
        if ($handle) { fwrite($handle,"<?php\n// Installation key: open this file in the hosting file manager.\nreturn '".bin2hex(random_bytes(24))."';\n"); fclose($handle); @chmod($keyFile,0600); }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        try {
            grace_same_origin();
            if (!$ready || !is_file($keyFile)) grace_error('Хостинг пока не готов. Проверьте требования ниже.');
            $actualKey=require $keyFile; $provided=$_POST['install_key'] ?? '';
            if (!is_string($actualKey) || !is_string($provided) || !hash_equals($actualKey,trim($provided))) grace_error('Неверный код установки. Откройте install-key.php через файловый менеджер хостинга.',403);
            $login=strtolower(grace_text($_POST['admin_login'] ?? '',60));
            if (!preg_match('/^[a-z0-9_.-]{3,60}$/',$login)) grace_error('Логин: от 3 до 60 латинских букв, цифр, точек или дефисов.');
            $password=$_POST['admin_password'] ?? ''; $confirm=$_POST['admin_password_repeat'] ?? '';
            if (!is_string($password) || strlen($password)<10 || strlen($password)>72 || str_contains($password,"\0")) grace_error('Укажите пароль длиной от 10 до 72 байт.');
            if (!is_string($confirm) || !hash_equals($password,$confirm)) grace_error('Пароли не совпали.');
            $driver=grace_text($_POST['db_driver'] ?? 'sqlite',10);
            if (!in_array($driver,['mysql','sqlite'],true) || !in_array($driver,$drivers,true)) grace_error('Выбранный тип базы не поддерживается хостингом.');
            $prefix=grace_text($_POST['db_prefix'] ?? 'gm_',20);
            if (!preg_match('/^[a-zA-Z0-9_]{1,20}$/',$prefix)) grace_error('Префикс таблиц: латинские буквы, цифры и знак подчеркивания.');
            $dataDir=grace_text($_POST['data_dir'] ?? '',1200);
            if ($dataDir==='' || !str_starts_with($dataDir,DIRECTORY_SEPARATOR)) grace_error('Укажите полный путь к папке данных вне папки сайта.');
            if (!is_dir($dataDir) && !@mkdir($dataDir,0700,true)) grace_error('PHP не может создать папку данных. Создайте ее в файловом менеджере хостинга и укажите полный путь.');
            $dataDir=realpath($dataDir); $webRoot=realpath(GRACE_ROOT);
            if (!$dataDir || !is_writable($dataDir)) grace_error('Папка данных недоступна для записи PHP.');
            if ($dataDir===$webRoot || str_starts_with($dataDir,$webRoot.DIRECTORY_SEPARATOR)) grace_error('Папка данных должна находиться вне папки сайта (public_html или www).');
            @chmod($dataDir,0700);
            if (!is_dir($dataDir.'/uploads') && !mkdir($dataDir.'/uploads',0700,true)) grace_error('Не удалось создать папку для фотографий.');
            $siteUrl=rtrim(grace_text($_POST['site_url'] ?? grace_origin(),500),'/');
            $url=parse_url($siteUrl);
            if (!$url || !in_array($url['scheme'] ?? '',['https','http'],true) || empty($url['host']) || !empty($url['user']) || !empty($url['query']) || !empty($url['fragment']) || (!empty($url['path']) && $url['path']!=='/')) grace_error('Укажите адрес корня сайта: https://ваш-домен.ru');
            $siteUrl=strtolower($url['scheme']).'://'.strtolower($url['host']);
            if (isset($url['port']) && !(($url['scheme']==='https' && $url['port']===443) || ($url['scheme']==='http' && $url['port']===80))) $siteUrl.=':'.$url['port'];
            $config=['installed'=>true,'version'=>GRACE_VERSION,'site_url'=>$siteUrl,'data_dir'=>$dataDir,'admin_path'=>'/s/'.bin2hex(random_bytes(12)),'admin_login'=>$login,'admin_hash'=>password_hash($password,PASSWORD_DEFAULT),'database'=>['driver'=>$driver,'host'=>grace_text($_POST['db_host'] ?? 'localhost',200),'port'=>(int)($_POST['db_port'] ?? 3306),'name'=>grace_text($_POST['db_name'] ?? '',200),'user'=>grace_text($_POST['db_user'] ?? '',200),'password'=>(string)($_POST['db_password'] ?? ''),'prefix'=>$prefix]];
            if ($driver==='mysql' && ($config['database']['name']==='' || $config['database']['user']==='')) grace_error('Укажите имя базы и пользователя MySQL.');
            try { $pdo=grace_connect($config); } catch (PDOException) { grace_error('Не удалось подключиться к базе. Проверьте имя базы, сервер, пользователя и пароль.'); }
            // A pre-existing installation with this prefix must never be overwritten.
            grace_create_schema($pdo,$config);
            $count=(int)$pdo->query('SELECT COUNT(*) FROM '.grace_table('settings',$config))->fetchColumn();
            if ($count!==0) grace_error('Эти таблицы уже содержат данные. Выберите другую базу или другой префикс.');
            grace_seed_database($pdo,$config,isset($_POST['demo_mode']),grace_text($_POST['agency_name'] ?? 'Grace Models',100) ?: 'Grace Models');
            grace_atomic_php(GRACE_ROOT.'/config.php',$config);
            @unlink($keyFile);
            header('Location: '.$config['admin_path'],true,303); exit;
        } catch (GraceError $exception) { $failure=$exception->getMessage(); http_response_code($exception->status); }
        catch (Throwable $exception) { error_log('Grace Models installation: '.$exception->getMessage()); $failure='Установка не завершилась. Проверьте права на папку и подключение базы данных.'; http_response_code(500); }
    }
    $defaultDir=dirname(GRACE_ROOT).'/grace-models-data-'.substr(hash('sha256',GRACE_ROOT),0,10);
    $driver=in_array('sqlite',$drivers,true)?'sqlite':'mysql';
    $fields=function(string $name,string $title,string $value='',string $type='text',bool $required=false): string {
        return '<label class="setup-field">'.grace_escape($title).'<input name="'.$name.'" type="'.$type.'" value="'.grace_escape($type==='password'?'':$value).'" '.($required?'required':'').' autocomplete="'.($type==='password'?'new-password':'off').'"></label>';
    };
    $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Установка — Grace Models</title><link rel="stylesheet" href="/login.css"><script src="/install.js" defer></script><style>body{padding:35px 20px}.setup{max-width:760px;margin:auto}.setup h1{margin-top:35px}.setup p{font-size:12px;color:#7b826d;line-height:1.8}.checks{display:flex;gap:12px;flex-wrap:wrap;font-size:10px;padding:20px 0;border-block:1px solid #ddd}.checks span{padding:7px 10px;background:#e9eddf;border-radius:4px}.checks .bad{background:#f3dfdc;color:#a24d3e}.setup-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 22px}.setup-field{display:block;font-size:11px;color:#677257}.setup-field input,.setup-field select{margin-top:8px;width:100%}.setup h2{font-size:19px;font-weight:400;margin:30px 0 20px}.setup-error{padding:16px;background:#f4e3dc;color:#963f32;margin-top:20px}.setup small{font-size:10px;color:#7d846e;line-height:1.7;display:block;margin-bottom:15px}.setup .full{grid-column:1/-1}.setup .submit{margin:25px 0 40px}.setup-check{font-size:11px;display:flex;align-items:center;gap:10px}.setup-check input{width:15px;min-height:15px;margin:0}.setup select{padding:12px;border:1px solid #d9ddce;border-radius:5px;background:white;font:inherit;font-size:12px}.setup #mysql-fields[hidden]{display:none}@media(max-width:600px){.setup-grid{grid-template-columns:1fr}.checks{font-size:9px}.setup h1{font-size:33px}}</style></head><body><main class="setup"><a class="login-wordmark" href="/">GRACE MODELS<span>УСТАНОВКА САЙТА</span></a><h1>Ваше агентство.<br>На вашем хостинге.</h1><p>Заполните настройки один раз. После установки каталог, заявки и панель будут работать на вашем домене.</p><div class="checks">';
    foreach ($requirements as $label=>$ok) $html.='<span class="'.($ok?'':'bad').'">'.($ok?'✓ ':'× ').grace_escape($label).'</span>';
    $html.='</div>'.($failure!==''?'<div class="setup-error" role="alert">'.grace_escape($failure).'</div>':'').'<form method="post" action="/install.php"><h2>1. Подтвердите доступ к файлам хостинга</h2>'.$fields('install_key','Код из файла install-key.php','','text',true).'<small>Откройте install-key.php в файловом менеджере хостинга и скопируйте строку после return. Код не показывается посетителям сайта.</small><h2>2. Агентство и администратор</h2><div class="setup-grid">'.$fields('agency_name','Название агентства','Grace Models','text',true).$fields('site_url','Адрес сайта',grace_origin(),'url',true).$fields('admin_login','Логин администратора','gracemodels','text',true).'<div></div>'.$fields('admin_password','Пароль администратора','','password',true).$fields('admin_password_repeat','Повторите пароль','','password',true).'</div><h2>3. Хранение данных</h2><label class="setup-field">База данных<select name="db_driver" id="db-driver">';
    foreach (['sqlite'=>'SQLite — отдельный файл, без настройки MySQL','mysql'=>'MySQL / MariaDB — база из панели хостинга'] as $key=>$label) if (in_array($key,$drivers,true)) $html.='<option value="'.$key.'" '.($driver===$key?'selected':'').'>'.grace_escape($label).'</option>';
    $html.='</select></label><div class="setup-grid" id="mysql-fields">'.$fields('db_host','Сервер MySQL','localhost').$fields('db_port','Порт MySQL','3306','number').$fields('db_name','Имя базы').$fields('db_user','Пользователь MySQL').$fields('db_password','Пароль MySQL','','password').$fields('db_prefix','Префикс таблиц','gm_').'</div>'.$fields('data_dir','Полный путь к папке данных вне public_html / www',$defaultDir,'text',true).'<small>Здесь будут база SQLite и фотографии. Если PHP не может создать папку, создайте ее в файловом менеджере хостинга и вставьте ее полный путь.</small><label class="setup-check"><input type="checkbox" name="demo_mode" checked>Добавить демонстрационные анкеты и статьи</label><button class="submit" type="submit" '.($ready?'':'disabled').'><span>Установить Grace Models</span><span>↗</span></button></form></main></body></html>';
    grace_html($html,http_response_code() ?: 200);
}
