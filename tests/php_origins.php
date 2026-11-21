<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('GRACE_ROOT',dirname(__DIR__));
require GRACE_ROOT.'/app/bootstrap.php';
$cases=[
    ['https://agency.test','http://agency.test',true],
    ['https://AGENCY.test:443/','http://agency.test:80/',true],
    ['https://agency.test','http://agency.test:443',true],
    ['http://agency.test','https://agency.test',false],
    ['https://agency.test','https://agency.test',true],
    ['https://agency.test:8443','http://agency.test:8443',true],
    ['https://agency.test:8443','http://agency.test',false],
    ['https://www.agency.test','http://agency.test',false],
    ['https://agency.test.evil.test','http://agency.test',false],
    ['https://evil.test','https://agency.test',false],
    ['https://agency.test@evil.test','https://agency.test',false],
    ['https://evil.test@agency.test','https://agency.test',false],
    ['https://agency.test/path','https://agency.test',false],
    ['https://agency.test?x=1','https://agency.test',false],
    ['https://agency.test#fragment','https://agency.test',false],
    ['null','https://agency.test',false],
    ['https://agency.test:65536','https://agency.test',false],
    ["https://agency.test\r\n",'https://agency.test',false],
];
foreach ($cases as [$incoming,$stored,$expected]) {
    if (grace_origin_allowed($incoming,$stored)!==$expected) throw new RuntimeException('Origin validation failed: '.$incoming);
}
foreach ([['HTTPS'=>'on'],['SERVER_PORT'=>'443'],['HTTP_X_FORWARDED_PROTO'=>'https'],['HTTP_X_FORWARDED_SSL'=>'on']] as $server) {
    $_SERVER=$server;
    if (!grace_request_https()) throw new RuntimeException('HTTPS cookie signal was missed.');
}
$_SERVER=['HTTPS'=>'off','SERVER_PORT'=>'80','HTTP_X_FORWARDED_PROTO'=>'http'];
if (grace_request_https()) throw new RuntimeException('Plain HTTP was mistaken for HTTPS.');
echo "PASS ".count($cases)." origin cases and five transport cases\n";
