<?php

$libmemcachedVersion = `php --info | awk '/^libmemcached version => / {print $4;}'`;
header('X-Libmemcached-Version: ' . $libmemcachedVersion);

session_start();

echo 'The session has started: ' . session_id() . PHP_EOL;

echo 'Key values in the session:'. PHP_EOL;
foreach ($_SESSION as $key => $value) {
    echo '    ' . $key . ' = ' . $value . PHP_EOL;
}

if (count($_GET) > 0) {
    echo 'Adding to the session:' . PHP_EOL;
    foreach ($_GET as $key => $value) {
        $_SESSION[$key] = $value;
        echo '    ' . $key . ' = ' . $value . PHP_EOL;
    }
}

session_write_close();
