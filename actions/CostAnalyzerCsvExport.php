<?php declare(strict_types = 0);

namespace Modules\ZabbixFinOpsToolkit\Actions;

use API,
CController,
CControllerResponseData,
CRoleHelper;

class CostAnalyzerCsvExport extends CController {

private const DEFAULT_ANALYSIS_PERIOD = '30d';
private const DEFAULT_CPU_DISPLAY_UNIT = 'OCPU';
private const ANALYSIS_PERIOD_SECONDS = [
'1h'  => 3600,
'1d'  => 86400,
'2d'  => 2 * 86400,
'5d'  => 5 * 86400,
'7d'  => 7 * 86400,
'14d' => 14 * 86400,
'30d' => 30 * 86400
];
private const CPU_AVG_THRESHOLD   = 20;
private const RAM_AVG_THRESHOLD   = 40;
private const CPU_MAX_THRESHOLD   = 60;
private const RAM_MAX_THRESHOLD   = 80;
private const DISK_HIGH_THRESHOLD = 85;
private const RIGHT_SIZE_FACTOR = 0.80;

private const OCI_MYSQL_SHAPES = [
['name' => 'MySQL.2',   'cpu' => 2,   'ram' => 16],
['name' => 'MySQL.4',   'cpu' => 4,   'ram' => 32],
['name' => 'MySQL.8',   'cpu' => 8,   'ram' => 64],
['name' => 'MySQL.16',  'cpu' => 16,  'ram' => 128],
['name' => 'MySQL.32',  'cpu' => 32,  'ram' => 256],
['name' => 'MySQL.48',  'cpu' => 48,  'ram' => 384],
['name' => 'MySQL.64',  'cpu' => 64,  'ram' => 512],
['name' => 'MySQL.96',  'cpu' => 96,  'ram' => 768],
['name' => 'MySQL.192', 'cpu' => 192, 'ram' => 1290],
['name' => 'MySQL.256', 'cpu' => 256, 'ram' => 1024]
];

private const ITEM_KEY_CPU       = 'system.cpu.util';
private const ITEM_KEY_RAM_UTIL  = 'vm.memory.utilization';
private const ITEM_KEY_RAM_UTIL_WINDOWS = 'vm.memory.util';
private const ITEM_KEY_RAM_PAVAIL = 'vm.memory.size[pavailable]';
private const ITEM_KEY_DISK      = 'vfs.fs.size[/,pused]';
private const ITEM_KEY_NETIN     = 'net.if.in';
private const ITEM_KEY_NETOUT    = 'net.if.out';
private const ITEM_KEY_LOAD      = 'system.cpu.load';
private const ITEM_KEY_CPU_NUM   = 'system.cpu.num';
private const ITEM_KEY_CPU_NUM_WINDOWS = 'wmi.get[root/cimv2,"Select NumberOfLogicalProcessors';
private const ITEM_KEY_CPU_DISCOVERY = 'system.cpu.discovery';
private const ITEM_KEY_RAM_TOTAL = 'vm.memory.size[total]';
private const ITEM_KEY_OCI_DB_CPU = 'oci.db.cpu.utilization';
private const ITEM_KEY_OCI_DB_RAM = 'oci.db.memory.utilization';
private const ITEM_KEY_OCI_DB_ECPU = 'oci.db.ecpu';
private const ITEM_KEY_OCI_DB_RAM_CONFIGURED_GB = 'oci.db.memory.configured_gb';

protected function init(): void {
$this->disableCsrfValidation();
}

protected function checkInput(): bool {
$fields = [
'filter_groupids' => 'array',
'analysis_period' => 'in 1h,1d,2d,5d,7d,14d,30d',
'cpu_display_unit' => 'in OCPU,vCPU'
];

return $this->validateInput($fields);
}

protected function checkPermissions(): bool {
return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
}

protected function doAction(): void {
$filter_groupids = $this->getInput('filter_groupids', []);
$analysis_period = $this->getInput('analysis_period', self::DEFAULT_ANALYSIS_PERIOD);
$cpu_display_unit = $this->getInput('cpu_display_unit', self::DEFAULT_CPU_DISPLAY_UNIT);

$now = time();
$analysis_seconds = self::ANALYSIS_PERIOD_SECONDS[$analysis_period];
$time_from = $now - $analysis_seconds;
$use_history = ($analysis_period === '1h');
$trend_windows = $this->getTrendComparisonWindows($time_from, $now, $use_history);

$host_options = [
'output'            => ['hostid', 'host', 'name'],
'selectHostGroups'  => ['groupid', 'name'],
'filter'            => ['status' => HOST_STATUS_MONITORED],
'monitored_hosts'   => true,
'preservekeys'      => true,
'limit'             => 1000
];

if (!empty($filter_groupids)) {
$host_options['groupids'] = $filter_groupids;
}

$hosts = API::Host()->get($host_options);
$hostids = array_keys($hosts);

$items = API::Item()->get([
'output'      => ['itemid', 'hostid', 'key_', 'value_type', 'units', 'lastvalue'],
'hostids'     => $hostids,
'search'      => [
'key_' => [
self::ITEM_KEY_CPU, self::ITEM_KEY_RAM_UTIL, self::ITEM_KEY_RAM_UTIL_WINDOWS, self::ITEM_KEY_RAM_PAVAIL,
self::ITEM_KEY_DISK, self::ITEM_KEY_NETIN, self::ITEM_KEY_NETOUT,
self::ITEM_KEY_LOAD, self::ITEM_KEY_CPU_NUM, self::ITEM_KEY_CPU_NUM_WINDOWS,
self::ITEM_KEY_CPU_DISCOVERY, self::ITEM_KEY_RAM_TOTAL,
self::ITEM_KEY_OCI_DB_CPU, self::ITEM_KEY_OCI_DB_RAM,
self::ITEM_KEY_OCI_DB_ECPU, self::ITEM_KEY_OCI_DB_RAM_CONFIGURED_GB
]
],
'searchByAny' => true,
'monitored'   => true,
'preservekeys'=> true
]);

$host_items = [];
foreach ($items as $item) {
$hid = $item['hostid'];
$key = $item['key_'];

if ($key === self::ITEM_KEY_CPU) {
$host_items[$hid]['cpu'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_OCI_DB_CPU) === 0 && !isset($host_items[$hid]['cpu'])) {
$host_items[$hid]['cpu'] = $item;
$host_items[$hid]['is_oci_db'] = true;
$host_items[$hid]['cpu_unit'] = 'ECPU';
}
elseif ($key === self::ITEM_KEY_RAM_UTIL) {
$host_items[$hid]['ram'] = $item;
$host_items[$hid]['ram_inverted'] = false;
}
elseif ($key === self::ITEM_KEY_RAM_UTIL_WINDOWS && !isset($host_items[$hid]['ram'])) {
$host_items[$hid]['ram'] = $item;
$host_items[$hid]['ram_inverted'] = false;
}
elseif (strpos($key, self::ITEM_KEY_OCI_DB_RAM) === 0 && !isset($host_items[$hid]['ram'])) {
$host_items[$hid]['ram'] = $item;
$host_items[$hid]['ram_inverted'] = false;
$host_items[$hid]['is_oci_db'] = true;
$host_items[$hid]['cpu_unit'] = 'ECPU';
}
elseif ($key === self::ITEM_KEY_RAM_PAVAIL && !isset($host_items[$hid]['ram'])) {
$host_items[$hid]['ram'] = $item;
$host_items[$hid]['ram_inverted'] = true;
}
elseif ($key === self::ITEM_KEY_DISK) {
$host_items[$hid]['disk'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_NETIN) === 0) {
$host_items[$hid]['net_in'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_NETOUT) === 0) {
$host_items[$hid]['net_out'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_LOAD) === 0) {
$host_items[$hid]['load'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_CPU_NUM_WINDOWS) === 0) {
$host_items[$hid]['cpu_num_windows'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_CPU_NUM) === 0) {
if ($key === self::ITEM_KEY_CPU_NUM || !isset($host_items[$hid]['cpu_num'])) {
$host_items[$hid]['cpu_num'] = $item;
}
}
elseif ($key === self::ITEM_KEY_CPU_DISCOVERY) {
$host_items[$hid]['cpu_discovery'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_OCI_DB_ECPU) === 0) {
$host_items[$hid]['cpu_db'] = $item;
$host_items[$hid]['is_oci_db'] = true;
$host_items[$hid]['cpu_unit'] = 'ECPU';
}
elseif ($key === self::ITEM_KEY_RAM_TOTAL) {
$host_items[$hid]['ram_total'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_OCI_DB_RAM_CONFIGURED_GB) === 0) {
$host_items[$hid]['ram_configured_gb'] = $item;
$host_items[$hid]['is_oci_db'] = true;
$host_items[$hid]['cpu_unit'] = 'ECPU';
}
}

$results = [];
foreach ($hosts as $hostid => $host) {
if (!isset($host_items[$hostid])) {
continue;
}

$hi = $host_items[$hostid];
$group_names = array_column($host['hostgroups'], 'name');
$is_oci_db_host = !empty($hi['is_oci_db']);
$is_windows_host = isset($hi['cpu_num_windows'])
|| (isset($hi['ram']) && empty($hi['ram_inverted']) && $hi['ram']['key_'] === self::ITEM_KEY_RAM_UTIL_WINDOWS)
|| $this->hostLooksLikeWindows($group_names);

$r = [
'host_name'  => $host['name'],
'host_groups'=> implode('; ', $group_names),
'cpu_avg'    => null, 'cpu_max' => null, 'cpu_p95' => null,
'ram_avg'    => null, 'ram_max' => null, 'ram_p95' => null,
'disk_avg'   => null,
'net_in_avg' => null, 'net_out_avg' => null,
'load_avg'   => null,
'waste_score'      => null,
'efficiency_score' => null,
'cpu_trend'  => null, 'ram_trend' => null,
'cpu_count'  => null, 'cpu_unit' => $hi['cpu_unit'] ?? $cpu_display_unit,
'cpu_shape' => null, 'cpu_recommended_shape' => null, 'ram_total_gb' => null,
'cpu_recommended' => null, 'ram_recommended_gb' => null,
'recommendation' => ''
];

if (isset($hi['cpu'])) {
$d = $this->getTrendData($hi['cpu'], $time_from, $now, $use_history);
$r['cpu_avg'] = $d['avg'];
$r['cpu_max'] = $d['max'];
$r['cpu_p95'] = $this->getP95Peak($hi['cpu'], $time_from, $now, $use_history);
$r['cpu_trend'] = $this->calculateTrend($hi['cpu'], $trend_windows, $use_history);
}

if (isset($hi['ram'])) {
$d = $this->getTrendData($hi['ram'], $time_from, $now, $use_history);
$ram_inverted = !empty($hi['ram_inverted']);

if ($ram_inverted && $d['avg'] !== null) {
$r['ram_avg'] = round(100 - $d['avg'], 2);
$r['ram_max'] = ($d['min'] !== null) ? round(100 - $d['min'], 2) : null;
$r['ram_p95'] = $r['ram_max'];
}
else {
$r['ram_avg'] = $d['avg'];
$r['ram_max'] = $d['max'];
$r['ram_p95'] = $this->getP95Peak($hi['ram'], $time_from, $now, $use_history);
}

foreach (['ram_avg', 'ram_max', 'ram_p95'] as $ram_key) {
if ($r[$ram_key] !== null) {
$r[$ram_key] = round(max(0, min(100, (float) $r[$ram_key])), 2);
}
}

$ram_trend_raw = $this->calculateTrend($hi['ram'], $trend_windows, $use_history);
$r['ram_trend'] = ($ram_inverted && $ram_trend_raw !== null) ? round(-$ram_trend_raw, 2) : $ram_trend_raw;
}

if (isset($hi['disk'])) {
$r['disk_avg'] = $this->getTrendData($hi['disk'], $time_from, $now, $use_history)['avg'];
}
if (isset($hi['net_in'])) {
$r['net_in_avg'] = $this->getTrendData($hi['net_in'], $time_from, $now, $use_history)['avg'];
}
if (isset($hi['net_out'])) {
$r['net_out_avg'] = $this->getTrendData($hi['net_out'], $time_from, $now, $use_history)['avg'];
}
if (isset($hi['load'])) {
$r['load_avg'] = $this->getTrendData($hi['load'], $time_from, $now, $use_history)['avg'];
}

// Hardware specs.
if ($is_oci_db_host && isset($hi['cpu_db']) && $hi['cpu_db']['lastvalue'] !== '') {
$r['cpu_count'] = round((float) $hi['cpu_db']['lastvalue'], 1);
}
elseif (isset($hi['cpu_num']) && $hi['cpu_num']['lastvalue'] !== '') {
// system.cpu.num reports logical CPUs; present them in the selected compute unit.
$r['cpu_count'] = $this->logicalCpusToDisplayUnit((int) $hi['cpu_num']['lastvalue'], $cpu_display_unit);
}
elseif ($is_windows_host && isset($hi['cpu_num_windows']) && $hi['cpu_num_windows']['lastvalue'] !== '') {
// Official Windows templates expose logical processors through WMI.
$r['cpu_count'] = $this->logicalCpusToDisplayUnit(
(int) $hi['cpu_num_windows']['lastvalue'], $cpu_display_unit
);
}
elseif ($is_windows_host && isset($hi['cpu_discovery']) && $hi['cpu_discovery']['lastvalue'] !== '') {
$cpu_threads = $this->countDiscoveredCpus($hi['cpu_discovery']['lastvalue']);

if ($cpu_threads !== null && $cpu_threads > 0) {
$r['cpu_count'] = $this->logicalCpusToDisplayUnit($cpu_threads, $cpu_display_unit);
}
}
if ($is_oci_db_host && isset($hi['ram_configured_gb']) && $hi['ram_configured_gb']['lastvalue'] !== '') {
$r['ram_total_gb'] = round((float) $hi['ram_configured_gb']['lastvalue'], 1);
}
elseif (isset($hi['ram_total']) && !empty($hi['ram_total']['lastvalue'])) {
$r['ram_total_gb'] = round((float) $hi['ram_total']['lastvalue'] / 1073741824, 1);
}

if ($is_oci_db_host) {
$r['cpu_shape'] = $this->findOciMysqlShapeName($r['cpu_count'], $r['ram_total_gb']);
}

if ($r['cpu_avg'] !== null && $r['ram_avg'] !== null) {
$avg_usage = ($r['cpu_avg'] + $r['ram_avg']) / 2;
$r['waste_score'] = max(0, min(100, round(100 - $avg_usage, 1)));
$r['efficiency_score'] = max(0, min(100, round($avg_usage, 1)));
}

$r = $this->calculateRightSizing($r);
$r['recommendation'] = $this->generateRecommendation($r);
$results[] = $r;
}

usort($results, function ($a, $b) {
return ($b['waste_score'] ?? 0) <=> ($a['waste_score'] ?? 0);
});

$response = new CControllerResponseData([
'results' => $results
]);
$response->setFileName(
'finops_cost_analysis_'.$analysis_period.'_'.strtolower($cpu_display_unit).'_'.date('Y-m-d').'.csv'
);
$this->setResponse($response);
}

private function getTrendData(array $item, int $time_from, int $time_till, bool $use_history = false): array {
$is_uint = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64);
$table = $use_history ? ($is_uint ? 'history_uint' : 'history') : ($is_uint ? 'trends_uint' : 'trends');
$avg_column = $use_history ? 'value' : 'value_avg';
$max_column = $use_history ? 'value' : 'value_max';
$min_column = $use_history ? 'value' : 'value_min';

$sql = 'SELECT AVG('.$avg_column.') AS avg_val, MAX('.$max_column.') AS max_val,'.
' MIN('.$min_column.') AS min_val'.
' FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($time_from).
' AND clock<='.zbx_dbstr($time_till);

$row = \DBfetch(\DBselect($sql));

return [
'avg' => ($row && $row['avg_val'] !== null) ? round((float) $row['avg_val'], 2) : null,
'max' => ($row && $row['max_val'] !== null) ? round((float) $row['max_val'], 2) : null,
'min' => ($row && $row['min_val'] !== null) ? round((float) $row['min_val'], 2) : null
];
}

private function getP95Peak(array $item, int $time_from, int $time_till, bool $use_history = false): ?float {
$is_uint = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64);
$table = $use_history ? ($is_uint ? 'history_uint' : 'history') : ($is_uint ? 'trends_uint' : 'trends');
$value_column = $use_history ? 'value' : 'value_max';

$sql_count = 'SELECT COUNT(*) AS cnt FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($time_from).
' AND clock<='.zbx_dbstr($time_till);

$row_count = \DBfetch(\DBselect($sql_count));
$total = ($row_count) ? (int) $row_count['cnt'] : 0;

if ($total === 0) {
return null;
}

$offset = max(0, (int) floor($total * 0.95) - 1);

$sql_p95 = 'SELECT '.$value_column.' AS percentile_value FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($time_from).
' AND clock<='.zbx_dbstr($time_till).
' ORDER BY '.$value_column.' ASC';

$result = \DBselect($sql_p95, 1, $offset);
$row = \DBfetch($result);

return ($row && $row['percentile_value'] !== null)
? round((float) $row['percentile_value'], 2)
: null;
}

private function getTrendComparisonWindows(int $time_from, int $time_till, bool $use_history): array {
$window_seconds = $time_till - $time_from;

if ($window_seconds >= 14 * 86400) {
$first_start = $time_from;
$first_end = $time_from + (7 * 86400) - 1;
$last_start = $time_till - (7 * 86400);
$last_end = $time_till;
$segment_seconds = 7 * 86400;
}
else {
$midpoint = $time_from + (int) floor($window_seconds / 2);
$first_start = $time_from;
$first_end = $midpoint - 1;
$last_start = $midpoint;
$last_end = $time_till;
$segment_seconds = (int) floor($window_seconds / 2);
}

$min_samples = $use_history
? 2
: max(2, min(24, (int) floor($segment_seconds / 7200)));

return [
'first_start' => $first_start,
'first_end' => $first_end,
'last_start' => $last_start,
'last_end' => $last_end,
'min_samples' => $min_samples
];
}

private function calculateTrend(array $item, array $windows, bool $use_history = false): ?float {
$is_uint = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64);
$table = $use_history ? ($is_uint ? 'history_uint' : 'history') : ($is_uint ? 'trends_uint' : 'trends');
$avg_column = $use_history ? 'value' : 'value_avg';

$sql_first = 'SELECT COUNT(*) AS cnt, AVG('.$avg_column.') AS avg_val FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($windows['first_start']).' AND clock<='.zbx_dbstr($windows['first_end']);

$sql_last = 'SELECT COUNT(*) AS cnt, AVG('.$avg_column.') AS avg_val FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($windows['last_start']).' AND clock<='.zbx_dbstr($windows['last_end']);

$row_first = \DBfetch(\DBselect($sql_first));
$row_last  = \DBfetch(\DBselect($sql_last));

if (!$row_first || !$row_last
|| (int) $row_first['cnt'] < $windows['min_samples']
|| (int) $row_last['cnt'] < $windows['min_samples']
|| $row_first['avg_val'] === null
|| $row_last['avg_val'] === null) {
return null;
}

return round((float) $row_last['avg_val'] - (float) $row_first['avg_val'], 2);
}

private function hostLooksLikeWindows(array $group_names): bool {
foreach ($group_names as $group_name) {
if (stripos($group_name, 'windows') !== false) {
return true;
}
}

return false;
}

private function logicalCpusToOcpus(int $logical_cpus): int {
return max(1, (int) floor($logical_cpus / 2));
}

private function logicalCpusToDisplayUnit(int $logical_cpus, string $cpu_display_unit): int {
if ($cpu_display_unit === 'vCPU') {
return max(1, $logical_cpus);
}

return $this->logicalCpusToOcpus($logical_cpus);
}

private function countDiscoveredCpus(string $lastvalue): ?int {
$decoded = json_decode($lastvalue, true);

if (!is_array($decoded)) {
return null;
}

if (isset($decoded['data']) && is_array($decoded['data'])) {
return count($decoded['data']);
}

if (isset($decoded[0]) && is_array($decoded[0])) {
return count($decoded);
}

return null;
}

private function findOciMysqlShapeName(?float $cpu, ?float $ram): ?string {
if ($cpu === null || $ram === null) {
return null;
}

foreach (self::OCI_MYSQL_SHAPES as $shape) {
if ((float) $shape['cpu'] === (float) $cpu && (float) $shape['ram'] === (float) $ram) {
return $shape['name'];
}
}

return null;
}

private function calculateRightSizing(array $r): array {
if (($r['cpu_unit'] ?? 'OCPU') === 'ECPU') {
return $this->calculateOciDbRightSizing($r);
}

if ($r['cpu_p95'] !== null && $r['cpu_p95'] > 0 && $r['cpu_count'] !== null && $r['cpu_count'] > 0) {
$recommended = max(1, (int) floor($r['cpu_count'] * self::RIGHT_SIZE_FACTOR));
$actual_need = ($r['cpu_p95'] / 100) * $r['cpu_count'];

if ($recommended >= $actual_need && $recommended < $r['cpu_count']) {
$r['cpu_recommended'] = $recommended;
}
}

if ($r['ram_p95'] !== null && $r['ram_p95'] > 0 && $r['ram_total_gb'] !== null && $r['ram_total_gb'] > 0) {
$recommended = max(2, round($r['ram_total_gb'] * self::RIGHT_SIZE_FACTOR, 1));
$actual_need = ($r['ram_p95'] / 100) * $r['ram_total_gb'];

if ($recommended >= $actual_need && $recommended < $r['ram_total_gb']) {
$r['ram_recommended_gb'] = $recommended;
}
}

return $r;
}

private function calculateOciDbRightSizing(array $r): array {
if ($r['cpu_p95'] === null || $r['cpu_p95'] <= 0
|| $r['ram_p95'] === null || $r['ram_p95'] <= 0
|| $r['cpu_count'] === null || $r['cpu_count'] <= 0
|| $r['ram_total_gb'] === null || $r['ram_total_gb'] <= 0) {
return $r;
}

$cpu_target = $r['cpu_count'] * self::RIGHT_SIZE_FACTOR;
$ram_target = $r['ram_total_gb'] * self::RIGHT_SIZE_FACTOR;
$cpu_actual_need = ($r['cpu_p95'] / 100) * $r['cpu_count'];
$ram_actual_need = ($r['ram_p95'] / 100) * $r['ram_total_gb'];
$selected_shape = null;

foreach (self::OCI_MYSQL_SHAPES as $shape) {
if ($shape['cpu'] >= $r['cpu_count'] || $shape['ram'] >= $r['ram_total_gb']) {
continue;
}

if ($shape['cpu'] > $cpu_target || $shape['ram'] > $ram_target) {
continue;
}

if ($shape['cpu'] < $cpu_actual_need || $shape['ram'] < $ram_actual_need) {
continue;
}

if ($selected_shape === null || $shape['cpu'] > $selected_shape['cpu']) {
$selected_shape = $shape;
}
}

if ($selected_shape !== null) {
$r['cpu_recommended'] = $selected_shape['cpu'];
$r['ram_recommended_gb'] = $selected_shape['ram'];
$r['cpu_recommended_shape'] = $selected_shape['name'];
}

return $r;
}

private function generateRecommendation(array $r): string {
if ($r['cpu_avg'] === null || $r['ram_avg'] === null) {
return 'Insufficient data';
}

$growth_blocking = false;
$growth_parts = [];

if ($r['cpu_trend'] !== null && $r['cpu_trend'] > 0) {
$cpu_projected = $r['cpu_avg'] + $r['cpu_trend'];
if ($cpu_projected >= self::CPU_AVG_THRESHOLD) {
$growth_blocking = true;
$growth_parts[] = 'CPU: +'.$r['cpu_trend'].'% -> projected '.round($cpu_projected, 1).'%';
}
}
if ($r['ram_trend'] !== null && $r['ram_trend'] > 0) {
$ram_projected = $r['ram_avg'] + $r['ram_trend'];
if ($ram_projected >= self::RAM_AVG_THRESHOLD) {
$growth_blocking = true;
$growth_parts[] = 'RAM: +'.$r['ram_trend'].'% -> projected '.round($ram_projected, 1).'%';
}
}

$disk_saturated = ($r['disk_avg'] !== null && $r['disk_avg'] >= self::DISK_HIGH_THRESHOLD);
$net_high = ($r['net_in_avg'] !== null && $r['net_in_avg'] > 100000000)
|| ($r['net_out_avg'] !== null && $r['net_out_avg'] > 100000000);

$cpu_peak = $r['cpu_p95'] ?? $r['cpu_max'];
$ram_peak = $r['ram_p95'] ?? $r['ram_max'];

$cpu_avg_low = ($r['cpu_avg'] < self::CPU_AVG_THRESHOLD);
$ram_avg_low = ($r['ram_avg'] < self::RAM_AVG_THRESHOLD);
$cpu_peak_ok = ($cpu_peak !== null && $cpu_peak < self::CPU_MAX_THRESHOLD);
$ram_peak_ok = ($ram_peak !== null && $ram_peak < self::RAM_MAX_THRESHOLD);

if (!$cpu_avg_low || !$ram_avg_low) {
return 'Usage within acceptable range';
}

if ($disk_saturated || $net_high) {
return 'Low CPU/RAM but other resources high - no reduction';
}

if ($growth_blocking) {
return 'Workload growing toward thresholds ('.implode('; ', $growth_parts).') - no reduction';
}

if ($cpu_peak_ok && $ram_peak_ok) {
if (($r['cpu_unit'] ?? 'OCPU') === 'ECPU') {
if (!empty($r['cpu_recommended_shape'])) {
return 'Oversized - consider resizing to '.$r['cpu_recommended_shape'];
}

return 'Low utilization, but no smaller OCI DB shape satisfies P95 CPU/RAM safety requirements';
}

return 'Oversized - consider reducing CPU and memory';
}

$spike_parts = [];
if (!$cpu_peak_ok) {
$spike_parts[] = 'CPU P95: '.$cpu_peak.'%';
}
if (!$ram_peak_ok) {
$spike_parts[] = 'RAM P95: '.$ram_peak.'%';
}

return 'Mostly idle but periodic spikes ('.implode('; ', $spike_parts).')';
}
}
