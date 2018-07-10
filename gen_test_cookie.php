#!/usr/bin/env php
<?php

$token = bin2hex(random_bytes(16));
$time = time();
$secret = 'eyZsz5dJDg28oNr385YjG4UQasx7D4q9_PLZ_CHANGE_IT!!!';
$digest = md5("{$secret}{$token}{$time}");

$template = <<<EOT
// Copy it in browser console for the localhost page.
// The next action: var ws = new WebSocket("ws://localhost:8099");
// Please, enjoy!
document.cookie = "token=$token";
document.cookie = "time=$time";
document.cookie = "digest=$digest";

EOT;

echo $template;
