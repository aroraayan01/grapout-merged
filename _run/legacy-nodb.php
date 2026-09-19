<?php
/**
 * A database that answers "nothing", so the legacy site renders locally.
 *
 * grapout.com's PHP connects to a live MySQL server in `isrntclpuydeys/`, with
 * the host and password written into the file. We are not pointing a local
 * preview at the production database — so instead the mysqli extension is left
 * switched off and these stand-ins are defined in plain PHP before any page
 * runs (php -d auto_prepend_file).
 *
 * Every connection succeeds, every query returns an empty result. Pages whose
 * content is written in the template — the consulting page, Contact Us, the
 * header and footer — come out exactly as they will live. Pages that list rows
 * from the database come out empty, which is the honest answer: there is no
 * database here.
 *
 * Nothing in this file writes anywhere. It cannot touch production even by
 * accident, because it never opens a socket.
 */

if (extension_loaded('mysqli')) {
    return; // the real thing is present; leave it alone
}

/*
 * The extension's constants come with the extension, so they have to be
 * declared here too — the site passes MYSQLI_ASSOC to fetch_array in 445
 * places, and an undefined constant is a fatal error, not a notice.
 */
foreach ([
    'MYSQLI_ASSOC' => 1, 'MYSQLI_NUM' => 2, 'MYSQLI_BOTH' => 3,
    'MYSQLI_STORE_RESULT' => 0, 'MYSQLI_USE_RESULT' => 1, 'MYSQLI_ASYNC' => 8,
    'MYSQLI_REPORT_OFF' => 0, 'MYSQLI_REPORT_ERROR' => 1, 'MYSQLI_REPORT_STRICT' => 2,
    'MYSQLI_REPORT_INDEX' => 4, 'MYSQLI_REPORT_ALL' => 255,
    'MYSQLI_ASSOC_MODE' => 1,
] as $name => $value) {
    if (! defined($name)) {
        define($name, $value);
    }
}

class mysqli_sql_exception extends RuntimeException {}

class mysqli_result implements IteratorAggregate
{
    public $num_rows = 0;
    public $field_count = 0;
    public $lengths = [];

    public function fetch_assoc() { return null; }
    public function fetch_array($mode = null) { return null; }
    public function fetch_row() { return null; }
    public function fetch_object($class = null, $args = []) { return null; }
    public function fetch_all($mode = null) { return []; }
    public function fetch_field() { return null; }
    public function fetch_fields() { return []; }
    public function data_seek($offset) { return true; }
    public function free() {}
    public function close() {}
    public function free_result() {}
    public function getIterator(): Iterator { return new ArrayIterator([]); }
}

class mysqli_stmt
{
    public $affected_rows = 0;
    public $insert_id = 0;
    public $num_rows = 0;
    public $error = '';
    public $errno = 0;

    public function bind_param(...$a) { return true; }
    public function bind_result(&...$a) { return true; }
    public function execute($params = null) { return true; }
    public function get_result() { return new mysqli_result(); }
    public function store_result() { return true; }
    public function fetch() { return null; }
    public function close() { return true; }
    public function free_result() {}
}

class mysqli
{
    public $connect_error = null;
    public $connect_errno = 0;
    public $error = '';
    public $errno = 0;
    public $insert_id = 0;
    public $affected_rows = 0;
    public $sqlstate = '00000';
    public $host_info = 'local preview (no database)';
    public $server_info = '0.0.0-preview';

    public function __construct(...$args) {}

    public function set_charset($charset) { return true; }
    public function query($sql, $mode = null) { return new mysqli_result(); }
    public function multi_query($sql) { return true; }
    public function real_query($sql) { return true; }
    public function store_result() { return new mysqli_result(); }
    public function use_result() { return new mysqli_result(); }
    public function more_results() { return false; }
    public function next_result() { return false; }
    public function prepare($sql) { return new mysqli_stmt(); }
    public function real_escape_string($s) { return addslashes((string) $s); }
    public function escape_string($s) { return addslashes((string) $s); }
    public function autocommit($mode) { return true; }
    public function begin_transaction($flags = 0, $name = null) { return true; }
    public function commit($flags = 0, $name = null) { return true; }
    public function rollback($flags = 0, $name = null) { return true; }
    public function ping() { return true; }
    public function select_db($db) { return true; }
    public function close() { return true; }
    public function stat() { return 'preview'; }
    public function get_charset() { return null; }
    public function thread_id() { return 0; }
}

function mysqli_connect(...$a) { return new mysqli(); }
function mysqli_connect_error() { return null; }
function mysqli_connect_errno() { return 0; }
function mysqli_select_db($c, $db) { return true; }
function mysqli_set_charset($c, $charset) { return true; }
function mysqli_query($c, $sql, $mode = null) { return new mysqli_result(); }
function mysqli_prepare($c, $sql) { return new mysqli_stmt(); }
function mysqli_stmt_bind_param(...$a) { return true; }
function mysqli_stmt_execute(...$a) { return true; }
function mysqli_stmt_get_result(...$a) { return new mysqli_result(); }
function mysqli_stmt_close(...$a) { return true; }
function mysqli_fetch_assoc($r) { return null; }
function mysqli_fetch_array($r, $mode = null) { return null; }
function mysqli_fetch_row($r) { return null; }
function mysqli_fetch_object($r, ...$a) { return null; }
function mysqli_fetch_all($r, $mode = null) { return []; }
function mysqli_num_rows($r) { return 0; }
function mysqli_num_fields($r) { return 0; }
function mysqli_affected_rows($c) { return 0; }
function mysqli_insert_id($c) { return 0; }
function mysqli_real_escape_string($c, $s) { return addslashes((string) $s); }
function mysqli_escape_string($c, $s) { return addslashes((string) $s); }
function mysqli_error($c) { return ''; }
function mysqli_errno($c) { return 0; }
function mysqli_free_result($r) {}
function mysqli_close($c) { return true; }
function mysqli_data_seek($r, $o) { return true; }
function mysqli_ping($c) { return true; }
function mysqli_autocommit($c, $m) { return true; }
function mysqli_begin_transaction($c, ...$a) { return true; }
function mysqli_commit($c, ...$a) { return true; }
function mysqli_rollback($c, ...$a) { return true; }
function mysqli_report($flags) { return true; }
