<?php

function flow_env_defaults_path() {
    return '/etc/default/asstats';
}

function flow_env_setting($key, $default = '') {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    $path = flow_env_defaults_path();
    if (!is_readable($path)) {
        return $default;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $default;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        list($name, $rawValue) = explode('=', $line, 2);
        if (trim($name) !== $key) {
            continue;
        }
        $rawValue = trim($rawValue);
        $rawValue = trim($rawValue, "\"'");
        return $rawValue;
    }

    return $default;
}

function flow_events_backend() {
    $backend = strtolower(trim((string)flow_env_setting('ASSTATS_FLOW_BACKEND', 'sqlite')));
    return $backend === 'pgsql' ? 'pgsql' : 'sqlite';
}

function flow_events_is_pgsql() {
    return flow_events_backend() === 'pgsql';
}

function flow_events_db_label() {
    if (flow_events_is_pgsql()) {
        return 'PostgreSQL';
    }
    return 'flow_events.db';
}

function flow_events_available() {
    if (flow_events_is_pgsql()) {
        return extension_loaded('pdo_pgsql');
    }
    return is_file(flow_events_db_path());
}

function flow_events_pdo_dsn() {
    $dsn = flow_env_setting('ASSTATS_FLOW_PDO_DSN', '');
    if ($dsn !== '') {
        return $dsn;
    }

    $host = flow_env_setting('ASSTATS_FLOW_DB_HOST', '127.0.0.1');
    $port = flow_env_setting('ASSTATS_FLOW_DB_PORT', '5432');
    $dbname = flow_env_setting('ASSTATS_FLOW_DB_NAME', 'flow_observatory');
    return 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname;
}

function flow_events_open_connection(&$error = null) {
    $error = null;
    if (flow_events_is_pgsql()) {
        if (!extension_loaded('pdo_pgsql')) {
            $error = 'Modulo PHP pdo_pgsql nao esta carregado.';
            return null;
        }
        try {
            $pdo = new PDO(
                flow_events_pdo_dsn(),
                flow_env_setting('ASSTATS_FLOW_USER', 'flow'),
                flow_env_setting('ASSTATS_FLOW_PASSWORD', ''),
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 2,
                    PDO::ATTR_EMULATE_PREPARES => true,
                )
            );
            return new FlowPgDb($pdo);
        } catch (Exception $exception) {
            $error = 'Nao foi possivel conectar no PostgreSQL da telemetria.';
            return null;
        }
    }

    $dbPath = flow_events_db_path();
    if (!is_file($dbPath)) {
        $error = 'A base flow_events.db ainda nao existe neste ambiente.';
        return null;
    }

    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(1000);
        @$db->exec('PRAGMA busy_timeout = 1000');
        @$db->exec('PRAGMA query_only = ON');
        return $db;
    } catch (Exception $exception) {
        $error = 'Nao foi possivel abrir a base SQLite de eventos.';
        return null;
    }
}

function flow_events_has_table($db, $table) {
    if ($db instanceof FlowPgDb) {
        return $db->hasTable($table);
    }
    if ($db instanceof SQLite3) {
        $exists = @$db->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . SQLite3::escapeString($table) . "' LIMIT 1");
        return $exists === $table;
    }
    return false;
}

class FlowPgDb {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function prepare($sql) {
        try {
            return new FlowPgStmt($this->pdo->prepare($this->translateSql($sql)));
        } catch (Exception $exception) {
            return false;
        }
    }

    public function querySingle($sql, $entireRow = false) {
        try {
            $stmt = $this->pdo->query($this->translateSql($sql));
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$row) {
                return $entireRow ? array() : null;
            }
            return $entireRow ? $row : reset($row);
        } catch (Exception $exception) {
            return $entireRow ? array() : null;
        }
    }

    public function exec($sql) {
        try {
            $this->pdo->exec($this->translateSql($sql));
            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    public function close() {
        $this->pdo = null;
    }

    public function createFunction($name, $callback, $argc = null) {
        return $name === 'flow_ip_filter_match';
    }

    public function hasTable($table) {
        try {
            $stmt = $this->pdo->prepare("SELECT to_regclass(:table_name) AS table_name");
            $stmt->execute(array(':table_name' => $table));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return !empty($row['table_name']);
        } catch (Exception $exception) {
            return false;
        }
    }

    private function translateSql($sql) {
        $sql = str_replace('flow_ip_filter_match(src_ip, :ip_filter) = 1', '(src_ip::inet <<= CAST(:ip_filter AS cidr))', $sql);
        $sql = str_replace('flow_ip_filter_match(dst_ip, :ip_filter) = 1', '(dst_ip::inet <<= CAST(:ip_filter AS cidr))', $sql);
        $sql = str_replace('flow_dashboard_ip_match(src_ip, ', 'flow_pg_ip_match(src_ip, ', $sql);
        $sql = str_replace('flow_dashboard_ip_match(dst_ip, ', 'flow_pg_ip_match(dst_ip, ', $sql);
        $sql = preg_replace('/flow_pg_ip_match\((src_ip|dst_ip),\s*(:[A-Za-z0-9_]+)\)\s*=\s*1/', '($1::inet <<= CAST($2 AS cidr))', $sql);
        return $sql;
    }
}

class FlowPgStmt {
    private $stmt;
    private $values = array();

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function bindValue($name, $value, $type = null) {
        $this->values[$name] = $value;
        return true;
    }

    public function execute() {
        try {
            $this->stmt->execute($this->values);
            return new FlowPgResult($this->stmt);
        } catch (Exception $exception) {
            return false;
        }
    }
}

class FlowPgResult {
    private $stmt;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function fetchArray($mode = null) {
        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: false;
    }
}
