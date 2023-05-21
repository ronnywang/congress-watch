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
}
