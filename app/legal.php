<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

function grace_legal_defaults(): array {
    static $defaults;
    return $defaults ??= json_decode((string)file_get_contents(GRACE_ROOT.'/app/legal-defaults.json'),true,64,JSON_THROW_ON_ERROR);
}
function grace_legal_schema(PDO $pdo, array $config): void {
    $mysql=$config['database']['driver']==='mysql'; $text=$mysql?'LONGTEXT':'TEXT';
    $pdo->exec('CREATE TABLE IF NOT EXISTS '.grace_table('legal_documents',$config)." (version VARCHAR(64) PRIMARY KEY, document_key VARCHAR(30) NOT NULL, title VARCHAR(300) NOT NULL, body $text NOT NULL, created_at VARCHAR(40) NOT NULL)".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''));
}
function grace_seed_legal(PDO $pdo, array $config): void {
    foreach (grace_legal_defaults() as $key=>$document) {
        $setting='legal_'.$key.'_version';
        $statement=$pdo->prepare('SELECT setting_value FROM '.grace_table('settings',$config).' WHERE setting_key=?'); $statement->execute([$setting]);
        if ($statement->fetchColumn()!==false) continue;
        $mysql=$config['database']['driver']==='mysql';
        $suffix=$mysql?' ON DUPLICATE KEY UPDATE version=version':' ON CONFLICT(version) DO NOTHING';
        $pdo->prepare('INSERT INTO '.grace_table('legal_documents',$config).' (version,document_key,title,body,created_at) VALUES (?,?,?,?,?)'.$suffix)->execute([$document['version'],$key,$document['title'],$document['body'],grace_now()]);
        $suffix=$mysql?' ON DUPLICATE KEY UPDATE setting_value=setting_value':' ON CONFLICT(setting_key) DO NOTHING';
        $pdo->prepare('INSERT INTO '.grace_table('settings',$config).' (setting_key,setting_value) VALUES (?,?)'.$suffix)->execute([$setting,json_encode($document['version'],JSON_THROW_ON_ERROR)]);
    }
}
function grace_legal_document(string $key, ?string $version=null): array {
    if (!in_array($key,['consent','privacy'],true)) grace_not_found();
    if (!is_file(GRACE_ROOT.'/config.php')) return ['document_key'=>$key,...grace_legal_defaults()[$key],'created_at'=>''];
    $version ??= grace_setting('legal_'.$key.'_version');
    $row=is_string($version)?grace_first('SELECT * FROM '.grace_table('legal_documents').' WHERE document_key=? AND version=?',[$key,$version]):null;
    if (!$row) grace_not_found();
    return $row;
}
function grace_legal_metadata(): array {
    $out=[];
    foreach (['consent','privacy'] as $key) {
        $document=grace_legal_document($key);
        $out[$key]=['title'=>$document['title'],'version'=>$document['version'],'url'=>'/'.$key];
    }
    return $out;
}
function grace_legal_admin(bool $all=false): array {
    $out=[];
    foreach (['consent','privacy'] as $key) {
        $out[$key]=grace_legal_document($key);
        $out[$key]['history']=grace_rows('SELECT version,title,created_at'.($all?',body':'').' FROM '.grace_table('legal_documents').' WHERE document_key=? ORDER BY created_at DESC,version DESC'.($all?'':' LIMIT 50'),[$key]);
    }
    return $out;
}
function grace_inquiries_with_legal(array $inquiries): array {
    $known=array_fill_keys(array_column(grace_rows('SELECT version FROM '.grace_table('legal_documents')." WHERE document_key='consent'"),'version'),true);
    foreach ($inquiries as &$inquiry) {
        $version=$inquiry['consent_version'] ?? '';
        $inquiry['consent_document_url']=isset($known[$version])?'/consent?version='.rawurlencode($version):null;
    }
    unset($inquiry); return $inquiries;
}
function grace_publish_legal(string $key, array $input): array {
    if (!in_array($key,['consent','privacy'],true)) grace_error('Документ не найден.',404);
    $title=grace_public_email(grace_text($input['title'] ?? '',300));$body=grace_public_email(grace_text($input['body'] ?? '',60000));
    if ($title==='' || $body==='') grace_error('Укажите заголовок и текст документа.');
    $current=grace_legal_document($key);
    if (($input['expected_version'] ?? '')!==$current['version']) grace_error('Документ уже изменён в другом окне. Скопируйте свои правки и обновите страницу.',409);
    $version=gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));
    $pdo=grace_db(); $pdo->beginTransaction();
    try {
        grace_insert('legal_documents',['version'=>$version,'document_key'=>$key,'title'=>$title,'body'=>$body,'created_at'=>grace_now()]);
        $update=$pdo->prepare('UPDATE '.grace_table('settings').' SET setting_value=? WHERE setting_key=? AND setting_value=?');
        $update->execute([json_encode($version,JSON_THROW_ON_ERROR),'legal_'.$key.'_version',json_encode($current['version'],JSON_THROW_ON_ERROR)]);
        if ($update->rowCount()!==1) grace_error('Документ уже изменён в другом окне. Скопируйте свои правки и обновите страницу.',409);
        $pdo->commit();
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
    grace_audit($key==='consent'?'Согласие обновлено':'Политика конфиденциальности обновлена',$title);
    return ['ok'=>true,'version'=>$version];
}
function grace_legal_inline(string $value): string {
    $value=grace_escape($value);
    $value=preg_replace('/\*\*(.+?)\*\*/u','<strong>$1</strong>',$value) ?? $value;
    $value=preg_replace('~\b(https?://[a-zA-Z0-9/_.?=&;:%+#@\~-]+)~','<a href="$1" rel="noopener noreferrer">$1</a>',$value) ?? $value;
    return nl2br($value);
}
function grace_legal_body(string $body): string {
    $html='';
    foreach (preg_split('/\n\s*\n/u',str_replace(["\r\n","\r"],"\n",$body)) as $block) {
        $block=trim($block); if ($block==='') continue;
        if (preg_match('/^#{1,3}\s+([^\n]+)$/u',$block,$heading)) $html.='<h2>'.grace_legal_inline($heading[1]).'</h2>';
        elseif (preg_match('/^-\s+/u',$block)) {
            $html.='<ul>';foreach (preg_split('/\n(?=-\s)/u',$block) as $item) $html.='<li>'.grace_legal_inline(preg_replace('/^-\s+/u','',$item) ?? $item).'</li>';$html.='</ul>';
        } else $html.='<p>'.grace_legal_inline($block).'</p>';
    }
    return $html;
}
function grace_legal_page(string $key): never {
    $requested=$_GET['version'] ?? null;
    if ($requested!==null && (!is_string($requested) || !preg_match('/^[a-zA-Z0-9_.-]{1,64}$/',$requested))) grace_not_found();
    $document=grace_legal_document($key,$requested);
    $updated=preg_match('/^(\d{4}-\d{2}-\d{2})/',$document['version'],$date)?(new DateTimeImmutable($date[1]))->format('d.m.Y'):(new DateTimeImmutable($document['created_at'] ?: 'now'))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y');
    $html=(string)file_get_contents(GRACE_ROOT.'/public/legal-document.html');
    $html=strtr($html,['{{UPDATED}}'=>$updated,'{{TITLE}}'=>grace_escape($document['title']),'{{VERSION}}'=>grace_escape($document['version']),'{{BODY}}'=>grace_legal_body($document['body']),...grace_chrome_template_values(grace_request_language())]);
    grace_html($html);
}
