<?php

// git clone https://github.com/ronnywang/ivod-transcript
include(__DIR__ . "/../init.inc.php");
$db = CongressWatch::getDb();
foreach (glob("ivod-transcript/output/*/*") as $file) {
    if (!preg_match('#1M_(\d+)$#', $file, $matches)) {
        continue;
    }
    $id = intval($matches[1]);

    $row = $db->query("SELECT * FROM video WHERE video_id = " . intval($id))->fetch();
    if ($row['transcript']) {
        continue;
    }
    error_log("update {$id} transcript");
    $db->prepare("UPDATE video SET transcript = :transcript WHERE video_id = :video_id")->execute([
        'transcript' => file_get_contents($file),
        'video_id' => $id,
    ]);
}
