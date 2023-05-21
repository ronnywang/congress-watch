<?php

include(__DIR__ . '/init.inc.php');

$db = CongressWatch::getDb();

$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver == 'mysql') {
    $autoIncrement = 'AUTO_INCREMENT';
} elseif ($driver == 'sqlite') {
    $autoIncrement = 'AUTOINCREMENT';
}
$cols = [];
$cols[] = 'congressman_id INTEGER ';
$cols[] = 'name VARCHAR(64)';
$cols[] = 'slack_channel VARCHAR(64)';
$cols[] = 'user_data TEXT';
$cols[] = "PRIMARY KEY ( congressman_id $autoIncrement )";

$db->exec("CREATE TABLE congressman (" . implode(',', $cols) . ")");

$cols = [];
$cols[] = 'speech_id INTEGER ';
$cols[] = 'video_id INT';
$cols[] = 'video_start INT';
$cols[] = 'video_end INT';
$cols[] = 'spoken_by INT';
$cols[] = 'data TEXT';
$cols[] = 'summary TEXT';
$cols[] = 'summary_sent_at INT';
$cols[] = 'summary_message_id TEXT';
$cols[] = "PRIMARY KEY ( speech_id $autoIncrement )";
$db->exec("CREATE TABLE speech (" . implode(',', $cols) . ")");
$db->exec("CREATE INDEX speech_video_id ON speech (video_id)");

$cols = [];
$cols[] = 'video_id INTEGER ';
$cols[] = 'video_url TEXT';
$cols[] = 'video_at INT';
$cols[] = 'transcript TEXT';
$cols[] = 'data TEXT';
$cols[] = "PRIMARY KEY ( video_id $autoIncrement )";

$db->exec("CREATE TABLE video (" . implode(',', $cols) . ")");
$db->exec("CREATE INDEX video_video_url  ON video (video_url)");

