<?php
declare(strict_types=1);

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$_SERVER['REQUEST_URI'] = '/ebay/callback' . ($queryString !== '' ? '?' . $queryString : '');
require dirname(__DIR__) . '/index.php';
