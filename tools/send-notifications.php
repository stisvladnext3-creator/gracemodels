<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('GRACE_ROOT',dirname(__DIR__));
require GRACE_ROOT.'/app/bootstrap.php';
$limit=isset($argv[1])?(int)$argv[1]:20;
$result=grace_process_mail($limit);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['failed']>0?1:0);
