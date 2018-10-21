<?php

try {

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/reports.php';

    // выводим лог
    echo Log::get();

} catch (Exception $e) {

    Log::append($e->getMessage());
    echo Log::get();

}
