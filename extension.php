<?php

final class TMTTranslateExtension extends Minz_Extension
{
    public function init(): void
    {
        parent::init();
        $this->registerHook('entry_before_display', [$this, 'onEntryBeforeDisplay'], 0);
        $this->registerHook('menu_configuration_entry', [$this, 'menuConfigurationEntry']);
    }

    public function install(): bool
    {
        $dbConfig = FreshRSS_Context::systemConf()->db;
        $prefix = $dbConfig['prefix'] ?? '';
        $dbType = $dbConfig['type'] ?? 'mysql';

        $sqlStatements = $this->getCreateTableSQL($prefix, $dbType);

        try {
            $dao = new TMTTranslateDAO();
            foreach ($sqlStatements as $sql) {
                $dao->exec($sql);
            }
            return true;
        } catch (\Throwable $e) {
            if (class_exists('Minz_Log')) {
                Minz_Log::error('TMT-Translate install failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    public function uninstall(): bool
    {
        $dbConfig = FreshRSS_Context::systemConf()->db;
        $prefix = $dbConfig['prefix'] ?? '';
        $dbType = $dbConfig['type'] ?? 'mysql';

        $tableName = $this->getTableName($prefix, $dbType);
        $sql = "DROP TABLE IF EXISTS {$tableName}";

        try {
            $dao = new TMTTranslateDAO();
            $dao->exec($sql);
            return true;
        } catch (\Throwable $e) {
            if (class_exists('Minz_Log')) {
                Minz_Log::error('TMT-Translate uninstall failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    private function getTableName(string $prefix, string $dbType): string
    {
        if ($dbType === 'sqlite') {
            return "`{$prefix}tmt_translate_cache`";
        }
        return "`{$prefix}_tmt_translate_cache`";
    }

    private function getCreateTableSQL(string $prefix, string $dbType): array
    {
        $tableName = $this->getTableName($prefix, $dbType);

        if ($dbType === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS {$tableName} (
                    id SERIAL PRIMARY KEY,
                    entry_id VARCHAR(255) NOT NULL,
                    user_id VARCHAR(50) NOT NULL,
                    feed_id VARCHAR(50) NOT NULL,
                    original_title TEXT,
                    translated_title TEXT,
                    original_content TEXT,
                    translated_content TEXT,
                    created_at BIGINT DEFAULT 0,
                    updated_at BIGINT DEFAULT 0
                )",
                "CREATE INDEX IF NOT EXISTS tmt_entry_id_index ON {$tableName} (entry_id)",
                "CREATE INDEX IF NOT EXISTS tmt_user_feed_index ON {$tableName} (user_id, feed_id)",
                "CREATE UNIQUE INDEX IF NOT EXISTS tmt_entry_user_unique ON {$tableName} (entry_id, user_id)"
            ];
        } elseif ($dbType === 'sqlite') {
            return [
                "CREATE TABLE IF NOT EXISTS {$tableName} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    entry_id VARCHAR(255) NOT NULL,
                    user_id VARCHAR(50) NOT NULL,
                    feed_id VARCHAR(50) NOT NULL,
                    original_title TEXT,
                    translated_title TEXT,
                    original_content TEXT,
                    translated_content TEXT,
                    created_at BIGINT DEFAULT 0,
                    updated_at BIGINT DEFAULT 0
                )",
                "CREATE INDEX IF NOT EXISTS tmt_entry_id_index ON {$tableName} (entry_id)",
                "CREATE INDEX IF NOT EXISTS tmt_user_feed_index ON {$tableName} (user_id, feed_id)",
                "CREATE UNIQUE INDEX IF NOT EXISTS tmt_entry_user_unique ON {$tableName} (entry_id, user_id)"
            ];
        } else {
            return [
                "CREATE TABLE IF NOT EXISTS {$tableName} (
                    id INT NOT NULL AUTO_INCREMENT,
                    entry_id VARCHAR(255) NOT NULL,
                    user_id VARCHAR(50) NOT NULL,
                    feed_id VARCHAR(50) NOT NULL,
                    original_title TEXT,
                    translated_title TEXT,
                    original_content LONGTEXT,
                    translated_content LONGTEXT,
                    created_at BIGINT DEFAULT 0,
                    updated_at BIGINT DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY (entry_id, user_id),
                    INDEX (entry_id),
                    INDEX (user_id, feed_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=INNODB"
            ];
        }
    }

    public function menuConfigurationEntry(): string
    {
        $url = '?tab=extensions&ext=' . rawurlencode($this->getName());
        return "<li class=\"item\"><a href=\"{$url}\">TMT-Translate</a></li>";
    }

    public function handleConfigureAction(): void
    {
        if (Minz_Request::isPost()) {
            $cfg = FreshRSS_Context::$user_conf;
            if ($cfg === null) return;

            $cfg->TMTTranslateSecretId = Minz_Request::param('secret_id', '');
            $cfg->TMTTranslateSecretKey = Minz_Request::param('secret_key', '');
            $cfg->TMTTranslateRegion = Minz_Request::param('region', 'ap-guangzhou');
            $cfg->TMTTranslateEndpoint = Minz_Request::param('endpoint', 'tmt.tencentcloudapi.com');
            $cfg->TMTTranslateContent = Minz_Request::param('translate_content', '0') === '1';

            $selectedFeeds = Minz_Request::param('TMTTranslateFeeds', []);
            if (!is_array($selectedFeeds)) {
                $selectedFeeds = [];
            }
            $cfg->TMTTranslateFeeds = array_fill_keys(array_keys($selectedFeeds), '1');

            $cfg->save();
        }
    }

    public function onEntryBeforeDisplay($entry)
    {
        try {
            if (!is_object($entry)) return $entry;

            $cfg = FreshRSS_Context::$user_conf;
            if ($cfg === null) return $entry;

            $selectedFeeds = $cfg->TMTTranslateFeeds ?? [];
            if (!is_array($selectedFeeds)) $selectedFeeds = [];

            $feedId = null;
            if (method_exists($entry, 'feed')) {
                $feed = $entry->feed();
                if (is_object($feed) && method_exists($feed, 'id')) {
                    $feedId = $feed->id();
                }
            }
            if ($feedId === null) return $entry;

            if (!isset($selectedFeeds[$feedId]) || $selectedFeeds[$feedId] !== '1') {
                return $entry;
            }

            $secretId = $cfg->TMTTranslateSecretId ?? '';
            $secretKey = $cfg->TMTTranslateSecretKey ?? '';
            $region = $cfg->TMTTranslateRegion ?? 'ap-guangzhou';
            $endpoint = $cfg->TMTTranslateEndpoint ?? 'tmt.tencentcloudapi.com';
            if (empty($secretId) || empty($secretKey)) return $entry;

            $entryId = method_exists($entry, 'id') ? $entry->id() : null;
            if ($entryId === null) return $entry;

            $cached = $this->getTranslationFromCache($entryId, $feedId);

            if ($cached) {
                if (!empty($cached['translated_title'])) {
                    if (method_exists($entry, '_title')) $entry->_title('[译] ' . $cached['translated_title']);
                    else $entry->title = '[译] ' . $cached['translated_title'];
                }

                $translateContent = $cfg->TMTTranslateContent ?? false;
                if ($translateContent && !empty($cached['translated_content'])) {
                    if (method_exists($entry, '_content')) $entry->_content($cached['translated_content']);
                    else $entry->content = $cached['translated_content'];
                }
                return $entry;
            }

            $client = new TMTClient($secretId, $secretKey, $region, $endpoint);

            $translatedTitle = null;
            $translatedContent = null;

            $title = method_exists($entry, 'title') ? $entry->title() : ($entry->title ?? '');
            if (!empty($title)) {
                $translatedTitle = $client->translate($title, 'en', 'zh');
                if ($translatedTitle !== null) {
                    if (method_exists($entry, '_title')) $entry->_title('[译] ' . $translatedTitle);
                    else $entry->title = '[译] ' . $translatedTitle;
                }
            }

            $translateContent = $cfg->TMTTranslateContent ?? false;
            if ($translateContent) {
                $content = method_exists($entry, 'content') ? $entry->content() : ($entry->content ?? '');
                if (!empty($content)) {
                    $translatedContent = $client->translate(strip_tags($content), 'en', 'zh');
                    if ($translatedContent !== null) {
                        if (method_exists($entry, '_content')) $entry->_content($translatedContent);
                        else $entry->content = $translatedContent;
                    }
                }
            }

            $this->saveTranslationToCache($entryId, $feedId, $title, $translatedTitle, $content ?? '', $translatedContent);
        } catch (\Throwable $e) {
            if (class_exists('Minz_Log')) Minz_Log::warning('TMT-Translate: ' . $e->getMessage());
            else error_log('TMT-Translate: ' . $e->getMessage());
        }
        return $entry;
    }

    private function getTranslationFromCache(string $entryId, string $feedId): ?array
    {
        try {
            $dao = new TMTTranslateDAO();
            $dbConfig = FreshRSS_Context::systemConf()->db;
            $prefix = $dbConfig['prefix'] ?? '';
            $dbType = $dbConfig['type'] ?? 'mysql';
            $userId = Minz_User::name() ?? '';

            $tableName = $this->getTableName($prefix, $dbType);
            $stmt = $dao->prepare("SELECT * FROM {$tableName} WHERE entry_id = ? AND user_id = ?");
            $stmt->execute([$entryId, $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ?: null;
        } catch (\Throwable $e) {
            if (class_exists('Minz_Log')) Minz_Log::warning('TMT-Translate cache read error: ' . $e->getMessage());
            else error_log('TMT-Translate cache read error: ' . $e->getMessage());
            return null;
        }
    }

    private function saveTranslationToCache(string $entryId, string $feedId, string $originalTitle, ?string $translatedTitle, string $originalContent, ?string $translatedContent): void
    {
        try {
            $dao = new TMTTranslateDAO();
            $dbConfig = FreshRSS_Context::systemConf()->db;
            $prefix = $dbConfig['prefix'] ?? '';
            $dbType = $dbConfig['type'] ?? 'mysql';
            $userId = Minz_User::name() ?? '';

            $tableName = $this->getTableName($prefix, $dbType);
            $now = time();

            if ($dbType === 'pgsql') {
                $sql = "INSERT INTO {$tableName} 
                        (entry_id, user_id, feed_id, original_title, translated_title, original_content, translated_content, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON CONFLICT (entry_id, user_id) DO UPDATE SET
                        translated_title = EXCLUDED.translated_title,
                        translated_content = EXCLUDED.translated_content,
                        updated_at = EXCLUDED.updated_at";
                $stmt = $dao->prepare($sql);
                $stmt->execute([$entryId, $userId, $feedId, $originalTitle, $translatedTitle, $originalContent, $translatedContent, $now, $now]);
            } elseif ($dbType === 'sqlite') {
                $sql = "INSERT OR REPLACE INTO {$tableName} 
                        (id, entry_id, user_id, feed_id, original_title, translated_title, original_content, translated_content, created_at, updated_at)
                        VALUES ((SELECT id FROM {$tableName} WHERE entry_id = ? AND user_id = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $dao->prepare($sql);
                $stmt->execute([$entryId, $userId, $entryId, $userId, $feedId, $originalTitle, $translatedTitle, $originalContent, $translatedContent, $now, $now]);
            } else {
                $sql = "INSERT INTO {$tableName} 
                        (entry_id, user_id, feed_id, original_title, translated_title, original_content, translated_content, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        translated_title = VALUES(translated_title),
                        translated_content = VALUES(translated_content),
                        updated_at = VALUES(updated_at)";
                $stmt = $dao->prepare($sql);
                $stmt->execute([$entryId, $userId, $feedId, $originalTitle, $translatedTitle, $originalContent, $translatedContent, $now, $now]);
            }
        } catch (\Throwable $e) {
            if (class_exists('Minz_Log')) Minz_Log::warning('TMT-Translate cache write error: ' . $e->getMessage());
            else error_log('TMT-Translate cache write error: ' . $e->getMessage());
        }
    }
}

class TMTClient
{
    private string $secretId;
    private string $secretKey;
    private string $region;
    private string $endpoint;
    private string $service = 'tmt';
    private string $version = '2018-03-21';
    private static float $lastRequestTime = 0;
    private const MIN_INTERVAL = 0.25;

    public function __construct(string $secretId, string $secretKey, string $region = 'ap-guangzhou', string $endpoint = 'tmt.tencentcloudapi.com')
    {
        $this->secretId = $secretId;
        $this->secretKey = $secretKey;
        $this->region = $region;
        $this->endpoint = $endpoint;

        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    private function applyRateLimit(): void
    {
        $now = microtime(true);
        $elapsed = $now - self::$lastRequestTime;

        if ($elapsed < self::MIN_INTERVAL) {
            usleep((int)((self::MIN_INTERVAL - $elapsed) * 1000000));
        }

        self::$lastRequestTime = microtime(true);
    }

    public function translate(string $text, string $source = 'en', string $target = 'zh'): ?string
    {
        $this->applyRateLimit();

        if (class_exists('\\TencentCloud\\Tmt\\V20180321\\TmtClient')) {
            try {
                $cred = new \TencentCloud\Common\Credential($this->secretId, $this->secretKey);
                $httpProfile = new \TencentCloud\Common\Profile\HttpProfile();
                $httpProfile->setEndpoint($this->endpoint);
                $clientProfile = new \TencentCloud\Common\Profile\ClientProfile();
                $clientProfile->setHttpProfile($httpProfile);
                $client = new \TencentCloud\Tmt\V20180321\TmtClient($cred, $this->region, $clientProfile);

                $req = new \TencentCloud\Tmt\V20180321\Models\TextTranslateRequest();
                $params = [
                    'SourceText' => mb_substr($text, 0, 5000),
                    'Source' => $source,
                    'Target' => $target,
                    'ProjectId' => 0,
                ];
                $req->fromJsonString(json_encode($params, JSON_UNESCAPED_UNICODE));
                $resp = $client->TextTranslate($req);
                if (isset($resp->TargetText)) return $resp->TargetText;
            } catch (\Throwable $e) {
                if (class_exists('Minz_Log')) Minz_Log::warning('TMT-Translate SDK error: ' . $e->getMessage());
                else error_log('TMT-Translate SDK error: ' . $e->getMessage());
            }
        }

        return null;
    }
}

class TMTTranslateDAO extends Minz_ModelPdo
{
    public function exec(string $sql): int|false
    {
        return $this->pdo->exec($sql);
    }

    public function prepare(string $sql): PDOStatement|false
    {
        return $this->pdo->prepare($sql);
    }
}
