<?php
require_once("func.inc");
require_once("flow_ui.php");

flow_auth_require_role(array('master', 'admin'));

function flow_config_inc_path() {
    return __DIR__ . DIRECTORY_SEPARATOR . 'config.inc';
}

function flow_knownlinks_path() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'knownlinks';
}

function flow_read_local_asn() {
    $path = flow_config_inc_path();
    if (!is_file($path)) {
        return '';
    }
    $content = (string)file_get_contents($path);
    if (preg_match('/\$my_asn\s*=\s*"([^"]+)";/', $content, $matches)) {
        return $matches[1];
    }
    return '';
}

function flow_write_local_asn($asn) {
    $path = flow_config_inc_path();
    $content = (string)file_get_contents($path);
    $content = preg_replace('/\$my_asn\s*=\s*"([^"]+)";/', '$my_asn = "' . $asn . '";', $content, 1);
    file_put_contents($path, $content);
}

function flow_read_knownlinks_text() {
    $path = flow_knownlinks_path();
    return is_file($path) ? (string)file_get_contents($path) : '';
}

function flow_set_knownlinks_write_error($message) {
    $GLOBALS['flow_knownlinks_write_error'] = trim((string)$message);
}

function flow_knownlinks_write_error() {
    return isset($GLOBALS['flow_knownlinks_write_error']) ? (string)$GLOBALS['flow_knownlinks_write_error'] : '';
}

function flow_write_knownlinks_via_helper($payload) {
    $runtimeDir = flow_runtime_dir();
    if (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0770, true);
    }

    $tmp = @tempnam($runtimeDir, 'knownlinks-write-');
    if ($tmp === false || $tmp === '') {
        flow_set_knownlinks_write_error('Nao foi possivel criar arquivo temporario em ' . $runtimeDir);
        return false;
    }

    if (@file_put_contents($tmp, $payload) === false) {
        @unlink($tmp);
        flow_set_knownlinks_write_error('Nao foi possivel gravar conteudo temporario para o helper.');
        return false;
    }

    list($ok, $output) = flow_run_maintenance_action('knownlinks-write', array($tmp));
    @unlink($tmp);

    if (!$ok) {
        flow_set_knownlinks_write_error($output !== '' ? $output : 'Falha ao gravar knownlinks via helper.');
        return false;
    }

    flow_set_knownlinks_write_error('');
    return true;
}

function flow_write_knownlinks_text($text) {
    $text = (string)$text;
    $text = str_replace(array("\r\n", "\r"), "\n", $text);
    $payload = rtrim($text) . "\n";
    $path = flow_knownlinks_path();

    flow_set_knownlinks_write_error('');

    if (@file_put_contents($path, $payload) !== false) {
        return true;
    }

    if (flow_write_knownlinks_via_helper($payload)) {
        return true;
    }

    if (flow_knownlinks_write_error() === '') {
        $error = error_get_last();
        $details = is_array($error) && isset($error['message']) ? (string)$error['message'] : '';
        flow_set_knownlinks_write_error($details !== '' ? $details : 'Sem detalhes de erro.');
    }

    return false;
}

function flow_cdn_profiles_path() {
    return flow_runtime_dir() . DIRECTORY_SEPARATOR . 'dashboard-local-cdn.json';
}

function flow_default_cdn_profiles_text() {
    $example = array(
        'netflix' => array(
            'links' => array('Vlanif2982', 'Vlanif347'),
            'remote_asns' => array(2906, 40027, 55095, 394406),
            'local_asns' => array(),
            'prefixes' => array(),
            'note' => 'Ajuste links e assinaturas conforme a entrega local do cliente.',
        ),
        'facebook' => array(
            'links' => array('Vlanif2982', 'Vlanif347'),
            'remote_asns' => array(32934, 63293),
            'local_asns' => array(),
            'prefixes' => array(),
            'note' => '',
        ),
        'google' => array(
            'links' => array('Vlanif2982', 'Vlanif347'),
            'remote_asns' => array(15169, 36040, 139070),
            'local_asns' => array(),
            'prefixes' => array(),
            'note' => '',
        ),
    );
    return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function flow_read_cdn_profiles_text() {
    $path = flow_cdn_profiles_path();
    if (!is_file($path)) {
        return flow_default_cdn_profiles_text();
    }
    $content = @file_get_contents($path);
    return $content === false || trim((string)$content) === '' ? flow_default_cdn_profiles_text() : (string)$content;
}

function flow_validate_cidr_prefix($prefix) {
    $prefix = trim((string)$prefix);
    if ($prefix === '' || strpos($prefix, '/') === false) {
        return false;
    }
    list($network, $bits) = array_pad(explode('/', $prefix, 2), 2, null);
    if (!ctype_digit((string)$bits) || filter_var($network, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    $max = strpos($network, ':') !== false ? 128 : 32;
    return (int)$bits >= 0 && (int)$bits <= $max;
}

function flow_validate_cdn_profiles_text($text) {
    $decoded = json_decode((string)$text, true);
    if (!is_array($decoded)) {
        return array(false, 'JSON de CDN invalido. Revise virgulas, chaves e aspas.');
    }

    $normalized = array();
    foreach ($decoded as $provider => $profile) {
        $provider = trim((string)$provider);
        if ($provider === '' || !preg_match('/^[a-z0-9._-]+$/i', $provider)) {
            return array(false, 'Nome de provedor invalido: ' . $provider);
        }
        if (!is_array($profile)) {
            return array(false, 'O perfil ' . $provider . ' precisa ser um objeto JSON.');
        }

        $item = array(
            'links' => array(),
            'remote_asns' => array(),
            'local_asns' => array(),
            'prefixes' => array(),
            'note' => isset($profile['note']) ? trim((string)$profile['note']) : '',
        );

        foreach (array('links', 'prefixes') as $field) {
            if (isset($profile[$field]) && !is_array($profile[$field])) {
                return array(false, $field . ' em ' . $provider . ' precisa ser uma lista.');
            }
            foreach ((array)($profile[$field] ?? array()) as $value) {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }
                if ($field === 'links' && !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
                    return array(false, 'Link invalido em ' . $provider . ': ' . $value);
                }
                if ($field === 'prefixes' && !flow_validate_cidr_prefix($value)) {
                    return array(false, 'Prefixo invalido em ' . $provider . ': ' . $value);
                }
                $item[$field][] = $value;
            }
            $item[$field] = array_values(array_unique($item[$field]));
        }

        foreach (array('remote_asns', 'local_asns') as $field) {
            if (isset($profile[$field]) && !is_array($profile[$field])) {
                return array(false, $field . ' em ' . $provider . ' precisa ser uma lista.');
            }
            foreach ((array)($profile[$field] ?? array()) as $asn) {
                $asn = trim((string)$asn);
                if ($asn === '') {
                    continue;
                }
                if (!ctype_digit($asn)) {
                    return array(false, 'ASN invalido em ' . $provider . ': ' . $asn);
                }
                $item[$field][] = (int)$asn;
            }
            $item[$field] = array_values(array_unique($item[$field]));
        }

        $normalized[$provider] = $item;
    }

    return array(true, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function flow_write_cdn_profiles_text($text) {
    $dir = dirname(flow_cdn_profiles_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return @file_put_contents(flow_cdn_profiles_path(), (string)$text) !== false;
}

function flow_knownlinks_backup_dir() {
    return flow_runtime_dir() . DIRECTORY_SEPARATOR . 'knownlinks-history';
}

function flow_ensure_knownlinks_backup_dir() {
    $dir = flow_knownlinks_backup_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir;
}

function flow_backup_knownlinks_snapshot($reason = 'manual') {
    $source = flow_knownlinks_path();
    if (!is_file($source)) {
        return null;
    }

    $dir = flow_ensure_knownlinks_backup_dir();
    $reason = preg_replace('/[^a-zA-Z0-9._-]/', '-', (string)$reason);
    $reason = trim((string)$reason, '-');
    if ($reason === '') {
        $reason = 'manual';
    }

    $filename = 'knownlinks-' . date('Ymd-His') . '-' . $reason . '.bak';
    $target = $dir . DIRECTORY_SEPARATOR . $filename;

    if (@copy($source, $target)) {
        return $target;
    }

    return null;
}

function flow_list_knownlinks_backups() {
    $dir = flow_knownlinks_backup_dir();
    if (!is_dir($dir)) {
        return array();
    }

    $items = glob($dir . DIRECTORY_SEPARATOR . 'knownlinks-*.bak');
    if (!is_array($items)) {
        return array();
    }

    rsort($items, SORT_NATURAL);
    return $items;
}

function flow_restore_knownlinks_backup($basename) {
    $basename = basename((string)$basename);
    $source = flow_knownlinks_backup_dir() . DIRECTORY_SEPARATOR . $basename;
    if (!is_file($source)) {
        return array(false, 'Backup nao encontrado.');
    }

    $content = (string)file_get_contents($source);
    list($ok, $message) = flow_validate_knownlinks_text($content);
    if (!$ok) {
        return array(false, 'O backup selecionado esta invalido: ' . $message);
    }

    flow_backup_knownlinks_snapshot('pre-rollback');
    if (!flow_write_knownlinks_text($content)) {
        $details = flow_knownlinks_write_error();
        return array(false, 'Nao foi possivel gravar o knownlinks restaurado. Verifique permissoes do arquivo.' . ($details !== '' ? ' | Detalhe: ' . $details : ''));
    }
    return array(true, $basename);
}

function flow_maintenance_helper_path() {
    return '/usr/local/bin/flow-maintenance-helper.sh';
}

function flow_run_maintenance_action($action, $args = array()) {
    $helper = flow_maintenance_helper_path();
    if (!is_file($helper)) {
        return array(false, "Helper de manutencao nao encontrado em {$helper}");
    }

    if (!function_exists('exec')) {
        return array(false, 'Funcao exec() indisponivel no PHP.');
    }

    $allowed = array(
        'refresh-collection' => 0,
        'optimize-flow-db' => 0,
        'reset-collection' => 0,
        'tail-collector-log' => 0,
        'tail-extractor-log' => 0,
        'tail-apache-log' => 0,
        'validate-flow' => 0,
        'knownlinks-write' => 1,
    );
    if (!array_key_exists($action, $allowed)) {
        return array(false, 'Acao de manutencao invalida.');
    }
    if (count((array)$args) !== $allowed[$action]) {
        return array(false, 'Quantidade de argumentos invalida para a acao ' . $action . '.');
    }

    $command = 'sudo ' . escapeshellarg($helper) . ' ' . escapeshellarg($action);
    foreach ((array)$args as $arg) {
        $command .= ' ' . escapeshellarg((string)$arg);
    }
    $command .= ' 2>&1';

    $lines = array();
    $status = 0;
    exec($command, $lines, $status);
    $output = trim(implode("\n", $lines));
    if ($status !== 0) {
        return array(false, $output !== '' ? $output : 'Falha ao executar helper de manutencao.');
    }

    return array(true, $output);
}

function flow_knownlinks_entries() {
    $entries = array();
    $lines = preg_split('/\r\n|\r|\n/', flow_read_knownlinks_text());

    foreach ($lines as $lineNumber => $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $parts = explode("\t", $line);
        if (count($parts) < 6) {
            continue;
        }

        $entries[] = array(
            'line' => $lineNumber + 1,
            'exporter' => trim((string)$parts[0]),
            'ifindex' => trim((string)$parts[1]),
            'tag' => trim((string)$parts[2]),
            'description' => trim((string)$parts[3]),
            'color' => strtoupper(trim((string)$parts[4])),
            'sampling' => trim((string)$parts[5]),
        );
    }

    return $entries;
}

function flow_validate_knownlink_fields($exporter, $ifindex, $tag, $description, $color, $sampling, $existingTag = '') {
    $exporter = trim((string)$exporter);
    $ifindex = trim((string)$ifindex);
    $tag = trim((string)$tag);
    $description = preg_replace('/[\r\n\t]+/', ' ', trim((string)$description));
    $color = strtoupper(trim((string)$color));
    $sampling = trim((string)$sampling);

    if ($exporter === '') {
        return array(false, 'Informe o IP ou hostname do exportador.');
    }
    if ($ifindex === '' || !ctype_digit($ifindex)) {
        return array(false, 'O ifIndex precisa ser numerico.');
    }
    if ($tag === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $tag)) {
        return array(false, 'A TAG deve usar apenas letras, numeros, ponto, underscore ou hifen, preservando a grafia exata do RRD.');
    }
    if ($description === '') {
        return array(false, 'Informe a descricao do link.');
    }
    if (!preg_match('/^[0-9A-F]{6}$/', $color)) {
        return array(false, 'A cor precisa estar em hexadecimal de 6 caracteres, como 33A02C.');
    }
    if ($sampling === '' || !ctype_digit($sampling)) {
        return array(false, 'O sampling precisa ser numerico.');
    }

    foreach (flow_knownlinks_entries() as $entry) {
        if ($entry['tag'] === $tag && $entry['tag'] !== trim((string)$existingTag)) {
            return array(false, 'Ja existe uma TAG igual no knownlinks: ' . $tag);
        }
    }

    return array(true, array(
        'exporter' => $exporter,
        'ifindex' => $ifindex,
        'tag' => $tag,
        'description' => $description,
        'color' => $color,
        'sampling' => $sampling,
    ));
}

function flow_validate_knownlinks_text($text) {
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    $lines = preg_split('/\n/', $text);
    foreach ($lines as $index => $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $parts = explode("\t", $line);
        if (count($parts) !== 6) {
            return array(false, 'Linha ' . ($index + 1) . ' invalida. O arquivo precisa usar TAB e exatamente 6 colunas.');
        }

        list($ok, $payload) = flow_validate_knownlink_fields($parts[0], $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $parts[2]);
        if (!$ok) {
            return array(false, 'Linha ' . ($index + 1) . ': ' . $payload);
        }
    }

    return array(true, null);
}

function flow_render_audit_table($rows) {
    if (empty($rows)) {
        return flow_render_empty_state('Sem eventos recentes', 'As alteracoes administrativas e acessos relevantes passarao a aparecer aqui.');
    }

    $html = '<div class="flow-table-wrap"><table class="flow-table"><thead><tr><th>Quando</th><th>Usuario</th><th>Perfil</th><th>Acao</th><th>Alvo</th><th>Origem</th><th>Detalhes</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($row['created_at']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['username'] ?: 'sistema') . '</td>';
        $html .= '<td>' . htmlspecialchars(strtoupper($row['role'] ?: 'N/A')) . '</td>';
        $html .= '<td><span class="flow-audit-action">' . htmlspecialchars($row['action']) . '</span></td>';
        $html .= '<td>' . htmlspecialchars($row['target'] ?: '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['ip_address'] ?: '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['details'] ?: '-') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
}

function flow_render_knownlinks_backups($items) {
    if (empty($items)) {
        return flow_render_empty_state('Sem versoes salvas', 'Os snapshots do knownlinks passarao a aparecer aqui sempre que houver alteracao pelo painel.');
    }

    $html = '<div class="flow-table-wrap"><table class="flow-table"><thead><tr><th>Arquivo</th><th>Atualizado</th><th>Acoes</th></tr></thead><tbody>';
    foreach ($items as $path) {
        $basename = basename($path);
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($basename) . '</td>';
        $html .= '<td>' . htmlspecialchars(date('Y-m-d H:i:s', (int)filemtime($path))) . '</td>';
        $html .= '<td><form method="post" class="flow-inline-form">';
        $html .= '<input type="hidden" name="action" value="rollback_knownlinks">';
        $html .= '<input type="hidden" name="backup_name" value="' . htmlspecialchars($basename) . '">';
        $html .= '<button class="flow-button flow-button-ghost" type="submit">Restaurar</button>';
        $html .= '</form></td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
}

function flow_config_handle_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $currentUser = flow_auth_current_user();
    $db = flow_auth_connect();

    switch ($action) {
        case 'save_local_asn':
            $asn = trim((string)($_POST['local_asn'] ?? ''));
            if ($asn === '' || !ctype_digit($asn)) {
                flow_auth_set_flash('Informe um ASN numerico valido.', 'error');
            } else {
                flow_write_local_asn($asn);
                flow_auth_audit('config.local_asn.updated', 'ASN local ajustado para AS' . $asn, 'config.inc');
                flow_auth_set_flash('ASN local atualizado com sucesso.', 'success');
            }
            break;

        case 'save_knownlinks':
            $knownlinks = (string)($_POST['knownlinks'] ?? '');
            list($ok, $message) = flow_validate_knownlinks_text($knownlinks);
            if (!$ok) {
                flow_auth_set_flash($message, 'error');
            } else {
                flow_backup_knownlinks_snapshot('save');
                if (!flow_write_knownlinks_text($knownlinks)) {
                    $details = flow_knownlinks_write_error();
                    flow_auth_set_flash('Nao foi possivel gravar o knownlinks. Verifique permissao de escrita em ' . flow_knownlinks_path() . ($details !== '' ? ' | Detalhe: ' . $details : ''), 'error');
                } else {
                    flow_run_maintenance_action('refresh-collection');
                    flow_auth_audit('config.knownlinks.updated', 'Arquivo knownlinks salvo pelo painel', 'knownlinks');
                    flow_auth_set_flash('Arquivo knownlinks atualizado e coleta reiniciada.', 'success');
                }
            }
            break;

        case 'append_knownlink':
            list($ok, $payload) = flow_validate_knownlink_fields(
                $_POST['exporter_host'] ?? '',
                $_POST['ifindex'] ?? '',
                $_POST['tag'] ?? '',
                $_POST['description'] ?? '',
                $_POST['color'] ?? '',
                $_POST['sampling'] ?? ''
            );
            if (!$ok) {
                flow_auth_set_flash($payload, 'error');
                break;
            }

            $line = implode("\t", array(
                $payload['exporter'],
                $payload['ifindex'],
                $payload['tag'],
                $payload['description'],
                $payload['color'],
                $payload['sampling'],
            ));
            $current = rtrim(flow_read_knownlinks_text());
            $next = $current === '' ? $line : ($current . PHP_EOL . $line);
            flow_backup_knownlinks_snapshot('append');
            if (!flow_write_knownlinks_text($next)) {
                $details = flow_knownlinks_write_error();
                flow_auth_set_flash('Nao foi possivel adicionar o link. Verifique permissao de escrita em ' . flow_knownlinks_path() . ($details !== '' ? ' | Detalhe: ' . $details : ''), 'error');
            } else {
                flow_run_maintenance_action('refresh-collection');
                flow_auth_audit('config.knownlinks.appended', 'Novo link anexado ao knownlinks', $payload['tag']);
                flow_auth_set_flash('Novo link ' . $payload['tag'] . ' adicionado ao knownlinks e coleta reiniciada.', 'success');
            }
            break;

        case 'save_cdn_profiles':
            list($ok, $payload) = flow_validate_cdn_profiles_text((string)($_POST['cdn_profiles'] ?? ''));
            if (!$ok) {
                flow_auth_set_flash($payload, 'error');
            } elseif (!flow_write_cdn_profiles_text($payload)) {
                flow_auth_set_flash('Nao foi possivel gravar os perfis de CDN em ' . flow_cdn_profiles_path(), 'error');
            } else {
                flow_auth_audit('config.cdn_profiles.updated', 'Perfis de CDN local atualizados pelo painel', 'dashboard-local-cdn.json');
                flow_auth_set_flash('Perfis de CDN local atualizados com sucesso.', 'success');
            }
            break;

        case 'rollback_knownlinks':
            $backupName = (string)($_POST['backup_name'] ?? '');
            list($ok, $payload) = flow_restore_knownlinks_backup($backupName);
            if (!$ok) {
                flow_auth_set_flash($payload, 'error');
            } else {
                flow_run_maintenance_action('refresh-collection');
                flow_auth_audit('config.knownlinks.rollback', 'Rollback de versionamento executado', $payload);
                flow_auth_set_flash('Rollback aplicado com sucesso a partir de ' . $payload . '.', 'success');
            }
            break;

        case 'refresh_collection':
            list($ok, $payload) = flow_run_maintenance_action('refresh-collection');
            if (!$ok) {
                flow_auth_set_flash($payload, 'error');
            } else {
                flow_auth_audit('maintenance.refresh_collection', 'Coleta reiniciada pelo painel');
                flow_auth_set_flash('Coleta reiniciada e extrator acionado.', 'success');
            }
            break;

        case 'reset_collection':
            if (!flow_auth_has_role(array('master'))) {
                flow_auth_set_flash('Somente master pode zerar a coleta.', 'error');
                break;
            }
            list($ok, $payload) = flow_run_maintenance_action('reset-collection');
            if (!$ok) {
                flow_auth_set_flash($payload, 'error');
            } else {
                flow_auth_audit('maintenance.reset_collection', 'Reset total da coleta executado pelo painel');
                flow_auth_set_flash('Coleta zerada com sucesso. O ambiente iniciou uma base nova.', 'success');
            }
            break;

        case 'validate_flow':
            list($ok, $payload) = flow_run_maintenance_action('validate-flow');
            if (!$ok) {
                flow_auth_set_flash($payload, 'error');
            } else {
                flow_auth_audit('maintenance.validate_flow', 'Validacao de chegada de flow executada pelo painel');
                flow_auth_set_flash('Validacao de flow executada. Veja a secao de logs operacionais para o resultado.', 'success');
            }
            break;

        case 'optimize_flow_db':
            list($ok, $payload) = flow_run_maintenance_action('optimize-flow-db');
            if (!$ok) {
                flow_auth_set_flash($payload, 'error');
            } else {
                flow_auth_audit('maintenance.optimize_flow_db', 'Banco flow_events otimizado em WAL pelo painel');
                flow_auth_set_flash('Otimizacao do flow_events executada: ' . $payload, 'success');
            }
            break;

        case 'create_user':
            $username = trim((string)($_POST['username'] ?? ''));
            $role = trim((string)($_POST['role'] ?? 'read'));
            $password = (string)($_POST['password'] ?? '');

            if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
                flow_auth_set_flash('Usuario invalido. Use apenas letras, numeros, ponto, underline ou hifen.', 'error');
                break;
            }
            if (!in_array($role, flow_auth_roles(), true)) {
                flow_auth_set_flash('Perfil invalido.', 'error');
                break;
            }
            if (!flow_auth_can_manage_target($role)) {
                flow_auth_set_flash('Seu perfil nao pode criar usuarios desse nivel.', 'error');
                break;
            }
            if ($password === '') {
                flow_auth_set_flash('A senha do novo usuario nao pode ficar vazia.', 'error');
                break;
            }

            $stmt = $db->prepare('INSERT INTO users (username, role, password_hash, is_active, created_at, updated_at) VALUES (:username, :role, :password_hash, 1, datetime(\'now\'), datetime(\'now\'))');
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':role', $role, SQLITE3_TEXT);
            $stmt->bindValue(':password_hash', password_hash($password, PASSWORD_DEFAULT), SQLITE3_TEXT);
            @$stmt->execute();
            if ($db->lastErrorCode() !== 0) {
                flow_auth_set_flash('Nao foi possivel criar o usuario: ' . $db->lastErrorMsg(), 'error');
            } else {
                flow_auth_audit('auth.user.created', 'Novo usuario provisionado via painel', $username);
                flow_auth_set_flash('Usuario criado com sucesso.', 'success');
            }
            break;

        case 'reset_password':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = (string)($_POST['new_password'] ?? '');
            $target = null;
            foreach (flow_auth_all_users() as $userRow) {
                if ((int)$userRow['id'] === $userId) {
                    $target = $userRow;
                    break;
                }
            }
            if (!$target || !flow_auth_can_manage_target($target['role'])) {
                flow_auth_set_flash('Voce nao pode alterar a senha desse usuario.', 'error');
                break;
            }
            if ($newPassword === '') {
                flow_auth_set_flash('A nova senha nao pode ficar vazia.', 'error');
                break;
            }
            $stmt = $db->prepare('UPDATE users SET password_hash = :password_hash, updated_at = datetime(\'now\') WHERE id = :id');
            $stmt->bindValue(':password_hash', password_hash($newPassword, PASSWORD_DEFAULT), SQLITE3_TEXT);
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();
            flow_auth_audit('auth.user.password_reset', 'Senha redefinida por operador autenticado', $target['username']);
            flow_auth_set_flash('Senha atualizada com sucesso.', 'success');
            break;

        case 'toggle_user':
            $userId = (int)($_POST['user_id'] ?? 0);
            $target = null;
            foreach (flow_auth_all_users() as $userRow) {
                if ((int)$userRow['id'] === $userId) {
                    $target = $userRow;
                    break;
                }
            }
            if (!$target || !flow_auth_can_manage_target($target['role'])) {
                flow_auth_set_flash('Voce nao pode alterar esse usuario.', 'error');
                break;
            }
            if ($currentUser && $currentUser['username'] === $target['username']) {
                flow_auth_set_flash('Voce nao pode desativar sua propria conta nesta tela.', 'error');
                break;
            }
            $nextStatus = ((int)$target['is_active'] === 1) ? 0 : 1;
            $stmt = $db->prepare('UPDATE users SET is_active = :is_active, updated_at = datetime(\'now\') WHERE id = :id');
            $stmt->bindValue(':is_active', $nextStatus, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();
            flow_auth_audit('auth.user.status_changed', $nextStatus === 1 ? 'Conta reativada' : 'Conta desativada', $target['username']);
            flow_auth_set_flash('Status do usuario atualizado.', 'success');
            break;
    }

    header('Location: config.php');
    exit;
}

function flow_render_flash_message() {
    $flash = flow_auth_take_flash();
    if (!$flash) {
        return '';
    }

    $class = ($flash['type'] === 'error') ? 'flow-inline-alert' : 'flow-inline-success';
    return '<div class="' . $class . '">' . htmlspecialchars($flash['message']) . '</div>';
}

flow_config_handle_post();

$flashHtml = flow_render_flash_message();
$localAsn = flow_read_local_asn();
$cdnProfilesText = flow_read_cdn_profiles_text();
$knownlinksText = flow_read_knownlinks_text();
$knownlinksEntries = flow_knownlinks_entries();
$knownlinksBackups = array_slice(flow_list_knownlinks_backups(), 0, 20);
$users = flow_auth_all_users();
$auditRows = flow_auth_recent_audit_logs(40);
$selectedLog = isset($_GET['log']) ? trim((string)$_GET['log']) : 'collector';
$logActions = array(
    'collector' => 'tail-collector-log',
    'extractor' => 'tail-extractor-log',
    'apache' => 'tail-apache-log',
    'flowcheck' => 'validate-flow',
);
$logOutput = '';
if (isset($logActions[$selectedLog])) {
    list($logOk, $logPayload) = flow_run_maintenance_action($logActions[$selectedLog]);
    $logOutput = $logPayload;
}
$heroStats = array(
    array('label' => 'Perfil', 'value' => strtoupper(flow_auth_current_user()['role'])),
    array('label' => 'Usuarios', 'value' => count($users)),
    array('label' => 'ASN local', 'value' => $localAsn !== '' ? 'AS' . $localAsn : 'nao definido'),
    array('label' => 'Links', 'value' => count($knownlinksEntries)),
);

$settingsBody = $flashHtml;
$settingsBody .= '<form method="post" class="flow-form-stack">';
$settingsBody .= '<input type="hidden" name="action" value="save_local_asn">';
$settingsBody .= '<label>ASN local do cliente</label>';
$settingsBody .= '<input class="flow-input" type="text" name="local_asn" value="' . htmlspecialchars($localAsn) . '" placeholder="Ex.: 268840">';
$settingsBody .= '<button class="flow-button" type="submit">Salvar configuracao</button>';
$settingsBody .= '</form>';

$knownlinksBody = '<form method="post" class="flow-form-stack">';
$knownlinksBody .= '<input type="hidden" name="action" value="save_knownlinks">';
$knownlinksBody .= '<label>Arquivo knownlinks</label>';
$knownlinksBody .= '<textarea class="flow-input flow-textarea" name="knownlinks">' . htmlspecialchars($knownlinksText) . '</textarea>';
$knownlinksBody .= '<button class="flow-button" type="submit">Salvar knownlinks</button>';
$knownlinksBody .= '<span class="flow-search-hint">Use TAB entre as 6 colunas. A tela agora valida exporter, ifIndex, TAG, descricao, cor e sampling antes de salvar.</span>';
$knownlinksBody .= '</form>';

$cdnProfilesBody = '<form method="post" class="flow-form-stack">';
$cdnProfilesBody .= '<input type="hidden" name="action" value="save_cdn_profiles">';
$cdnProfilesBody .= '<label>Perfis de CDN local por cliente</label>';
$cdnProfilesBody .= '<textarea class="flow-input flow-textarea" name="cdn_profiles">' . htmlspecialchars($cdnProfilesText) . '</textarea>';
$cdnProfilesBody .= '<button class="flow-button" type="submit">Salvar perfis de CDN</button>';
$cdnProfilesBody .= '<span class="flow-search-hint">Use links para limitar a VLAN/interface de CDN compartilhado. Use remote_asns ou prefixes para atribuir parte do trafego a Netflix, Meta, Google etc. O restante aparece como CDN compartilhado nao classificado.</span>';
$cdnProfilesBody .= '</form>';

$appendKnownlinkBody = '<form method="post" class="flow-form-stack">';
$appendKnownlinkBody .= '<input type="hidden" name="action" value="append_knownlink">';
$appendKnownlinkBody .= '<label>Novo roteador / link no knownlinks</label>';
$appendKnownlinkBody .= '<div class="flow-inline-form">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="exporter_host" placeholder="IP ou hostname do exportador">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="ifindex" placeholder="ifIndex">';
$appendKnownlinkBody .= '</div>';
$appendKnownlinkBody .= '<div class="flow-inline-form">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="tag" placeholder="TAG ex.: VLANIF2984">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="description" placeholder="Descricao do link">';
$appendKnownlinkBody .= '</div>';
$appendKnownlinkBody .= '<div class="flow-inline-form">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="color" placeholder="Cor HEX ex.: 33A02C">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="sampling" value="128" placeholder="Sampling">';
$appendKnownlinkBody .= '<button class="flow-button" type="submit">Adicionar ao knownlinks</button>';
$appendKnownlinkBody .= '</div>';
$appendKnownlinkBody .= '<span class="flow-search-hint">Esse formulario monta a linha com TAB automaticamente e evita duplicidade de TAG. A TAG e sensivel a maiusculas/minusculas porque precisa bater com a serie do RRD.</span>';
$appendKnownlinkBody .= '</form>';

if (!empty($knownlinksEntries)) {
    $appendKnownlinkBody .= '<div class="flow-table-wrap"><table class="flow-table"><thead><tr><th>Exportador</th><th>IfIndex</th><th>TAG</th><th>Descricao</th><th>Cor</th><th>Sampling</th></tr></thead><tbody>';
    foreach ($knownlinksEntries as $entry) {
        $appendKnownlinkBody .= '<tr>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['exporter']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['ifindex']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['tag']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['description']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['color']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['sampling']) . '</td>';
        $appendKnownlinkBody .= '</tr>';
    }
    $appendKnownlinkBody .= '</tbody></table></div>';
}

$usersBody = '<div class="flow-user-admin">';
$usersBody .= '<div class="flow-table-wrap"><table class="flow-table"><thead><tr><th>Usuario</th><th>Perfil</th><th>Status</th><th>Ultimo login</th><th>Acoes</th></tr></thead><tbody>';
foreach ($users as $userRow) {
    $usersBody .= '<tr>';
    $usersBody .= '<td>' . htmlspecialchars($userRow['username']) . '</td>';
    $usersBody .= '<td>' . htmlspecialchars(strtoupper($userRow['role'])) . '</td>';
    $usersBody .= '<td>' . ((int)$userRow['is_active'] === 1 ? 'ativo' : 'inativo') . '</td>';
    $usersBody .= '<td>' . htmlspecialchars($userRow['last_login_at'] ?: 'nunca') . '</td>';
    $usersBody .= '<td class="flow-user-actions">';
    if (flow_auth_can_manage_target($userRow['role'])) {
        $usersBody .= '<form method="post" class="flow-inline-form flow-user-form">';
        $usersBody .= '<input type="hidden" name="action" value="reset_password">';
        $usersBody .= '<input type="hidden" name="user_id" value="' . (int)$userRow['id'] . '">';
        $usersBody .= '<input class="flow-input" type="password" name="new_password" placeholder="Nova senha">';
        $usersBody .= '<button class="flow-button flow-button-ghost" type="submit">Trocar senha</button>';
        $usersBody .= '</form>';
        $usersBody .= '<form method="post" class="flow-inline-form flow-user-form">';
        $usersBody .= '<input type="hidden" name="action" value="toggle_user">';
        $usersBody .= '<input type="hidden" name="user_id" value="' . (int)$userRow['id'] . '">';
        $usersBody .= '<button class="flow-button flow-button-ghost" type="submit">' . ((int)$userRow['is_active'] === 1 ? 'Desativar' : 'Ativar') . '</button>';
        $usersBody .= '</form>';
    } else {
        $usersBody .= '<span class="flow-search-hint">Gerenciado apenas por master</span>';
    }
    $usersBody .= '</td></tr>';
}
$usersBody .= '</tbody></table></div>';

$usersBody .= '<form method="post" class="flow-form-stack flow-user-create">';
$usersBody .= '<input type="hidden" name="action" value="create_user">';
$usersBody .= '<label>Novo usuario</label>';
$usersBody .= '<div class="flow-inline-form">';
$usersBody .= '<input class="flow-input" type="text" name="username" placeholder="usuario">';
$usersBody .= '<select class="flow-input" name="role">';
foreach (flow_auth_roles() as $role) {
    if (!flow_auth_can_manage_target($role)) {
        continue;
    }
    $usersBody .= '<option value="' . htmlspecialchars($role) . '">' . htmlspecialchars(strtoupper($role)) . '</option>';
}
$usersBody .= '</select>';
$usersBody .= '<input class="flow-input" type="password" name="password" placeholder="senha inicial">';
$usersBody .= '<button class="flow-button" type="submit">Criar usuario</button>';
$usersBody .= '</div>';
$usersBody .= '</form>';
$usersBody .= '</div>';

$auditBody = flow_render_audit_table($auditRows);
$backupBody = flow_render_knownlinks_backups($knownlinksBackups);

$maintenanceBody = '<div class="flow-form-stack">';
$maintenanceBody .= '<form method="post" class="flow-inline-form">';
$maintenanceBody .= '<input type="hidden" name="action" value="refresh_collection">';
$maintenanceBody .= '<button class="flow-button" type="submit">Reiniciar coleta</button>';
$maintenanceBody .= '<span class="flow-search-hint">Reinicia o coletor e aciona o extrator.</span>';
$maintenanceBody .= '</form>';
$maintenanceBody .= '<form method="post" class="flow-inline-form">';
$maintenanceBody .= '<input type="hidden" name="action" value="validate_flow">';
$maintenanceBody .= '<button class="flow-button flow-button-ghost" type="submit">Validar chegada de flow</button>';
$maintenanceBody .= '<span class="flow-search-hint">Executa checagem de portas, servico e captura rapida de pacotes UDP.</span>';
$maintenanceBody .= '</form>';
$maintenanceBody .= '<form method="post" class="flow-inline-form">';
$maintenanceBody .= '<input type="hidden" name="action" value="optimize_flow_db">';
$maintenanceBody .= '<button class="flow-button flow-button-ghost" type="submit">Otimizar flow_events</button>';
$maintenanceBody .= '<span class="flow-search-hint">Ativa WAL, cria indices e faz ANALYZE para reduzir lock/lentidao.</span>';
$maintenanceBody .= '</form>';
if (flow_auth_has_role(array('master'))) {
    $maintenanceBody .= '<form method="post" class="flow-inline-form">';
    $maintenanceBody .= '<input type="hidden" name="action" value="reset_collection">';
    $maintenanceBody .= '<button class="flow-button flow-button-danger" type="submit" onclick="return confirm(\'Isso vai apagar o historico atual. Deseja continuar?\');">Zerar coleta</button>';
    $maintenanceBody .= '<span class="flow-search-hint">Apaga RRD, asstats_day e flow_events para iniciar uma base nova.</span>';
    $maintenanceBody .= '</form>';
}
$maintenanceBody .= '</div>';

$logBody = '<div class="flow-form-stack">';
$logBody .= '<div class="flow-inline-form">';
$logBody .= '<a class="flow-button flow-button-ghost" href="config.php?log=collector">Log do coletor</a>';
$logBody .= '<a class="flow-button flow-button-ghost" href="config.php?log=extractor">Log do extrator</a>';
$logBody .= '<a class="flow-button flow-button-ghost" href="config.php?log=apache">Log do Apache</a>';
$logBody .= '<a class="flow-button flow-button-ghost" href="config.php?log=flowcheck">Diagnostico de flow</a>';
$logBody .= '</div>';
$logBody .= '<pre class="flow-log-console">' . htmlspecialchars($logOutput !== '' ? $logOutput : 'Nenhum log disponivel no momento.') . '</pre>';
$logBody .= '</div>';

flow_render_shell_start('Flow | Config', 'config');
echo flow_render_hero('config center', 'Administracao do Flow', 'Gerencie autenticacao, configuracoes locais e o arquivo knownlinks da plataforma.', $heroStats);
echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Parametros locais', $settingsBody, 'fa-sliders');
echo flow_render_panel('Controle de acesso', $usersBody, 'fa-lock');
echo flow_render_panel('Trilha de auditoria', $auditBody, 'fa-shield');
echo '</div>';
echo '<div class="flow-stack">';
echo flow_render_panel('Manutencao controlada', $maintenanceBody, 'fa-wrench');
echo flow_render_panel('Logs operacionais', $logBody, 'fa-file-text-o');
echo flow_render_panel('Rollback de knownlinks', $backupBody, 'fa-history');
echo flow_render_panel('Adicionar roteador / link', $appendKnownlinkBody, 'fa-plus-circle');
echo flow_render_panel('Perfis de CDN local', $cdnProfilesBody, 'fa-cloud');
echo flow_render_panel('Editor de knownlinks', $knownlinksBody, 'fa-code');
echo '</div>';
echo '</div>';
flow_render_shell_end();
