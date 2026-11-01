<?php

declare(strict_types=1);

$db = new PDO('sqlite:'.__DIR__.'/example.sqlite');
$db->exec('CREATE TABLE IF NOT EXISTS sales (category TEXT, amount INTEGER)');
$db->exec('DELETE FROM sales');
$db->exec("INSERT INTO sales VALUES ('books', 20), ('books', 30), ('music', 10)");
