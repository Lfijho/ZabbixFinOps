<?php declare(strict_types = 0);
/**
 * Zabbix FinOps Toolkit - CSV Export View
 *
 * @var CView $this
 * @var array $data
 */

$csv = '';

// BOM for UTF-8 compatibility with Excel.
$csv .= "\xEF\xBB\xBF";

// Header row.
$csv .= implode(',', [
        '"Host"',
        '"Host Group"',
        '"CPU Avg %"',
        '"CPU Max %"',
        '"RAM Avg %"',
        '"RAM Max %"',
        '"Disk Avg %"',
        '"Net In Avg (bytes/s)"',
        '"Net Out Avg (bytes/s)"',
        '"Load Avg"',
        '"Waste Score"',
        '"Waste Level"',
        '"Efficiency Score"',
        '"Efficiency Level"',
        '"CPU P95 %"',
        '"RAM P95 %"',
        '"CPU"',
        '"CPU Unit"',
        '"CPU Shape"',
        '"CPU Recommended"',
        '"CPU Recommended Shape"',
        '"RAM GB"',
        '"RAM Recommended GB"',
        '"CPU Trend"',
        '"RAM Trend"',
        '"Recommendation"'
])."\n";

foreach ($data['results'] as $r) {
        $cpu_unit = $r['cpu_unit'] ?? 'OCPU';
        $waste_level = '';
        if ($r['waste_score'] !== null) {
                if ($r['waste_score'] >= 80) $waste_level = 'HIGH';
                elseif ($r['waste_score'] >= 60) $waste_level = 'MEDIUM';
                elseif ($r['waste_score'] >= 40) $waste_level = 'LOW';
                else $waste_level = 'HEALTHY';
        }

        $eff_level = '';
        if ($r['efficiency_score'] !== null) {
                if ($r['efficiency_score'] >= 70) $eff_level = 'Healthy';
                elseif ($r['efficiency_score'] >= 40) $eff_level = 'Can be optimized';
                else $eff_level = 'High waste';
        }

        $csv .= implode(',', [
                '"'.str_replace('"', '""', $r['host_name']).'"',
                '"'.str_replace('"', '""', $r['host_groups'] ?? '').'"',
                ($r['cpu_avg'] !== null) ? $r['cpu_avg'] : '',
                ($r['cpu_max'] !== null) ? $r['cpu_max'] : '',
                ($r['ram_avg'] !== null) ? $r['ram_avg'] : '',
                ($r['ram_max'] !== null) ? $r['ram_max'] : '',
                ($r['disk_avg'] !== null) ? $r['disk_avg'] : '',
                ($r['net_in_avg'] !== null) ? $r['net_in_avg'] : '',
                ($r['net_out_avg'] !== null) ? $r['net_out_avg'] : '',
                ($r['load_avg'] !== null) ? $r['load_avg'] : '',
                ($r['waste_score'] !== null) ? $r['waste_score'] : '',
                '"'.$waste_level.'"',
                ($r['efficiency_score'] !== null) ? $r['efficiency_score'] : '',
                '"'.$eff_level.'"',
                ($r['cpu_p95'] !== null) ? $r['cpu_p95'] : '',
                ($r['ram_p95'] !== null) ? $r['ram_p95'] : '',
                ($r['cpu_count'] !== null) ? $r['cpu_count'] : '',
                '"'.$cpu_unit.'"',
                '"'.str_replace('"', '""', $r['cpu_shape'] ?? '').'"',
                ($r['cpu_recommended'] !== null) ? $r['cpu_recommended'] : '',
                '"'.str_replace('"', '""', $r['cpu_recommended_shape'] ?? '').'"',
                ($r['ram_total_gb'] !== null) ? $r['ram_total_gb'] : '',
                ($r['ram_recommended_gb'] !== null) ? $r['ram_recommended_gb'] : '',
                ($r['cpu_trend'] !== null) ? $r['cpu_trend'] : '',
                ($r['ram_trend'] !== null) ? $r['ram_trend'] : '',
                '"'.str_replace('"', '""', $r['recommendation']).'"'
        ])."\n";
}

echo $csv;
