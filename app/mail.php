<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

final class GraceMailError extends RuntimeException {}

function grace_mail_recipients(string $kind): array {
    return $kind==='application'
        ? ['Info@grace-models.com','gracemodelsmail@gmail.com','gracemodelsmoscow@gmail.com','info@grace-models.ru']
        : ['gracemodelsmoscow@gmail.com','info@grace-models.ru'];
}

function grace_mail_schema(PDO $pdo, array $config): void {
    $mysql=$config['database']['driver']==='mysql';
    $pdo->exec('CREATE TABLE IF NOT EXISTS '.grace_table('mail_queue',$config)." (id VARCHAR(64) PRIMARY KEY, dedupe_key VARCHAR(64) NOT NULL UNIQUE, inquiry_id VARCHAR(64) NOT NULL, recipient VARCHAR(254) NOT NULL, status VARCHAR(20) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at BIGINT NOT NULL DEFAULT 0, locked_until BIGINT NOT NULL DEFAULT 0, last_error VARCHAR(500) NOT NULL DEFAULT '', created_at VARCHAR(40) NOT NULL, sent_at VARCHAR(40) NOT NULL DEFAULT '')".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''));
}

function grace_enqueue_mail(string $inquiryId, string $kind, PDO $pdo, array $config): void {
    foreach (grace_mail_recipients($kind) as $recipient) {
        grace_insert('mail_queue',['id'=>grace_id(),'dedupe_key'=>hash('sha256',$inquiryId.':'.strtolower($recipient)),'inquiry_id'=>$inquiryId,'recipient'=>$recipient,'status'=>'pending','attempts'=>0,'next_attempt_at'=>0,'locked_until'=>0,'last_error'=>'','created_at'=>grace_now(),'sent_at'=>''],$pdo,$config);
    }
}

function grace_mail_rows(array $inquiry): array {
    $date=(new DateTimeImmutable($inquiry['created_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
    $rows=[['Тип заявки',$inquiry['kind']==='application'?'Анкета модели':'Бронирование модели'],['Номер заявки',strtoupper(substr($inquiry['id'],0,8))],['Получена',$date.' (Москва)'],['ФИО',$inquiry['name']],['Телефон',$inquiry['phone']],['Электронная почта',$inquiry['email']]];
    if ($inquiry['kind']==='application') {
        $hair=['blonde'=>'Блонд','light_brown'=>'Русые','brown'=>'Каштановые','black'=>'Чёрные','red'=>'Рыжие','gray'=>'Седые','other'=>'Другой цвет'];
        $eyes=['brown'=>'Карие','blue'=>'Голубые','green'=>'Зелёные','gray'=>'Серые','hazel'=>'Ореховые','gray_blue'=>'Серо-голубые','gray_green'=>'Серо-зелёные','other'=>'Другой цвет'];
        $rows=[...$rows,['Город',$inquiry['city']],['Ник в Телеграм',$inquiry['telegram']],['Возраст, полных лет',$inquiry['age']],['Рост, см',$inquiry['height']],['Обхват груди, см',$inquiry['bust']],['Бюст — чашка',$inquiry['bust_cup']],['Талия, см',$inquiry['waist']],['Бёдра, см',$inquiry['hips']],['Размер обуви',$inquiry['shoe']],['Цвет волос',$hair[$inquiry['hair']] ?? ''],['Цвет глаз',$eyes[$inquiry['eyes']] ?? ''],['Ссылка на файлообменник',$inquiry['file_link']]];
        if (!empty($inquiry['instagram'])) $rows[]=['Соцсеть / портфолио',$inquiry['instagram']];
        if (!empty($inquiry['birth_date'])) $rows[]=['Дата рождения',$inquiry['birth_date']];
    } else $rows=[...$rows,['Бренд / компания',$inquiry['company']],['Выбранная модель',$inquiry['model_name'] ?: 'Подбор модели'],['Дата съёмки',$inquiry['shooting_date']]];
    $custom=is_array($inquiry['custom_data'] ?? null)?$inquiry['custom_data']:json_decode($inquiry['custom_data'] ?? '{}',true,64,JSON_THROW_ON_ERROR);
    foreach ($custom['fields'] ?? [] as $field) $rows[]=[$field['label'],$field['value']];
    if (!empty($inquiry['guardian_consent'])) {
        $rows[]=['Отметка для кандидата до 18 лет',$custom['guardian_label'] ?? 'Подтверждено'];
        $rows[]=['Дата подтверждения отметки',$inquiry['guardian_consent_at']];
    }
    return [...$rows,['Сообщение',$inquiry['message']],['Согласие на обработку данных',!empty($inquiry['consent'])?'Подтверждено':'Не зафиксировано'],['Редакция согласия',$inquiry['consent_version'] ?? ''],['Согласие подтверждено',$inquiry['consent_at'] ?? '']];
}

function grace_build_mail(array $inquiry, array $job): \PHPMailer\PHPMailer\PHPMailer {
    if (!extension_loaded('ctype')) throw new GraceMailError('Для отправки писем включите расширение PHP Ctype на хостинге.');
    require_once GRACE_ROOT.'/app/vendor/phpmailer/src/Exception.php';
    require_once GRACE_ROOT.'/app/vendor/phpmailer/src/PHPMailer.php';
    require_once GRACE_ROOT.'/app/vendor/phpmailer/src/SMTP.php';
    $options=grace_config()['mail'] ?? [];
    $transport=$options['transport'] ?? 'mail';
    $mailer=new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet='UTF-8'; $mailer->Encoding='base64'; $mailer->Timeout=10;
    if ($transport==='smtp') {
        $host=(string)($options['host'] ?? '');
        if ($host==='' || preg_match('/[\r\n;]/',$host)) throw new GraceMailError('Не указан сервер SMTP в config.php.');
        $security=(string)($options['encryption'] ?? 'tls');
        if (!in_array($security,['tls','ssl','none'],true)) throw new GraceMailError('Проверьте тип защиты SMTP.');
        if ($security!=='none' && !extension_loaded('openssl')) throw new GraceMailError('Для защищенного SMTP включите расширение PHP OpenSSL.');
        if ($security==='none' && !in_array($host,['127.0.0.1','::1','localhost'],true)) throw new GraceMailError('Для внешнего SMTP используйте TLS или SSL.');
        $mailer->isSMTP(); $mailer->Host=$host;
        $mailer->Port=(int)($options['port'] ?? ($security==='ssl'?465:587));
        $mailer->SMTPSecure=$security==='none'?'':$security;
        $mailer->SMTPAutoTLS=$security!=='none';
        $mailer->SMTPAuth=!empty($options['username']);
        $mailer->Username=(string)($options['username'] ?? '');
        $mailer->Password=(string)($options['password'] ?? '');
    } elseif ($transport==='mail') {
        if (!function_exists('mail')) throw new GraceMailError('Функция mail недоступна. Настройте SMTP в config.php.');
        $mailer->isMail();
    } else throw new GraceMailError('Выберите mail или smtp в настройках config.php.');
    $from=(string)($options['from_email'] ?? 'no-reply@grace-models.ru');
    if (!filter_var($from,FILTER_VALIDATE_EMAIL)) throw new GraceMailError('Проверьте адрес отправителя в config.php.');
    $mailer->setFrom($from,'Grace Models');
    $mailer->addAddress($job['recipient']);
    $mailer->addReplyTo($inquiry['email'],$inquiry['name']);
    $mailer->MessageID='<grace-'.$job['id'].'@'.substr(strrchr($from,'@'),1).'>';
    $mailer->Subject='Grace Models — '.($inquiry['kind']==='application'?'Анкета модели':'Бронирование модели').': '.$inquiry['name'];
    $heading=$inquiry['kind']==='application'?'Новая анкета модели':'Новая заявка на бронирование';
    $plain=$heading."\n\n"; $table='';
    foreach (grace_mail_rows($inquiry) as [$label,$value]) {
        $value=($value===null || $value==='')?'Не указано':(string)$value;
        $plain.=$label.': '.$value."\n";
        $table.='<tr><th style="text-align:left;vertical-align:top;padding:10px;border-bottom:1px solid #ddd;width:35%">'.grace_escape($label).'</th><td style="padding:10px;border-bottom:1px solid #ddd;white-space:pre-wrap">'.nl2br(grace_escape($value)).'</td></tr>';
    }
    $attachments=is_array($inquiry['attachments'])?$inquiry['attachments']:json_decode($inquiry['attachments'],true,64,JSON_THROW_ON_ERROR);
    foreach ($attachments as $file) {
        $path=grace_file_path($file['key']);
        if (!is_file($path)) throw new GraceMailError('Не удалось найти один из файлов анкеты. Проверьте вложения в кабинете.');
        $mailer->addAttachment($path,$file['name'],'base64',grace_file_mime($file['key']));
    }
    $link=grace_origin().grace_config()['admin_path'].'?inquiry='.rawurlencode($inquiry['id']).'#inquiries';
    $plain.="\nПриложенных файлов: ".count($attachments)."\nОткрыть в кабинете: ".$link."\n";
    $mailer->isHTML(true);
    $mailer->Body='<!doctype html><html lang="ru"><meta charset="utf-8"><body style="font:16px/1.6 Arial,sans-serif;color:#222"><h1>'.grace_escape($heading).'</h1><table style="border-collapse:collapse;width:100%;max-width:900px">'.$table.'</table><p>Приложенных файлов: '.count($attachments).'</p><p><a href="'.grace_escape($link).'">Открыть заявку в кабинете</a></p></body></html>';
    $mailer->AltBody=$plain;
    return $mailer;
}

function grace_process_mail(int $limit=4, ?string $inquiryId=null): array {
    $limit=max(1,min(100,$limit)); $now=time(); $table=grace_table('mail_queue');
    grace_query("UPDATE $table SET status='failed',locked_until=0,last_error='Превышено число попыток отправки. Повторите отправку из кабинета.' WHERE attempts>=5 AND locked_until<=? AND status IN ('pending','retry')",[$now]);
    $where="status IN ('pending','retry') AND next_attempt_at<=? AND locked_until<=?"; $parameters=[$now,$now];
    if ($inquiryId!==null) { $where.=' AND inquiry_id=?'; $parameters[]=$inquiryId; }
    $jobs=grace_rows('SELECT id FROM '.$table.' WHERE '.$where.' ORDER BY created_at,id LIMIT '.$limit,$parameters);
    $result=['sent'=>0,'failed'=>0];
    foreach ($jobs as $job) {
        $claim=grace_query("UPDATE $table SET locked_until=?,attempts=attempts+1 WHERE id=? AND locked_until<=? AND next_attempt_at<=? AND status IN ('pending','retry')",[$now+300,$job['id'],$now,$now]);
        if ($claim->rowCount()!==1) continue;
        $row=grace_first("SELECT * FROM $table WHERE id=?",[$job['id']]);
        $inquiry=grace_first('SELECT * FROM '.grace_table('inquiries').' WHERE id=?',[$row['inquiry_id']]);
        if (!$inquiry) { grace_query("DELETE FROM $table WHERE id=?",[$row['id']]); continue; }
        try {
            if (!in_array(strtolower($row['recipient']),array_map('strtolower',grace_mail_recipients($inquiry['kind'])),true)) throw new GraceMailError('Адрес получателя не соответствует типу заявки.');
            $mailer=grace_build_mail($inquiry,$row); $mailer->send(); unset($mailer);
            grace_query("UPDATE $table SET status='sent',sent_at=?,last_error='',locked_until=0 WHERE id=?",[grace_now(),$row['id']]);
            $result['sent']++;
        } catch (Throwable $exception) {
            $attempt=(int)$row['attempts'];
            $status=$attempt>=5?'failed':'retry';
            $delay=[1=>60,2=>300,3=>900,4=>3600][$attempt] ?? 3600;
            $error=$exception instanceof GraceMailError?$exception->getMessage():'Почтовый сервер не принял письмо. Проверьте почту хостинга или настройки SMTP.';
            grace_query("UPDATE $table SET status=?,next_attempt_at=?,last_error=?,locked_until=0 WHERE id=?",[$status,time()+$delay,grace_text($error,500),$row['id']]);
            $result['failed']++;
        }
        unset($mailer,$exception,$inquiry,$row);
    }
    return $result;
}

function grace_mail_after_response(?string $inquiryId=null): void {
    register_shutdown_function(static function() use ($inquiryId): void {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try { grace_process_mail(4,$inquiryId); }
        catch (Throwable) { error_log('Grace Models: mail queue processing failed.'); }
    });
}

function grace_inquiries_with_mail(array $inquiries): array {
    $grouped=[];
    foreach (array_chunk(array_column($inquiries,'id'),400) as $ids) {
        $marks=implode(',',array_fill(0,count($ids),'?'));
        foreach (grace_rows('SELECT recipient,status,attempts,last_error,sent_at,inquiry_id FROM '.grace_table('mail_queue').' WHERE inquiry_id IN ('.$marks.') ORDER BY recipient',$ids) as $mail) $grouped[$mail['inquiry_id']][]=$mail;
    }
    foreach ($inquiries as &$inquiry) $inquiry['notifications']=$grouped[$inquiry['id']] ?? [];
    unset($inquiry); return $inquiries;
}

function grace_retry_inquiry_mail(string $id): array {
    if (!grace_first('SELECT id FROM '.grace_table('inquiries').' WHERE id=?',[$id])) grace_error('Заявка не найдена.',404);
    grace_query("UPDATE ".grace_table('mail_queue')." SET status='pending',attempts=0,next_attempt_at=0,last_error='' WHERE inquiry_id=? AND status IN ('retry','failed') AND locked_until<=?",[$id,time()]);
    return grace_process_mail(4,$id);
}
