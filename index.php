<?php
/**
 * Redirige Laravel desde la raíz
 * Coloca este archivo en /public_html/index.php
 */
define('LARAVEL_START', microtime(true));

// Apunta a la carpeta public de tu backend
require_once __DIR__ . '/backend/public/index.php';
