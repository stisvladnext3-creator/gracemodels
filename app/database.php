<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

function grace_connect(array $config): PDO {
    $database = $config['database'];
    if ($database['driver'] === 'mysql') {
        $dsn = 'mysql:host='.$database['host'].';port='.(int)$database['port'].';dbname='.$database['name'].';charset=utf8mb4';
        $pdo = new PDO($dsn,$database['user'],$database['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    } else {
        $pdo = new PDO('sqlite:'.$config['data_dir'].'/grace-models.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('PRAGMA busy_timeout=5000'); $pdo->exec('PRAGMA journal_mode=WAL');
    }
    return $pdo;
}
function grace_db(): PDO {
    static $pdo;
    if ($pdo === null) {
        $config=grace_config();
        $connection=grace_connect($config);
        grace_upgrade_database($connection,$config);
        $pdo=$connection;
    }
    return $pdo;
}

function grace_database_columns(PDO $pdo, string $table, bool $mysql): array {
    $rows=$pdo->query($mysql?'SHOW COLUMNS FROM '.$table:'PRAGMA table_info('.$table.')')->fetchAll();
    return array_column($rows,$mysql?'Field':'name');
}
function grace_application_columns(): array {
    return ['telegram'=>"VARCHAR(100) NOT NULL DEFAULT ''",'birth_date'=>"VARCHAR(10) NOT NULL DEFAULT ''",'bust_cup'=>"VARCHAR(3) NOT NULL DEFAULT ''",'bust'=>'INTEGER NULL','waist'=>'INTEGER NULL','hips'=>'INTEGER NULL','shoe'=>'INTEGER NULL','hair'=>"VARCHAR(30) NOT NULL DEFAULT ''",'eyes'=>"VARCHAR(30) NOT NULL DEFAULT ''",'file_link'=>"VARCHAR(1200) NOT NULL DEFAULT ''"];
}

function grace_upgrade_database(PDO $pdo, array $config): void {
    $settings=grace_table('settings',$config);
    $stored=$pdo->query("SELECT setting_value FROM $settings WHERE setting_key='schema_version'")->fetchColumn();
    if ((int)$stored>=GRACE_SCHEMA_VERSION) return;
    $mysql=$config['database']['driver']==='mysql';
    $changes=[];
    if ((int)$stored<2) $changes=['models'=>['bust_cup'=>"VARCHAR(3) NOT NULL DEFAULT ''"],'inquiries'=>['consent_version'=>"VARCHAR(40) NOT NULL DEFAULT ''",'consent_at'=>"VARCHAR(40) NOT NULL DEFAULT ''"]];
    $changes['inquiries']=[...($changes['inquiries'] ?? []),...grace_application_columns()];
    foreach ($changes as $name=>$definitions) {
        $table=grace_table($name,$config);
        foreach ($definitions as $column=>$definition) {
            if (in_array($column,grace_database_columns($pdo,$table,$mysql),true)) continue;
            try { $pdo->exec('ALTER TABLE '.$table.' ADD COLUMN `'.$column.'` '.$definition); }
            catch (PDOException $exception) {
                // Another request may have added this column while we waited.
                if (!in_array($column,grace_database_columns($pdo,$table,$mysql),true)) throw $exception;
            }
        }
    }
    $statement=$pdo->query("SELECT setting_value FROM $settings WHERE setting_key='agency'");
    $agency=json_decode((string)$statement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
    if (is_array($agency) && in_array($agency['email'] ?? '',['','hello@example.com','info@example.com'],true)) {
        $agency['email']='Info@grace-models.com';
        $pdo->prepare("UPDATE $settings SET setting_value=? WHERE setting_key='agency'")->execute([json_encode($agency,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    $pdo->exec("UPDATE $settings SET setting_value='".GRACE_SCHEMA_VERSION."' WHERE setting_key='schema_version'");
}
function grace_table(string $name, ?array $config = null): string {
    $prefix = ($config ?? grace_config())['database']['prefix'];
    if (!preg_match('/^[a-zA-Z0-9_]+$/',$prefix.$name)) throw new RuntimeException('Invalid database table');
    return '`'.$prefix.$name.'`';
}
function grace_query(string $sql, array $values = []): PDOStatement { $statement=grace_db()->prepare($sql); $statement->execute($values); return $statement; }
function grace_first(string $sql, array $values = []): ?array { $row=grace_query($sql,$values)->fetch(); return $row === false ? null : $row; }
function grace_rows(string $sql, array $values = []): array { return grace_query($sql,$values)->fetchAll(); }
function grace_insert(string $table, array $record, ?PDO $pdo = null, ?array $config = null): void {
    $columns = array_keys($record);
    foreach ($columns as $column) if (!preg_match('/^[a-zA-Z0-9_]+$/',$column)) throw new RuntimeException('Invalid database column');
    $sql='INSERT INTO '.grace_table($table,$config).' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
    ($pdo ?? grace_db())->prepare($sql)->execute(array_map(fn($v)=>is_bool($v)?(int)$v:$v,array_values($record)));
}
function grace_update(string $table, array $record, string $id): void {
    $columns=array_keys($record); foreach ($columns as $column) if (!preg_match('/^[a-zA-Z0-9_]+$/',$column)) throw new RuntimeException('Invalid database column');
    grace_query('UPDATE '.grace_table($table).' SET '.implode(',',array_map(fn($key)=>'`'.$key.'`=?',$columns)).' WHERE id=?',[...array_map(fn($v)=>is_bool($v)?(int)$v:$v,array_values($record)),$id]);
}
function grace_setting(string $key): mixed {
    $row=grace_first('SELECT setting_value FROM '.grace_table('settings').' WHERE setting_key=?',[$key]);
    return $row ? json_decode($row['setting_value'],true,64,JSON_THROW_ON_ERROR) : null;
}
function grace_save_setting(string $key, mixed $value): void {
    grace_query('UPDATE '.grace_table('settings').' SET setting_value=? WHERE setting_key=?',[json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$key]);
}
function grace_settings(): array { return ['applications_enabled'=>true,...(grace_setting('agency') ?? grace_seed()['settings'])]; }
function grace_seed(): array { static $seed; return $seed ??= json_decode((string)file_get_contents(GRACE_ROOT.'/app/seed.json'),true,64,JSON_THROW_ON_ERROR); }
function grace_audit(string $action, string $subject): void { grace_insert('activity',['id'=>grace_id(),'action'=>$action,'subject'=>grace_text($subject,180),'created_at'=>grace_now()]); }
function grace_create_schema(PDO $pdo, array $config): void {
    $mysql=$config['database']['driver']==='mysql'; $text=$mysql?'LONGTEXT':'TEXT';
    $schemas=[
        'models'=>"id VARCHAR(64) PRIMARY KEY, slug VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, name_en VARCHAR(160) NOT NULL, gender VARCHAR(20) NOT NULL, category VARCHAR(30) NOT NULL, city VARCHAR(160) NOT NULL, height INTEGER NOT NULL, bust_cup VARCHAR(3) NOT NULL DEFAULT '', bust INTEGER NOT NULL, waist INTEGER NOT NULL, hips INTEGER NOT NULL, shoe INTEGER NOT NULL, hair VARCHAR(30) NOT NULL, eyes VARCHAR(30) NOT NULL, cover TEXT NOT NULL, gallery $text NOT NULL, bio $text NOT NULL, bio_en $text NOT NULL, published INTEGER NOT NULL DEFAULT 0, featured INTEGER NOT NULL DEFAULT 0, demo INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL",
        'inquiries'=>"id VARCHAR(64) PRIMARY KEY, kind VARCHAR(30) NOT NULL, name VARCHAR(160) NOT NULL, email VARCHAR(254) NOT NULL, phone VARCHAR(100) NOT NULL, city VARCHAR(160) NOT NULL, age INTEGER NULL, height INTEGER NULL, instagram TEXT NOT NULL, model_id VARCHAR(64) NOT NULL, model_name VARCHAR(160) NOT NULL, company VARCHAR(254) NOT NULL, shooting_date VARCHAR(40) NOT NULL, message $text NOT NULL, attachments $text NOT NULL, status VARCHAR(30) NOT NULL, internal_note $text NOT NULL, consent INTEGER NOT NULL, consent_version VARCHAR(40) NOT NULL DEFAULT '', consent_at VARCHAR(40) NOT NULL DEFAULT '', created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL",
        'news'=>"id VARCHAR(64) PRIMARY KEY, title VARCHAR(300) NOT NULL, title_en VARCHAR(300) NOT NULL, summary $text NOT NULL, summary_en $text NOT NULL, body $text NOT NULL, body_en $text NOT NULL, image TEXT NOT NULL, published INTEGER NOT NULL, published_at VARCHAR(40) NOT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL",
        'settings'=>"setting_key VARCHAR(80) PRIMARY KEY, setting_value $text NOT NULL",
        'activity'=>"id VARCHAR(64) PRIMARY KEY, action VARCHAR(200) NOT NULL, subject TEXT NOT NULL, created_at VARCHAR(40) NOT NULL",
        'admin_sessions'=>"token_hash VARCHAR(64) PRIMARY KEY, credential_version VARCHAR(64) NOT NULL, created_at VARCHAR(40) NOT NULL",
        'request_limits'=>"limiter_key VARCHAR(100) PRIMARY KEY, attempt_count INTEGER NOT NULL, expires_at BIGINT NOT NULL"
    ];
    foreach (grace_application_columns() as $column=>$definition) $schemas['inquiries'].=', `'.$column.'` '.$definition;
    foreach ($schemas as $name=>$schema) $pdo->exec('CREATE TABLE IF NOT EXISTS '.grace_table($name,$config).' ('.$schema.')'.($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''));
}
function grace_seed_database(PDO $pdo, array $config, bool $demo, string $agencyName): void {
    $seed=grace_seed(); $settings=$seed['settings']; $settings['name']=$agencyName;
    foreach (['about','about_en','seo_title','seo_description'] as $key) $settings[$key]=str_replace('Grace Models',$agencyName,$settings[$key]);
    $settings['demo_mode']=$demo;
    $pdo->beginTransaction();
    try {
        grace_insert('settings',['setting_key'=>'agency','setting_value'=>json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)],$pdo,$config);
        grace_insert('settings',['setting_key'=>'schema_version','setting_value'=>(string)GRACE_SCHEMA_VERSION],$pdo,$config);
        if ($demo) {
            foreach ($seed['models'] as $model) {
                $model['gallery']=json_encode($model['gallery'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $model['created_at']=$model['updated_at']=grace_now();
                grace_insert('models',$model,$pdo,$config);
            }
            foreach ($seed['news'] as $story) { $story['created_at']=$story['updated_at']=grace_now(); grace_insert('news',$story,$pdo,$config); }
        }
        $pdo->commit();
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}
