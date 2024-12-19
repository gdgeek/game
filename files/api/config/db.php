<?php
return [
    'class' => 'yii\db\Connection',
    'dsn' => 'mysql:host=' . getenv('MYSQL_HOST') . ';dbname=' . getenv('MYSQL_DB'),
    'username' => getenv('MYSQL_NAME'),
    'password' => getenv('MYSQL_PASSWORD'),
    'charset' => 'utf8',
];
