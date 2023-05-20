<?php

include(__DIR__ . '/../init.inc.php');

$db = CongressWatch::getDb();

$congressmen = [];
// get taiwan legislator data
$obj = json_decode(file_get_contents('https://data.ly.gov.tw/odw/openDatasetJson.action?id=9&selectTerm=all&page=1'));
foreach ($obj->jsonList as $list) {
    $committee = explode(';', trim($list->committee, ';'));
    $list->name = trim($list->name);

    $congressman = new StdClass;
    // name for congressman name
    $congressman->name = $list->name;
    // description for slack description
    $congressman->description = sprintf("政黨：%s，委員會：%s", $list->party, array_shift($committee));
    // channel for slack channel name
    $congressman->slack_channel = strtolower("立委-" . $list->name);
    $congressman->slack_channel = str_replace('‧', '-', $congressman->slack_channel);
    $congressman->slack_channel = str_replace(' ', '-', $congressman->slack_channel);
    $congressman->origin_data = $list;

    $congressmen[] = $congressman;
}

// update to database
// TODO: handle same name
$db_data = [];
foreach ($db->query("SELECT * FROM congressman", PDO::FETCH_OBJ) as $row) {
    $db_data[$row->name] = $row;
}

foreach ($congressmen as $congressman) {
    // Insert DATA
    if (!array_key_exists($congressman->name, $db_data)) {
        $db->prepare("INSERT INTO congressman (name, slack_channel, user_data) VALUES (:name, :slack_channel, :user_data)")->execute([
            'name' => $congressman->name,
            'slack_channel' => $congressman->slack_channel,
            'user_data' => json_encode($congressman),
        ]);
    }

    $channels[$congressman->slack_channel] = $congressman;
    // TODO: update data
}

// update to slack
foreach (CongressWatch::slackListChannel() as $channel) {
    if (array_key_exists($channel->name, $channels)) {
        if ($channel->topic->value != $channels[$channel->name]->description) {
            CongressWatch::slackQuery('conversations.setTopic', 'POST', [
                'channel' => $channel->id,
                'topic' => $channels[$channel->name]->description,
            ]);
        }
        unset($channels[$channel->name]);
    }
}

// create new channel
foreach ($channels as $channel_name => $data) {
    error_log("creating {$channel_name}");
    $ret = CongressWatch::slackQuery('conversations.create', 'POST', [
        'name' => $channel_name,
    ]);
        
    CongressWatch::slackQuery('conversations.setTopic', 'POST', [
        'channel' => $ret->channel->id,
        'topic' => $data->description,
    ]);
}
