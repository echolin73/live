<?php
/**
 * iTalkBB TV 直播源抓取 -> m3u8 (最高分辨率)
 *
 * 原理(匿名访客流程, 与官网 /live 页面一致, 无需登录):
 *   1) POST api.italkbbtv.com/auth/v1/token   拿访客 token
 *   2) GET  api.italkbbtv.com/classictv/common/v1/roots   动态取根分类(alias=live 的 id)
 *   3) GET  api.italkbbtv.com/classictv/live/v1/categories   动态取分类(取第一个)
 *   4) GET  api.italkbbtv.com/classictv/live/v1/lives   拿频道列表
 *   5) GET  api.italkbbtv.com/playauth/v1/live?series_id=xxx   拿 m3u8 (manifest)
 *   6) 解析 master m3u8, 选 BANDWIDTH 最大的那一路 (即最高分辨率)
 *
 * 用法:
 * 用法(推荐用 url/id 直取, 不依赖频道列表接口, 最稳):
 *   直取 m3u8:      italkbbtv.php?url=https://m.italkbbtv.com/live/6a017e09bdf9f93f62467172
 *   或:             italkbbtv.php?id=6a017e09bdf9f93f62467172
 *   按名搜索:        italkbbtv.php?channel=凤凰资讯
 *   直接给播放器:    italkbbtv.php?id=6a017e09bdf9f93f62467172&action=redirect
 *   网页试播:        italkbbtv.php?id=6a017e09bdf9f93f62467172&action=player
 *   频道列表页:      italkbbtv.php
 *   列表(JSON):      italkbbtv.php?action=list_json
 *   诊断:            italkbbtv.php?action=debug   (排查用, 不暴露完整 token)
 *
 * channel 参数在内置的 23 个预置频道里按名搜索; 若关键字匹配到多个频道,
 * 会返回候选列表让你再选, 也可直接传 24 位 id。
 *
 * 注意:
 *  - iTalkBB 有版权地区限制。若服务器 IP 在限制地区, playauth 会返回
 *    "由于版权限制您所在的地区无法观看该视频"。解决办法二选一:
 *    (a) 把脚本部署到境外(有版权的地区)服务器上;
 *    (b) 设置 HTTP_PROXY 让请求走境外代理出口。
 *    m3u8 链接一般与出口 IP 绑定, 播放器最好也走同一个出口, 否则可能播不了。
 *  - 抓到的 m3u8 带签名会过期, 过期后重新请求本脚本即可。
 *  - device_model / app_version 是抓包时的值, 若将来 token 接口报错,
 *    把它们改成新版即可(脚本里有标注位置)。
 */

// ================= 配置 =================
define('API_BASE', 'https://api.italkbbtv.com');
define('SITE_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0');
define('SITE_REFERER', 'https://www.italkbbtv.com/');
define('HTTP_TIMEOUT', 20);

// 可选: 让脚本的所有对外请求走代理 (http/socks5), 例如 'http://127.0.0.1:7890'
// 适用于服务器在版权限制地区、但有境外代理可用的情况。
// 注意: m3u8 链接一般与出口 IP 绑定, 播放器最好也走同一个出口, 否则可能播不了。
//找个美国代理（http/socks5 都行），填进脚本的 HTTP_PROXY 配置项，比如：
//define('HTTP_PROXY', 'http://127.0.0.1:7890');
//define('HTTP_PROXY', 'socks5h://127.0.0.1:1080');
//用 socks5h:// 比 socks5:// 更好，h 表示 DNS 也走代理，避免 DNS 泄露：
define('HTTP_PROXY', '');

// 根分类 ID 动态获取, 此处仅作兜底(官网从 /classictv/common/v1/roots 取 alias=live 的 id)
define('LIVES_ROOT_ID', '62ac4e2e4beefe535864769d');
// 分类 ID 动态获取, 此处仅作兜底(官网取分类列表第一个)
define('LIVES_CATEGORY_ID', '62ac4e314beefe53586476c2');

// token 接口的固定字段(若将来返回 invalid/报错, 优先更新这两项)
define('DEVICE_MODEL', 'Chrome148');
define('APP_VERSION', '305013');

define('CHANNEL_CACHE_TTL', 3600); // 频道列表缓存 1 小时
// =========================================

// 预置频道: m.italkbbtv.com/live/<id> 里的 24 位 id 即 playauth 接口要的 series_id。
// 直接走 id 换 m3u8, 不依赖经常拿不到数据的三级分类接口, 最稳。
const PRESET_CHANNELS = [
    ['name' => 'CCTV 国际',     'id' => '6a017e09bdf9f93f62467172'],
    ['name' => 'CCTV 娱乐',     'id' => '62cbaf604725b530583c118a'],
    ['name' => 'CCTV 戏曲',     'id' => '6a018984edb269311e00a185'],
    ['name' => '中国电影频道',   'id' => '6a27b9babcd12719750308fd'],
    ['name' => 'CGTN',          'id' => '6a17e7aabcd127197503045d'],
    ['name' => 'CGTN纪录',      'id' => '6a17e7af3368ef058c28b47a'],
    ['name' => '凤凰资讯',       'id' => '62cc97174725b5305843c454'],
    ['name' => '凤凰美洲',       'id' => '62cc96a74725b5305843c0d5'],
    ['name' => '北京国际',       'id' => '6a27b9b63368ef058c28b881'],
    ['name' => '东方国际',       'id' => '6a27b9be820a5849076b0499'],
    ['name' => '浙江国际',       'id' => '62cbb2414725b530583c286f'],
    ['name' => '安徽国际',       'id' => '649832edf2aa61306858c0ef'],
    ['name' => '海峡卫视',       'id' => '6673d2ff8b2ff81ec200d575'],
    ['name' => '厦门卫视',       'id' => '6a17e7b3820a5849076b0009'],
    ['name' => '大湾区卫视',     'id' => '62cbb01d4725b530583c1759'],
    ['name' => '深圳卫视',       'id' => '6673d2fc8b2ff81ec200d573'],
    ['name' => '美南新闻',       'id' => '62cc94444725b5305843ae06'],
    ['name' => '加拿大600新闻',  'id' => '62cb9bbc4725b530583b6b0e'],
    ['name' => '東森美洲新聞',   'id' => '62cc95e04725b5305843ba86'],
    ['name' => '东森美洲卫视',   'id' => '62cc959e4725b5305843b84e'],
    ['name' => '東森中国',       'id' => '62cc93a14725b5305843a8f1'],
    ['name' => '超視美洲',       'id' => '62cc959b4725b5305843b838'],
    ['name' => '中旺电视',       'id' => '62cc979c4725b5305843c853'],
];

function cache_dir(): string {
    $dir = sys_get_temp_dir() . '/italkbbtv-cache';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}
function cache_get(string $key) {
    $f = cache_dir() . '/' . $key . '.json';
    if (!is_file($f)) return null;
    $d = json_decode(@file_get_contents($f), true);
    if (!is_array($d)) return null;
    if (isset($d['_expire_at']) && time() > $d['_expire_at']) { @unlink($f); return null; }
    return $d;
}
function cache_set(string $key, array $data, int $ttl): void {
    $data['_expire_at'] = time() + $ttl;
    @file_put_contents(cache_dir() . '/' . $key . '.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function cache_del(string $key): void { @unlink(cache_dir() . '/' . $key . '.json'); }

function uuidv4(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function http_request(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => SITE_UA,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (defined('HTTP_PROXY') && HTTP_PROXY !== '') {
        curl_setopt($ch, CURLOPT_PROXY, HTTP_PROXY);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $resp === false ? '' : $resp, $err];
}
function base_headers(): array {
    // 尽量模拟真实浏览器, 降低被识别为机器人的概率
    return [
        'Referer: ' . SITE_REFERER,
        'Origin: https://www.italkbbtv.com',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    ];
}

/** 取访客 token, 失败返回 [null, $errmsg] */
function get_token(): array {
    if ($c = cache_get('token')) return [$c['token'], null];

    $deviceId = uuidv4();
    $fields = http_build_query([
        'login_type'  => 'password',
        'grant_type'  => 'tv_login_in',
        'device_id'   => $deviceId,
        'login_name'  => $deviceId . '@web.visitor.italkbb.com',
        'password'    => 'visitor_secret',
        'device_type' => 'web',
        'device_model'=> DEVICE_MODEL,
        'app_version' => APP_VERSION,
    ]);
    [$code, $resp] = http_request('POST', API_BASE . '/auth/v1/token',
        array_merge(base_headers(), ['Content-Type: application/x-www-form-urlencoded']), $fields);
    $data = json_decode($resp, true);
    $token = $data['access']['token'] ?? null;
    $expireMs = $data['access']['expire_time'] ?? 0;
    if (!$token) {
        $msg = $data['message'] ?? ("HTTP $code 获取 token 失败");
        return [null, "拿访客 token 失败: $msg (若持续失败, 检查 DEVICE_MODEL/APP_VERSION 是否需更新)"];
    }
    $ttl = max(60, (int)($expireMs / 1000) - time() - 60);
    cache_set('token', ['token' => $token], $ttl);
    return [$token, null];
}

/** 统一带 token 的 GET, token 失效自动刷新重试一次 */
function api_get(string $path, bool $retry = true): array {
    [$token, $err] = get_token();
    if ($err) return [null, $err];
    [$code, $resp] = http_request('GET', API_BASE . $path,
        array_merge(base_headers(), ["Authorization: Bearer $token"]));
    $data = json_decode($resp, true);
    if (is_array($data) && ($data['code'] ?? 0) == 104404 && $retry) {
        cache_del('token'); // invalid token, 刷新后重试
        return api_get($path, false);
    }
    if (!is_array($data)) return [null, "接口返回非 JSON (HTTP $code)"];
    return [$data, null];
}

/** 取根分类: 找 alias=live 的 root id (官网就是这么解析的) */
function get_live_root_id(): array {
    $q = http_build_query(['hl' => 'zh_CN']);
    [$data, $err] = api_get("/classictv/common/v1/roots?$q");
    if ($err) return [null, $err];
    // 兼容几种可能的 envelope
    $list = $data['roots'] ?? $data['data'] ?? $data['list'] ?? (isset($data[0]) ? $data : null);
    if (!is_array($list) || count($list) === 0) {
        $detail = 'top-level keys: ' . implode(',', array_keys($data));
        if (isset($data['code'])) $detail .= '; code=' . $data['code'];
        if (isset($data['message'])) $detail .= '; message=' . $data['message'];
        return [null, "根分类接口未返回列表($detail)"];
    }
    foreach ($list as $r) {
        if (is_array($r) && strcasecmp((string)($r['alias'] ?? ''), 'live') === 0 && !empty($r['id'])) {
            return [(string)$r['id'], null];
        }
    }
    $id = $list[0]['id'] ?? null; // 没找到 alias=live 就取第一个
    if (!$id) return [null, '根分类数据缺少 id 字段'];
    return [(string)$id, null];
}

/** 取某 root 下的分类列表, 返回第一个分类的 id (官网就是这么取的) */
function get_first_category_id(string $rootId): array {
    $q = http_build_query(['root_id' => $rootId, 'hl' => 'zh_CN']);
    [$data, $err] = api_get("/classictv/live/v1/categories?$q");
    if ($err) return [null, $err];
    // 兼容几种可能的 envelope
    $list = $data['categories'] ?? $data['data'] ?? $data['list'] ?? (isset($data[0]) ? $data : null);
    if (!is_array($list) || count($list) === 0) {
        $detail = 'top-level keys: ' . implode(',', array_keys($data));
        if (isset($data['code'])) $detail .= '; code=' . $data['code'];
        if (isset($data['message'])) $detail .= '; message=' . $data['message'];
        return [null, "分类接口未返回分类列表($detail)"];
    }
    $id = $list[0]['id'] ?? null;
    if (!$id) return [null, '分类数据缺少 id 字段'];
    return [(string)$id, null];
}

/** 三级动态解析: root(alias=live) -> 第一个分类; 失败则逐级回退到硬编码 */
function resolve_live_ids(): array {
    [$rootId, $rootErr] = get_live_root_id();
    if ($rootErr || !$rootId) $rootId = LIVES_ROOT_ID;
    [$catId, $catErr] = get_first_category_id($rootId);
    if ($catErr || !$catId) $catId = LIVES_CATEGORY_ID;
    return [$rootId, $catId, $rootErr, $catErr];
}

function get_channels(): array {
    [$rootId, $catId] = resolve_live_ids();
    $ckey = 'channels_' . md5($rootId . '|' . $catId);
    if ($c = cache_get($ckey)) return [$c['lives'], null];
    $q = http_build_query([
        'root_id' => $rootId, 'category_id' => $catId,
        'page' => 1, 'size' => 100, 'data_version' => '', 'hl' => 'zh_CN',
    ]);
    [$data, $err] = api_get("/classictv/live/v1/lives?$q");
    if ($err) return [null, $err];
    $lives = $data['lives'] ?? [];
    if (!$lives) {
        $detail = "root_id=$rootId, category_id=$catId; top-level keys: " . implode(',', array_keys($data));
        if (isset($data['code'])) $detail .= '; code=' . $data['code'];
        if (isset($data['message'])) $detail .= '; message=' . $data['message'];
        if (isset($data['total'])) $detail .= '; total=' . $data['total'];
        return [null, "频道列表为空($detail)。可用 ?action=debug 看三级解析的诊断"];
    }
    cache_set($ckey, ['lives' => $lives], CHANNEL_CACHE_TTL);
    return [$lives, null];
}

/** 按关键字/ID 找频道: 返回 [唯一频道, null] 或 [null, 候选列表/错误] */
function find_channel(array $lives, string $query): array {
    $query = trim($query);
    foreach ($lives as $ch) {
        if ((string)($ch['id'] ?? '') === $query) return [$ch, null];
    }
    $hits = [];
    foreach ($lives as $ch) {
        $name = (string)($ch['name'] ?? '');
        if ($name !== '' && mb_stripos($name, $query) !== false) $hits[] = $ch;
    }
    if (count($hits) === 1) return [$hits[0], null];
    return [null, $hits];
}

/** 取某频道的 manifest(m3u8 地址) */
function get_manifest(string $seriesId): array {
    $q = http_build_query(['series_id' => $seriesId, 'hl' => 'zh_CN']);
    [$data, $err] = api_get("/playauth/v1/live?$q");
    if ($err) return [null, $err];
    $m = $data['manifest'] ?? $data['data']['manifest'] ?? $data['url'] ?? null;
    if (!$m) return [null, '接口未返回 manifest(可能该频道暂无直播信号): ' . mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 200)];
    return [$m, null];
}

/** 把相对地址解析成绝对地址, 并尽量保留 master 的签名 query */
function resolve_url(string $base, string $ref): string {
    $ref = trim($ref);
    if (preg_match('#^https?://#i', $ref)) return $ref;
    $p = parse_url($base);
    $schemeHost = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($ref, '/') === 0) {
        $abs = $schemeHost . $ref;
    } else {
        $dir = rtrim(dirname($p['path'] ?? '/'), '/');
        $abs = $schemeHost . $dir . '/' . $ref;
    }
    // variant 自身没带 query 时, 把 master 的 query (常为签名) 补上
    if (strpos($abs, '?') === false && !empty($p['query'])) $abs .= '?' . $p['query'];
    return $abs;
}

/**
 * 解析 master m3u8, 选 BANDWIDTH 最大的一路(即最高分辨率)。
 * 返回 [url, 分辨率, 带宽, 全部档位] ; 若不是多档位 playlist, 直接返回 master 本身。
 */
function pick_highest_variant(string $masterUrl): array {
    [$code, $body] = http_request('GET', $masterUrl, base_headers());
    if ($code !== 200 || $body === '' || strpos($body, '#EXTM3U') === false) {
        return [$masterUrl, null, null, []]; // 抓不到就原样返回
    }
    $variants = [];
    $lines = preg_split('/\r?\n/', $body);
    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (stripos($line, '#EXT-X-STREAM-INF:') === 0) {
            $attrs = substr($line, strlen('#EXT-X-STREAM-INF:'));
            $bw = 0; $res = null;
            if (preg_match('/BANDWIDTH=(\d+)/i', $attrs, $m)) $bw = (int)$m[1];
            if (preg_match('/RESOLUTION=(\d+x\d+)/i', $attrs, $m)) $res = $m[1];
            // 下一行非空非注释即为地址
            $uri = null;
            for ($j = $i + 1; $j < count($lines); $j++) {
                $t = trim($lines[$j]);
                if ($t === '') continue;
                if (strpos($t, '#') === 0) continue;
                $uri = $t; break;
            }
            if ($uri) $variants[] = ['bw' => $bw, 'res' => $res, 'url' => resolve_url($masterUrl, $uri)];
        }
    }
    if (!$variants) return [$masterUrl, null, null, []]; // 单档 playlist
    usort($variants, function ($a, $b) {
        if ($a['bw'] !== $b['bw']) return $b['bw'] <=> $a['bw'];
        $area = fn($r) => $r && preg_match('/(\d+)x(\d+)/', $r, $m) ? $m[1] * $m[2] : 0;
        return $area($b['res']) <=> $area($a['res']);
    });
    $best = $variants[0];
    return [$best['url'], $best['res'], $best['bw'], $variants];
}

/** 从直播页 URL 或裸 id 中提取 24 位 series_id, 提不出返回 null */
function extract_series_id(string $input): ?string {
    $input = trim($input);
    if ($input === '') return null;
    if (preg_match('/^[0-9a-f]{24}$/i', $input)) return strtolower($input);
    if (preg_match('#/live/([0-9a-f]{24})#i', $input, $m)) return strtolower($m[1]);
    return null;
}
function preset_lookup_name(string $id): ?string {
    foreach (PRESET_CHANNELS as $ch) {
        if (strcasecmp($ch['id'], $id) === 0) return $ch['name'];
    }
    return null;
}
function preset_search(string $query): array {
    $hits = [];
    foreach (PRESET_CHANNELS as $ch) {
        if ($query !== '' && mb_stripos($ch['name'], $query) !== false) $hits[] = $ch;
    }
    return $hits;
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ================= 主流程 =================
$channelQ = trim($_GET['channel'] ?? '');
$urlQ     = trim($_GET['url'] ?? '');
$idQ      = trim($_GET['id'] ?? '');
$action   = strtolower(trim($_GET['action'] ?? 'json'));

// ---------- 诊断模式: ?action=debug (不暴露完整 token) ----------
if ($action === 'debug') {
    [$token, $tErr] = get_token();
    [$rootId, $catId, $rootErr, $catErr] = resolve_live_ids();
    [$livesDbg, $livesErr] = get_channels();
    json_out(['success' => true, 'debug' => [
        'token' => $tErr ? ['error' => $tErr]
                         : ['ok' => true, 'length' => strlen($token), 'prefix' => substr($token, 0, 12) . '...'],
        'root_id_dynamic' => $rootId,
        'root_error' => $rootErr,
        'category_id_dynamic' => $catId,
        'category_error' => $catErr,
        'lives_error' => $livesErr,
        'lives_count' => $livesDbg ? count($livesDbg) : 0,
        'preset_channels' => count(PRESET_CHANNELS),
        'php_version' => PHP_VERSION,
        'curl_available' => function_exists('curl_init'),
        'mbstring_available' => function_exists('mb_stripos'),
    ]]);
}

if ($action === 'list_json') {
    json_out(['success' => true, 'count' => count(PRESET_CHANNELS), 'channels' => PRESET_CHANNELS,
        'note' => '预置频道表; 传 ?id=<24位id> 或 ?url=<直播页地址> 直接换 m3u8']);
}

// ---------- 解析目标频道: 优先级 url/id > channel名(预置表) > channel名(API列表) ----------
$seriesId = null;
$chName = null;

$fromUrl = $urlQ !== '' ? $urlQ : $idQ;
if ($fromUrl !== '') {
    // 方式一: 直接给直播页 URL 或 24 位 id, 最稳, 不依赖频道列表接口
    $seriesId = extract_series_id($fromUrl);
    if (!$seriesId) json_out(['success' => false, 'error' => "无法从 url/id 中提取频道 ID: $fromUrl"], 400);
    $chName = preset_lookup_name($seriesId);
} elseif ($channelQ !== '') {
    // 方式二: 先在预置表里按名搜
    if (($sid = extract_series_id($channelQ))) {
        $seriesId = $sid;
        $chName = preset_lookup_name($sid);
    } else {
        $hits = preset_search($channelQ);
        if (count($hits) === 1) {
            $seriesId = $hits[0]['id'];
            $chName = $hits[0]['name'];
        } elseif (count($hits) > 1) {
            json_out(['success' => false, 'error' => '匹配到多个频道, 请用更精确的名字或直接传 id',
                'candidates' => $hits], 404);
        }
    }
    // 方式三: 预置表没找到, 回退到 API 频道列表(该接口目前可能不可用)
    if (!$seriesId) {
        [$lives, $err] = get_channels();
        if ($err) json_out(['success' => false, 'error' => $err . '；且预置频道表中未找到“' . $channelQ . '”'], 502);
        [$ch, $fhits] = find_channel($lives, $channelQ);
        if (!$ch) {
            $cands = array_map(fn($c) => ['id' => $c['id'] ?? null, 'name' => $c['name'] ?? null], is_array($fhits) ? $fhits : []);
            json_out(['success' => false,
                'error' => $cands ? '匹配到多个频道, 请用更精确的名字或 id' : "未找到频道: $channelQ",
                'candidates' => $cands], 404);
        }
        $seriesId = (string)$ch['id'];
        $chName = $ch['name'] ?? null;
    }
}

// 无参数: 频道列表页(用预置表, 不依赖 API)
if (!$seriesId) {
    header('Content-Type: text/html; charset=utf-8');
    $self = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>iTalkBB TV 频道列表</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:24px auto;padding:0 16px}a{color:#0b6}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px;font-size:14px}input{width:70%;padding:8px}button{padding:8px 14px}</style></head><body>';
    echo '<h2>iTalkBB TV 频道列表 (' . count(PRESET_CHANNELS) . ')</h2>';
    echo '<form method="get"><input name="url" placeholder="或粘贴 m.italkbbtv.com/live/xxx 直播页地址"><button type="submit">转换</button></form>';
    echo '<table><tr><th>频道</th><th>m3u8(JSON)</th><th>直链(播放器)</th><th>试播</th></tr>';
    foreach (PRESET_CHANNELS as $ch) {
        $id = urlencode($ch['id']);
        $name = htmlspecialchars($ch['name'], ENT_QUOTES);
        echo "<tr><td>$name</td>"
            . "<td><a href=\"$self?id=$id\">JSON</a></td>"
            . "<td><a href=\"$self?id=$id&action=redirect\">播放器直链</a></td>"
            . "<td><a href=\"$self?id=$id&action=player\">试播</a></td></tr>";
    }
    echo '</table><p style="color:#666;font-size:13px">也支持按名搜索, 如 ?channel=凤凰资讯；或直接 ?url=直播页地址</p></body></html>';
    exit;
}

[$manifest, $err] = get_manifest($seriesId);
if ($err) json_out(['success' => false, 'error' => $err, 'channel' => $chName], 502);

[$bestUrl, $res, $bw, $variants] = pick_highest_variant($manifest);

if ($action === 'redirect') {
    header('Location: ' . $bestUrl); // 302, 直接把最高清 m3u8 给播放器
    exit;
}
if ($action === 'player') {
    header('Content-Type: text/html; charset=utf-8');
    $escName = htmlspecialchars((string)($chName ?? $seriesId), ENT_QUOTES);
    $escUrl  = htmlspecialchars($bestUrl, ENT_QUOTES);
    $escRes  = htmlspecialchars((string)($res ?? 'auto'), ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>$escName - 试播</title><script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<style>body{font-family:system-ui,sans-serif;max-width:800px;margin:20px auto;padding:0 16px}video{width:100%;background:#000}</style>
</head><body><h3>$escName <small style="color:#666">($escRes)</small></h3><video id="v" controls playsinline></video>
<script>var src="$escUrl",v=document.getElementById('v');
if(Hls.isSupported()){var h=new Hls();h.loadSource(src);h.attachMedia(v);}else if(v.canPlayType('application/vnd.apple.mpegurl')){v.src=src;}</script>
<p style="color:#666;font-size:13px">m3u8 带签名会过期, 过期刷新本页即可。</p></body></html>
HTML;
    exit;
}

json_out([
    'success'  => true,
    'channel'  => ['id' => $seriesId, 'name' => $chName],
    'm3u8'     => $bestUrl,          // 最高分辨率那一路, 可直接填进播放器
    'resolution' => $res,
    'bandwidth'  => $bw,
    'master_playlist' => $manifest,  // 原始多档位 master
    'variants' => array_map(fn($v) => ['resolution' => $v['res'], 'bandwidth' => $v['bw'], 'url' => $v['url']], $variants),
    'expires_note' => 'm3u8 带签名会过期, 过期后重新请求本脚本即可',
]);
