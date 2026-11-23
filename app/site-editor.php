<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

function grace_site_defaults(): array {
    static $defaults;
    return $defaults ??= json_decode((string)file_get_contents(GRACE_ROOT.'/app/site-defaults.json'),true,128,JSON_THROW_ON_ERROR);
}
function grace_public_email(string $text): string { return str_ireplace('info@grace-models.com','info@grace-models.ru',$text); }
function grace_editor_data(bool $private=false): array {
    $settings=grace_settings();$layout=grace_layout();$navigation=[];
    foreach ($layout['navigation'] as $item) { $html=grace_item_html($item,$settings);if ($html!=='') $navigation[]=['html'=>$html,'placement'=>$item['placement']]; }
    $contacts=grace_team_contacts();
    if (!$private) {
        $contacts['items']=array_values(array_filter($contacts['items'],fn($person)=>$person['enabled']));
        $layout['navigation']=array_values(array_filter($layout['navigation'],fn($item)=>$item['enabled']));
        $layout['footer']=array_values(array_filter($layout['footer'],fn($block)=>$block['enabled']));
        foreach ($layout['footer'] as &$block) $block['items']=array_values(array_filter($block['items'],fn($item)=>$item['enabled']));unset($block);
    }
    return ['forms'=>grace_forms(),'layout'=>$layout,'contacts'=>$contacts,'chrome'=>['logo'=>grace_logo_html($settings),'footer'=>grace_footer_html(),'navigation'=>$navigation]];
}
function grace_migrate_legal_contact(PDO $pdo, array $config): void {
    // Publish a new active revision; signed historical documents remain immutable.
    foreach (['consent','privacy'] as $key) {
        $pointer=$pdo->prepare('SELECT setting_value FROM '.grace_table('settings',$config).' WHERE setting_key=?');$pointer->execute(['legal_'.$key.'_version']);$raw=$pointer->fetchColumn();
        if (!is_string($raw)) continue;
        $version=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        $find=$pdo->prepare('SELECT * FROM '.grace_table('legal_documents',$config).' WHERE document_key=? AND version=?');$find->execute([$key,$version]);$document=$find->fetch();
        if (!$document || grace_public_email($document['body'])===$document['body']) continue;
        $pdo->beginTransaction();
        try {
            $next=gmdate('Ymd\THis\Z').'-contact-'.bin2hex(random_bytes(4));
            grace_insert('legal_documents',['version'=>$next,'document_key'=>$key,'title'=>grace_public_email($document['title']),'body'=>grace_public_email($document['body']),'created_at'=>grace_now()],$pdo,$config);
            $update=$pdo->prepare('UPDATE '.grace_table('settings',$config).' SET setting_value=? WHERE setting_key=? AND setting_value=?');$update->execute([json_encode($next,JSON_THROW_ON_ERROR),'legal_'.$key.'_version',$raw]);
            if ($update->rowCount()!==1) { $pdo->rollBack();continue; }
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack();throw $error; }
    }
}
function grace_editor_seed(PDO $pdo, array $config): void {
    $defaults=grace_site_defaults();
    $mysql=$config['database']['driver']==='mysql';
    $suffix=$mysql?' ON DUPLICATE KEY UPDATE setting_value=setting_value':' ON CONFLICT(setting_key) DO NOTHING';
    foreach (['form_application'=>$defaults['forms']['application'],'form_booking'=>$defaults['forms']['booking'],'site_layout'=>$defaults['layout'],'contact_people'=>$defaults['contacts']] as $key=>$value) {
        $pdo->prepare('INSERT INTO '.grace_table('settings',$config).' (setting_key,setting_value) VALUES (?,?)'.$suffix)->execute([$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
}
function grace_forms(): array {
    if (!is_file(GRACE_ROOT.'/config.php')) return grace_site_defaults()['forms'];
    return ['application'=>grace_setting('form_application') ?? grace_site_defaults()['forms']['application'],'booking'=>grace_setting('form_booking') ?? grace_site_defaults()['forms']['booking']];
}
function grace_layout(): array { return is_file(GRACE_ROOT.'/config.php')?(grace_setting('site_layout') ?? grace_site_defaults()['layout']):grace_site_defaults()['layout']; }
function grace_team_contacts(): array { return is_file(GRACE_ROOT.'/config.php')?(grace_setting('contact_people') ?? grace_site_defaults()['contacts']):grace_site_defaults()['contacts']; }
function grace_publish_contacts(array $input): array {
    $items=$input['items'] ?? null;
    if (!is_array($items) || !array_is_list($items) || count($items)>30) grace_error('Можно добавить до 30 контактов.');
    $out=[];$seen=[];
    foreach ($items as $person) {
        if (!is_array($person)) grace_error('Проверьте контакт.');
        $id=grace_text($person['id'] ?? '',64);$role=grace_public_email(grace_text($person['role'] ?? '',400));$name=grace_public_email(grace_text($person['name'] ?? '',200));$telegram=grace_text($person['telegram'] ?? '',300);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$id) || isset($seen[$id])) grace_error('Неверный или повторяющийся контакт.');
        if ($role==='') grace_error('Укажите, за какую работу отвечает контакт.');
        if (preg_match('/^@?([a-zA-Z0-9_]{5,32})$/',$telegram,$match)) $telegram='https://t.me/'.$match[1];
        if (!preg_match('#^https://t\.me/[a-zA-Z0-9_]{5,32}/?$#',$telegram)) grace_error('Укажите ник Telegram с @ или ссылку https://t.me/username.');
        $seen[$id]=true;$out[]=['id'=>$id,'role'=>$role,'name'=>$name,'telegram'=>rtrim($telegram,'/'),'enabled'=>(bool)filter_var($person['enabled'] ?? true,FILTER_VALIDATE_BOOLEAN)];
    }
    return grace_editor_save('contact_people',['items'=>$out],grace_text($input['expected_version'] ?? '',100));
}
function grace_editor_save(string $key, array $value, string $expected): array {
    $pdo=grace_db();$pdo->beginTransaction();
    try {
        $statement=$pdo->prepare('SELECT setting_value FROM '.grace_table('settings').' WHERE setting_key=?');$statement->execute([$key]);$raw=$statement->fetchColumn();
        $current=is_string($raw)?json_decode($raw,true,128,JSON_THROW_ON_ERROR):null;
        if (!$current || ($current['version'] ?? '')!==$expected) grace_error('Настройки уже изменены в другом окне. Скопируйте правки и обновите страницу.',409);
        $value['version']=gmdate('YmdHis').'-'.bin2hex(random_bytes(6));
        $update=$pdo->prepare('UPDATE '.grace_table('settings').' SET setting_value=? WHERE setting_key=? AND setting_value=?');
        $update->execute([json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$key,$raw]);
        if ($update->rowCount()!==1) grace_error('Настройки уже изменены в другом окне. Скопируйте правки и обновите страницу.',409);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    grace_audit('Редактор сайта: сохранено',$key);
    return $value;
}
function grace_form_config(string $kind, array $input): array {
    $defaults=grace_site_defaults()['forms'][$kind] ?? null;
    if (!$defaults) grace_error('Форма не найдена.',404);
    $out=[];
    foreach (['eyebrow'=>200,'title'=>400,'description'=>3000,'submit_label'=>100,'success_title'=>300,'success_text'=>3000,'guardian_label'=>1500] as $key=>$length) $out[$key]=grace_public_email(grace_text($input[$key] ?? $defaults[$key],$length));
    if ($out['title']==='' || $out['submit_label']==='' || $out['success_title']==='') grace_error('Заполните заголовки формы и результата, а также текст кнопки.');
    if ($kind==='application' && $out['guardian_label']==='') grace_error('Укажите текст отметки для несовершеннолетних.');
    $out['image']=grace_media_url($input['image'] ?? '');
    $fields=$input['fields'] ?? null;
    if (!is_array($fields) || !array_is_list($fields) || count($fields)>50) grace_error('В форме может быть до 50 полей.');
    $base=array_column($defaults['fields'],null,'key');$seen=[];$out['fields']=[];
    $locked=$kind==='application'?['name','phone','email','age']:['name','phone','email'];
    foreach ($fields as $field) {
        if (!is_array($field)) grace_error('Проверьте описание поля.');
        $key=grace_text($field['key'] ?? '',64);
        if (isset($seen[$key]) || (!isset($base[$key]) && !preg_match('/^custom_[a-z0-9_]{1,40}$/',$key))) grace_error('Неверный или повторяющийся идентификатор поля.');
        $seen[$key]=true;$builtin=$base[$key] ?? null;
        $type=$builtin['type'] ?? ($field['type'] ?? 'text');
        if (!in_array($type,['text','email','tel','url','number','date','select','textarea','checkbox','photos','model'],true) || (!$builtin && in_array($type,['photos','model'],true))) grace_error('Неверный тип поля.');
        $label=grace_public_email(grace_text($field['label'] ?? '',200));if ($label==='') grace_error('У каждого поля должно быть название.');
        $record=['key'=>$key,'label'=>$label,'type'=>$type,'required'=>in_array($key,$locked,true) || filter_var($field['required'] ?? false,FILTER_VALIDATE_BOOLEAN),'visible'=>in_array($key,$locked,true) || filter_var($field['visible'] ?? true,FILTER_VALIDATE_BOOLEAN),'full'=>(bool)filter_var($field['full'] ?? false,FILTER_VALIDATE_BOOLEAN),'placeholder'=>grace_public_email(grace_text($field['placeholder'] ?? '',300)),'help'=>grace_public_email(grace_text($field['help'] ?? '',1000)),'options'=>$builtin['options'] ?? []];
        if ($builtin) foreach (['min','max'] as $limit) { if (isset($builtin[$limit])) $record[$limit]=$builtin[$limit]; }
        elseif ($type==='select') {
            $options=$field['options'] ?? [];
            if (!is_array($options) || count($options)<1 || count($options)>50) grace_error('В списке нужно от 1 до 50 вариантов.');
            foreach ($options as $option) {
                $text=grace_text(is_array($option)?($option[1] ?? ''):$option,150);
                if ($text!=='') $record['options'][]=[$text,$text];
            }
            if (!$record['options']) grace_error('Заполните варианты списка.');
        }
        $out['fields'][]=$record;
    }
    foreach ($base as $key=>$field) if (!isset($seen[$key])) {
        if (in_array($key,$locked,true)) grace_error('Нельзя удалить имя, телефон, почту или возраст.');
        $field['visible']=false;$out['fields'][]=$field;
    }
    return $out;
}
function grace_form_submission(string $kind, array $input, array $files): array {
    $form=grace_forms()[$kind];$extra=[];
    foreach ($form['fields'] as $field) {
        if (!$field['visible']) continue;
        $key=$field['key'];$type=$field['type'];$value=$input[$key] ?? '';
        if ($type==='photos') { if ($field['required'] && !$files) grace_error('Добавьте фотографии.'); continue; }
        if (!is_scalar($value) && $value!==null) grace_error('Проверьте поле «'.$field['label'].'».');
        $text=grace_text($value,$type==='textarea'?5000:1200);
        $checked=$type==='checkbox' && filter_var($value,FILTER_VALIDATE_BOOLEAN);
        if ($field['required'] && ($type==='checkbox'?!$checked:$text==='')) grace_error('Заполните обязательное поле «'.$field['label'].'».');
        if ($text!=='' && $type==='email' && !filter_var($text,FILTER_VALIDATE_EMAIL)) grace_error('Проверьте электронную почту.');
        if ($text!=='' && $type==='url' && (!filter_var($text,FILTER_VALIDATE_URL) || !preg_match('#^https?://#i',$text))) grace_error('Проверьте ссылку в поле «'.$field['label'].'».');
        if ($text!=='' && $type==='number' && (!is_numeric($text) || !is_finite((float)$text) || (isset($field['min']) && (float)$text<$field['min']) || (isset($field['max']) && (float)$text>$field['max']))) grace_error('Проверьте число в поле «'.$field['label'].'».');
        if ($text!=='' && $type==='date') { $date=DateTimeImmutable::createFromFormat('!Y-m-d',$text); if (!$date || $date->format('Y-m-d')!==$text) grace_error('Проверьте дату в поле «'.$field['label'].'».'); }
        if ($text!=='' && $type==='select' && !in_array($text,array_column($field['options'],0),true)) grace_error('Выберите значение из списка «'.$field['label'].'».');
        if (str_starts_with($key,'custom_')) $extra[]=['key'=>$key,'label'=>$field['label'],'type'=>$type,'value'=>$type==='checkbox'?($checked?'Да':'Нет'):$text];
    }
    $minor=$kind==='application' && (int)($input['age'] ?? 0)<18;
    if ($minor && !filter_var($input['guardian_consent'] ?? false,FILTER_VALIDATE_BOOLEAN)) grace_error('Для анкеты до 18 лет подтвердите отметку о согласии родителя или законного представителя.');
    return ['custom_data'=>json_encode(['form_title'=>$form['title'],'form_version'=>$form['version'],'fields'=>$extra,'guardian_label'=>$minor?$form['guardian_label']:''],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'guardian_consent'=>$minor?1:null,'guardian_consent_at'=>$minor?grace_now():''];
}
function grace_layout_item(array $input, bool $navigation=false): array {
    $id=grace_text($input['id'] ?? '',64);if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$id)) grace_error('Неверный идентификатор блока.');
    $type=$input['type'] ?? 'text';if (!in_array($type,['link','text','phone','email','address'],true)) grace_error('Неверный тип блока.');
    $value=grace_public_email(grace_text($input['value'] ?? '',1500));$source=$input['source'] ?? '';
    if (!in_array($source,['','email','phone','city','telegram','instagram','vk'],true)) grace_error('Проверьте источник контакта.');
    if ($source==='') {
        if ($type==='link' && $value!=='' && !grace_editor_link_allowed($value)) grace_error('Ссылка должна начинаться с /, https:// или http://.');
        if ($type==='email' && $value!=='' && !filter_var($value,FILTER_VALIDATE_EMAIL)) grace_error('Проверьте адрес электронной почты.');
        if ($type==='phone' && $value!=='' && !preg_match('/^[+0-9() .-]{3,60}$/',$value)) grace_error('Проверьте номер телефона.');
    }
    $record=['id'=>$id,'type'=>$type,'value'=>$value,'source'=>$source,'label'=>grace_public_email(grace_text($input['label'] ?? '',250)),'enabled'=>(bool)filter_var($input['enabled'] ?? true,FILTER_VALIDATE_BOOLEAN)];
    if ($navigation) { $record['placement']=($input['placement'] ?? '')==='actions'?'actions':'main'; }
    return $record;
}
function grace_editor_link_allowed(string $url): bool {
    if (preg_match('/[\x00-\x20\\\\]/',$url)) return false;
    if (str_starts_with($url,'/') && !str_starts_with($url,'//')) return true;
    $parts=parse_url($url);
    return $parts && filter_var($url,FILTER_VALIDATE_URL) && in_array(strtolower($parts['scheme'] ?? ''),['http','https'],true) && !isset($parts['user']) && !isset($parts['pass']);
}
function grace_publish_layout(array $input): array {
    $current=grace_layout();$out=$current;
    foreach (['navigation','footer'] as $section) if (array_key_exists($section,$input)) {
        if (!is_array($input[$section]) || !array_is_list($input[$section]) || count($input[$section])>30) grace_error('Можно добавить до 30 блоков.');
        $items=[];$seen=[];
        foreach ($input[$section] as $block) {
            if (!is_array($block)) grace_error('Проверьте описание блока.');
            if ($section==='navigation') $record=grace_layout_item($block,true);
            else {
                $id=grace_text($block['id'] ?? '',64);if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$id)) grace_error('Проверьте идентификатор блока.');
                $children=$block['items'] ?? [];if (!is_array($children) || !array_is_list($children) || count($children)>30) grace_error('В блоке может быть до 30 элементов.');
                $childIds=[];$childOut=[];
                foreach ($children as $child) { if (!is_array($child)) grace_error('Проверьте элемент блока.');$clean=grace_layout_item($child);if (isset($childIds[$clean['id']])) grace_error('Повторяющийся элемент блока.');$childIds[$clean['id']]=true;$childOut[]=$clean; }
                $record=['id'=>$id,'title'=>grace_public_email(grace_text($block['title'] ?? '',250)),'kind'=>($block['kind'] ?? '')==='brand'?'brand':'content','align'=>in_array($block['align'] ?? '',['left','center','right'],true)?$block['align']:'left','width'=>max(1,min(4,(int)($block['width'] ?? 1))),'enabled'=>(bool)filter_var($block['enabled'] ?? true,FILTER_VALIDATE_BOOLEAN),'items'=>$childOut];
            }
            if (isset($seen[$record['id']])) grace_error('Повторяющийся блок.');$seen[$record['id']]=true;$items[]=$record;
        }
        $out[$section]=$items;
    }
    return grace_editor_save('site_layout',$out,grace_text($input['expected_version'] ?? '',100));
}
function grace_logo_html(?array $settings=null): string {
    $settings ??= is_file(GRACE_ROOT.'/config.php')?grace_settings():grace_seed()['settings'];
    $url=$settings['logo_url'] ?? '/images/grace-models-logo-original.jpg';
    $original=$url==='/images/grace-models-logo-original.jpg';
    return '<span class="grace-logo'.($original?' grace-logo-original':'').'"><img src="'.grace_escape($url).'" alt="'.grace_escape($settings['name']).'" '.($original?'width="1783" height="1364"':'').' decoding="async"></span>';
}
function grace_item_html(array $item, array $settings): string {
    if (!$item['enabled']) return '';
    $value=grace_public_email((string)(($item['source'] ?? '')!==''?($settings[$item['source']] ?? ''):$item['value']));
    if ($value==='') return '';
    if (parse_url($value,PHP_URL_PATH)==='/become-a-model' && !$settings['applications_enabled']) return '';
    $label=$item['label'] ?: $value;$type=$item['type'];
    if ($type==='link' && !grace_editor_link_allowed($value)) return '';
    $href=match($type) {'link'=>$value,'phone'=>'tel:'.preg_replace('/[^+0-9]/','',$value),'email'=>'mailto:'.$value,default=>''};
    if ($href!=='') return '<a href="'.grace_escape($href).'"'.($type==='link' && str_starts_with($href,'/') && !preg_match('#^/(consent|privacy|cookies)(?:[/?]|$)#',$href)?' data-route':'').'>'.grace_escape($label).'</a>';
    return '<span class="chrome-text">'.($item['label']!==''?'<span class="chrome-item-label">'.grace_escape($item['label']).': </span>':'').nl2br(grace_escape($value)).'</span>';
}
function grace_footer_html(): string {
    $settings=is_file(GRACE_ROOT.'/config.php')?grace_settings():grace_seed()['settings'];$layout=grace_layout();$blocks='';
    foreach ($layout['footer'] as $block) {
        if (!$block['enabled']) continue;
        $body=$block['kind']==='brand'?'<a class="chrome-brand" href="/" data-route>'.grace_logo_html($settings).'</a><p>'.grace_escape($settings['tagline']).'</p>':'';
        foreach ($block['items'] as $item) $body.=grace_item_html($item,$settings);
        $blocks.='<section class="chrome-footer-block align-'.$block['align'].' width-'.$block['width'].'">'.($block['title']!==''?'<h2>'.grace_escape($block['title']).'</h2>':'').$body.'</section>';
    }
    return '<footer class="site-footer chrome-footer"><div class="chrome-footer-grid">'.$blocks.'</div><div class="chrome-footer-bottom"><span>© '.gmdate('Y').' '.grace_escape($settings['name']).'</span><a href="/consent">Согласие на обработку данных</a><a href="/privacy">Политика конфиденциальности</a><a href="/cookies">Файлы cookie</a><button type="button" class="cookie-settings-link" data-cookie-settings>Настройки cookie</button></div></footer>';
}
