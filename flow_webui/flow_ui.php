<?php

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
    $brand = 'CECTI Flow Observatory';
    $title = htmlspecialchars($title);
    $activeOverview = flow_active_class($active, 'overview');
    $activeHistory = flow_active_class($active, 'history');
    $activeIp = flow_active_class($active, 'ipsearch');
    $activeAsset = flow_active_class($active, 'asset');
    $activeIx = flow_active_class($active, 'ix');
    $activeLinks = flow_active_class($active, 'links');

    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Refresh" content="300">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <title>{$title}</title>
  <link rel="icon" href="favicon.ico" />
  <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="plugins/font-awesome/font-awesome.min.css">
  <link rel="stylesheet" href="plugins/ionicons/ionicons.min.css">
  <link rel="stylesheet" href="css/custom.css">
</head>
<body class="flow-body">
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
        <a class="{$activeOverview}" href="index.php">Radar AS</a>
        <a class="{$activeHistory}" href="history.php">ASN Explorer</a>
        <a class="{$activeIp}" href="ipsearch.php">IP Lens</a>
        <a class="{$activeAsset}" href="asset.php">AS-SET Studio</a>
        <a class="{$activeIx}" href="ix.php">IX Analytics</a>
        <a class="{$activeLinks}" href="linkusage.php">Link Flow</a>
      </nav>
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
  <script src="plugins/jQuery/jquery-2.2.3.min.js"></script>
  <script src="bootstrap/js/bootstrap.min.js"></script>
</body>
</html>
HTML;
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

    $html = '<article class="flow-as-row">';
    $html .= '<div class="flow-as-meta">';
    $html .= '<span class="flow-rank">#' . (int)$rank . '</span>';
    $html .= '<div class="flow-as-title">' . $flag . '<strong>AS' . htmlspecialchars($as) . '</strong></div>';
    $html .= '<p>' . htmlspecialchars($asinfo['descr']) . '</p>';
    $html .= '<div class="flow-micro-metrics">';
    $html .= '<span>IPv4 IN ' . htmlspecialchars(format_bytes($in4)) . '</span>';
    $html .= '<span>IPv4 OUT ' . htmlspecialchars(format_bytes($out4)) . '</span>';
    if ($showv6) {
        $html .= '<span>IPv6 IN ' . htmlspecialchars(format_bytes($in6)) . '</span>';
        $html .= '<span>IPv6 OUT ' . htmlspecialchars(format_bytes($out6)) . '</span>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="flow-as-graphs">';
    $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv4</div>' . $graph4 . '</div>';
    if ($showv6) {
        $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv6</div>' . $graph6 . '</div>';
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

function flow_render_link_card($title, $graph4, $graph6 = '') {
    $html = '<article class="flow-link-card">';
    $html .= '<header><span>' . htmlspecialchars($title) . '</span></header>';
    $html .= '<div class="flow-graph-pair">';
    $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv4</div>' . $graph4 . '</div>';
    if ($graph6 !== '') {
        $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv6</div>' . $graph6 . '</div>';
    }
    $html .= '</div>';
    $html .= '</article>';
    return $html;
}
