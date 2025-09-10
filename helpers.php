<?php
// helpers.php — drobne funkcje pomocnicze
// ZAPISZ: C:\xampp\htdocs\librus\helpers.php

if (!function_exists('sanitize')) {
    function sanitize($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
