<?php
require_once("auth.php");
require_once("flow_db.php");

function flow_as_name_overrides() {
    static $overrides = null;
    if ($overrides !== null) {
        return $overrides;
    }

    $overrides = array(
        264996 => 'SERVLINK TELECOM LTDA - ME,BR',
    );

    $candidateFiles = array(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'asn_overrides.tsv',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'asn_overrides.txt',
        '/data/asstats/conf/asn_overrides.tsv',
        '/data/asstats/conf/asn_overrides.txt',
    );

    foreach ($candidateFiles as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\t+|;|=/', $line, 2);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            $asn = (int)trim((string)$parts[0]);
            $name = trim((string)$parts[1]);
            if ($asn > 0 && $name !== '') {
                $overrides[$asn] = $name;
            }
        }
    }

    return $overrides;
}

function flow_cache_enabled() {
    $flag = strtolower(trim((string)flow_env_setting('ASSTATS_UI_CACHE', '1')));
    return !in_array($flag, array('0', 'off', 'false', 'no'), true);
}

function flow_cache_use_apcu() {
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }
    $flag = strtolower(trim((string)flow_env_setting('ASSTATS_UI_CACHE_APCU', '1')));
    if (in_array($flag, array('0', 'off', 'false', 'no'), true)) {
        $enabled = false;
        return $enabled;
    }
    $enabled = function_exists('apcu_fetch') && function_exists('apcu_store') && ini_get('apc.enabled');
    return $enabled;
}

function flow_cache_use_disk_fallback() {
    $flag = strtolower(trim((string)flow_env_setting('ASSTATS_UI_CACHE_DISK_FALLBACK', '1')));
    return !in_array($flag, array('0', 'off', 'false', 'no'), true);
}

function flow_cache_namespace_dir($namespace) {
    $namespace = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$namespace);
    $dir = flow_runtime_dir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $namespace;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function flow_cache_key($payload) {
    return sha1(serialize($payload));
}

function flow_cache_apcu_key($namespace, $payload) {
    return 'flow:' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$namespace) . ':' . flow_cache_key($payload);
}

function flow_cache_get($namespace, $payload, $ttl, &$hit = null) {
    static $requestCache = array();
    $hit = false;
    if (!flow_cache_enabled()) {
        return null;
    }
    $ttl = max(1, (int)$ttl);
    $localKey = flow_cache_apcu_key($namespace, $payload);

    if (isset($requestCache[$localKey])) {
        $entry = $requestCache[$localKey];
        if (is_array($entry) && isset($entry['stored_at']) && array_key_exists('value', $entry)) {
            if (((int)$entry['stored_at'] + $ttl) >= time()) {
                $hit = true;
                return $entry['value'];
            }
        }
    }

    if (flow_cache_use_apcu()) {
        $ok = false;
        $entry = apcu_fetch($localKey, $ok);
        if ($ok && is_array($entry) && isset($entry['stored_at']) && array_key_exists('value', $entry)) {
            if (((int)$entry['stored_at'] + $ttl) >= time()) {
                $requestCache[$localKey] = $entry;
                $hit = true;
                return $entry['value'];
            }
        }
    }

    if (!flow_cache_use_disk_fallback()) {
        return null;
    }
    $file = flow_cache_namespace_dir($namespace) . DIRECTORY_SEPARATOR . flow_cache_key($payload) . '.cache';
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $entry = @unserialize($raw);
    if (!is_array($entry) || !isset($entry['stored_at']) || !array_key_exists('value', $entry)) {
        return null;
    }
    if (((int)$entry['stored_at'] + $ttl) < time()) {
        return null;
    }
    $requestCache[$localKey] = $entry;
    if (flow_cache_use_apcu()) {
        apcu_store($localKey, $entry, $ttl);
    }
    $hit = true;
    return $entry['value'];
}

function flow_cache_set($namespace, $payload, $value) {
    static $requestCache = array();
    if (!flow_cache_enabled()) {
        return;
    }
    $storedAt = time();
    $entry = array(
        'stored_at' => $storedAt,
        'value' => $value,
    );
    $localKey = flow_cache_apcu_key($namespace, $payload);
    $requestCache[$localKey] = $entry;

    if (flow_cache_use_apcu()) {
        $defaultTtl = (int)flow_env_setting('ASSTATS_UI_CACHE_APCU_TTL', '120');
        if ($defaultTtl <= 0) {
            $defaultTtl = 120;
        }
        apcu_store($localKey, $entry, $defaultTtl);
    }

    if (!flow_cache_use_disk_fallback()) {
        return;
    }
    $file = flow_cache_namespace_dir($namespace) . DIRECTORY_SEPARATOR . flow_cache_key($payload) . '.cache';
    @file_put_contents($file, serialize($entry), LOCK_EX);
}

function flow_bgphe_cache_path() {
    $candidates = array(
        '/data/asstats/asstats/cache/asn_bgphe_cache.json',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'asstats' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'asn_bgphe_cache.json',
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow_asn_bgphe_cache.json',
    );

    foreach ($candidates as $path) {
        $dir = dirname($path);
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            return $path;
        }
    }

    return $candidates[count($candidates) - 1];
}

function flow_bgphe_cache_load() {
    static $loaded = false;
    static $cache = array();

    if ($loaded) {
        return $cache;
    }
    $loaded = true;

    $path = flow_bgphe_cache_path();
    if (!is_file($path) || !is_readable($path)) {
        return $cache;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $cache;
    }

    $decoded = @json_decode($raw, true);
    if (!is_array($decoded)) {
        return $cache;
    }

    foreach ($decoded as $asn => $item) {
        $asnInt = (int)$asn;
        if ($asnInt <= 0 || !is_array($item)) {
            continue;
        }
        $name = isset($item['name']) ? trim((string)$item['name']) : '';
        $updated = isset($item['updated']) ? (int)$item['updated'] : 0;
        if ($name !== '') {
            $cache[$asnInt] = array('name' => $name, 'updated' => $updated);
        }
    }

    return $cache;
}

function flow_bgphe_cache_save($cache) {
    if (!is_array($cache)) {
        return;
    }
    $path = flow_bgphe_cache_path();
    @file_put_contents($path, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function flow_bgphe_extract_as_name($html, $asn) {
    $asn = (int)$asn;
    if (!is_string($html) || trim($html) === '' || $asn <= 0) {
        return '';
    }

    $patterns = array(
        '/<title>\s*AS' . $asn . '\s+([^<]+?)\s*-\s*bgp\.he/iu',
        '/<h1[^>]*>\s*AS' . $asn . '\s+([^<]+?)\s*<\/h1>/iu',
    );
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $match)) {
            $name = trim(html_entity_decode((string)$match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name !== '') {
                return $name;
            }
        }
    }

    return '';
}

function flow_fetch_as_name_from_bgphe($asn) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return '';
    }
    static $networkDisabled = false;

    $cache = flow_bgphe_cache_load();
    $ttl = 7 * 24 * 3600;
    if (isset($cache[$asn])) {
        $cachedName = trim((string)$cache[$asn]['name']);
        $cachedAt = isset($cache[$asn]['updated']) ? (int)$cache[$asn]['updated'] : 0;
        if ($cachedName !== '' && $cachedAt > (time() - $ttl)) {
            return $cachedName;
        }
    }
    $allowLiveLookup = strtolower(trim((string)flow_env_setting('ASSTATS_BGPHE_LIVE_LOOKUP', '0')));
    if (in_array($allowLiveLookup, array('0', 'off', 'false', 'no'), true)) {
        return isset($cache[$asn]['name']) ? trim((string)$cache[$asn]['name']) : '';
    }
    if ($networkDisabled) {
        return isset($cache[$asn]['name']) ? trim((string)$cache[$asn]['name']) : '';
    }

    $url = 'https://bgp.he.net/AS' . $asn;
    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 2,
            'header' => "User-Agent: Flow-Observatory/1.0\r\nAccept: text/html\r\n",
        ),
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
        ),
    ));
    $html = @file_get_contents($url, false, $context);
    if (!is_string($html) || trim($html) === '') {
        $networkDisabled = true;
        return isset($cache[$asn]['name']) ? trim((string)$cache[$asn]['name']) : '';
    }

    $name = flow_bgphe_extract_as_name($html, $asn);
    if ($name === '') {
        return isset($cache[$asn]['name']) ? trim((string)$cache[$asn]['name']) : '';
    }

    $cache[$asn] = array('name' => $name, 'updated' => time());
    flow_bgphe_cache_save($cache);
    return $name;
}

function flow_enrich_as_info($as, $asinfo) {
    $as = (int)$as;
    if (!is_array($asinfo)) {
        $asinfo = array();
    }
    $descr = isset($asinfo['descr']) ? trim((string)$asinfo['descr']) : '';
    $overrides = flow_as_name_overrides();
    if ($as > 0 && isset($overrides[$as]) && trim((string)$overrides[$as]) !== '') {
        $descr = trim((string)$overrides[$as]);
    }
    if ($as > 0 && !isset($overrides[$as])) {
        $bgpheName = flow_fetch_as_name_from_bgphe($as);
        if ($bgpheName !== '') {
            $descr = $bgpheName;
        }
    }
    if ($descr === '') {
        $descr = 'AS' . $as;
    }
    $asinfo['descr'] = $descr;
    return $asinfo;
}

function flow_active_class($current, $expected) {
    return $current === $expected ? 'is-active' : '';
}

function flow_hidden_inputs($params) {
    $html = '';
    foreach ($params as $name => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">' . "\n";
    }
    return $html;
}

function flow_top_links() {
    global $top_intervals;
    return isset($top_intervals) && is_array($top_intervals) ? $top_intervals : array(
        array('hours' => 1, 'label' => '1 hora'),
        array('hours' => 4, 'label' => '4 horas'),
        array('hours' => 6, 'label' => '6 horas'),
        array('hours' => 24, 'label' => '24 horas'),
        array('hours' => 72, 'label' => '72 horas'),
        array('hours' => 168, 'label' => '7 dias'),
    );
}

function flow_render_shell_start($title, $active = 'overview') {
    flow_auth_require_login();

    $brand = 'CECTI Flow Observatory';
    $title = htmlspecialchars($title);
    $currentUser = flow_auth_current_user();
    $username = htmlspecialchars($currentUser ? $currentUser['username'] : 'guest');
    $userRole = htmlspecialchars($currentUser ? strtoupper($currentUser['role']) : 'GUEST');
    $activeDashboard = flow_active_class($active, 'dashboard');
    $activeOverview = flow_active_class($active, 'overview');
    $activeDdos = flow_active_class($active, 'ddos');
    $activeNoc = flow_active_class($active, 'noc');
    $activeHistory = flow_active_class($active, 'history');
    $activeIp = flow_active_class($active, 'ipsearch');
    $activeIx = flow_active_class($active, 'ix');
    $activeLinks = flow_active_class($active, 'links');
    $activeConfig = flow_active_class($active, 'config');
    $configLink = flow_auth_has_role(array('master', 'admin')) ? '<a class="' . $activeConfig . '" href="config.php">Config</a>' : '';

    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Refresh" content="300">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="application-name" content="CECTI Flow Observatory">
  <meta name="description" content="Plataforma de observabilidade, inteligencia de trafego e analise operacional da CECTI.">
  <meta name="theme-color" content="#07111f">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>{$title}</title>
  <link rel="icon" href="favicon.svg" type="image/svg+xml">
  <link rel="shortcut icon" href="favicon.svg" type="image/svg+xml">
  <link rel="manifest" href="site.webmanifest">
  <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="plugins/font-awesome/font-awesome.min.css">
  <link rel="stylesheet" href="plugins/ionicons/ionicons.min.css">
  <link rel="stylesheet" href="css/custom.css">
</head>
<body class="flow-body" data-theme="dark">
  <div class="flow-shell">
    <header class="flow-topbar">
      <div class="flow-brand">
        <span class="flow-brand-mark">FLOW</span>
        <div class="flow-brand-copy">
          <strong>{$brand}</strong>
          <span>Traffic Intelligence Console</span>
        </div>
      </div>
      <nav class="flow-nav">
        <a class="{$activeDashboard}" href="dashboard.php">Dashboard</a>
        <a class="{$activeOverview}" href="index.php">Radar AS</a>
        <a class="{$activeDdos}" href="ddos.php">DDoS</a>
        <a class="{$activeNoc}" href="noc.php">NOC</a>
        <a class="{$activeHistory}" href="history.php">ASN Explorer</a>
        <a class="{$activeIp}" href="ipsearch.php">IP Lens</a>
        <a class="{$activeIx}" href="ix.php">IX Analytics</a>
        <a class="{$activeLinks}" href="linkusage.php">Link Flow</a>
        {$configLink}
      </nav>
      <div class="flow-userbar">
        <button class="flow-theme-toggle" type="button" id="flowThemeToggle" aria-label="Alternar tema">
          <i class="fa fa-moon-o" aria-hidden="true"></i>
          <span id="flowThemeToggleLabel">Escuro</span>
        </button>
        <span class="flow-user-pill">{$userRole}</span>
        <span class="flow-user-name">{$username}</span>
        <a class="flow-user-link" href="logout.php">Sair</a>
      </div>
    </header>
    <main class="flow-main">
HTML;
}

function flow_render_shell_end() {
    echo <<<HTML
    </main>
    <footer class="flow-footer">
      <div class="flow-footer-copy">
        <strong>CECTI Flow Observatory</strong>
        <span>personalizado por CECTI</span>
      </div>
      <div class="flow-footer-copy">
        <span>Atualização automática a cada 5 minutos</span>
      </div>
    </footer>
  </div>
  <script>
    (function() {
      var storageKey = 'flow-theme';
      var body = document.body;
      var toggle = document.getElementById('flowThemeToggle');
      var toggleLabel = document.getElementById('flowThemeToggleLabel');
      var toggleIcon = toggle ? toggle.querySelector('i') : null;

      function applyTheme(theme) {
        body.setAttribute('data-theme', theme);
        if (!toggleLabel || !toggleIcon) {
          return;
        }
        if (theme === 'light') {
          toggleLabel.textContent = 'Claro';
          toggleIcon.className = 'fa fa-sun-o';
        } else {
          toggleLabel.textContent = 'Escuro';
          toggleIcon.className = 'fa fa-moon-o';
        }
      }

      var savedTheme = null;
      try {
        savedTheme = window.localStorage.getItem(storageKey);
      } catch (error) {
        savedTheme = null;
      }
      applyTheme(savedTheme === 'light' ? 'light' : 'dark');

      if (toggle) {
        toggle.addEventListener('click', function() {
          var nextTheme = body.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
          applyTheme(nextTheme);
          try {
            window.localStorage.setItem(storageKey, nextTheme);
          } catch (error) {
          }
        });
      }
    })();
  </script>
  <script src="plugins/jQuery/jquery-2.2.3.min.js"></script>
  <script src="bootstrap/js/bootstrap.min.js"></script>
</body>
</html>
HTML;
}

function flow_format_bits($bits) {
    $bits = (float)$bits;
    if ($bits >= 1000000000000) {
        return sprintf('%.2f Tb/s', $bits / 1000000000000);
    } elseif ($bits >= 1000000000) {
        return sprintf('%.2f Gb/s', $bits / 1000000000);
    } elseif ($bits >= 1000000) {
        return sprintf('%.2f Mb/s', $bits / 1000000);
    } elseif ($bits >= 1000) {
        return sprintf('%.2f Kb/s', $bits / 1000);
    }
    return sprintf('%.0f b/s', $bits);
}

function flow_events_db_path() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'asstats' . DIRECTORY_SEPARATOR . 'flow_events.db';
}

function flow_build_as_drill_url($as, $hours, $selectedLinks = array()) {
    $params = array(
        'as' => (int)$as,
        'numhours' => max(1, (int)$hours),
    );

    foreach ($selectedLinks as $tag) {
        $params['link_' . $tag] = 'on';
    }

    return 'asdrill.php?' . http_build_query($params);
}

function flow_resolve_selected_links($selectedLinks) {
    if (!empty($selectedLinks)) {
        return $selectedLinks;
    }

    $resolved = array();
    foreach (getknownlinks() as $link) {
        if (isset($link['tag'])) {
            $resolved[] = $link['tag'];
        }
    }
    return $resolved;
}

function flow_fetch_rrd_graph_stats($as, $ipversion, $start, $end, $peerusage, $selectedLinks = array()) {
    global $rrdtool;

    $rrdfile = getRRDFileForAS($as, $peerusage);
    if (!is_file($rrdfile)) {
        return null;
    }

    $links = flow_resolve_selected_links($selectedLinks);
    if (empty($links)) {
        return null;
    }

    $rrdtoolBin = isset($rrdtool) && $rrdtool !== '' ? $rrdtool : 'rrdtool';
    $command = escapeshellcmd($rrdtoolBin)
        . ' fetch ' . escapeshellarg($rrdfile)
        . ' AVERAGE --start ' . (int)$start
        . ' --end ' . (int)$end . ' 2>/dev/null';

    $output = shell_exec($command);
    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($output));
    if (count($lines) < 2) {
        return null;
    }

    $header = preg_split('/\s+/', trim(array_shift($lines)));
    if (empty($header)) {
        return null;
    }

    $prefix = ($ipversion == 6) ? 'v6_' : '';
    $wanted = array();
    foreach ($links as $tag) {
        $wanted[$tag . '_' . $prefix . 'in'] = 'in';
        $wanted[$tag . '_' . $prefix . 'out'] = 'out';
    }

    $indexes = array();
    foreach ($header as $index => $name) {
        if (isset($wanted[$name])) {
            $indexes[$index] = $wanted[$name];
        }
    }

    if (empty($indexes)) {
        return null;
    }

    $stats = array(
        'in' => array('min' => null, 'max' => null, 'current' => null),
        'out' => array('min' => null, 'max' => null, 'current' => null),
    );

    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }

        list(, $valuesRaw) = explode(':', $line, 2);
        $values = preg_split('/\s+/', trim($valuesRaw));
        if (empty($values)) {
            continue;
        }

        $sum = array('in' => 0.0, 'out' => 0.0);
        $seen = array('in' => false, 'out' => false);

        foreach ($indexes as $index => $direction) {
            if (!isset($values[$index])) {
                continue;
            }
            $value = trim($values[$index]);
            if ($value === '-nan' || $value === 'nan' || $value === '') {
                continue;
            }
            $number = (float)$value * 8;
            $sum[$direction] += $number;
            $seen[$direction] = true;
        }

        foreach (array('in', 'out') as $direction) {
            if (!$seen[$direction]) {
                continue;
            }
            if ($stats[$direction]['min'] === null || $sum[$direction] < $stats[$direction]['min']) {
                $stats[$direction]['min'] = $sum[$direction];
            }
            if ($stats[$direction]['max'] === null || $sum[$direction] > $stats[$direction]['max']) {
                $stats[$direction]['max'] = $sum[$direction];
            }
            $stats[$direction]['current'] = $sum[$direction];
        }
    }

    return $stats;
}

function flow_render_graph_stats($stats) {
    if (!is_array($stats)) {
        return '';
    }

    $html = '<div class="flow-graph-stats">';
    foreach (array('in' => array('label' => 'Entrada', 'badge' => 'IN', 'icon' => 'fa-arrow-down'), 'out' => array('label' => 'Saida', 'badge' => 'OUT', 'icon' => 'fa-arrow-up')) as $direction => $meta) {
        if (!isset($stats[$direction]) || $stats[$direction]['current'] === null) {
            continue;
        }
        $html .= '<div class="flow-graph-stat-group flow-graph-stat-group-' . htmlspecialchars($direction) . '">';
        $html .= '<div class="flow-graph-stat-head">';
        $html .= '<span class="flow-graph-stat-badge"><i class="fa ' . htmlspecialchars($meta['icon']) . '"></i>' . htmlspecialchars($meta['badge']) . '</span>';
        $html .= '<div class="flow-graph-stat-title">';
        $html .= '<strong>' . htmlspecialchars($meta['label']) . '</strong>';
        $html .= '<span>telemetria da janela atual</span>';
        $html .= '</div>';
        $html .= '<div class="flow-graph-stat-current">';
        $html .= '<small>Atual</small>';
        $html .= '<strong>' . htmlspecialchars(flow_format_bits($stats[$direction]['current'])) . '</strong>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="flow-graph-stat-metrics">';
        $html .= '<div class="flow-graph-stat-kpi"><small>Minimo</small><strong>' . htmlspecialchars(flow_format_bits($stats[$direction]['min'])) . '</strong></div>';
        $html .= '<div class="flow-graph-stat-kpi"><small>Pico</small><strong>' . htmlspecialchars(flow_format_bits($stats[$direction]['max'])) . '</strong></div>';
        $html .= '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function flow_knownlinks_index() {
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = array();
    if (!function_exists('getknownlinks')) {
        return $index;
    }
    $knownlinks = getknownlinks();
    if (!is_array($knownlinks)) {
        return $index;
    }
    foreach ($knownlinks as $link) {
        if (!is_array($link) || !isset($link['tag'])) {
            continue;
        }
        $index[(string)$link['tag']] = $link;
    }
    return $index;
}

function flow_link_sampling_factor($linkTag) {
    $index = flow_knownlinks_index();
    if (!isset($index[(string)$linkTag]) || !is_array($index[(string)$linkTag])) {
        return 1.0;
    }
    $link = $index[(string)$linkTag];
    $candidateKeys = array('sampling', 'samplingrate', 'linksamplingrate', 'sample');
    foreach ($candidateKeys as $key) {
        if (!isset($link[$key])) {
            continue;
        }
        $value = (float)$link[$key];
        if ($value > 0) {
            return $value;
        }
    }
    return 1.0;
}

function flow_link_palette_colors() {
    return array(
        'A6CEE3', '1F78B4', 'B2DF8A', '33A02C', 'FB9A99',
        'E31A1C', 'FDBF6F', 'FF7F00', 'CAB2D6', '6A3D9A',
    );
}

function flow_fetch_link_flow_stats($linkTag, $ipversion, $hours) {
    static $memo = array();
    $memoKey = (string)$linkTag . '|' . (int)$ipversion . '|' . (int)$hours;
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }

    $cacheHit = false;
    $cachedValue = flow_cache_get(
        'link_flow_stats',
        array(
            'link' => (string)$linkTag,
            'ip' => (int)$ipversion,
            'hours' => (int)$hours,
            'backend' => flow_events_backend(),
        ),
        20,
        $cacheHit
    );
    if ($cacheHit) {
        $memo[$memoKey] = $cachedValue;
        return $cachedValue;
    }

    if (!flow_events_available()) {
        $memo[$memoKey] = null;
        return null;
    }

    $dbError = null;
    $db = flow_events_open_connection($dbError);
    if (!$db) {
        $memo[$memoKey] = null;
        return null;
    }

    $start = time() - ((int)$hours * 3600);
    $samplingFactor = flow_link_sampling_factor($linkTag);
    $asnExpression = "CASE WHEN direction = 'out' THEN dst_asn ELSE src_asn END";

    $topStmt = @$db->prepare(
        "SELECT {$asnExpression} AS asn, SUM(bytes) AS total_bytes
         FROM flow_events
         WHERE minute_ts >= :start
           AND link_tag = :link_tag
           AND ip_version = :ip_version
         GROUP BY {$asnExpression}
         HAVING {$asnExpression} > 0
         ORDER BY total_bytes DESC
         LIMIT 10"
    );
    if (!$topStmt) {
        $db->close();
        $memo[$memoKey] = null;
        return null;
    }
    $topStmt->bindValue(':start', $start, SQLITE3_INTEGER);
    $topStmt->bindValue(':link_tag', (string)$linkTag, SQLITE3_TEXT);
    $topStmt->bindValue(':ip_version', (int)$ipversion, SQLITE3_INTEGER);
    $topResult = @$topStmt->execute();
    if ($topResult === false) {
        $db->close();
        $memo[$memoKey] = null;
        return null;
    }

    $topAsns = array();
    while ($row = $topResult->fetchArray(SQLITE3_ASSOC)) {
        $asn = isset($row['asn']) ? (int)$row['asn'] : 0;
        if ($asn > 0) {
            $topAsns[] = $asn;
        }
    }
    if (empty($topAsns)) {
        $db->close();
        flow_cache_set('link_flow_stats', array('link' => (string)$linkTag, 'ip' => (int)$ipversion, 'hours' => (int)$hours, 'backend' => flow_events_backend()), null);
        $memo[$memoKey] = null;
        return null;
    }

    $asnList = implode(',', array_map('intval', $topAsns));
    $stmt = @$db->prepare(
        "SELECT minute_ts, direction, {$asnExpression} AS asn, SUM(bytes) AS total_bytes
         FROM flow_events
         WHERE minute_ts >= :start
           AND link_tag = :link_tag
           AND ip_version = :ip_version
           AND {$asnExpression} IN ({$asnList})
         GROUP BY minute_ts, direction, {$asnExpression}
         ORDER BY minute_ts ASC"
    );
    if (!$stmt) {
        $db->close();
        $memo[$memoKey] = null;
        return null;
    }

    $stmt->bindValue(':start', $start, SQLITE3_INTEGER);
    $stmt->bindValue(':link_tag', (string)$linkTag, SQLITE3_TEXT);
    $stmt->bindValue(':ip_version', (int)$ipversion, SQLITE3_INTEGER);
    $result = @$stmt->execute();
    if ($result === false) {
        $db->close();
        $memo[$memoKey] = null;
        return null;
    }

    $timeline = array();
    $asnTotals = array();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $minute = (int)$row['minute_ts'];
        if (!isset($timeline[$minute])) {
            $timeline[$minute] = array('in' => 0.0, 'out' => 0.0);
        }
        $direction = strtolower((string)$row['direction']) === 'out' ? 'out' : 'in';
        $scaledBytes = ((float)$row['total_bytes']) * $samplingFactor;
        $timeline[$minute][$direction] += ($scaledBytes * 8.0) / 60.0;

        $asn = isset($row['asn']) ? (int)$row['asn'] : 0;
        if ($asn > 0) {
            if (!isset($asnTotals[$asn])) {
                $asnTotals[$asn] = 0.0;
            }
            $asnTotals[$asn] += $scaledBytes;
        }
    }
    $db->close();

    if (empty($timeline)) {
        flow_cache_set('link_flow_stats', array('link' => (string)$linkTag, 'ip' => (int)$ipversion, 'hours' => (int)$hours, 'backend' => flow_events_backend()), null);
        $memo[$memoKey] = null;
        return null;
    }

    $stats = array(
        'in' => array('min' => null, 'max' => null, 'current' => null),
        'out' => array('min' => null, 'max' => null, 'current' => null),
        'legend' => array(),
    );

    foreach ($timeline as $point) {
        foreach (array('in', 'out') as $direction) {
            $value = (float)$point[$direction];
            if ($stats[$direction]['min'] === null || $value < $stats[$direction]['min']) {
                $stats[$direction]['min'] = $value;
            }
            if ($stats[$direction]['max'] === null || $value > $stats[$direction]['max']) {
                $stats[$direction]['max'] = $value;
            }
            $stats[$direction]['current'] = $value;
        }
    }

    if (!empty($asnTotals)) {
        arsort($asnTotals, SORT_NUMERIC);
        $palette = flow_link_palette_colors();
        $colorIndex = 0;
        foreach ($asnTotals as $asn => $totalBytes) {
            $asInfo = flow_enrich_as_info((int)$asn, function_exists('getASInfo') ? getASInfo((int)$asn) : array());
            $stats['legend'][] = array(
                'asn' => (int)$asn,
                'label' => isset($asInfo['descr']) ? (string)$asInfo['descr'] : ('AS' . (int)$asn),
                'bytes' => (float)$totalBytes,
                'color' => '#' . $palette[$colorIndex % count($palette)],
            );
            $colorIndex++;
            if ($colorIndex >= 10) {
                break;
            }
        }
    }

    $cachePayload = array(
        'link' => (string)$linkTag,
        'ip' => (int)$ipversion,
        'hours' => (int)$hours,
        'backend' => flow_events_backend(),
    );
    flow_cache_set('link_flow_stats', $cachePayload, $stats);
    $memo[$memoKey] = $stats;
    return $stats;
}

function flow_render_hero($eyebrow, $title, $subtitle, $stats = array()) {
    $html = '<section class="flow-hero">';
    $html .= '<div class="flow-hero-copy">';
    $html .= '<span class="flow-eyebrow">' . htmlspecialchars($eyebrow) . '</span>';
    $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
    $html .= '<p>' . htmlspecialchars($subtitle) . '</p>';
    $html .= '</div>';

    if (!empty($stats)) {
        $html .= '<div class="flow-hero-stats">';
        foreach ($stats as $stat) {
            $label = htmlspecialchars($stat['label']);
            $value = htmlspecialchars($stat['value']);
            $html .= '<div class="flow-stat-card">';
            $html .= '<span>' . $label . '</span>';
            $html .= '<strong>' . $value . '</strong>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }

    $html .= '</section>';
    return $html;
}

function flow_render_panel($title, $body, $icon = 'fa-circle-o', $classes = '') {
    $title = htmlspecialchars($title);
    $icon = htmlspecialchars($icon);
    return '<section class="flow-panel ' . htmlspecialchars($classes) . '">'
        . '<header class="flow-panel-head"><i class="fa ' . $icon . '"></i><span>' . $title . '</span></header>'
        . '<div class="flow-panel-body">' . $body . '</div>'
        . '</section>';
}

function flow_render_filter_form($hours, $ntop, $selectedLinks, $action = 'index.php', $extra = array()) {
    $options = flow_top_links();
    $hasCurrent = false;
    foreach ($options as $option) {
        if ((int)$option['hours'] === (int)$hours) {
            $hasCurrent = true;
            break;
        }
    }
    if (!$hasCurrent && (int)$hours > 0) {
        $options[] = array('hours' => (int)$hours, 'label' => (int)$hours . ' horas');
    }
    $html = '<form method="get" action="' . htmlspecialchars($action) . '" class="flow-form-stack">';
    $html .= flow_hidden_inputs($extra);
    foreach ($selectedLinks as $tag) {
        $html .= '<input type="hidden" name="link_' . htmlspecialchars($tag) . '" value="on">';
    }
    $html .= '<label>Top de AS</label>';
    $html .= '<input class="flow-input" type="number" min="1" max="200" name="n" value="' . htmlspecialchars($ntop) . '">';
    $html .= '<label>Janela de análise</label>';
    $html .= '<select class="flow-input" name="numhours">';
    foreach ($options as $option) {
        $selected = (int)$option['hours'] === (int)$hours ? ' selected' : '';
        $html .= '<option value="' . (int)$option['hours'] . '"' . $selected . '>' . htmlspecialchars($option['label']) . '</option>';
    }
    $html .= '</select>';
    $html .= '<button class="flow-button" type="submit">Atualizar painel</button>';
    $html .= '</form>';
    return $html;
}

function flow_render_search_form($name, $value, $placeholder, $action = '', $extra = array(), $button = 'Buscar') {
    $html = '<form method="get" action="' . htmlspecialchars($action) . '" class="flow-form-stack">';
    $html .= flow_hidden_inputs($extra);
    $html .= '<label>' . htmlspecialchars($placeholder) . '</label>';
    $html .= '<div class="flow-inline-form">';
    $html .= '<input class="flow-input" type="text" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '" placeholder="' . htmlspecialchars($placeholder) . '">';
    $html .= '<button class="flow-button" type="submit">' . htmlspecialchars($button) . '</button>';
    $html .= '</div>';
    $html .= '</form>';
    return $html;
}

function flow_render_legend_form($knownlinks, $selectedLinks, $hours, $ntop, $action = '', $extra = array()) {
    $html = '<form method="get" action="' . htmlspecialchars($action) . '" class="flow-form-stack">';
    $html .= flow_hidden_inputs(array_merge($extra, array('numhours' => $hours, 'n' => $ntop)));
    $html .= '<div class="flow-legend-grid">';

    foreach ($knownlinks as $link) {
        $tag = 'link_' . $link['tag'];
        $checked = in_array($link['tag'], $selectedLinks, true) ? ' checked' : '';
        $color = htmlspecialchars('#' . $link['color']);
        $label = htmlspecialchars($link['descr']);
        $html .= '<label class="flow-legend-item">';
        $html .= '<span class="flow-legend-swatch" style="background:' . $color . '"></span>';
        $html .= '<span class="flow-legend-label">' . $label . '</span>';
        $html .= '<input type="checkbox" name="' . htmlspecialchars($tag) . '" value="on"' . $checked . '>';
        $html .= '</label>';
    }

    $html .= '</div>';
    $html .= '<button class="flow-button flow-button-ghost" type="submit">Aplicar filtros</button>';
    $html .= '</form>';
    return $html;
}

function flow_render_as_row($rank, $as, $asinfo, $nbytes, $start, $end, $peerusage, $selectedLinks, $showv6) {
    global $customlinks;
    $asinfo = flow_enrich_as_info($as, $asinfo);

    $flag = '';
    if (isset($asinfo['country'])) {
        $flagfile = 'flags/' . strtolower($asinfo['country']) . '.gif';
        if (file_exists($flagfile)) {
            $is = getimagesize($flagfile);
            $flag = '<img src="' . htmlspecialchars($flagfile) . '" ' . $is[3] . ' alt="">';
        }
    }

    $in4 = isset($nbytes[0]) ? $nbytes[0] : 0;
    $out4 = isset($nbytes[1]) ? $nbytes[1] : 0;
    $in6 = isset($nbytes[2]) ? $nbytes[2] : 0;
    $out6 = isset($nbytes[3]) ? $nbytes[3] : 0;

    $hours = max(1, (int)round(($end - $start) / 3600));
    $series4 = flow_fetch_as_minute_series((int)$as, 4, $hours, $selectedLinks);
    $series6 = $showv6 ? flow_fetch_as_minute_series((int)$as, 6, $hours, $selectedLinks) : array();
    $graph4 = flow_render_link_svg_chart($series4, 'as-' . (int)$as . '-v4-' . (int)$rank, 'AS' . (int)$as . ' - IPv4');
    $graph6 = $showv6 ? flow_render_link_svg_chart($series6, 'as-' . (int)$as . '-v6-' . (int)$rank, 'AS' . (int)$as . ' - IPv6') : '';
    $graph4Stats = flow_stats_from_minute_series($series4);
    $graph6Stats = $showv6 ? flow_stats_from_minute_series($series6) : null;
    $drillUrl = flow_build_as_drill_url($as, $hours, $selectedLinks);
    $quickLinks = '';

    if (isset($customlinks) && is_array($customlinks)) {
        $linkItems = array();
        foreach ($customlinks as $linkName => $url) {
            $label = ($linkName === 'HE') ? 'bgp.he' : $linkName;
            $linkItems[] = '<a class="flow-quick-link" href="' . htmlspecialchars(str_replace('%as%', $as, $url)) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($label) . '</a>';
        }
        if (!empty($linkItems)) {
            $quickLinks = '<div class="flow-quick-links">' . implode('', $linkItems) . '</div>';
        }
    }
    $drillCta = '<a class="flow-drill-cta" href="' . htmlspecialchars($drillUrl) . '" title="Abrir consumo detalhado por IP para AS' . htmlspecialchars((string)$as) . '"><i class="fa fa-search-plus"></i><span>Abrir drilldown por IP</span></a>';

    $html = '<article class="flow-as-row">';
    $html .= '<div class="flow-as-meta">';
    $html .= '<span class="flow-rank">#' . (int)$rank . '</span>';
    $html .= '<div class="flow-as-title">' . $flag . '<strong>AS' . htmlspecialchars($as) . '</strong></div>';
    $html .= '<p>' . htmlspecialchars($asinfo['descr']) . '</p>';
    $html .= $quickLinks;
    $html .= $drillCta;
    $html .= '<div class="flow-micro-metrics">';
    $html .= '<span>IPv4 IN total ' . htmlspecialchars(format_bytes($in4)) . '</span>';
    $html .= '<span>IPv4 OUT total ' . htmlspecialchars(format_bytes($out4)) . '</span>';
    if ($showv6) {
        $html .= '<span>IPv6 IN total ' . htmlspecialchars(format_bytes($in6)) . '</span>';
        $html .= '<span>IPv6 OUT total ' . htmlspecialchars(format_bytes($out6)) . '</span>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="flow-as-graphs">';
    $html .= '<a class="flow-graph-drill" href="' . htmlspecialchars($drillUrl) . '" title="Abrir consumo detalhado por IP para AS' . htmlspecialchars((string)$as) . '"><div class="flow-graph-card"><div class="flow-graph-label">IPv4</div>' . $graph4 . flow_render_graph_stats($graph4Stats) . '</div></a>';
    if ($showv6) {
        $html .= '<a class="flow-graph-drill" href="' . htmlspecialchars($drillUrl) . '" title="Abrir consumo detalhado por IP para AS' . htmlspecialchars((string)$as) . '"><div class="flow-graph-card"><div class="flow-graph-label">IPv6</div>' . $graph6 . flow_render_graph_stats($graph6Stats) . '</div></a>';
    }
    $html .= '</div>';
    $html .= '</article>';
    return $html;
}

function flow_render_empty_state($title, $body) {
    return '<div class="flow-empty-state"><strong>' . htmlspecialchars($title) . '</strong><p>' . htmlspecialchars($body) . '</p></div>';
}

function flow_render_dual_graph($title, $graph4, $graph6 = '') {
    $html = '<section class="flow-panel flow-panel-wide">';
    $html .= '<header class="flow-panel-head"><i class="fa fa-area-chart"></i><span>' . htmlspecialchars($title) . '</span></header>';
    $html .= '<div class="flow-panel-body">';
    $html .= '<div class="flow-graph-pair">';
    $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv4</div>' . $graph4 . '</div>';
    if ($graph6 !== '') {
        $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv6</div>' . $graph6 . '</div>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</section>';
    return $html;
}

function flow_render_link_legend($stats) {
    if (!is_array($stats) || empty($stats['legend']) || !is_array($stats['legend'])) {
        return '';
    }
    $html = '<div class="flow-link-legend-list">';
    foreach ($stats['legend'] as $entry) {
        if (!is_array($entry) || empty($entry['asn'])) {
            continue;
        }
        $asn = (int)$entry['asn'];
        $label = isset($entry['label']) ? (string)$entry['label'] : ('AS' . $asn);
        $color = isset($entry['color']) ? (string)$entry['color'] : '#7fbad9';
        $bytes = isset($entry['bytes']) ? (float)$entry['bytes'] : 0.0;
        $html .= '<span class="flow-link-legend-pill">';
        $html .= '<i style="background:' . htmlspecialchars($color) . '"></i>';
        $html .= '<strong>AS' . htmlspecialchars((string)$asn) . '</strong>';
        $html .= '<small>' . htmlspecialchars($label) . ' · ' . htmlspecialchars(format_bytes($bytes)) . '</small>';
        $html .= '</span>';
    }
    $html .= '</div>';
    return $html;
}

function flow_fetch_link_minute_series($linkTag, $ipVersion, $hours) {
    $cachePayload = array(
        'link' => (string)$linkTag,
        'ip' => (int)$ipVersion,
        'hours' => (int)$hours,
        'backend' => flow_events_backend(),
    );
    $cacheHit = false;
    $cached = flow_cache_get('link_minute_series', $cachePayload, 20, $cacheHit);
    if ($cacheHit && is_array($cached)) {
        return $cached;
    }

    $error = null;
    $db = flow_events_open_connection($error);
    if (!$db || !flow_events_has_table($db, 'flow_events')) {
        if ($db) {
            $db->close();
        }
        return array();
    }

    $windowStart = time() - (max(1, (int)$hours) * 3600);
    $samplingFactor = flow_link_sampling_factor($linkTag);
    $sql = "
        SELECT
            minute_ts,
            SUM(CASE WHEN lower(direction) = 'in' THEN bytes ELSE 0 END) AS in_bytes,
            SUM(CASE WHEN lower(direction) = 'out' THEN bytes ELSE 0 END) AS out_bytes
        FROM flow_events
        WHERE minute_ts >= :start
          AND link_tag = :link_tag
          AND ip_version = :ip_version
        GROUP BY minute_ts
        ORDER BY minute_ts ASC
    ";

    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        $db->close();
        return array();
    }

    $stmt->bindValue(':start', (int)$windowStart);
    $stmt->bindValue(':link_tag', (string)$linkTag);
    $stmt->bindValue(':ip_version', (int)$ipVersion);

    $result = @$stmt->execute();
    if ($result === false) {
        $db->close();
        return array();
    }

    $series = array();
    while ($row = $result->fetchArray()) {
        $minute = isset($row['minute_ts']) ? (int)$row['minute_ts'] : 0;
        if ($minute <= 0) {
            continue;
        }
        $inBytes = isset($row['in_bytes']) ? (float)$row['in_bytes'] : 0.0;
        $outBytes = isset($row['out_bytes']) ? (float)$row['out_bytes'] : 0.0;
        $series[] = array(
            'ts' => $minute,
            'in_bps' => (($inBytes * $samplingFactor) * 8.0) / 60.0,
            'out_bps' => (($outBytes * $samplingFactor) * 8.0) / 60.0,
        );
    }

    $db->close();
    flow_cache_set('link_minute_series', $cachePayload, $series);
    return $series;
}

function flow_fetch_as_minute_series($asn, $ipVersion, $hours, $selectedLinks = array()) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return array();
    }

    $links = array_values(array_filter(array_map('strval', (array)$selectedLinks)));
    sort($links);
    $cachePayload = array(
        'asn' => $asn,
        'ip' => (int)$ipVersion,
        'hours' => (int)$hours,
        'links' => $links,
        'backend' => flow_events_backend(),
    );
    $cacheHit = false;
    $cached = flow_cache_get('as_minute_series', $cachePayload, 20, $cacheHit);
    if ($cacheHit && is_array($cached)) {
        return $cached;
    }

    $error = null;
    $db = flow_events_open_connection($error);
    if (!$db || !flow_events_has_table($db, 'flow_events')) {
        if ($db) {
            $db->close();
        }
        return array();
    }

    $windowStart = time() - (max(1, (int)$hours) * 3600);
    $linkSql = '';
    if (!empty($links)) {
        $parts = array();
        foreach ($links as $idx => $tag) {
            $parts[] = ':link_' . $idx;
        }
        $linkSql = ' AND link_tag IN (' . implode(', ', $parts) . ')';
    }

    $sql = "
        SELECT
            minute_ts,
            link_tag,
            SUM(CASE WHEN dst_asn = :asn AND lower(direction) = 'in' THEN bytes ELSE 0 END) AS in_bytes,
            SUM(CASE WHEN src_asn = :asn AND lower(direction) = 'out' THEN bytes ELSE 0 END) AS out_bytes
        FROM flow_events
        WHERE minute_ts >= :start
          AND ip_version = :ip_version
          AND (src_asn = :asn OR dst_asn = :asn)
          {$linkSql}
        GROUP BY minute_ts, link_tag
        ORDER BY minute_ts ASC, link_tag ASC
    ";

    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        $db->close();
        return array();
    }

    $stmt->bindValue(':asn', $asn);
    $stmt->bindValue(':start', (int)$windowStart);
    $stmt->bindValue(':ip_version', (int)$ipVersion);
    foreach ($links as $idx => $tag) {
        $stmt->bindValue(':link_' . $idx, (string)$tag);
    }

    $result = @$stmt->execute();
    if ($result === false) {
        $db->close();
        return array();
    }

    $timeline = array();
    while ($row = $result->fetchArray()) {
        $minute = isset($row['minute_ts']) ? (int)$row['minute_ts'] : 0;
        if ($minute <= 0) {
            continue;
        }
        $linkTag = isset($row['link_tag']) ? (string)$row['link_tag'] : '';
        $samplingFactor = flow_link_sampling_factor($linkTag);
        $inBytes = isset($row['in_bytes']) ? (float)$row['in_bytes'] : 0.0;
        $outBytes = isset($row['out_bytes']) ? (float)$row['out_bytes'] : 0.0;
        if (!isset($timeline[$minute])) {
            $timeline[$minute] = array('in_bps' => 0.0, 'out_bps' => 0.0);
        }
        $timeline[$minute]['in_bps'] += (($inBytes * $samplingFactor) * 8.0) / 60.0;
        $timeline[$minute]['out_bps'] += (($outBytes * $samplingFactor) * 8.0) / 60.0;
    }

    ksort($timeline, SORT_NUMERIC);
    $series = array();
    foreach ($timeline as $minute => $values) {
        $series[] = array(
            'ts' => (int)$minute,
            'in_bps' => (float)$values['in_bps'],
            'out_bps' => (float)$values['out_bps'],
        );
    }

    $db->close();
    flow_cache_set('as_minute_series', $cachePayload, $series);
    return $series;
}

function flow_stats_from_minute_series($series) {
    if (!is_array($series) || empty($series)) {
        return null;
    }

    $stats = array(
        'in' => array('min' => null, 'max' => null, 'current' => null),
        'out' => array('min' => null, 'max' => null, 'current' => null),
    );
    foreach ($series as $point) {
        $in = isset($point['in_bps']) ? (float)$point['in_bps'] : 0.0;
        $out = isset($point['out_bps']) ? (float)$point['out_bps'] : 0.0;
        if ($stats['in']['min'] === null || $in < $stats['in']['min']) {
            $stats['in']['min'] = $in;
        }
        if ($stats['in']['max'] === null || $in > $stats['in']['max']) {
            $stats['in']['max'] = $in;
        }
        if ($stats['out']['min'] === null || $out < $stats['out']['min']) {
            $stats['out']['min'] = $out;
        }
        if ($stats['out']['max'] === null || $out > $stats['out']['max']) {
            $stats['out']['max'] = $out;
        }
        $stats['in']['current'] = $in;
        $stats['out']['current'] = $out;
    }

    return $stats;
}

function flow_render_link_svg_chart($series, $chartId, $title = '') {
    if (!is_array($series) || empty($series)) {
        return '<div class="flow-empty-state"><strong>Sem telemetria recente</strong><p>Sem pontos por minuto para este link na janela selecionada.</p></div>';
    }

    $w = 980.0;
    $h = 320.0;
    $padL = 56.0;
    $padR = 14.0;
    $padT = 14.0;
    $padB = 36.0;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    $n = count($series);
    $max = 1.0;
    foreach ($series as $p) {
        $max = max($max, (float)$p['in_bps'], (float)$p['out_bps']);
    }
    $max *= 1.10;

    $lineIn = '';
    $lineOut = '';
    $areaIn = '';
    $areaOut = '';
    $xTicks = '';
    $yTicks = '';
    $grid = '';
    $firstX = $padL;
    $lastX = $padL + $plotW;
    $baseY = $padT + $plotH;

    for ($i = 0; $i < $n; $i++) {
        $x = $padL + (($n <= 1 ? 0.0 : ($i / ($n - 1))) * $plotW);
        $yin = $baseY - (((float)$series[$i]['in_bps'] / $max) * $plotH);
        $yout = $baseY - (((float)$series[$i]['out_bps'] / $max) * $plotH);
        $lineIn .= ($i === 0 ? 'M ' : ' L ') . round($x, 2) . ' ' . round($yin, 2);
        $lineOut .= ($i === 0 ? 'M ' : ' L ') . round($x, 2) . ' ' . round($yout, 2);
    }

    $areaIn = $lineIn . ' L ' . round($lastX, 2) . ' ' . round($baseY, 2) . ' L ' . round($firstX, 2) . ' ' . round($baseY, 2) . ' Z';
    $areaOut = $lineOut . ' L ' . round($lastX, 2) . ' ' . round($baseY, 2) . ' L ' . round($firstX, 2) . ' ' . round($baseY, 2) . ' Z';

    $gridXCount = 8;
    for ($i = 0; $i <= $gridXCount; $i++) {
        $x = $padL + (($i / $gridXCount) * $plotW);
        $grid .= '<line x1="' . round($x, 2) . '" y1="' . $padT . '" x2="' . round($x, 2) . '" y2="' . round($baseY, 2) . '"></line>';
        $idx = (int)round(($n - 1) * ($i / $gridXCount));
        $labelTs = isset($series[$idx]['ts']) ? (int)$series[$idx]['ts'] : 0;
        $xTicks .= '<text x="' . round($x, 2) . '" y="' . round($h - 10, 2) . '">' . htmlspecialchars(date('H:i', $labelTs)) . '</text>';
    }

    $gridYCount = 5;
    for ($i = 0; $i <= $gridYCount; $i++) {
        $y = $padT + (($i / $gridYCount) * $plotH);
        $grid .= '<line x1="' . $padL . '" y1="' . round($y, 2) . '" x2="' . round($padL + $plotW, 2) . '" y2="' . round($y, 2) . '"></line>';
        $val = $max * (1 - ($i / $gridYCount));
        $yTicks .= '<text x="' . round($padL - 8, 2) . '" y="' . round($y + 4, 2) . '">' . htmlspecialchars(flow_format_bits($val)) . '</text>';
    }

    $chartTitle = $title !== '' ? '<text class="flow-link-svg-title" x="' . round($padL + 8, 2) . '" y="' . round($padT + 16, 2) . '">' . htmlspecialchars($title) . '</text>' : '';

    return '<div class="flow-link-svg-wrap"><svg class="flow-link-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-labelledby="' . htmlspecialchars($chartId) . '">'
        . '<defs>'
        . '<linearGradient id="flowInFill-' . htmlspecialchars($chartId) . '" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="rgba(103,232,249,0.35)"></stop><stop offset="100%" stop-color="rgba(103,232,249,0.03)"></stop></linearGradient>'
        . '<linearGradient id="flowOutFill-' . htmlspecialchars($chartId) . '" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="rgba(129,140,248,0.28)"></stop><stop offset="100%" stop-color="rgba(129,140,248,0.02)"></stop></linearGradient>'
        . '</defs>'
        . '<g class="flow-link-svg-grid">' . $grid . '</g>'
        . '<path class="flow-link-svg-area-in" d="' . $areaIn . '" fill="url(#flowInFill-' . htmlspecialchars($chartId) . ')"></path>'
        . '<path class="flow-link-svg-area-out" d="' . $areaOut . '" fill="url(#flowOutFill-' . htmlspecialchars($chartId) . ')"></path>'
        . '<path class="flow-link-svg-line-in" d="' . $lineIn . '"></path>'
        . '<path class="flow-link-svg-line-out" d="' . $lineOut . '"></path>'
        . '<g class="flow-link-svg-y">' . $yTicks . '</g>'
        . '<g class="flow-link-svg-x">' . $xTicks . '</g>'
        . $chartTitle
        . '</svg></div>';
}

function flow_render_link_card($title, $graph4, $graph6 = '', $stats4 = null, $stats6 = null) {
    $html = '<article class="flow-link-card">';
    $html .= '<header><span>' . htmlspecialchars($title) . '</span></header>';
    $html .= '<div class="flow-graph-pair">';
    $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv4</div><div class="flow-link-graph-frame">' . $graph4 . '</div>' . flow_render_graph_stats($stats4) . '</div>';
    if ($graph6 !== '') {
        $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv6</div><div class="flow-link-graph-frame">' . $graph6 . '</div>' . flow_render_graph_stats($stats6) . '</div>';
    }
    $html .= '</div>';
    $html .= '</article>';
    return $html;
}
