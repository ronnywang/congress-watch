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
    protected static $_id_to_name = null;
    public static function getCongressManId($name)
    {
        if (is_null(self::$_names)) {
            $db = self::getDb();
            self::$_names = [];
            self::$_id_to_name = [];
            foreach ($db->query("SELECT congressman_id, name, slack_channel FROM congressman") as $row) {
                self::$_names[$row['name']] = $row['congressman_id'];
                self::$_id_to_name[$row['congressman_id']] = $row;
            }
        }
        if (array_key_exists($name, self::$_names)) {
            return self::$_names[$name];
        }
        if (array_key_exists($name, self::$_id_to_name)) {
            return self::$_id_to_name[$name];
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

    public static function getPartialTranscript($record)
    {
        if ($record['video_start'] != 0 or $record['video_end'] != 0) {
            throw new Exception('TODO: unsupport video_start, video_end');
        }
        // TODO: get partial transcript by video_start and video_end
        $transcript = $record['transcript'];
        $transcript = preg_replace('#\[[0-9\- .>:]*\]\s+#', '', $transcript);
        $transcript = preg_replace('#\s+#', ' ', $transcript);
        return $transcript;
    }

    public static function summaryTranscript($text)
    {
        $obj = new StdClass;
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 2000);
            $obj->truncated = true;
        }
        $obj->prompt = '請幫我以 100 字摘要以下內容，並且以 「#關鍵字」 的型式列出最多三個重要的關鍵字：' . "\n";
        $obj->prompt .= $text;

        $messages = [
            ['role' => 'user', 'content' => $obj->prompt],
        ];

        while (true) {
            if (getenv('OPENAI_ENDPOINT')) {
                $curl = curl_init(getenv('OPENAI_ENDPOINT') . "/chat/completions?api-version=2023-03-15-preview");
            } else {
                $curl = curl_init("https://api.openai.com/v1/chat/completions");
            }
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Authorization: Bearer " . getenv('OPENAI_SECRET'),
                'api-key: ' . getenv('OPENAI_SECRET'),
            ]);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
                'model' => 'gpt-3.5-turbo',
                'temperature' => 0,
                'messages' => $messages,
            ]));
            $content = curl_exec($curl);
            error_log("got: " . $content);
            // {"id":"chatcmpl-7IdyKe3iWocE1Y1spEIpp3wx2B4QP","object":"chat.completion","created":1684677912,"model":"gpt-3.5-turbo-0301","usage":{"prompt_tokens":1613,"completion_tokens":265,"total_tokens":1878},"choices":[{"message":{"role":"assistant","content":"本文介紹了民眾黨黨團針對海洋污染防治法的修法提案。海岸線總長有一千五百多公里，各種垃圾很容易 進入海洋，影響海洋生態及環境。因此推動海洋環境保護工程非常重要。修法提案包括定期公開國家海洋污染防治白皮書、增加海 洋廢棄物來源的管理、明確規範路源污染物等，以強化我國海洋污染防治效果。另外，也提出了第35條的修正案，以避免侵害船員 返鄉權。#海洋污染 #海洋環境保護 #修法提案"},"finish_reason":"stop","index":0}]}
            if (!$ret = json_decode($content)) {
                throw new Exception('not valid json: ' . $content);
            }
            if (!property_exists($ret, 'choices')) {
                throw new Exception('choices not found: ' . $content);
            }
            if ($ret->choices[0]->finish_reason == 'stop') {
                break;
            }
            $messages[] = $ret->choices[0]->message;
        }
        $obj->result = $ret;
        return $obj;
    }
}
