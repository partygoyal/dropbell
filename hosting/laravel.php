<?php

if (str_starts_with($_SERVER['REQUEST_URI'], '/backend-api/')) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen('/backend-api'));
}
require __DIR__.'/../backend/public/index.php';
