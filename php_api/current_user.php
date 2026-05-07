<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$s = require_session();
json_response($s);
