<?php
require_once("auth.php");

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
    return isset($top_intervals) && is_array($top_intervals) ? $top_intervals : array(array('hours' => 24, 'label' => '24 horas'));
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
    $activeHistory = flow_active_class($active, 'history');
    $activeIp = flow_active_class($active, 'ipsearch');
    $activeAsset = flow_active_class($active, 'asset');
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
        <a class="{$activeHistory}" href="history.php">ASN Explorer</a>
        <a class="{$activeIp}" href="ipsearch.php">IP Lens</a>
        <a class="{$activeAsset}" href="asset.php">AS-SET Studio</a>
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

function flow_fetch_link_flow_stats($linkTag, $ipversion, $hours) {
    $dbPath = flow_events_db_path();
    if (!is_file($dbPath)) {
        return null;
    }

    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    } catch (Exception $exception) {
        return null;
    }

    $start = time() - ((int)$hours * 3600);
    $stmt = $db->prepare(
        "SELECT minute_ts, direction, SUM(bytes) AS total_bytes
         FROM flow_events
         WHERE minute_ts >= :start
           AND link_tag = :link_tag
           AND ip_version = :ip_version
         GROUP BY minute_ts, direction
         ORDER BY minute_ts ASC"
    );
    $stmt->bindValue(':start', $start, SQLITE3_INTEGER);
    $stmt->bindValue(':link_tag', (string)$linkTag, SQLITE3_TEXT);
    $stmt->bindValue(':ip_version', (int)$ipversion, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $timeline = array();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $minute = (int)$row['minute_ts'];
        if (!isset($timeline[$minute])) {
            $timeline[$minute] = array('in' => 0.0, 'out' => 0.0);
        }
        $direction = strtolower((string)$row['direction']) === 'out' ? 'out' : 'in';
        $timeline[$minute][$direction] += (((float)$row['total_bytes']) * 8.0) / 60.0;
    }
    $db->close();

    if (empty($timeline)) {
        return null;
    }

    $stats = array(
        'in' => array('min' => null, 'max' => null, 'current' => null),
        'out' => array('min' => null, 'max' => null, 'current' => null),
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

    $graph4 = getHTMLUrl($as, 4, $asinfo['descr'], $start, $end, $peerusage, $selectedLinks);
    $graph6 = $showv6 ? getHTMLUrl($as, 6, $asinfo['descr'], $start, $end, $peerusage, $selectedLinks) : '';
    $graph4Stats = flow_fetch_rrd_graph_stats($as, 4, $start, $end, $peerusage, $selectedLinks);
    $graph6Stats = $showv6 ? flow_fetch_rrd_graph_stats($as, 6, $start, $end, $peerusage, $selectedLinks) : null;
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

    $html = '<article class="flow-as-row">';
    $html .= '<div class="flow-as-meta">';
    $html .= '<span class="flow-rank">#' . (int)$rank . '</span>';
    $html .= '<div class="flow-as-title">' . $flag . '<strong>AS' . htmlspecialchars($as) . '</strong></div>';
    $html .= '<p>' . htmlspecialchars($asinfo['descr']) . '</p>';
    $html .= $quickLinks;
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
    $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv4</div>' . $graph4 . flow_render_graph_stats($graph4Stats) . '</div>';
    if ($showv6) {
        $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv6</div>' . $graph6 . flow_render_graph_stats($graph6Stats) . '</div>';
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

function flow_render_link_card($title, $graph4, $graph6 = '', $stats4 = null, $stats6 = null) {
    $html = '<article class="flow-link-card">';
    $html .= '<header><span>' . htmlspecialchars($title) . '</span></header>';
    $html .= '<div class="flow-graph-pair">';
    $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv4</div>' . $graph4 . flow_render_graph_stats($stats4) . '</div>';
    if ($graph6 !== '') {
        $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv6</div>' . $graph6 . flow_render_graph_stats($stats6) . '</div>';
    }
    $html .= '</div>';
    $html .= '</article>';
    return $html;
}
