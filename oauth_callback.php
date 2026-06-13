<?php
declare(strict_types=1);

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$_SERVER['REQUEST_URI'] = '/ebay/oauth/callback' . ($queryString !== '' ? '?' . $queryString : '');
require __DIR__ . '/index.php';
