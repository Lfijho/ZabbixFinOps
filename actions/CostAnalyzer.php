<?php declare(strict_types = 0);

namespace Modules\ZabbixFinOpsToolkit\Actions;

use API,
	CController,
	CControllerResponseData,
	CRoleHelper;

class CostAnalyzer extends CController {

	// Thresholds for oversized detection.
	private const CPU_AVG_THRESHOLD   = 20;
	private const RAM_AVG_THRESHOLD   = 40;
	private const CPU_MAX_THRESHOLD   = 60;
	private const RAM_MAX_THRESHOLD   = 80;
	private const DISK_HIGH_THRESHOLD = 85;
	private const NET_HIGH_THRESHOLD  = 80; // percentage of theoretical max or relative threshold

	// Analysis window: 30 days.
	private const ANALYSIS_DAYS = 30;

	// Trend: first week vs last week.
	private const TREND_GROWTH_THRESHOLD = 5; // percentage points

	// Item keys to look for.
	private const ITEM_KEY_CPU       = 'system.cpu.util';
	private const ITEM_KEY_RAM_UTIL  = 'vm.memory.utilization';
	private const ITEM_KEY_RAM_PAVAIL = 'vm.memory.size[pavailable]';
	private const ITEM_KEY_DISK      = 'vfs.fs.size[/,pused]';
	private const ITEM_KEY_NETIN     = 'net.if.in';
	private const ITEM_KEY_NETOUT    = 'net.if.out';
	private const ITEM_KEY_LOAD      = 'system.cpu.load';
	private const ITEM_KEY_CPU_NUM   = 'system.cpu.num';
	private const ITEM_KEY_RAM_TOTAL = 'vm.memory.size[total]';

	// Right-sizing: recommend 80% of current allocation.
	private const RIGHT_SIZE_FACTOR = 0.80;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_groupids' => 'array',
			'sort'            => 'in waste_score,efficiency_score,cpu_avg,ram_avg,host_name',
			'sortorder'       => 'in ASC,DESC',
			'page'            => 'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['error' => _('Invalid input parameters.')]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	protected function doAction(): void {
		$filter_groupids = $this->getInput('filter_groupids', []);
		$sort = $this->getInput('sort', 'waste_score');
		$sortorder = $this->getInput('sortorder', 'DESC');

		$now = time();
		$time_from = $now - (self::ANALYSIS_DAYS * 86400);

		// Week boundaries for trend calculation.
		$first_week_start = $time_from;
		$first_week_end   = $time_from + (7 * 86400);
		$last_week_start  = $now - (7 * 86400);
		$last_week_end    = $now;

		// Fetch hosts.
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

		if (empty($hosts)) {
			$this->setResponse(new CControllerResponseData([
				'results'        => [],
				'host_groups'    => $this->getHostGroups(),
				'filter_groupids'=> $filter_groupids,
				'sort'           => $sort,
				'sortorder'      => $sortorder,
				'active_tab'     => 1
			]));
			return;
		}

		$hostids = array_keys($hosts);

		// Fetch relevant items for all hosts in a single API call.
		$items = API::Item()->get([
			'output'      => ['itemid', 'hostid', 'key_', 'value_type', 'units', 'lastvalue'],
			'hostids'     => $hostids,
			'search'      => [
				'key_' => [
					self::ITEM_KEY_CPU,
					self::ITEM_KEY_RAM_UTIL,
					self::ITEM_KEY_RAM_PAVAIL,
					self::ITEM_KEY_DISK,
					self::ITEM_KEY_NETIN,
					self::ITEM_KEY_NETOUT,
					self::ITEM_KEY_LOAD,
					self::ITEM_KEY_CPU_NUM,
					self::ITEM_KEY_RAM_TOTAL
				]
			],
			'searchByAny' => true,
			'monitored'   => true,
			'preservekeys'=> true
		]);

		// Organize items by host and type.
		// For CPU: prefer exact "system.cpu.util" (composite utilization).
		// For RAM: prefer "vm.memory.utilization", fallback to "vm.memory.size[pavailable]" (inverted).
		$host_items = [];
		foreach ($items as $item) {
			$hid = $item['hostid'];
			$key = $item['key_'];

			// CPU: exact match only (ignore system.cpu.util[,idle], etc.)
			if ($key === self::ITEM_KEY_CPU) {
				$host_items[$hid]['cpu'] = $item;
			}
			// RAM: vm.memory.utilization (percentage used — preferred)
			elseif ($key === self::ITEM_KEY_RAM_UTIL) {
				$host_items[$hid]['ram'] = $item;
				$host_items[$hid]['ram_inverted'] = false;
			}
			// RAM fallback: vm.memory.size[pavailable] (will invert: usage = 100 - available)
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
			elseif ($key === self::ITEM_KEY_CPU_NUM) {
				$host_items[$hid]['cpu_num'] = $item;
			}
			elseif ($key === self::ITEM_KEY_RAM_TOTAL) {
				$host_items[$hid]['ram_total'] = $item;
			}
		}

		// Process each host.
		$results = [];
		foreach ($hosts as $hostid => $host) {
			if (!isset($host_items[$hostid])) {
				continue;
			}

			$hi = $host_items[$hostid];
			$group_names = array_column($host['hostgroups'], 'name');

			$result = [
				'hostid'     => $hostid,
				'host_name'  => $host['name'],
				'host_groups'=> implode(', ', $group_names),
				'cpu_avg'    => null,
				'cpu_max'    => null,
				'cpu_p95'    => null,
				'ram_avg'    => null,
				'ram_max'    => null,
				'ram_p95'    => null,
				'disk_avg'   => null,
				'net_in_avg' => null,
				'net_out_avg'=> null,
				'load_avg'   => null,
				'waste_score'      => null,
				'efficiency_score' => null,
				'cpu_trend'        => null,
				'ram_trend'        => null,
				'recommendation'   => '',
				'waste_level'      => '',
				'efficiency_level' => '',
				'cpu_count'        => null,
				'ram_total_gb'     => null,
				'cpu_recommended'  => null,
				'ram_recommended_gb' => null
			];

			// Query trends for each metric.
			if (isset($hi['cpu'])) {
				$cpu_data = $this->getTrendData($hi['cpu'], $time_from, $now);
				$result['cpu_avg'] = $cpu_data['avg'];
				$result['cpu_max'] = $cpu_data['max'];
				$result['cpu_p95'] = $this->getP95Peak($hi['cpu'], $time_from, $now);
				$result['cpu_trend'] = $this->calculateTrend(
					$hi['cpu'], $first_week_start, $first_week_end, $last_week_start, $last_week_end
				);
			}

			if (isset($hi['ram'])) {
				$ram_data = $this->getTrendData($hi['ram'], $time_from, $now);
				$ram_inverted = !empty($hi['ram_inverted']);
				$ram_p95_raw = $this->getP95Peak($hi['ram'], $time_from, $now);

				if ($ram_inverted && $ram_data['avg'] !== null) {
					$result['ram_avg'] = round(100 - $ram_data['avg'], 2);
					$result['ram_max'] = ($ram_data['min'] !== null)
						? round(100 - $ram_data['min'], 2)
						: null;
					$result['ram_p95'] = $result['ram_max'];
				}
				else {
					$result['ram_avg'] = $ram_data['avg'];
					$result['ram_max'] = $ram_data['max'];
					$result['ram_p95'] = $ram_p95_raw;
				}

				// Clamp all RAM values to [0, 100] — both vm.memory.utilization
				// and inverted pavailable can produce out-of-range values.
				foreach (['ram_avg', 'ram_max', 'ram_p95'] as $ram_key) {
					if ($result[$ram_key] !== null) {
						$result[$ram_key] = round(max(0, min(100, (float) $result[$ram_key])), 2);
					}
				}

				$ram_trend_raw = $this->calculateTrend(
					$hi['ram'], $first_week_start, $first_week_end, $last_week_start, $last_week_end
				);
				$result['ram_trend'] = ($ram_inverted && $ram_trend_raw !== null)
					? round(-$ram_trend_raw, 2)
					: $ram_trend_raw;
			}

			if (isset($hi['disk'])) {
				$disk_data = $this->getTrendData($hi['disk'], $time_from, $now);
				$result['disk_avg'] = $disk_data['avg'];
			}

			if (isset($hi['net_in'])) {
				$net_in_data = $this->getTrendData($hi['net_in'], $time_from, $now);
				$result['net_in_avg'] = $net_in_data['avg'];
			}

			if (isset($hi['net_out'])) {
				$net_out_data = $this->getTrendData($hi['net_out'], $time_from, $now);
				$result['net_out_avg'] = $net_out_data['avg'];
			}

			if (isset($hi['load'])) {
				$load_data = $this->getTrendData($hi['load'], $time_from, $now);
				$result['load_avg'] = $load_data['avg'];
			}

			// Calculate Waste Score and Efficiency Score.
			if ($result['cpu_avg'] !== null && $result['ram_avg'] !== null) {
				$avg_usage = ($result['cpu_avg'] + $result['ram_avg']) / 2;
				$result['waste_score'] = max(0, min(100, round(100 - $avg_usage, 1)));
				$result['efficiency_score'] = max(0, min(100, round($avg_usage, 1)));

				$result['waste_level'] = $this->classifyWaste($result['waste_score']);
				$result['efficiency_level'] = $this->classifyEfficiency($result['efficiency_score']);
			}

			// Get current hardware specs for right-sizing.
			if (isset($hi['cpu_num']) && !empty($hi['cpu_num']['lastvalue'])) {
				$result['cpu_count'] = (int) $hi['cpu_num']['lastvalue'];
			}
			if (isset($hi['ram_total']) && !empty($hi['ram_total']['lastvalue'])) {
				$result['ram_total_gb'] = round((float) $hi['ram_total']['lastvalue'] / 1073741824, 1);
			}

			// Apply detection rules and generate recommendation.
			$result['recommendation'] = $this->generateRecommendation($result);

			// Calculate right-sizing suggestion.
			$result = $this->calculateRightSizing($result);

			$results[] = $result;
		}

		// Sort results.
		usort($results, function ($a, $b) use ($sort, $sortorder) {
			$va = $a[$sort] ?? 0;
			$vb = $b[$sort] ?? 0;

			if ($va == $vb) {
				return 0;
			}

			if ($sortorder === 'DESC') {
				return ($va > $vb) ? -1 : 1;
			}

			return ($va < $vb) ? -1 : 1;
		});

		$data = [
			'results'         => $results,
			'host_groups'     => $this->getHostGroups(),
			'filter_groupids' => $filter_groupids,
			'sort'            => $sort,
			'sortorder'       => $sortorder,
			'active_tab'      => 1
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Infrastructure Cost Analyzer'));
		$this->setResponse($response);
	}

	/**
	 * Query trend data for a given item.
	 * Uses trends table for float items, trends_uint for integer items.
	 * Returns avg, max, min, and p95 (percentage of hours exceeding a threshold).
	 */
	private function getTrendData(array $item, int $time_from, int $time_till): array {
		$table = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64) ? 'trends_uint' : 'trends';

		$sql = 'SELECT AVG(value_avg) AS avg_val, MAX(value_max) AS max_val, MIN(value_min) AS min_val'.
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

	/**
	 * Calculate the P95 peak: value_max at the 95th percentile of hourly trend rows.
	 * This ignores rare spikes (top 5% of hours) to avoid false negatives.
	 */
	private function getP95Peak(array $item, int $time_from, int $time_till): ?float {
		$table = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64) ? 'trends_uint' : 'trends';

		// Count total rows.
		$sql_count = 'SELECT COUNT(*) AS cnt FROM '.$table.
			' WHERE itemid='.zbx_dbstr($item['itemid']).
			' AND clock>='.zbx_dbstr($time_from).
			' AND clock<='.zbx_dbstr($time_till);

		$row_count = \DBfetch(\DBselect($sql_count));
		$total = ($row_count) ? (int) $row_count['cnt'] : 0;

		if ($total === 0) {
			return null;
		}

		// Skip top 5% of rows, take the next one = P95.
		$offset = max(0, (int) floor($total * 0.95) - 1);

		$sql_p95 = 'SELECT value_max FROM '.$table.
			' WHERE itemid='.zbx_dbstr($item['itemid']).
			' AND clock>='.zbx_dbstr($time_from).
			' AND clock<='.zbx_dbstr($time_till).
			' ORDER BY value_max ASC';

		$result = \DBselect($sql_p95, 1, $offset);
		$row = \DBfetch($result);

		return ($row && $row['value_max'] !== null) ? round((float) $row['value_max'], 2) : null;
	}

	/**
	 * Calculate trend: compare first week average vs last week average.
	 * Returns the difference in percentage points (positive = growth).
	 */
	private function calculateTrend(array $item, int $fw_start, int $fw_end, int $lw_start, int $lw_end): ?float {
		$table = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64) ? 'trends_uint' : 'trends';

		// Require at least 24 hours of trend data per week for a reliable comparison.
		$min_hours = 24;

		$sql_first = 'SELECT COUNT(*) AS cnt, AVG(value_avg) AS avg_val FROM '.$table.
			' WHERE itemid='.zbx_dbstr($item['itemid']).
			' AND clock>='.zbx_dbstr($fw_start).
			' AND clock<='.zbx_dbstr($fw_end);

		$sql_last = 'SELECT COUNT(*) AS cnt, AVG(value_avg) AS avg_val FROM '.$table.
			' WHERE itemid='.zbx_dbstr($item['itemid']).
			' AND clock>='.zbx_dbstr($lw_start).
			' AND clock<='.zbx_dbstr($lw_end);

		$row_first = \DBfetch(\DBselect($sql_first));
		$row_last  = \DBfetch(\DBselect($sql_last));

		if (!$row_first || !$row_last
			|| (int) $row_first['cnt'] < $min_hours
			|| (int) $row_last['cnt'] < $min_hours
			|| $row_first['avg_val'] === null
			|| $row_last['avg_val'] === null) {
			return null;
		}

		return round((float) $row_last['avg_val'] - (float) $row_first['avg_val'], 2);
	}

	/**
	 * Classify Waste Score.
	 */
	private function classifyWaste(float $score): string {
		if ($score >= 80) return _('HIGH');
		if ($score >= 60) return _('MEDIUM');
		if ($score >= 40) return _('LOW');
		return _('HEALTHY');
	}

	/**
	 * Classify Efficiency Score.
	 */
	private function classifyEfficiency(float $score): string {
		if ($score >= 70) return _('Healthy usage');
		if ($score >= 40) return _('Can be optimized');
		return _('High waste');
	}

	/**
	 * Generate recommendation based on detection rules.
	 * Uses P95 peaks instead of absolute MAX to ignore rare spikes.
	 */
	private function generateRecommendation(array $r): string {
		// Need at least CPU and RAM data.
		if ($r['cpu_avg'] === null || $r['ram_avg'] === null) {
			return _('Insufficient data for analysis.');
		}

		// Projection-based growth: only block downsizing if projected usage
		// (current avg + monthly trend) would reach or exceed the threshold.
		$growth_blocking = false;
		$growth_details = [];

		if ($r['cpu_trend'] !== null && $r['cpu_trend'] > 0) {
			$cpu_projected = $r['cpu_avg'] + $r['cpu_trend'];
			if ($cpu_projected >= self::CPU_AVG_THRESHOLD) {
				$growth_blocking = true;
				$growth_details[] = sprintf(_('CPU: +%s%% → projected %s%%'),
					$r['cpu_trend'], round($cpu_projected, 1));
			}
		}

		if ($r['ram_trend'] !== null && $r['ram_trend'] > 0) {
			$ram_projected = $r['ram_avg'] + $r['ram_trend'];
			if ($ram_projected >= self::RAM_AVG_THRESHOLD) {
				$growth_blocking = true;
				$growth_details[] = sprintf(_('RAM: +%s%% → projected %s%%'),
					$r['ram_trend'], round($ram_projected, 1));
			}
		}

		// Check if disk is near saturation.
		$disk_saturated = ($r['disk_avg'] !== null && $r['disk_avg'] >= self::DISK_HIGH_THRESHOLD);

		// Check if network usage is persistently high.
		$net_in_high  = ($r['net_in_avg'] !== null && $r['net_in_avg'] > 100000000);
		$net_out_high = ($r['net_out_avg'] !== null && $r['net_out_avg'] > 100000000);
		$net_high = $net_in_high || $net_out_high;

		// Use P95 for peak evaluation (ignores rare spikes).
		$cpu_peak = $r['cpu_p95'] ?? $r['cpu_max'];
		$ram_peak = $r['ram_p95'] ?? $r['ram_max'];

		// Check basic thresholds using averages.
		$cpu_avg_low = ($r['cpu_avg'] < self::CPU_AVG_THRESHOLD);
		$ram_avg_low = ($r['ram_avg'] < self::RAM_AVG_THRESHOLD);

		// Check P95 peaks.
		$cpu_peak_ok = ($cpu_peak !== null && $cpu_peak < self::CPU_MAX_THRESHOLD);
		$ram_peak_ok = ($ram_peak !== null && $ram_peak < self::RAM_MAX_THRESHOLD);

		// If averages are not low, server is being used adequately.
		if (!$cpu_avg_low || !$ram_avg_low) {
			return _('Resource usage is within acceptable range. No action needed.');
		}

		// Averages are low — check other resources as safeguard.
		if ($disk_saturated || $net_high) {
			return _('Server with low CPU/RAM usage, however other resources are under high utilization. Reduction not recommended.');
		}

		// Check projection-based growth trend.
		if ($growth_blocking) {
			return _('Workload growing toward thresholds. Resource reduction not recommended at this time.')
				.' ('.implode('; ', $growth_details).')';
		}

		// Averages are low and no safeguard triggered.
		if ($cpu_peak_ok && $ram_peak_ok) {
			return _('Server with low resource utilization. Consider reducing CPU and memory for this machine.');
		}

		// Averages low but P95 peaks are high — occasional heavy usage.
		$spike_parts = [];
		if (!$cpu_peak_ok) {
			$spike_parts[] = sprintf(_('CPU P95 peak: %s%%'), $cpu_peak);
		}
		if (!$ram_peak_ok) {
			$spike_parts[] = sprintf(_('RAM P95 peak: %s%%'), $ram_peak);
		}

		return _('Server mostly idle but with periodic load spikes. Investigate spike patterns before downsizing.')
			.' ('.implode('; ', $spike_parts).')';
	}

	/**
	 * Calculate right-sizing recommendations based on P95 usage + safety margin.
	 * Only suggests reduction when recommended < current.
	 */
	private function calculateRightSizing(array $r): array {
		// CPU: recommend 80% of current, but never below P95 actual usage. Min 1 vCPU.
		if ($r['cpu_p95'] !== null && $r['cpu_p95'] > 0 && $r['cpu_count'] !== null && $r['cpu_count'] > 0) {
			$recommended = max(1, (int) floor($r['cpu_count'] * self::RIGHT_SIZE_FACTOR));
			$actual_need = ($r['cpu_p95'] / 100) * $r['cpu_count'];

			if ($recommended >= $actual_need && $recommended < $r['cpu_count']) {
				$r['cpu_recommended'] = $recommended;
			}
		}

		// RAM: recommend 80% of current, but never below P95 actual usage. Min 2 GB.
		if ($r['ram_p95'] !== null && $r['ram_p95'] > 0 && $r['ram_total_gb'] !== null && $r['ram_total_gb'] > 0) {
			$recommended = max(2, round($r['ram_total_gb'] * self::RIGHT_SIZE_FACTOR, 1));
			$actual_need = ($r['ram_p95'] / 100) * $r['ram_total_gb'];

			if ($recommended >= $actual_need && $recommended < $r['ram_total_gb']) {
				$r['ram_recommended_gb'] = $recommended;
			}
		}

		return $r;
	}

	/**
	 * Get list of host groups for the filter dropdown.
	 */
	private function getHostGroups(): array {
		return API::HostGroup()->get([
			'output'         => ['groupid', 'name'],
			'with_monitored_hosts' => true,
			'preservekeys'   => true
		]);
	}
}
