<?php

if (file_exists(__DIR__ . '/config.php')) {
    require_once(__DIR__ . '/config.php');
}

class CongressWatch
{
    protected static $_pdo = null;
    public static function getDb()
    {
        if (is_null(self::$_pdo)) {
            $pdo = new PDO(getenv('PDO_DSN'), getenv('PDO_USERNAME'), getenv('PDO_PASSWORD'));
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            self::$_pdo = $pdo;
        }
        return self::$_pdo;
    }

    public static function slackQuery($api, $method = 'GET', $data = null)
    {
        $url = sprintf("https://slack.com/api/{$api}");
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . getenv('SLACK_ACCESS_TOKEN'),
            "Content-Type: application/json; charset=utf-8",
        ]);
        if ($method != 'GET') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        }
        if (!is_null($data)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $ret = json_decode(curl_exec($curl));
        if (!$ret or !property_exists($ret, 'ok') or !$ret->ok) {
            throw new Exception('slack error: ' . json_encode($ret));
        }
        return $ret;
    }

    public static function slackListChannel()
    {
        $limit = 20;
        $url = "conversations.list?limit={$limit}";
        while (true) {
            $ret = CongressWatch::slackQuery($url);
            foreach ($ret->channels as $channel) {
                yield $channel;
            }
            if (!$ret->response_metadata->next_cursor) {
                break;
            }
            $url = "conversations.list?limit={$limit}&cursor={$ret->response_metadata->next_cursor}";
        }
    }

    protected static $_names = null;
    public static function getCongressManId($name)
    {
        if (is_null(self::$_names)) {
            $db = self::getDb();
            self::$_names = [];
            foreach ($db->query("SELECT congressman_id, name FROM congressman") as $row) {
                self::$_names[$row['name']] = $row['congressman_id'];
            }
        }
        if (array_key_exists($name, self::$_names)) {
            return self::$_names[$name];
        }
        return null;
    }

    public static function addSpeech($speech)
    {
        $db = self::getDb();
        if (!property_exists($speech, 'video_url')) {
            throw new Exception('need speech.video_url');
        }

        $stmt = $db->prepare("SELECT * FROM video WHERE video_id = :video_id");
        $stmt->execute(['video_id' => $speech->video_id]);
        if (!$video = $stmt->fetch()) {
            $db->prepare("INSERT INTO video (video_id, video_url, video_at, transcript, data) VALUES (:video_id, :video_url, :video_at, '', '{}')")->execute([
                'video_id' => $speech->video_id,
                'video_url' => $speech->video_url,
                'video_at' => intval($speech->video_at),
            ]);
            $video_id = $db->lastInsertId();
        } else {
            $video_id = $video['video_id'];
        }

        error_log("inserting speech {$video_id}");
        $stmt = $db->prepare("SELECT * FROM speech WHERE speech_id = :speech_id");
        $stmt->execute(['speech_id' => $video_id]);
        if (!$row = $stmt->fetch()) {
            $db->prepare("INSERT INTO speech (speech_id, video_id, video_start, video_end, spoken_by, data, summary, summary_sent_at, summary_message_id) VALUES (:speech_id, :video_id, :video_start, :video_end, :spoken_by, :data, :summary, :summary_sent_at, :summary_message_id)")->execute([
                'speech_id' => $speech->speech_id,
                'video_id' => $speech->video_id,
                'video_start' => $speech->video_start,
                'video_end' => $speech->video_end,
                'spoken_by' => $speech->spoken_by,
                'data' => $speech->data,
                'summary' => '',
                'summary_sent_at' => 0,
                'summary_message_id' => '',
            ]);
        }
    }
}
