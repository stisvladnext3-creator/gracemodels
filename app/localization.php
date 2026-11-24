<?php
declare(strict_types=1);
if (!defined('GRACE_ROOT')) { http_response_code(404); exit; }

function grace_request_language(): string { return ($_GET['lang'] ?? '')==='en'?'en':'ru'; }
function grace_chrome_template_values(string $lang): array {
    return ['{{SITE_FOOTER}}'=>grace_footer_html($lang),'{{SITE_LOGO}}'=>grace_logo_html(),'{{HOME_HREF}}'=>grace_language_href('/',$lang),'{{HOME_LABEL}}'=>$lang==='en'?'Back to home':'На главную','{{UI_LANGUAGE}}'=>$lang==='en'?'en':'ru'];
}
function grace_localized(array $record, string $field, string $lang): string {
    $english=$record[$field.'_en'] ?? '';
    return $lang==='en' && is_string($english) && $english!==''?$english:(string)($record[$field] ?? '');
}
function grace_known_english(string $text): string { return grace_site_defaults()['translations'][$text] ?? ''; }
function grace_localized_layout(array $layout): array {
    foreach ($layout['navigation'] as &$item) {
        $item['label_en'] ??= grace_known_english($item['label']);$item['value_en'] ??= '';
    } unset($item);
    foreach ($layout['footer'] as &$block) {
        $block['title_en'] ??= grace_known_english($block['title']);
        foreach ($block['items'] as &$item) {
            $item['label_en'] ??= grace_known_english($item['label']);
            $item['value_en'] ??= in_array($item['type'],['text','address'],true)?grace_known_english($item['value']):'';
        } unset($item);
    } unset($block);
    foreach (grace_site_defaults()['page_defaults'] as $key=>$defaults) {
        $layout['pages'][$key] ??= ['title'=>$defaults['title'],'title_en'=>$defaults['title_en']];
    }
    $layout['locale_schema']=1;
    return $layout;
}
function grace_migrate_layout_locales(PDO $pdo, array $config): void {
    $table=grace_table('settings',$config);
    $query=$pdo->prepare('SELECT setting_value FROM '.$table.' WHERE setting_key=?');$query->execute(['site_layout']);$raw=$query->fetchColumn();
    if (!is_string($raw)) return;
    $layout=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
    if (($layout['locale_schema'] ?? 0)>=1) return;
    $layout=grace_localized_layout($layout);$layout['version']=gmdate('YmdHis').'-locale-'.bin2hex(random_bytes(5));
    $update=$pdo->prepare('UPDATE '.$table.' SET setting_value=? WHERE setting_key=? AND setting_value=?');
    $update->execute([json_encode($layout,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'site_layout',$raw]);
}
function grace_page_key(string $href): ?string {
    $parts=parse_url($href);if (!$parts) return null;
    $path=rtrim($parts['path'] ?? '','/') ?: '/';$query=[];parse_str($parts['query'] ?? '',$query);
    if ($path==='/models') {
        if (($query['saved'] ?? '')==='1') return 'shortlist';
        return in_array($query['category'] ?? 'main',['main','new','international','direct'],true)?($query['category'] ?? 'main'):'main';
    }
    return ['/about'=>'about','/contacts'=>'contacts','/news'=>'news','/become-a-model'=>'application','/booking'=>'booking'][$path] ?? null;
}
function grace_page_titles(array $layout, array $forms): array {
    $out=['ru'=>[],'en'=>[]];
    foreach (['ru','en'] as $lang) foreach (grace_site_defaults()['page_defaults'] as $key=>$defaults) {
        // A blank page title follows the navigation; form headings keep their own editor.
        $title=(string)($layout['pages'][$key][$lang==='en'?'title_en':'title'] ?? '');
        if ($title==='' && $lang==='ru' && in_array($key,['application','booking'],true)) $title=(string)($forms[$key]['title'] ?? '');
        if ($title==='') foreach ($layout['navigation'] as $item) {
            if (!$item['enabled'] || $item['type']!=='link' || ($item['source'] ?? '')!=='') continue;
            // External links with a coinciding path must not rename a local page.
            if (!str_starts_with($item['value'],'/') || str_starts_with($item['value'],'//')) continue;
            if (grace_page_key($item['value'])!==$key) continue;
            $title=grace_localized($item,'label',$lang);if ($title!=='') break;
        }
        if ($title==='') $title=$defaults[$lang==='en'?'fallback_en':'fallback'];
        $out[$lang][$key]=$title;
    }
    return $out;
}
function grace_language_href(string $href, string $lang): string {
    if ($lang!=='en' || !str_starts_with($href,'/') || str_starts_with($href,'//')) return $href;
    $parts=parse_url($href);if (!$parts) return $href;
    $query=[];parse_str($parts['query'] ?? '',$query);$query['lang']='en';
    return ($parts['path'] ?? '/').'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986).(isset($parts['fragment'])?'#'.$parts['fragment']:'');
}
