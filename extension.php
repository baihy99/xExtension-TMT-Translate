<?php

final class TMTTranslateExtension extends Minz_Extension {
    #[\Override]
    public function init(): void {
        parent::init();

        $this->registerHook(Minz_HookType::EntryBeforeDisplay, [$this, 'onEntryBeforeDisplay'], 0);
        $this->registerHook('menu_configuration_entry', [$this, 'menuConfigurationEntry']);
        $this->registerHook('api_misc', [$this, 'apiMisc']);
    }

    public function menuConfigurationEntry(): string {
        $url = Minz_URL::absolute("?tab=extensions&ext=" . rawurlencode($this->getName()));
        return "<li class=\"item\"><a href=\"{$url}\">TMT-Translate</a></li>";
    }

    public function handleConfigureAction(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $cfg = $this->getSystemConfiguration();
            $cfg['SecretId'] = trim($_POST['secret_id'] ?? '');
            $cfg['SecretKey'] = trim($_POST['secret_key'] ?? '');
            $cfg['Region'] = trim($_POST['region'] ?? 'ap-guangzhou');
            $cfg['Endpoint'] = trim($_POST['endpoint'] ?? 'tmt.tencentcloudapi.com');
            // selected_feeds is an array of feed ids (strings); store as JSON
            $selected = $_POST['selected_feeds'] ?? [];
            if (is_array($selected)) {
                $cfg['SelectedFeeds'] = array_values($selected);
            } else {
                $cfg['SelectedFeeds'] = [];
            }
            // Backwards-compat: also accept manual feeds text
            $cfg['FeedsManual'] = trim($_POST['feeds_manual'] ?? '');
            $this->setSystemConfiguration($cfg);
        }

        $config = $this->getSystemConfiguration();
        // Try to load feeds for the configure view: attempt several methods, gracefully fallback.
        $feeds = [];
        try {
            if (class_exists('FreshRSS_Feed')) {
                // Try common methods (best-effort)
                if (method_exists('FreshRSS_Feed', 'all')) {
                    $all = FreshRSS_Feed::all();
                    foreach ($all as $f) {
                        $feeds[] = ['id' => $f->id ?? $f['id'] ?? '', 'title' => $f->title ?? $f['name'] ?? $f['url'] ?? ''];
                    }
                } elseif (method_exists('FreshRSS_Feed', 'getAll')) {
                    $all = FreshRSS_Feed::getAll();
                    foreach ($all as $f) {
                        $feeds[] = ['id' => $f->id ?? $f['feed_id'] ?? '', 'title' => $f->title ?? $f['name'] ?? $f['url'] ?? ''];
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore; template will fallback to manual input
        }

        include $this->getPath() . '/configure.phtml';
    }

    // Minimal misc API to return feeds as JSON if needed by JS
    public function apiMisc(): void {
        if (!isset($_GET['ext']) || $_GET['ext'] !== $this->getName()) {
            return;
        }
        $action = $_GET['action'] ?? '';
        if ($action === 'listFeeds') {
            $feeds = [];
            try {
                if (class_exists('FreshRSS_Feed')) {
                    if (method_exists('FreshRSS_Feed', 'all')) {
                        $all = FreshRSS_Feed::all();
                        foreach ($all as $f) {
                            $feeds[] = ['id' => $f->id ?? $f['id'] ?? '', 'title' => $f->title ?? $f['name'] ?? $f['url'] ?? ''];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            header('Content-Type: application/json');
            echo json_encode(['feeds' => $feeds]);
            exit;
        }
    }

    public function onEntryBeforeDisplay($entry) {
        try {
            if (!is_object($entry)) {
                return $entry;
            }
            $config = $this->getSystemConfiguration();
            $selected = $config['SelectedFeeds'] ?? [];
            $manual = isset($config['FeedsManual']) ? array_filter(array_map('trim', explode(',', $config['FeedsManual']))) : [];

            $feedId = null;
            if (property_exists($entry, 'feed') && is_object($entry->feed)) {
                $feedId = $entry->feed->id ?? ($entry->feed->feed_id ?? null);
            }
            if ($feedId === null && method_exists($entry, 'getFeed')) {
                $f = $entry->getFeed();
                if (is_object($f)) {
                    $feedId = $f->id ?? ($f->feed_id ?? null);
                }
            }

            $shouldTranslate = false;
            if ($feedId !== null && in_array((string)$feedId, $selected, true)) {
                $shouldTranslate = true;
            }
            // fallback: manual patterns (legacy)
            if (!$shouldTranslate && !empty($manual)) {
                $feedIdentifier = $entry->feed->url ?? $entry->feed->link ?? $entry->feed_url ?? null;
                if ($feedIdentifier !== null) {
                    foreach ($manual as $pattern) {
                        if ($pattern === '') continue;
                        if (stripos($feedIdentifier, $pattern) !== false || $feedIdentifier === $pattern) {
                            $shouldTranslate = true;
                            break;
                        }
                    }
                }
            }

            if (!$shouldTranslate) return $entry;

            $secretId = $config['SecretId'] ?? '';
            $secretKey = $config['SecretKey'] ?? '';
            $region = $config['Region'] ?? 'ap-guangzhou';
            $endpoint = $config['Endpoint'] ?? 'tmt.tencentcloudapi.com';
            if (empty($secretId) || empty($secretKey)) return $entry;

            $client = new TMTClient($secretId, $secretKey, $region, $endpoint);

            // title
            $title = method_exists($entry, 'title') ? $entry->title() : ($entry->title ?? '');
            if (!empty($title)) {
                $translatedTitle = $client->translate($title, 'en', 'zh');
                if ($translatedTitle !== null) {
                    if (method_exists($entry, '_title')) {
                        $entry->_title('[译] ' . $translatedTitle);
                    } else {
                        $entry->title = '[译] ' . $translatedTitle;
                    }
                }
            }

            // content
            $content = method_exists($entry, 'content') ? $entry->content() : ($entry->content ?? '');
            if (!empty($content)) {
                $translatedContent = $client->translate(strip_tags($content), 'en', 'zh');
                if ($translatedContent !== null) {
                    if (method_exists($entry, '_content')) {
                        $entry->_content($translatedContent);
                    } else {
                        $entry->content = $translatedContent;
                    }
                }
            }
        } catch (\Throwable $e) {
            if (class_exists('Minz_Log')) {
                Minz_Log::warning('TMT-Translate: ' . $e->getMessage());
            } else {
                error_log('TMT-Translate: ' . $e->getMessage());
            }
        }
        return $entry;
    }
}

// SDK / fallback client
class TMTClient {
    private string $secretId;
    private string $secretKey;
    private string $region;
    private string $endpoint;
    private string $service = 'tmt';
    private string $version = '2018-03-21';

    public function __construct(string $secretId, string $secretKey, string $region = 'ap-guangzhou', string $endpoint = 'tmt.tencentcloudapi.com') {
        $this->secretId = $secretId;
        $this->secretKey = $secretKey;
        $this->region = $region;
        $this->endpoint = $endpoint;
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    public function translate(string $text, string $source = 'en', string $target = 'zh'): ?string {
        // Try official SDK first
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
                // fall through to builtin implementation
            }
        }

        // Fallback: TC3 signing + curl
        $action = 'TextTranslate';
        $payload = [
            'SourceText' => mb_substr($text, 0, 5000),
            'Source' => $source,
            'Target' => $target,
            'ProjectId' => 0,
        ];
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $host = $this->endpoint;

        $canonicalUri = '/';
        $canonicalQueryString = '';
        $canonicalHeaders = "content-type:application/json\nhost:{$host}\n";
        $signedHeaders = 'content-type;host';
        $hashedRequestPayload = hash('sha256', $jsonPayload);

        $canonicalRequest = implode("\n", [
            'POST',
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            $hashedRequestPayload,
        ]);

        $algorithm = 'TC3-HMAC-SHA256';
        $credentialScope = "{$date}/{$this->service}/tc3_request";
        $hashedCanonicalRequest = hash('sha256', $canonicalRequest);
        $stringToSign = implode("\n", [
            $algorithm,
            (string)$timestamp,
            $credentialScope,
            $hashedCanonicalRequest,
        ]);

        $kDate = hash_hmac('sha256', $date, 'TC3' . $this->secretKey, true);
        $kService = hash_hmac('sha256', $this->service, $kDate, true);
        $kSigning = hash_hmac('sha256', 'tc3_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = sprintf(
            "%s Credential=%s/%s, SignedHeaders=%s, Signature=%s",
            $algorithm,
            $this->secretId,
            $credentialScope,
            $signedHeaders,
            $signature
        );

        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Version: ' . $this->version,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Region: ' . $this->region,
        ];

        $url = 'https://' . $host;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $err) {
            if (class_exists('Minz_Log')) Minz_Log::warning('TMT-Translate HTTP error: ' . $err);
            else error_log('TMT-Translate HTTP error: ' . $err);
            return null;
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            if (class_exists('Minz_Log')) Minz_Log::warning('TMT-Translate invalid JSON response');
            else error_log('TMT-Translate invalid JSON response');
            return null;
        }

        if (isset($data['Response']['TargetText'])) return $data['Response']['TargetText'];
        if (isset($data['TargetText'])) return $data['TargetText'];

        if (isset($data['Response']['Error'])) {
            $errInfo = $data['Response']['Error'];
            $msg = sprintf('TMT API Error: Code=%s Message=%s', $errInfo['Code'] ?? '', $errInfo['Message'] ?? '');
            if (class_exists('Minz_Log')) Minz_Log::warning($msg);
            else error_log($msg);
        }
        return null;
    }
}
