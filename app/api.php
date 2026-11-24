<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

function grace_model_row(array $row): array {
    $row['gallery']=json_decode($row['gallery'],true,64,JSON_THROW_ON_ERROR);
    foreach (['height','bust','waist','hips','shoe','sort_order'] as $key) $row[$key]=(int)$row[$key];
    foreach (['published','featured','demo'] as $key) $row[$key]=(bool)$row[$key];
    return $row;
}
function grace_inquiry_row(array $row): array {
    $row['attachments']=json_decode($row['attachments'],true,64,JSON_THROW_ON_ERROR);
    $row['custom_data']=json_decode($row['custom_data'] ?? '{}',true,64,JSON_THROW_ON_ERROR);
    $row['guardian_consent']=$row['guardian_consent']===null?null:(bool)$row['guardian_consent'];
    foreach (['age','height','bust','waist','hips','shoe'] as $key) $row[$key]=$row[$key]===null?null:(int)$row[$key];
    $row['consent']=(bool)$row['consent']; return $row;
}
function grace_news_row(array $row): array { $row['published']=(bool)$row['published']; return $row; }
function grace_validate_model(array $input): array {
    $out=['name'=>grace_text($input['name'] ?? '',100),'name_en'=>grace_text($input['name_en'] ?? '',100),'slug'=>strtolower(grace_text($input['slug'] ?? '',100)),'city'=>grace_text($input['city'] ?? '',100)];
    if ($out['name']==='') grace_error('Укажите имя модели.');
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$out['slug'])) grace_error('Адрес анкеты: латинские буквы, цифры и дефисы.');
    $out['gender']=in_array($input['gender'] ?? '',['women','men'],true)?$input['gender']:'women';
    $out['category']=in_array($input['category'] ?? '',['main','new','international','direct'],true)?$input['category']:'main';
    foreach (['height'=>[100,230,175],'bust'=>[40,160,84],'waist'=>[35,160,61],'hips'=>[40,160,90],'shoe'=>[20,55,39],'sort_order'=>[0,9999,0]] as $key=>[$min,$max,$default]) {
        $value=$input[$key] ?? $default;
        if (!is_numeric($value) || (float)$value<$min || (float)$value>$max) grace_error('Проверьте поле «'.$key.'».');
        $out[$key]=(int)round((float)$value);
    }
    foreach (grace_appearance_options() as $key=>$options) {
        $out[$key]=in_array($input[$key] ?? '',array_column($options,0),true)?$input[$key]:'brown';
    }
    $cup=grace_text($input['bust_cup'] ?? '',10);
    if (!in_array($cup,['','AA','A','B','C','D','DD','E','F','G','H'],true)) grace_error('Выберите размер чашки бюста из списка.');
    $out['bust_cup']=$cup;
    $out['cover']=grace_media_url($input['cover'] ?? ''); $gallery=is_array($input['gallery'] ?? null)?$input['gallery']:[];
    $gallery=array_values(array_unique(array_filter(array_map('grace_media_url',array_slice($gallery,0,24)))));
    $out['gallery']=json_encode($gallery,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $out['bio']=grace_text($input['bio'] ?? '',5000); $out['bio_en']=grace_text($input['bio_en'] ?? '',5000);
    foreach (['published','featured','demo'] as $key) $out[$key]=(int)filter_var($input[$key] ?? false,FILTER_VALIDATE_BOOLEAN);
    if ($out['published'] && $out['cover']==='') grace_error('Добавьте обложку перед публикацией.');
    return $out;
}
function grace_file_path(string $key): string {
    if (!preg_match('#^(?:public/photos|private/inquiries/[a-f0-9-]+)/[a-f0-9-]+\.(?:jpg|png|webp|pdf)$#',$key)) grace_not_found();
    return grace_config()['data_dir'].'/uploads/'.$key;
}
function grace_file_mime(string $key): string { return ['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'][strtolower(pathinfo($key,PATHINFO_EXTENSION))] ?? 'application/octet-stream'; }
function grace_uploaded_files(string $field): array {
    $entry=$_FILES[$field] ?? null; if (!$entry) return [];
    if (!is_array($entry['name'])) return ($entry['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE?[]:[$entry];
    $out=[];
    foreach ($entry['name'] as $index=>$name) {
        $file=['name'=>$name,'type'=>$entry['type'][$index] ?? '','tmp_name'=>$entry['tmp_name'][$index] ?? '','error'=>$entry['error'][$index] ?? UPLOAD_ERR_NO_FILE,'size'=>$entry['size'][$index] ?? 0];
        if ($file['error']!==UPLOAD_ERR_NO_FILE) $out[]=$file;
    }
    return $out;
}
function grace_put_upload(array $file, string $prefix, bool $allowPdf = false): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {
        if (in_array($file['error'] ?? 0,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)) grace_error('Файл слишком большой. Лимит одного файла — 5 МБ; проверьте upload_max_filesize на хостинге.');
        grace_error('Не удалось загрузить файл. Попробуйте снова.');
    }
    if ($file['size']<=0 || $file['size']>5*1024*1024 || !is_uploaded_file($file['tmp_name'])) grace_error('Максимальный размер одного файла — 5 МБ.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']; if ($allowPdf) $types['application/pdf']='pdf';
    if (!isset($types[$mime])) grace_error('Поддерживаются JPG, PNG, WebP'.($allowPdf?' и PDF.':'.'));
    if (str_starts_with($mime,'image/')) {
        $dimensions=@getimagesize($file['tmp_name']);
        if (!$dimensions || $dimensions[0]>12000 || $dimensions[1]>12000) grace_error('Фотография повреждена или имеет слишком большое разрешение.');
    }
    $key=$prefix.'/'.grace_id().'.'.$types[$mime]; $path=grace_file_path($key);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true)) grace_error('Хранилище недоступно для записи.',503);
    if (!move_uploaded_file($file['tmp_name'],$path)) grace_error('Не удалось сохранить фотографию.',503);
    @chmod($path,0600);
    return ['key'=>$key,'name'=>grace_text(basename((string)$file['name']),160),'type'=>$mime,'size'=>(int)$file['size']];
}
function grace_public_data(): array {
    return ['models'=>array_map('grace_model_row',grace_rows('SELECT * FROM '.grace_table('models').' WHERE published=1 ORDER BY sort_order,created_at')),'news'=>array_map('grace_news_row',grace_rows('SELECT * FROM '.grace_table('news').' WHERE published=1 ORDER BY published_at DESC')),'settings'=>grace_settings(),'documents'=>grace_legal_metadata(),...grace_editor_data()];
}
function grace_admin_data(bool $all = false): array {
    return ['models'=>array_map('grace_model_row',grace_rows('SELECT * FROM '.grace_table('models').' ORDER BY sort_order,created_at')),'inquiries'=>grace_inquiries_with_legal(grace_inquiries_with_mail(array_map('grace_inquiry_row',grace_rows('SELECT * FROM '.grace_table('inquiries').' ORDER BY created_at DESC'.($all?'':' LIMIT 2000'))))),'news'=>array_map('grace_news_row',grace_rows('SELECT * FROM '.grace_table('news').' ORDER BY published_at DESC')),'activity'=>grace_rows('SELECT * FROM '.grace_table('activity').' ORDER BY created_at DESC'.($all?'':' LIMIT 40')),'settings'=>grace_settings(),'documents'=>grace_legal_admin($all),...grace_editor_data(true)];
}
function grace_application_data(array $input): array {
    $data=['telegram'=>grace_text($input['telegram'] ?? '',100),'city'=>grace_text($input['city'] ?? '',100)];
    $data['age']=filter_var($input['age'] ?? null,FILTER_VALIDATE_INT);
    if ($data['age']===false || $data['age']<14 || $data['age']>80) grace_error('Принимаем заявки от кандидатов от 14 до 80 лет. Проверьте возраст.');
    foreach (['height'=>['Рост',100,230],'bust'=>['Обхват груди',40,160],'waist'=>['Талия',35,160],'hips'=>['Бёдра',40,160],'shoe'=>['Размер обуви',20,55]] as $key=>[$label,$min,$max]) {
        $value=$input[$key] ?? '';
        if ($value==='' || $value===null) { $data[$key]=null; continue; }
        $integer=filter_var($value,FILTER_VALIDATE_INT);
        if ($integer===false || $integer<$min || $integer>$max) grace_error('Проверьте поле «'.$label.'».');
        $data[$key]=$integer;
    }
    $appearance=array_map(fn($options)=>array_column($options,0),grace_appearance_options());
    foreach ([...$appearance,'bust_cup'=>['AA','A','B','C','D','DD','E','F','G','H']] as $key=>$allowed) {
        $data[$key]=grace_text($input[$key] ?? '',30);
        if ($data[$key]!=='' && !in_array($data[$key],$allowed,true)) grace_error('Выберите параметры внешности из списка.');
    }
    $data['file_link']=grace_text($input['file_link'] ?? '',1200);
    if ($data['file_link']!=='') {
        $url=parse_url($data['file_link']);
        if (!filter_var($data['file_link'],FILTER_VALIDATE_URL) || !in_array(strtolower($url['scheme'] ?? ''),['http','https'],true) || isset($url['user']) || isset($url['pass'])) grace_error('Укажите корректную ссылку на файлообменник: https://…');
    }
    return $data;
}
function grace_create_inquiry(): never {
    grace_same_origin();
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>18*1024*1024) grace_error('Общий размер файлов не должен превышать 15 МБ.',413);
    $input=$_POST;
    if (!empty($input['website'])) grace_error('Не удалось отправить заявку.');
    $kind=$input['kind'] ?? ''; if (!in_array($kind,['application','booking'],true)) grace_error('Неверный тип заявки.');
    if ($kind==='application' && !grace_settings()['applications_enabled']) grace_error('Прием анкет временно закрыт.',403);
    $name=grace_text($input['name'] ?? '',100); $email=strtolower(grace_text($input['email'] ?? '',200)); $phone=grace_text($input['phone'] ?? '',60);
    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || $phone==='') grace_error('Укажите имя, телефон и корректный email.');
    if (($input['consent'] ?? '')!=='yes') grace_error('Нужно согласие на обработку данных.');
    $consentVersion=grace_legal_document('consent')['version'];
    if (($input['consent_version'] ?? '')!==$consentVersion) grace_error('Текст согласия обновился. Обновите страницу и подтвердите согласие еще раз.',409);
    $application=$kind==='application'?grace_application_data($input):[];
    $modelId=grace_text($input['model_id'] ?? '',100); $modelName='';
    if ($modelId!=='') { $model=grace_first('SELECT name FROM '.grace_table('models').' WHERE id=? AND published=1',[$modelId]); if (!$model) grace_error('Анкета модели недоступна. Выберите другую модель.'); $modelName=$model['name']; }
    $files=grace_uploaded_files('photos');
    $formData=grace_form_submission($kind,$input,$files);
    if ($kind==='application' && array_filter($files,fn($file)=>(int)$file['size']>3*1024*1024)) grace_error('Одна фотография — не более 3 МБ.');
    if (count($files)>3 || array_sum(array_column($files,'size'))>15*1024*1024) grace_error('Добавьте не более 3 файлов, до 5 МБ каждый.');
    $limitKey=grace_limiter_key('inquiry'); grace_limiter_check($limitKey,12); grace_limiter_add($limitKey,3600);
    $id=grace_id(); $attachments=[]; $pdo=grace_db();
    try {
        foreach ($files as $file) $attachments[]=grace_put_upload($file,'private/inquiries/'.$id,$kind!=='application');
        $acceptedAt=grace_now();
        $pdo->beginTransaction();
        grace_insert('inquiries',['id'=>$id,'kind'=>$kind,'name'=>$name,'email'=>$email,'phone'=>$phone,'city'=>grace_text($input['city'] ?? '',100),'age'=>null,'height'=>null,...$application,...$formData,'instagram'=>grace_text($input['instagram'] ?? '',200),'model_id'=>$modelId,'model_name'=>$modelName,'company'=>grace_text($input['company'] ?? '',200),'shooting_date'=>grace_text($input['shooting_date'] ?? '',40),'message'=>grace_text($input['message'] ?? '',5000),'attachments'=>json_encode($attachments,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'status'=>'new','internal_note'=>'','consent'=>1,'consent_version'=>$consentVersion,'consent_at'=>$acceptedAt,'created_at'=>$acceptedAt,'updated_at'=>$acceptedAt]);
        grace_enqueue_mail($id,$kind,$pdo,grace_config());
        $pdo->commit();
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); foreach ($attachments as $file) @unlink(grace_file_path($file['key'])); throw $exception; }
    grace_mail_after_response($id);
    grace_json(['ok'=>true,'id'=>$id,'reference'=>strtoupper(substr($id,0,8))],201);
}
function grace_api(string $path): never {
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($path==='/api/public' && $method==='GET') grace_json(grace_public_data());
    if ($path==='/api/documents' && $method==='GET') grace_json(grace_legal_metadata());
    if ($path==='/api/inquiries' && $method==='POST') grace_create_inquiry();
    if ($path==='/api/studio/login' && $method==='POST') grace_login();
    if ($path==='/api/studio/logout' && $method==='POST') grace_logout();
    if (!str_starts_with($path,'/api/studio/')) grace_error('Не найдено.',404);
    $session=grace_require_admin(); if ($method!=='GET') grace_same_origin();
    $parts=explode('/',substr($path,12)); $resource=$parts[0] ?? ''; $id=implode('/',array_slice($parts,1));
    if ($resource==='data' && $method==='GET') { grace_mail_after_response(); grace_json([...grace_admin_data(),'user'=>['login'=>$session['login']]]); }
    if ($resource==='notifications' && $method==='POST' && preg_match('#^([a-f0-9-]{36})/retry$#',$id,$match)) grace_json(grace_retry_inquiry_mail($match[1]));
    if ($resource==='documents' && $method==='PUT') grace_json(grace_publish_legal($id,grace_read_json(262144)));
    if ($resource==='forms' && $method==='PUT') {
        if (!in_array($id,['application','booking'],true)) grace_error('Форма не найдена.',404);
        $input=grace_read_json(262144);if (!is_array($input['config'] ?? null)) grace_error('Проверьте настройки формы.');$form=grace_form_config($id,$input['config']);
        grace_json(grace_editor_save('form_'.$id,$form,grace_text($input['expected_version'] ?? '',100)));
    }
    if ($resource==='layout' && $method==='PUT') grace_json(grace_publish_layout(grace_read_json(262144)));
    if ($resource==='contacts' && $method==='PUT') grace_json(grace_publish_contacts(grace_read_json(65536)));
    if ($resource==='export' && $method==='GET') {
        header('Content-Disposition: attachment; filename="grace-models-backup-'.gmdate('Y-m-d').'.json"'); grace_json(['version'=>GRACE_VERSION,'exported_at'=>grace_now(),...grace_admin_data(true)]);
    }
    if ($resource==='models' && in_array($method,['POST','PUT'],true)) {
        $record=grace_validate_model(grace_read_json()); $record['updated_at']=grace_now();
        if ($method==='POST') { $newId=grace_id(); grace_insert('models',['id'=>$newId,...$record,'created_at'=>grace_now()]); grace_audit('Анкета создана',$record['name']); grace_json(['id'=>$newId],201); }
        if (!grace_first('SELECT id FROM '.grace_table('models').' WHERE id=?',[$id])) grace_error('Анкета не найдена.',404);
        grace_update('models',$record,$id); grace_audit('Анкета обновлена',$record['name']); grace_json(['ok'=>true]);
    }
    if ($resource==='models' && $method==='DELETE') {
        $row=grace_first('SELECT name FROM '.grace_table('models').' WHERE id=?',[$id]); if (!$row) grace_error('Анкета не найдена.',404);
        grace_query('DELETE FROM '.grace_table('models').' WHERE id=?',[$id]); grace_audit('Анкета удалена',$row['name']); grace_json(['ok'=>true]);
    }
    if ($resource==='inquiries' && $method==='PUT') {
        $input=grace_read_json(); if (!in_array($input['status'] ?? '',['new','review','contacted','accepted','rejected','archived'],true)) grace_error('Неверный статус.');
        $row=grace_first('SELECT name FROM '.grace_table('inquiries').' WHERE id=?',[$id]); if (!$row) grace_error('Заявка не найдена.',404);
        grace_update('inquiries',['status'=>$input['status'],'internal_note'=>grace_text($input['internal_note'] ?? '',10000),'updated_at'=>grace_now()],$id); grace_audit('Заявка обновлена',$row['name']); grace_json(['ok'=>true]);
    }
    if ($resource==='inquiries' && $method==='DELETE') {
        $row=grace_first('SELECT attachments FROM '.grace_table('inquiries').' WHERE id=?',[$id]); if (!$row) grace_error('Заявка не найдена.',404);
        grace_query('DELETE FROM '.grace_table('inquiries').' WHERE id=?',[$id]);
        grace_query('DELETE FROM '.grace_table('mail_queue').' WHERE inquiry_id=?',[$id]);
        foreach (json_decode($row['attachments'],true,64,JSON_THROW_ON_ERROR) as $file) @unlink(grace_file_path($file['key']));
        grace_audit('Заявка удалена',substr($id,0,8)); grace_json(['ok'=>true]);
    }
    if ($resource==='upload' && $method==='POST') {
        $files=grace_uploaded_files('file'); if (count($files)!==1) grace_error('Выберите один файл.');
        $file=grace_put_upload($files[0],'public/photos'); grace_json(['url'=>'/media/'.$file['key']],201);
    }
    if ($resource==='files' && $method==='GET') {
        if (!str_starts_with($id,'private/inquiries/')) grace_not_found();
        $file=grace_file_path($id); grace_send_file($file,grace_file_mime($id),'attachment.'.pathinfo($id,PATHINFO_EXTENSION),true);
    }
    if ($resource==='settings' && $method==='PUT') {
        $input=grace_read_json(); $settings=grace_settings();
        foreach (array_keys(grace_seed()['settings']) as $key) if (array_key_exists($key,$input)) {
            if (in_array($key,['demo_mode','applications_enabled','news_enabled'],true)) $settings[$key]=(bool)filter_var($input[$key],FILTER_VALIDATE_BOOLEAN);
            elseif (str_starts_with($key,'hero_') || in_array($key,['about_image','logo_url'],true)) $settings[$key]=grace_media_url($input[$key]);
            elseif (in_array($key,['telegram','instagram','vk'],true)) $settings[$key]=str_starts_with(grace_text($input[$key],500),'https://') && filter_var($input[$key],FILTER_VALIDATE_URL)?grace_text($input[$key],500):'';
            else $settings[$key]=grace_public_email(grace_text($input[$key],str_starts_with($key,'about')?10000:500));
        }
        if ($settings['name']==='') grace_error('Укажите название агентства.');
        if (!filter_var($settings['email'],FILTER_VALIDATE_EMAIL)) grace_error('Укажите корректную публичную почту.');
        grace_save_setting('agency',$settings); grace_audit('Настройки сохранены',$settings['name']); grace_json(['ok'=>true]);
    }
    if ($resource==='news' && in_array($method,['POST','PUT'],true)) {
        $input=grace_read_json(); $record=[];
        foreach (['title','title_en','summary','summary_en','body','body_en','image','published','published_at'] as $key) {
            if ($key==='published') $record[$key]=(int)filter_var($input[$key] ?? false,FILTER_VALIDATE_BOOLEAN);
            elseif ($key==='image') $record[$key]=grace_media_url($input[$key] ?? '');
            else $record[$key]=grace_text($input[$key] ?? '',str_starts_with($key,'body')?15000:(str_starts_with($key,'summary')?2000:300));
        }
        if ($record['title']==='') grace_error('Укажите заголовок.');
        $record['published_at']=$record['published_at'] ?: gmdate('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$record['published_at'])) grace_error('Укажите дату в формате ГГГГ-ММ-ДД.');
        $record['updated_at']=grace_now();
        if ($method==='POST') grace_insert('news',['id'=>grace_id(),...$record,'created_at'=>grace_now()]);
        else { if (!grace_first('SELECT id FROM '.grace_table('news').' WHERE id=?',[$id])) grace_error('Публикация не найдена.',404); grace_update('news',$record,$id); }
        grace_audit('Публикация сохранена',$record['title']); grace_json(['ok'=>true]);
    }
    if ($resource==='news' && $method==='DELETE') {
        $row=grace_first('SELECT title FROM '.grace_table('news').' WHERE id=?',[$id]); if (!$row) grace_error('Публикация не найдена.',404);
        grace_query('DELETE FROM '.grace_table('news').' WHERE id=?',[$id]); grace_audit('Публикация удалена',$row['title']); grace_json(['ok'=>true]);
    }
    grace_error('Не найдено.',404);
}
