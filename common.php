<?php
declare(strict_types=1);

/*
 * common.php
 * Safe Oracle access and data retrieval for Open TO.
 *
 * - Returns structured arrays (not HTML) so the UI can render clean markup.
 * - Uses bind variables and proper resource cleanup.
 * - Requires OCI8 extension.
 */

/** Configuration */
const DB_USER = 'mm';
const DB_PASS = 'mm123';
const DB_CONN = 'reports';
const CREATED_DATE_THRESHOLD = '2026-01-01'; // ISO date used as bind value
const DEFAULT_FETCH_MODE = OCI_ASSOC;

/** Priority mapping */
const PRIORITY_TARGET_HOURS = [
    'AOG' => 4,
    'WSP' => 8,
];

/**
 * Connect to Oracle DB and return connection resource.
 *
 * @return resource
 * @throws RuntimeException
 */
function connect_db()
{
    $conn = @oci_connect(DB_USER, DB_PASS, DB_CONN);
    if ($conn === false) {
        $err = oci_error();
        throw new RuntimeException('Database connection failed: ' . ($err['message'] ?? 'unknown'));
    }
    return $conn;
}

/**
 * Close Oracle connection.
 *
 * @param resource|null $conn
 * @return void
 */
function close_db(&$conn): void
{
    if ($conn) {
        @oci_close($conn);
        $conn = null;
    }
}

/**
 * Execute a query with optional binds and return statement resource.
 * Caller must free statement with oci_free_statement.
 *
 * @param resource $conn
 * @param string $sql
 * @param array $binds  associative array ':name' => value
 * @return resource
 * @throws RuntimeException
 */
function execute_query($conn, string $sql, array $binds = [])
{
    $stid = oci_parse($conn, $sql);
    if ($stid === false) {
        $err = oci_error($conn);
        throw new RuntimeException('Failed to parse SQL: ' . ($err['message'] ?? 'unknown'));
    }

    foreach ($binds as $name => $value) {
        $bindName = ltrim($name, ':');
        if (!oci_bind_by_name($stid, ':' . $bindName, $binds[$name], -1)) {
            $err = oci_error($stid);
            throw new RuntimeException('Failed to bind ' . $bindName . ': ' . ($err['message'] ?? 'unknown'));
        }
    }

    $ok = oci_execute($stid, OCI_DEFAULT);
    if ($ok === false) {
        $err = oci_error($stid);
        throw new RuntimeException('Failed to execute SQL: ' . ($err['message'] ?? 'unknown'));
    }

    return $stid;
}

/**
 * Build an IN clause with bind variables and return clause string and binds.
 *
 * @param string $field
 * @param array $values
 * @param string $prefix
 * @return array [string $clause, array $binds]
 */
function build_in_clause(string $field, array $values, string $prefix = 'b'): array
{
    $values = array_values(array_filter($values, fn($v) => $v !== '' && $v !== null));
    if (count($values) === 0) {
        return ['', []];
    }
    $binds = [];
    $placeholders = [];
    foreach ($values as $i => $val) {
        $key = ':' . $prefix . $i;
        $placeholders[] = $key;
        $binds[$key] = $val;
    }
    $clause = sprintf('AND %s IN (%s)', $field, implode(',', $placeholders));
    return [$clause, $binds];
}

/**
 * Map LOCATION numeric code to array of location codes.
 *
 * @param int|string $location
 * @return array
 */
function get_location_codes($location): array
{
    $map = [
        1 => ['COMPOWH', 'COMPOWH-Q'],
        2 => ['AUTOMATED'],
        3 => ['MSU'],
        4 => ['INFLAM'],
        5 => ['RAWMAT'],
        10 => ['AUTOMATED', 'MSU', 'INFLAM', 'RAWMAT'],
    ];

    $key = is_numeric($location) ? (int)$location : 0;
    return $map[$key] ?? [];
}

/**
 * Fetch summary counts grouped by priority.
 *
 * @param int|string $LOCATION
 * @return array ['AOG'=>int,'WSP'=>int,'OTHERS'=>int]
 */
function fetch_summary_data($LOCATION = 0): array
{
    $conn = null;
    $stid = null;
    try {
        $conn = connect_db();

        $locCodes = get_location_codes($LOCATION);
        [$locClause, $locBinds] = build_in_clause('REQUESTER_LOCATION', $locCodes, 'loc');

        $subSql = "
            SELECT ORDER_HEADER.ORDER_NUMBER
            FROM ORDER_HEADER
            INNER JOIN ORDER_DETAIL ON ORDER_HEADER.ORDER_NUMBER = ORDER_DETAIL.ORDER_NUMBER
            WHERE ORDER_HEADER.ORDER_TYPE = 'TO'
              AND ORDER_HEADER.CREATED_DATE >= TO_DATE(:created_threshold, 'YYYY-MM-DD')
              AND ORDER_HEADER.STATUS = 'OPEN'
              {$locClause}
            GROUP BY ORDER_HEADER.ORDER_NUMBER
        ";

        $sql = "
            SELECT PRIORITY, COUNT(*) AS OPENCOUNT
            FROM (
                SELECT oh.PRIORITY
                FROM ({$subSql}) TO_LIST
                LEFT JOIN ORDER_HEADER oh ON oh.ORDER_NUMBER = TO_LIST.ORDER_NUMBER
            )
            GROUP BY PRIORITY
        ";

        $binds = array_merge([':created_threshold' => CREATED_DATE_THRESHOLD], $locBinds);

        $stid = execute_query($conn, $sql, $binds);

        $counts = ['AOG' => 0, 'WSP' => 0, 'OTHERS' => 0];
        while (($row = oci_fetch_array($stid, DEFAULT_FETCH_MODE)) !== false) {
            $priority = $row['PRIORITY'] ?? '';
            $count = (int)($row['OPENCOUNT'] ?? 0);
            if ($priority === 'AOG') {
                $counts['AOG'] = $count;
            } elseif ($priority === 'WSP') {
                $counts['WSP'] = $count;
            } else {
                $counts['OTHERS'] += $count;
            }
        }

        oci_free_statement($stid);
        $stid = null;
        close_db($conn);
        $conn = null;

        return $counts;
    } catch (Throwable $e) {
        if ($stid) @oci_free_statement($stid);
        if ($conn) close_db($conn);
        error_log('fetch_summary_data error: ' . $e->getMessage());
        return ['AOG' => 0, 'WSP' => 0, 'OTHERS' => 0];
    }
}

/**
 * Fetch detailed open TO rows as associative arrays.
 *
 * @param int|string $LOCATION
 * @return array
 */
function fetch_open_to_rows($LOCATION = 0): array
{
    $conn = null;
    $stid = null;
    try {
        $conn = connect_db();

        $locCodes = get_location_codes($LOCATION);
        [$locClause, $locBinds] = build_in_clause('REQUESTER_LOCATION', $locCodes, 'loc');

        $sql = "
            SELECT
                TO_CHAR(oh.CREATED_DATE, 'YYYY-MM-DD HH24:MI:SS') AS TO_DATE,
                ROUND((SYSDATE - oh.CREATED_DATE) * 24) AS RUNNING_HOURS,
                CASE WHEN oh.PRIORITY = 'AOG' THEN :aog_hours WHEN oh.PRIORITY = 'WSP' THEN :wsp_hours ELSE :default_hours END AS TARGET_HOURS,
                (CASE WHEN oh.PRIORITY = 'AOG' THEN :aog_hours WHEN oh.PRIORITY = 'WSP' THEN :wsp_hours ELSE :default_hours END) - ROUND((SYSDATE - oh.CREATED_DATE) * 24) AS REMAINING_HOURS,
                TO_CHAR(oh.CREATED_DATE + (CASE WHEN oh.PRIORITY = 'AOG' THEN :aog_hours WHEN oh.PRIORITY = 'WSP' THEN :wsp_hours ELSE :default_hours END)/24, 'YYYY-MM-DD HH24:MI:SS') AS TARGET_DATE,
                oh.ORDER_NUMBER,
                oh.PRIORITY,
                oh.SHIPPED_FROM_LOCATION,
                oh.REQUESTER_LOCATION
            FROM (
                SELECT ORDER_HEADER.ORDER_NUMBER
                FROM ORDER_HEADER
                INNER JOIN ORDER_DETAIL ON ORDER_HEADER.ORDER_NUMBER = ORDER_DETAIL.ORDER_NUMBER
                WHERE ORDER_HEADER.ORDER_TYPE = 'TO'
                  AND ORDER_HEADER.CREATED_DATE >= TO_DATE(:created_threshold, 'YYYY-MM-DD')
                  AND ORDER_HEADER.STATUS = 'OPEN'
                  {$locClause}
                GROUP BY ORDER_HEADER.ORDER_NUMBER
            ) TO_LIST
            LEFT JOIN ORDER_HEADER oh ON oh.ORDER_NUMBER = TO_LIST.ORDER_NUMBER
            ORDER BY REMAINING_HOURS ASC
        ";

        $binds = array_merge(
            [
                ':created_threshold' => CREATED_DATE_THRESHOLD,
                ':aog_hours' => PRIORITY_TARGET_HOURS['AOG'],
                ':wsp_hours' => PRIORITY_TARGET_HOURS['WSP'],
                ':default_hours' => 72,
            ],
            $locBinds
        );

        $stid = execute_query($conn, $sql, $binds);

        $rows = [];
        while (($row = oci_fetch_array($stid, DEFAULT_FETCH_MODE)) !== false) {
            // Normalize keys to simple strings
            $rows[] = [
                'TO_DATE' => $row['TO_DATE'] ?? '',
                'RUNNING_HOURS' => $row['RUNNING_HOURS'] ?? '',
                'TARGET_HOURS' => $row['TARGET_HOURS'] ?? '',
                'REMAINING_HOURS' => $row['REMAINING_HOURS'] ?? '',
                'TARGET_DATE' => $row['TARGET_DATE'] ?? '',
                'ORDER_NUMBER' => $row['ORDER_NUMBER'] ?? '',
                'PRIORITY' => $row['PRIORITY'] ?? '',
                'SHIPPED_FROM_LOCATION' => $row['SHIPPED_FROM_LOCATION'] ?? '',
                'REQUESTER_LOCATION' => $row['REQUESTER_LOCATION'] ?? '',
            ];
        }

        oci_free_statement($stid);
        $stid = null;
        close_db($conn);
        $conn = null;

        return $rows;
    } catch (Throwable $e) {
        if ($stid) @oci_free_statement($stid);
        if ($conn) close_db($conn);
        error_log('fetch_open_to_rows error: ' . $e->getMessage());
        return [];
    }
}
