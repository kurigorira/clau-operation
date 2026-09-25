<?php
define('OPE_APP', true);
require __DIR__ . '/auth.php';

$_SESSION = [];
session_destroy();
header('Location: login.php');
