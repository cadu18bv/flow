<?php
require_once("func.inc");
require_once("flow_ui.php");

$selected_links = array();
$val_searchasset = isset($_GET['asset']) ? $_GET['asset'] : '';
$aff_tools = '';
$assetRows = '';
$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 4;
$label = statsLabelForHours($hours);
$statsfile = isset($peerusage) && $peerusage ? $daypeerstatsfile : statsFileForHours($hours);
$knownlinks = getknownlinks();

foreach ($knownlinks as $link) {
    if (isset($_GET["link_{$link['tag']}"])) {
        $selected_links[] = $link['tag'];
    }
}

if (isset($_GET['asset'])) {
    $asset = strtoupper($_GET['asset']);
} else {
    $asset = '';
}

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'clearall') {
        clearCacheFileASSET("all");
        header("Location: asset.php");
        exit;
    } elseif ($action === 'clear' && $asset) {
        clearCacheFileASSET($asset);
        header("Location: asset.php?asset=" . urlencode($asset));
        exit;
    }
}

$heroStats = array(
    array('label' => 'Janela', 'value' => $label),
    array('label' => 'Busca', 'value' => $asset ?: 'AS-SET'),
    array('label' => 'Links filtrados', 'value' => count($selected_links) ? count($selected_links) : count($knownlinks))
);

if ($asset) {
    $aslist = getASSET($asset);
    $as_num = array();
    if ($aslist) {
        foreach ($aslist as $as) {
            $as_tmp = substr($as, 2);
            if (is_numeric($as_tmp)) {
                $as_num[] = $as_tmp;
            }
        }
    }

    if (!empty($as_num)) {
        $topas = getasstats_top(200, $statsfile, $selected_links, $as_num);
        $start = time() - $hours * 3600;
        $end = time();
        $rank = 1;
        foreach ($topas as $as => $nbytes) {
            $assetRows .= flow_render_as_row($rank, $as, getASInfo($as), $nbytes, $start, $end, isset($peerusage) ? $peerusage : 0, $selected_links, $showv6);
            $rank++;
        }
    }

    $aff_tools .= '<a class="flow-button flow-button-ghost" href="asset.php?action=clearall">Limpar cache geral</a> ';
    $aff_tools .= '<a class="flow-button flow-button-ghost" href="asset.php?asset=' . htmlspecialchars($asset) . '&action=clear">Limpar cache deste AS-SET</a>';
}

flow_render_shell_start('Flow | AS-SET Studio', 'asset');
echo flow_render_hero('as-set studio', 'Painel AS-SET', 'Visão consolidada de grupos ASN, útil para clientes, upstreams, parceiros e coleções operacionais.', $heroStats);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Pesquisar AS-SET', flow_render_search_form('asset', $val_searchasset, 'Digite o AS-SET', 'asset.php', array(), 'Consultar'), 'fa-cubes');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selected_links, $hours, 200, 'asset.php', array('asset' => $asset)), 'fa-random');
if ($aff_tools !== '') {
    echo flow_render_panel('Ferramentas de cache', $aff_tools, 'fa-wrench');
}
echo '</div>';

echo '<div class="flow-stack">';
if ($assetRows !== '') {
    echo flow_render_panel('ASNs do conjunto', $assetRows, 'fa-object-group');
} else {
    echo flow_render_panel('Nenhum dado de AS-SET', flow_render_empty_state('Sem resultados disponíveis', 'Consulte um AS-SET válido ou verifique a coleta e o cache da aplicação.'), 'fa-cubes');
}
echo '</div>';
echo '</div>';

flow_render_shell_end();
