<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80100) {
    http_response_code(503); header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Grace Models</h1><p>Включите PHP 8.1 или новее в панели хостинга.</p>'; exit;
}
define('GRACE_ROOT', __DIR__);
require GRACE_ROOT . '/app/bootstrap.php';
try {
    grace_dispatch();
} catch (Throwable $exception) {
    grace_handle_exception($exception);
}
