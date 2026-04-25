<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_noc_db_open() {
    $dbPath = flow_events_db_path();
    if (!is_file($dbPath)) {
        return null;
    }

    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(1000);
        @$db->exec('PRAGMA busy_timeout = 1000');
        @$db->exec('PRAGMA query_only = ON');
        return $db;
    } catch (Exception $exception) {
        return null;
    }
}

function flow_noc_db_health($db) {
    $health = array('ready' => false, 'rows' => 0, 'last_seen' => null);
    if (!$db instanceof SQLite3) {
        return $health;
    }
    $table = @$db->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'flow_events' LIMIT 1");
    if ($table !== 'flow_events') {
        return $health;
    }
    $health['ready'] = true;
    $row = @$db->querySingle('SELECT COUNT(*) AS rows, MAX(minute_ts) AS last_seen FROM flow_events', true);
    if (is_array($row)) {
        $health['rows'] = isset($row['rows']) ? (int)$row['rows'] : 0;
        $health['last_seen'] = isset($row['last_seen']) && $row['last_seen'] !== null ? (int)$row['last_seen'] : null;
    }
    return $health;
}

function flow_noc_selected_links($knownlinks) {
    $selected = array();
    foreach ($knownlinks as $link) {
        if (!isset($link['tag'])) {
            continue;
        }
        if (isset($_GET['link_' . $link['tag']])) {
            $selected[] = $link['tag'];
        }
    }
    return $selected;
}

function flow_noc_link_clause($selectedLinks, $prefix = 'noc_link_') {
    if (empty($selectedLinks)) {
        return '';
    }

    $parts = array();
    foreach (array_values($selectedLinks) as $index => $tag) {
        $parts[] = ':' . $prefix . $index;
    }
    return ' AND link_tag IN (' . implode(', ', $parts) . ') ';
}

function flow_noc_bind_links($stmt, $selectedLinks, $prefix = 'noc_link_') {
    foreach (array_values($selectedLinks) as $index => $tag) {
        $stmt->bindValue(':' . $prefix . $index, (string)$tag, SQLITE3_TEXT);
    }
}

function flow_noc_bgp_he($asn) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return '<span class="flow-inline-asn">AS0</span>';
    }

    $url = 'https://bgp.he.net/AS' . $asn;
    return '<a class="flow-inline-asn" href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer">AS' . htmlspecialchars((string)$asn) . '</a>';
}

function flow_noc_ip_cell($ip, $asn) {
    return '<div class="flow-ip-cell"><strong>' . htmlspecialchars((string)$ip) . '</strong>' . flow_noc_bgp_he($asn) . '</div>';
}

function flow_noc_render_table($headers, $rows) {
    if (empty($rows)) {
        return flow_render_empty_state('Sem dados', 'Nenhum registro foi agregado para esta grade.');
    }

    $html = '<div class="flow-table-wrap"><table class="flow-table"><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . $cell . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function flow_noc_runtime_dir() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime';
}

function flow_noc_geo_cache_path() {
    return flow_noc_runtime_dir() . DIRECTORY_SEPARATOR . 'flow_geo_cache.json';
}

function flow_noc_geo_cache_read() {
    $path = flow_noc_geo_cache_path();
    if (!is_file($path)) {
        return array();
    }

    $json = @file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return array();
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : array();
}

function flow_noc_geo_cache_write($cache) {
    $dir = flow_noc_runtime_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    @file_put_contents(flow_noc_geo_cache_path(), json_encode($cache));
}

function flow_noc_is_public_ip($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function flow_noc_geo_lookup_remote($ip) {
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,regionName,city,lat,lon,isp,org,as,query';
    $body = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CECTI-Flow-NOC/1.0');
        $body = curl_exec($ch);
        curl_close($ch);
    }

    if ($body === false && ini_get('allow_url_fopen')) {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => 3,
                'header' => "User-Agent: CECTI-Flow-NOC/1.0\r\n",
            ),
        ));
        $body = @file_get_contents($url, false, $context);
    }

    if ($body === false || trim((string)$body) === '') {
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'success') {
        return null;
    }

    return array(
        'country' => isset($data['country']) ? (string)$data['country'] : 'Indisponivel',
        'country_code' => isset($data['countryCode']) ? (string)$data['countryCode'] : '--',
        'region' => isset($data['regionName']) ? (string)$data['regionName'] : '',
        'city' => isset($data['city']) ? (string)$data['city'] : '',
        'lat' => isset($data['lat']) ? (float)$data['lat'] : null,
        'lon' => isset($data['lon']) ? (float)$data['lon'] : null,
        'isp' => isset($data['isp']) ? (string)$data['isp'] : '',
        'org' => isset($data['org']) ? (string)$data['org'] : '',
        'as' => isset($data['as']) ? (string)$data['as'] : '',
    );
}

function flow_noc_geolocate_ip($ip, &$cache) {
    if (!flow_noc_is_public_ip($ip)) {
        return array(
            'country' => 'Endereco interno',
            'country_code' => 'LAN',
            'region' => '',
            'city' => '',
            'lat' => null,
            'lon' => null,
            'isp' => '',
            'org' => '',
            'as' => '',
            'resolved' => true,
        );
    }

    if (isset($cache[$ip]) && isset($cache[$ip]['cached_at']) && ((time() - (int)$cache[$ip]['cached_at']) < 604800)) {
        $cache[$ip]['resolved'] = true;
        return $cache[$ip];
    }

    $data = flow_noc_geo_lookup_remote($ip);
    if ($data === null) {
        return array(
            'country' => 'Indisponivel',
            'country_code' => '--',
            'region' => '',
            'city' => '',
            'lat' => null,
            'lon' => null,
            'isp' => '',
            'org' => '',
            'as' => '',
            'resolved' => false,
        );
    }

    $data['cached_at'] = time();
    $data['resolved'] = true;
    $cache[$ip] = $data;
    return $data;
}

function flow_noc_query_remote_ips($db, $windowStart, $selectedLinks, $limit) {
    $sql = "
        SELECT
            CASE WHEN lower(direction) = 'out' THEN dst_ip ELSE src_ip END AS remote_ip,
            MAX(CASE WHEN lower(direction) = 'out' THEN dst_asn ELSE src_asn END) AS remote_asn,
            COUNT(*) AS events,
            COUNT(DISTINCT CASE WHEN lower(direction) = 'out' THEN src_ip ELSE dst_ip END) AS local_ips,
            COUNT(DISTINCT link_tag) AS links,
            SUM(bytes) AS total_bytes,
            SUM(samples) AS total_samples,
            MAX(minute_ts) AS last_seen
        FROM flow_events
        WHERE minute_ts >= :start
          " . flow_noc_link_clause($selectedLinks, 'remote_link_') . "
        GROUP BY remote_ip
        HAVING SUM(bytes) > 0
        ORDER BY total_bytes DESC, total_samples DESC
        LIMIT :limit
    ";

    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return array();
    }

    $stmt->bindValue(':start', (int)$windowStart, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', (int)$limit, SQLITE3_INTEGER);
    flow_noc_bind_links($stmt, $selectedLinks, 'remote_link_');

    $result = @$stmt->execute();
    if ($result === false) {
        return array();
    }

    $rows = array();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function flow_noc_enrich_rows($rows, &$cache) {
    $countries = array();
    $points = array();
    $unresolved = 0;

    foreach ($rows as &$row) {
        $geo = flow_noc_geolocate_ip($row['remote_ip'], $cache);
        $row['geo'] = $geo;
        if (!empty($geo['resolved']) && !empty($geo['country']) && $geo['country'] !== 'Endereco interno' && $geo['country'] !== 'Indisponivel') {
            if (!isset($countries[$geo['country']])) {
                $countries[$geo['country']] = 0;
            }
            $countries[$geo['country']] += (float)$row['total_bytes'];
        } else {
            $unresolved++;
        }

        if ($geo['lat'] !== null && $geo['lon'] !== null) {
            $points[] = array(
                'ip' => $row['remote_ip'],
                'country' => $geo['country'],
                'city' => $geo['city'],
                'lat' => (float)$geo['lat'],
                'lon' => (float)$geo['lon'],
                'bytes' => (float)$row['total_bytes'],
            );
        }
    }
    unset($row);

    arsort($countries);

    return array($rows, $countries, $points, $unresolved);
}

function flow_noc_render_world_map($points) {
    if (empty($points)) {
        return flow_render_empty_state('Sem pontos geograficos', 'Nenhum IP publico conseguiu ser geolocalizado na janela escolhida.');
    }

    $width = 960;
    $height = 380;
    $maxBytes = 1.0;
    foreach ($points as $point) {
        $maxBytes = max($maxBytes, (float)$point['bytes']);
    }

    $grid = '';
    for ($i = 0; $i <= 6; $i++) {
        $x = (int)round(($width / 6) * $i);
        $grid .= '<line x1="' . $x . '" y1="0" x2="' . $x . '" y2="' . $height . '"></line>';
    }
    for ($i = 0; $i <= 4; $i++) {
        $y = (int)round(($height / 4) * $i);
        $grid .= '<line x1="0" y1="' . $y . '" x2="' . $width . '" y2="' . $y . '"></line>';
    }

    $dots = '';
    foreach ($points as $point) {
        $x = (($point['lon'] + 180.0) / 360.0) * $width;
        $y = ((90.0 - $point['lat']) / 180.0) * $height;
        $radius = 4 + (16 * ((float)$point['bytes'] / $maxBytes));
        $title = trim($point['ip'] . ' • ' . $point['country'] . ' ' . $point['city'] . ' • ' . format_bytes((float)$point['bytes']));
        $dots .= '<circle cx="' . round($x, 2) . '" cy="' . round($y, 2) . '" r="' . round($radius, 2) . '"><title>' . htmlspecialchars($title) . '</title></circle>';
    }

    return <<<HTML
<div class="flow-world-map">
  <svg viewBox="0 0 {$width} {$height}" role="img" aria-label="Mapa global de IPs observados">
    <defs>
      <linearGradient id="flowWorldMapBackground" x1="0" x2="1" y1="0" y2="1">
        <stop offset="0%" stop-color="rgba(77, 212, 255, 0.10)" />
        <stop offset="100%" stop-color="rgba(0, 255, 166, 0.04)" />
      </linearGradient>
      <filter id="flowWorldMapGlow">
        <feGaussianBlur stdDeviation="4" result="blur" />
        <feMerge>
          <feMergeNode in="blur" />
          <feMergeNode in="SourceGraphic" />
        </feMerge>
      </filter>
    </defs>
    <rect x="0" y="0" width="{$width}" height="{$height}" rx="26" ry="26"></rect>
    <g class="flow-world-grid">{$grid}</g>
    <g class="flow-world-dots">{$dots}</g>
  </svg>
</div>
HTML;
}

function flow_noc_country_cards($countries) {
    if (empty($countries)) {
        return flow_render_empty_state('Sem paises agregados', 'Os IPs observados ainda nao retornaram geolocalizacao suficiente para agrupar por pais.');
    }

    $html = '<div class="flow-hotspot-grid">';
    $rank = 1;
    foreach (array_slice($countries, 0, 8, true) as $country => $bytes) {
        $html .= '<article class="flow-hotspot-card">';
        $html .= '<span>#' . $rank . '</span>';
        $html .= '<strong>' . htmlspecialchars((string)$country) . '</strong>';
        $html .= '<small>' . htmlspecialchars(format_bytes((float)$bytes)) . '</small>';
        $html .= '</article>';
        $rank++;
    }
    $html .= '</div>';
    return $html;
}

function flow_noc_trace_table($rows) {
    if (empty($rows)) {
        return flow_render_empty_state('Sem rastreabilidade', 'Nao houve eventos suficientes para montar a trilha global de IPs observados.');
    }

    $tableRows = array();
    foreach ($rows as $row) {
        $geo = isset($row['geo']) ? $row['geo'] : array();
        $location = trim(
            (isset($geo['country']) ? $geo['country'] : 'Indisponivel')
            . ((isset($geo['city']) && $geo['city'] !== '') ? ' • ' . $geo['city'] : '')
        );

        $extra = array();
        if (!empty($geo['org'])) {
            $extra[] = $geo['org'];
        } elseif (!empty($geo['isp'])) {
            $extra[] = $geo['isp'];
        }
        if (!empty($geo['as'])) {
            $extra[] = $geo['as'];
        }

        $tableRows[] = array(
            flow_noc_ip_cell($row['remote_ip'], $row['remote_asn']),
            htmlspecialchars($location),
            htmlspecialchars(implode(' • ', $extra)),
            htmlspecialchars((string)$row['local_ips']),
            htmlspecialchars((string)$row['links']),
            htmlspecialchars(format_bytes((float)$row['total_bytes'])),
            htmlspecialchars((string)$row['total_samples']),
            htmlspecialchars(date('d/m H:i', (int)$row['last_seen'])),
        );
    }

    return flow_noc_render_table(
        array('IP remoto', 'Localizacao', 'Operadora / AS', 'IPs locais', 'Links', 'Bytes', 'Samples', 'Ultima visao'),
        $tableRows
    );
}

$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 24;
$hours = $hours > 0 ? $hours : 24;
$limit = isset($_GET['n']) ? (int)$_GET['n'] : 20;
$limit = $limit > 0 ? min($limit, 100) : 20;
$knownlinks = getknownlinks();
$selectedLinks = flow_noc_selected_links($knownlinks);
$windowStart = time() - ($hours * 3600);
$db = flow_noc_db_open();
$traceRows = array();
$countryTotals = array();
$geoPoints = array();
$unresolved = 0;
$cache = flow_noc_geo_cache_read();
$dbHealth = array('ready' => false, 'rows' => 0, 'last_seen' => null);

if ($db) {
    $dbHealth = flow_noc_db_health($db);
    if ($dbHealth['ready']) {
        $traceRows = flow_noc_query_remote_ips($db, $windowStart, $selectedLinks, $limit);
        list($traceRows, $countryTotals, $geoPoints, $unresolved) = flow_noc_enrich_rows($traceRows, $cache);
    }
    $db->close();
    flow_noc_geo_cache_write($cache);
}

$heroStats = array(
    array('label' => 'Janela', 'value' => statsLabelForHours($hours)),
    array('label' => 'IPs geolocalizados', 'value' => (string)count($geoPoints)),
    array('label' => 'Paises visiveis', 'value' => (string)count($countryTotals)),
    array('label' => 'Nao resolvidos', 'value' => (string)$unresolved),
    array('label' => 'Eventos na base', 'value' => number_format((int)$dbHealth['rows'], 0, ',', '.')),
);

flow_render_shell_start('Flow | NOC', 'noc');
echo flow_render_hero(
    'global trace',
    'Painel NOC',
    'Camada operacional para rastreabilidade global do trafego, distribuicao geografica dos IPs remotos e leitura de hotspots por pais e operadora.',
    $heroStats
);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Controles do NOC', flow_render_filter_form($hours, $limit, $selectedLinks, 'noc.php'), 'fa-compass');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selectedLinks, $hours, $limit, 'noc.php'), 'fa-random');
echo flow_render_panel(
    'Fonte de geolocalizacao',
    '<div class="flow-copy-block"><p>Os IPs publicos sao resolvidos sob demanda e ficam em cache local. Enderecos privados aparecem como LAN. Quando o servico externo nao responde, o painel mantem a trilha tecnica sem geolocalizacao.</p><p>Base: ' . htmlspecialchars($dbHealth['ready'] ? 'flow_events pronta' : 'flow_events indisponivel') . ' | Ultima amostra: ' . htmlspecialchars($dbHealth['last_seen'] ? date('d/m H:i', (int)$dbHealth['last_seen']) : 'sem dados') . '</p></div>',
    'fa-globe'
);
echo '</div>';

echo '<div class="flow-stack">';
echo flow_render_panel('Mapa global de presenca', flow_noc_render_world_map($geoPoints), 'fa-globe');
echo flow_render_panel('Hotspots por pais', flow_noc_country_cards($countryTotals), 'fa-map-marker');
echo flow_render_panel('Feed de rastreabilidade', flow_noc_trace_table($traceRows), 'fa-sitemap');
echo '</div>';
echo '</div>';

flow_render_shell_end();
