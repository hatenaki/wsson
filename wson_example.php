#!/usr/bin/env php
<?php

if (php_sapi_name() != 'cli') {
    echo "CLI sapi only\n";
    exit;
}

$config = [
    'servers' => ['tcp://127.0.0.1:8099', 'tcp://127.0.0.2:8099'],
    'domains' => ['ws1.example.com:8099', 'ws2.example.com:8099'],
    'control' => 'unix://./control.sock',
    'pid' => './server.pid',
    'secret' => 'eyZsz5dJDg28oNr385YjG4UQasx7D4q9_PLZ_CHANGE_IT!!!',
    'origins' => ['http://localhost', 'file://']
];

$action = isset($argv[1]) ? strtolower(trim($argv[1])) : 'start';
if (!in_array($action, ['start', 'stop', 'restart'])) {
    echo "Usage: {$argv[0]} start|stop|restart\n";
    exit;
}

require './wson.class.php';

$server = new Wson($config);
$error = $server->$action();

if ($error) {
    echo date('Y-m-d H:i:s ')."$error\n";
}
