<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_noc_db_open() {
    $error = null;
    $db = flow_events_open_connection($error);
    return array($db, $error);
}

function flow_noc_db_health($db) {
    $cacheHit = false;
    $cached = flow_cache_get('noc_db_health', array('backend' => flow_events_backend()), 15, $cacheHit);
    if ($cacheHit && is_array($cached)) {
        return $cached;
    }

    $health = array('ready' => false, 'rows' => 0, 'last_seen' => null);
    if (!flow_events_has_table($db, 'flow_events')) {
        flow_cache_set('noc_db_health', array('backend' => flow_events_backend()), $health);
        return $health;
    }
    $health['ready'] = true;
    $row = @$db->querySingle('SELECT COUNT(*) AS rows, MAX(minute_ts) AS last_seen FROM flow_events', true);
    if (is_array($row)) {
        $health['rows'] = isset($row['rows']) ? (int)$row['rows'] : 0;
        $health['last_seen'] = isset($row['last_seen']) && $row['last_seen'] !== null ? (int)$row['last_seen'] : null;
    }
    flow_cache_set('noc_db_health', array('backend' => flow_events_backend()), $health);
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

function flow_noc_dns_cache_path() {
    return flow_runtime_dir() . DIRECTORY_SEPARATOR . 'flow_dns_cache.json';
}

function flow_noc_dns_cache_read() {
    $path = flow_noc_dns_cache_path();
    if (!is_file($path)) {
        return array();
    }
    $json = @file_get_contents($path);
    if ($json === false || trim((string)$json) === '') {
        return array();
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : array();
}

function flow_noc_dns_cache_write($cache) {
    $dir = flow_runtime_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    @file_put_contents(flow_noc_dns_cache_path(), json_encode($cache));
}

function flow_noc_resolve_hostname($ip, &$cache) {
    $ip = trim((string)$ip);
    if ($ip === '') {
        return '';
    }

    if (isset($cache[$ip]) && isset($cache[$ip]['updated_at']) && ((time() - (int)$cache[$ip]['updated_at']) < 86400)) {
        return isset($cache[$ip]['host']) ? (string)$cache[$ip]['host'] : '';
    }

    $host = @gethostbyaddr($ip);
    if (!is_string($host) || $host === '' || strcasecmp($host, $ip) === 0) {
        $host = '';
    }
    $cache[$ip] = array(
        'host' => $host,
        'updated_at' => time(),
    );
    return $host;
}

function flow_noc_ip_cell($ip, $asn, $dnsMap = array()) {
    $ipText = trim((string)$ip);
    $host = isset($dnsMap[$ipText]) ? trim((string)$dnsMap[$ipText]) : '';
    $html = '<div class="flow-ip-cell"><strong>' . htmlspecialchars($ipText) . '</strong>' . flow_noc_bgp_he($asn);
    if ($host !== '') {
        $html .= '<small>' . htmlspecialchars($host) . '</small>';
    }
    $html .= '</div>';
    return $html;
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

function flow_noc_geo_lookup_enabled() {
    $flag = strtolower(trim((string)flow_env_setting('FLOW_NOC_GEO_LOOKUP_ENABLED', '1')));
    return !in_array($flag, array('0', 'off', 'false', 'no'), true);
}

function flow_noc_dns_lookup_enabled() {
    $flag = strtolower(trim((string)flow_env_setting('FLOW_NOC_DNS_LOOKUP_ENABLED', '0')));
    return !in_array($flag, array('0', 'off', 'false', 'no'), true);
}

function flow_noc_geolocate_ip($ip, &$cache, &$lookupBudget = null) {
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
        $cache[$ip]['resolved'] = isset($cache[$ip]['resolved']) ? (bool)$cache[$ip]['resolved'] : true;
        return $cache[$ip];
    }

    if (!flow_noc_geo_lookup_enabled()) {
        return array(
            'country' => 'Geolocalizacao off',
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

    if ($lookupBudget !== null && $lookupBudget <= 0) {
        return array(
            'country' => 'Aguardando cache',
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

    if ($lookupBudget !== null) {
        $lookupBudget--;
    }

    $data = flow_noc_geo_lookup_remote($ip);
    if ($data === null) {
        $miss = array(
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
        $miss['cached_at'] = time();
        $cache[$ip] = $miss;
        return $miss;
    }

    $data['cached_at'] = time();
    $data['resolved'] = true;
    $cache[$ip] = $data;
    return $data;
}

function flow_noc_query_remote_ips($db, $windowStart, $selectedLinks, $limit) {
    $cachePayload = array('start' => (int)$windowStart, 'links' => array_values((array)$selectedLinks), 'limit' => (int)$limit, 'backend' => flow_events_backend());
    $cacheHit = false;
    $cached = flow_cache_get('noc_remote_ips', $cachePayload, 25, $cacheHit);
    if ($cacheHit && is_array($cached)) {
        return $cached;
    }

    $sql = "
        SELECT
            COALESCE(NULLIF(CASE WHEN lower(direction) = 'out' THEN dst_ip ELSE src_ip END, ''), '0.0.0.0') AS remote_ip,
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
        HAVING SUM(bytes) > 0 OR SUM(samples) > 0
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
    flow_cache_set('noc_remote_ips', $cachePayload, $rows);
    return $rows;
}

function flow_noc_enrich_rows($rows, &$cache) {
    $countries = array();
    $points = array();
    $unresolved = 0;
    $geoBudget = (int)flow_env_setting('FLOW_NOC_GEO_LOOKUPS_PER_REQUEST', '8');
    if ($geoBudget < 0) {
        $geoBudget = 0;
    }

    foreach ($rows as &$row) {
        $geo = flow_noc_geolocate_ip($row['remote_ip'], $cache, $geoBudget);
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
                'bytes_human' => format_bytes((float)$row['total_bytes']),
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

    $anchor = array(
        'label' => flow_env_setting('FLOW_NOC_ANCHOR_LABEL', 'NOC Core'),
        'lat' => (float)flow_env_setting('FLOW_NOC_ANCHOR_LAT', '-3.7319'),
        'lon' => (float)flow_env_setting('FLOW_NOC_ANCHOR_LON', '-38.5267'),
    );
    $sortedPoints = $points;
    usort($sortedPoints, function ($a, $b) {
        return ((float)$b['bytes'] <=> (float)$a['bytes']);
    });
    $maxPoints = (int)flow_env_setting('FLOW_NOC_MAP_MAX_POINTS', '260');
    if ($maxPoints <= 0) {
        $maxPoints = 260;
    }
    $sortedPoints = array_slice($sortedPoints, 0, $maxPoints);
    $mapPayload = array(
        'anchor' => $anchor,
        'points' => $sortedPoints,
    );
    $mapJson = htmlspecialchars(json_encode($mapPayload), ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div class="flow-world-map" id="flowNocWorldMap" data-map-points='{$mapJson}'></div>
<script>
(function() {
  function bootFlowNocMap() {
    var el = document.getElementById('flowNocWorldMap');
    if (!el || el.dataset.loaded === '1') return;
    el.dataset.loaded = '1';
    var payload = {};
    try { payload = JSON.parse(el.getAttribute('data-map-points') || '{}'); } catch (e) { payload = {}; }
    var anchor = payload.anchor || { lat: -3.7319, lon: -38.5267, label: 'NOC Core' };
    var points = Array.isArray(payload.points) ? payload.points : [];
    var anchorLat = Number(anchor.lat || -3.7319);
    var anchorLon = Number(anchor.lon || -38.5267);
    if (!isFinite(anchorLat)) anchorLat = -3.7319;
    if (!isFinite(anchorLon)) anchorLon = -38.5267;

    var map = L.map(el, { zoomControl: true, attributionControl: true }).setView([anchorLat, anchorLon], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    L.circleMarker([anchorLat, anchorLon], {
      radius: 7, color: '#00ffa6', weight: 2, fillColor: '#00ffa6', fillOpacity: 0.85
    }).addTo(map).bindPopup('<strong>' + String(anchor.label || 'NOC Core') + '</strong>');

    points.forEach(function(point) {
      var lat = Number(point.lat || 0);
      var lon = Number(point.lon || 0);
      if (!isFinite(lat) || !isFinite(lon)) return;
      var bytes = Number(point.bytes || 0);
      var radius = Math.max(3, Math.min(12, 2 + Math.log10(bytes + 1)));
      var marker = L.circleMarker([lat, lon], {
        radius: radius, color: '#4dd4ff', weight: 1, fillColor: '#4dd4ff', fillOpacity: 0.58
      }).addTo(map);
      marker.bindPopup(
        '<strong>' + String(point.ip || '-') + '</strong><br>'
        + String(point.country || '-') + (point.city ? (' - ' + String(point.city)) : '')
        + '<br>Volume: ' + String(point.bytes_human || '')
      );
      L.polyline([[anchorLat, anchorLon], [lat, lon]], { color: 'rgba(77,212,255,0.35)', weight: 1 }).addTo(map);
    });
  }

  function ensureLeaflet() {
    if (window.L && typeof window.L.map === 'function') { bootFlowNocMap(); return; }
    if (!document.getElementById('flowLeafletCss')) {
      var css = document.createElement('link');
      css.id = 'flowLeafletCss';
      css.rel = 'stylesheet';
      css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
      document.head.appendChild(css);
    }
    if (document.getElementById('flowLeafletJs')) {
      document.getElementById('flowLeafletJs').addEventListener('load', bootFlowNocMap, { once: true });
      return;
    }
    var js = document.createElement('script');
    js.id = 'flowLeafletJs';
    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    js.defer = true;
    js.onload = bootFlowNocMap;
    document.body.appendChild(js);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ensureLeaflet, { once: true });
  else ensureLeaflet();
})();
</script>
HTML;

    return $html;
}

function flow_noc_asn_cards($rows) {
    $totals = array();
    foreach ($rows as $row) {
        $asn = isset($row['remote_asn']) ? (int)$row['remote_asn'] : 0;
        if ($asn <= 0) {
            continue;
        }
        if (!isset($totals[$asn])) {
            $totals[$asn] = 0.0;
        }
        $totals[$asn] += (float)$row['total_bytes'];
    }

    if (empty($totals)) {
        return flow_render_empty_state('Sem ASN remoto em destaque', 'Ainda nao foi possivel consolidar ASN remotos na janela ativa.');
    }

    arsort($totals);
    $html = '<div class="flow-hotspot-grid">';
    $rank = 1;
    foreach (array_slice($totals, 0, 8, true) as $asn => $bytes) {
        $html .= '<article class="flow-hotspot-card">';
        $html .= '<span>#' . $rank . '</span>';
        $html .= '<strong>AS' . htmlspecialchars((string)$asn) . '</strong>';
        $html .= '<small>' . htmlspecialchars(format_bytes((float)$bytes)) . '</small>';
        $html .= '</article>';
        $rank++;
    }
    $html .= '</div>';
    return $html;
}

function flow_noc_operator_cards($rows) {
    $totals = array();
    foreach ($rows as $row) {
        $geo = isset($row['geo']) && is_array($row['geo']) ? $row['geo'] : array();
        $name = '';
        if (isset($geo['org']) && trim((string)$geo['org']) !== '') {
            $name = trim((string)$geo['org']);
        } elseif (isset($geo['isp']) && trim((string)$geo['isp']) !== '') {
            $name = trim((string)$geo['isp']);
        }
        if ($name === '') {
            continue;
        }
        if (!isset($totals[$name])) {
            $totals[$name] = 0.0;
        }
        $totals[$name] += (float)$row['total_bytes'];
    }

    if (empty($totals)) {
        return flow_render_empty_state('Sem operadoras em destaque', 'A geolocalizacao ainda nao retornou organizacao/ISP para consolidar ranking.');
    }

    arsort($totals);
    $html = '<div class="flow-hotspot-grid">';
    $rank = 1;
    foreach (array_slice($totals, 0, 8, true) as $name => $bytes) {
        $html .= '<article class="flow-hotspot-card">';
        $html .= '<span>#' . $rank . '</span>';
        $html .= '<strong>' . htmlspecialchars((string)$name) . '</strong>';
        $html .= '<small>' . htmlspecialchars(format_bytes((float)$bytes)) . '</small>';
        $html .= '</article>';
        $rank++;
    }
    $html .= '</div>';
    return $html;
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

function flow_noc_trace_table($rows, $dnsMap = array()) {
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
            flow_noc_ip_cell($row['remote_ip'], $row['remote_asn'], $dnsMap),
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

$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 4;
$hours = $hours > 0 ? $hours : 4;
$limit = isset($_GET['n']) ? (int)$_GET['n'] : 20;
$limit = $limit > 0 ? min($limit, 100) : 20;
$knownlinks = getknownlinks();
$selectedLinks = flow_noc_selected_links($knownlinks);
$windowStart = time() - ($hours * 3600);
$dbError = null;
list($db, $dbError) = flow_noc_db_open();
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

$dnsCache = flow_noc_dns_cache_read();
$dnsMap = array();
$dnsCandidates = array();
foreach ($traceRows as $row) {
    if (!empty($row['remote_ip'])) {
        $dnsCandidates[(string)$row['remote_ip']] = true;
    }
}
if (flow_noc_dns_lookup_enabled()) {
    $dnsLimit = (int)flow_env_setting('FLOW_NOC_DNS_LOOKUPS_PER_REQUEST', '24');
    if ($dnsLimit < 0) {
        $dnsLimit = 0;
    }
    foreach (array_slice(array_keys($dnsCandidates), 0, $dnsLimit) as $ip) {
        $dnsMap[$ip] = flow_noc_resolve_hostname($ip, $dnsCache);
    }
}
flow_noc_dns_cache_write($dnsCache);

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
    '<div class="flow-copy-block"><p>Os IPs publicos sao resolvidos sob demanda e ficam em cache local. Enderecos privados aparecem como LAN. Quando o servico externo nao responde, o painel mantem a trilha tecnica sem geolocalizacao.</p><p>Base: ' . htmlspecialchars($dbHealth['ready'] ? 'flow_events pronta' : 'flow_events indisponivel') . ' | Ultima amostra: ' . htmlspecialchars($dbHealth['last_seen'] ? date('d/m H:i', (int)$dbHealth['last_seen']) : 'sem dados') . '</p>' . ($dbError ? '<p><strong>Erro DB:</strong> ' . htmlspecialchars($dbError) . '</p>' : '') . '</div>',
    'fa-globe'
);
echo '</div>';

echo '<div class="flow-stack">';
echo flow_render_panel('Mapa global de presenca', flow_noc_render_world_map($geoPoints), 'fa-globe');
echo flow_render_panel('Hotspots por pais', flow_noc_country_cards($countryTotals), 'fa-map-marker');
echo flow_render_panel('ASN remotos dominantes', flow_noc_asn_cards($traceRows), 'fa-signal');
echo flow_render_panel('Operadoras / orgs remotas', flow_noc_operator_cards($traceRows), 'fa-building');
echo flow_render_panel('Feed de rastreabilidade', flow_noc_trace_table($traceRows, $dnsMap), 'fa-sitemap');
echo '</div>';
echo '</div>';

flow_render_shell_end();
