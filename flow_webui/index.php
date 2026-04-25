<?php
require_once("func.inc");
require_once("flow_ui.php");

if (!isset($peerusage)) $peerusage = 0;
$ntop = isset($_GET['n']) ? (int)$_GET['n'] : 20;
if ($ntop < 1) $ntop = 20;
if ($ntop > 200) $ntop = 200;
$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 24;

$statsfile = $peerusage ? $daypeerstatsfile : statsFileForHours($hours);
$label = statsLabelForHours($hours);
$knownlinks = getknownlinks();
$selected_links = array();

foreach ($knownlinks as $link) {
    if (isset($_GET["link_{$link['tag']}"])) {
        $selected_links[] = $link['tag'];
    }
}

$topas = getasstats_top($ntop, $statsfile, $selected_links);
$start = time() - $hours * 3600;
$end = time();

$heroStats = array(
    array('label' => 'Janela', 'value' => $label),
    array('label' => 'Top monitorado', 'value' => $ntop . ' AS'),
    array('label' => 'Links filtrados', 'value' => count($selected_links) ? count($selected_links) : count($knownlinks)),
    array('label' => 'Modo', 'value' => $showv6 ? 'IPv4 + IPv6' : 'IPv4')
);

flow_render_shell_start('Flow | Radar AS', 'overview');
echo flow_render_hero('flow radar', 'Radar de tráfego por ASN', 'Painel principal da operação para análise de origem, destino e pressão por sistema autônomo.', $heroStats);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Orquestração', flow_render_filter_form($hours, $ntop, $selected_links, 'index.php'), 'fa-sliders');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selected_links, $hours, $ntop, 'index.php'), 'fa-random');
echo '</div>';

echo '<div class="flow-stack">';
if (empty($topas)) {
    echo flow_render_panel('Nenhum dado disponível', flow_render_empty_state('Sem amostras para o período', 'Verifique o fluxo recebido, o knownlinks e a execução do extrator.'), 'fa-exclamation-triangle');
} else {
    $rows = '';
    $rank = 1;
    foreach ($topas as $as => $nbytes) {
        $rows .= flow_render_as_row($rank, $as, getASInfo($as), $nbytes, $start, $end, $peerusage, $selected_links, $showv6);
        $rank++;
    }
    echo flow_render_panel('Matriz de AS observados', $rows, 'fa-line-chart');
}
echo '</div>';
echo '</div>';

flow_render_shell_end();
