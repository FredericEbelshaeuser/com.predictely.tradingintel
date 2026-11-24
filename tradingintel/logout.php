<?php require 'bootstrap.php';if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}csrf();$_SESSION=[];session_destroy();echo 'Signed out';
