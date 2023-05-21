<?php

include(__DIR__ . '/../init.inc.php');

$db = CongressWatch::getDb();

$get_video_content = function() use ($db) {
    $ret = $db->query("SELECT MAX(video_id), MIN(video_id) FROM video")->fetch();
    if (is_null($ret[0])) {
        $min = 146360 - 1;
        $max = 146360;
    } else {
        $min = $ret[1] - 1;
        $max = $ret[0] + 1;
    }

    // get new
    for (; ; $max ++) {
        $url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $max);
        $content = file_get_contents($url);
        if (strpos($content, 'readyPlayer') === false) {
            break;
        }
        yield [$max, $url, $content];
    }

    // get old
    for (; $min; $min --) {
        $url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $min);
        $content = file_get_contents($url);
        if (strpos($content, 'readyPlayer') === false) {
            break;
        }
        yield [$min, $url, $content];
    }
};

$parse_video_info = function($content, $video_id) {
    $ret = new StdClass;
    $ret->video_id = $video_id;
    if (!preg_match('#readyPlayer\("([^"]*)"#', $content, $matches)) {
        throw new Exception("readyPlayer not found: $video_id");
    }
    $ret->video_url = $matches[1];

    $doc = new DOMDocument;
    $content = str_replace('<head>', '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">', $content);

    @$doc->loadHTML($content);
    foreach ($doc->getElementsByTagName('div') as $div_dom) {
        if ($div_dom->getAttribute('class') == 'video-text') {
            $h4 = $div_dom->getElementsByTagName('h4')->item(0);
            if (preg_match('#主辦單位 ：(.*)#', $h4->nodeValue, $matches)) {
                $ret->{'主辦單位'} = $matches[1];
            }
            $ret->{'會期'} = $h4->nextSibling->nextSibling->nodeValue;
            $fields = ['委員名稱', '委員發言時間', '影片長度', '會議時間', '會議名稱'];
            foreach ($div_dom->getElementsByTagName('strong') as $strong_dom) {
                $key = $strong_dom->nodeValue;
                $key = str_replace('：', '', $key);
                if (in_array($key, $fields)) {
                    $value = '';
                    $d = $strong_dom;
                    while ($d = $d->nextSibling) {
                        $value .= $d->nodeValue;
                    }
                    $ret->{$key} = trim($value);
                }
            }
            foreach ($div_dom->getElementsByTagName('a') as $a_dom) {
                if ($a_dom->getAttribute('title') == '會議相關資料') {
                    $ret->{'會議相關資料'} = $a_dom->getAttribute('href');
                }
            }
        }
    }
    return $ret;
};

foreach ($get_video_content() as $video) {
    list($video_id, $url, $content) = $video;
    $obj = $parse_video_info($content, $video_id);
    $name = $obj->{'委員名稱'};
    if (!$congressman_id = CongressWatch::getCongressManId($name)) {
        throw new Exception("Congressman {$name} not found, video_id = {$video_id}");
    }

    $speech = new StdClass;
    $speech->speech_id = $video_id;
    $speech->video_id = $video_id;
    $speech->video_url = $obj->video_url; // will build video data with this url
    $speech->video_start = 0; // 0 means all video with 1 speech
    $speech->video_end = 0; // 0 means all video with 1 speech
    $speech->video_at = strtotime(explode(' - ', $obj->{'委員發言時間'})[0], strtotime($obj->{'會議時間'})); // video start at
    $speech->spoken_by = $congressman_id;
    $speech->data = json_encode($obj);
    $speech->summary = '';
    $speech->summary_sent_at = 0;
    $speech->summary_message_id = '';

    CongressWatch::addSpeech($speech);
}
