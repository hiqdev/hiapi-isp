<?php

require_once 'IspApi.php';

$api = new IspApi($_SERVER['QUERY_STRING']);

$api->run();

//var_dump($_SERVER);

//echo php_sapi_name();
//phpinfo();
