<?php declare(strict_types = 0);
/**
 * Zabbix FinOps Toolkit - Infrastructure Cost Analyzer View
 * Clean Minimal Design
 *
 * @var CView $this
 * @var array $data
 */

// Build filter form with brutalist styling.
$filter_form = (new CForm('get'))
    ->setName('finops_filter')
    ->setAttribute('aria-label', _('Filter'))
    ->addClass('finops-filter-form');

$filter_form->addVar('action', 'finops.costanalyzer.view');

// Host group multiselect filter.
$selected_groups = [];
if (!empty($data['filter_groupids'])) {
    foreach ($data['host_groups'] as $group) {
        if (in_array($group['groupid'], $data['filter_groupids'])) {
            $selected_groups[] = [
                'id'   => $group['groupid'],
                'name' => $group['name']
            ];
        }
    }
}

$filter_form->addItem([
    (new CDiv([
        (new CLabel(_('Host Groups'), 'filter_groupids_'))
            ->addClass('finops-filter-label'),
        (new CDiv(
            (new CMultiSelect([
                'name' => 'filter_groupids[]',
                'object_name' => 'hostGroup',
                'data' => $selected_groups,
                'popup' => [
                    'parameters' => [
                        'srctbl' => 'host_groups',
                        'srcfld1' => 'groupid',
                        'dstfrm' => 'finops_filter',
                        'dstfld1' => 'filter_groupids_'
                    ]
                ]
            ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
        ))->addClass('finops-filter-select-wrapper')
    ]))->addClass('finops-filter-group'),
    (new CSubmitButton(_('Apply Filter'), 'filter_apply', '1'))
        ->addClass('finops-btn finops-btn-primary')
]);

// CSV export link.
$csv_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'finops.costanalyzer.csv');

if (!empty($data['filter_groupids'])) {
    foreach ($data['filter_groupids'] as $gid) {
        $csv_url->setArgument('filter_groupids[]', $gid);
    }
}

// Calculate summary statistics.
$results = $data['results'];
$total_hosts = count($results);
$oversized_count = 0;
$high_waste_count = 0;
$potential_savings = 0;

foreach ($results as $r) {
    if ($r['waste_level'] === _('HIGH') || $r['waste_level'] === _('MEDIUM')) {
        $oversized_count++;
    }
    if ($r['waste_level'] === _('HIGH')) {
        $high_waste_count++;
        if ($r['waste_score'] !== null) {
            $potential_savings += ($r['waste_score'] / 100) * 50;
        }
    }
}
$potential_savings = round($potential_savings, 0);

// Summary cards grid.
$summary = (new CDiv([
    // Total Hosts Card
    (new CDiv([
        (new CSpan(_('Hosts Analyzed')))->addClass('finops-stat-label'),
        (new CSpan((string)$total_hosts))->addClass('finops-stat-value')
    ]))->addClass('finops-stat-card'),

    // Oversized Card
    (new CDiv([
        (new CSpan(_('Potentially Oversized')))->addClass('finops-stat-label'),
        (new CSpan((string)$oversized_count))->addClass('finops-stat-value')
    ]))->addClass('finops-stat-card finops-stat-card--warning'),

    // High Waste Card
    (new CDiv([
        (new CSpan(_('Critical Waste')))->addClass('finops-stat-label'),
        (new CSpan((string)$high_waste_count))->addClass('finops-stat-value')
    ]))->addClass('finops-stat-card finops-stat-card--critical'),

    ]))->addClass('finops-summary-grid');

// Table sorting setup.
$sort = $data['sort'];
$sortorder = $data['sortorder'];

$base_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'finops.costanalyzer.view')
    ->getUrl();

// Helper: Create sortable header.
function make_brutalist_header($label, $field, $current_sort, $current_order, $base_url) {
    $is_sorted = ($current_sort === $field);
    $next_order = ($is_sorted && $current_order === 'DESC') ? 'ASC' : 'DESC';

    $url = (new CUrl($base_url))
        ->setArgument('sort', $field)
        ->setArgument('sortorder', $next_order);

    $class = 'finops-text-mono';
    if ($is_sorted) {
        $class .= ' sort-' . strtolower($current_order);
    }

    $indicator = $is_sorted
        ? (new CSpan($current_order === 'DESC' ? ' ▼' : ' ▲'))->addClass('sort-indicator')
        : (new CSpan(' ▼'))->addClass('sort-indicator');

    return (new CColHeader([
        (new CLink([$label, $indicator], $url->getUrl()))
            ->addClass($is_sorted ? 'sortable' : ''),
    ]));
}

// Table headers.
$header = [
    (new CColHeader(_('Host')))->addClass('finops-text-mono'),
    (new CColHeader(_('Group')))->addClass('finops-text-mono'),
    make_brutalist_header(_('CPU Avg'), 'cpu_avg', $sort, $sortorder, $base_url),
    (new CColHeader(_('CPU Max')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('CPU P95')))->addClass('finops-text-mono finops-text-right'),
    make_brutalist_header(_('RAM Avg'), 'ram_avg', $sort, $sortorder, $base_url),
    (new CColHeader(_('RAM Max')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('RAM P95')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('Net In')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('Net Out')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('Load')))->addClass('finops-text-mono finops-text-right'),
    make_brutalist_header(_('Waste'), 'waste_score', $sort, $sortorder, $base_url),
    make_brutalist_header(_('Efficiency'), 'efficiency_score', $sort, $sortorder, $base_url),
    (new CColHeader(_('vCPUs')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('vCPU Rec.')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('RAM')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('RAM Rec.')))->addClass('finops-text-mono finops-text-right'),
    (new CColHeader(_('Trend')))->addClass('finops-text-mono'),
    (new CColHeader(_('Recommendation')))->addClass('finops-text-mono'),
];

$table = (new CTableInfo())
    ->setHeader($header)
    ->addClass('finops-table');

// Helper: Format network values.
function formatNetworkBrutalist($bytes_per_sec) {
    if ($bytes_per_sec === null) {
        return (new CSpan('N/A'))->addClass('finops-cell-metric--na');
    }

    $val = (float) $bytes_per_sec;

    if ($val >= 1073741824) {
        $result = round($val / 1073741824, 2) . ' GB';
    } elseif ($val >= 1048576) {
        $result = round($val / 1048576, 2) . ' MB';
    } elseif ($val >= 1024) {
        $result = round($val / 1024, 2) . ' KB';
    } else {
        $result = round($val, 2) . ' B';
    }

    $class = 'finops-cell-metric';
    if ($val > 100000000) {
        $class .= ' finops-cell-metric--high';
    } elseif ($val > 10000000) {
        $class .= ' finops-cell-metric--medium';
    } else {
        $class .= ' finops-cell-metric--low';
    }

    return (new CSpan($result))->addClass($class);
}

// Helper: Format percentage.
function formatPctBrutalist($value, $low_thresh = 30, $high_thresh = 80) {
    if ($value === null) {
        return (new CSpan('N/A'))->addClass('finops-cell-metric--na');
    }

    $class = 'finops-cell-metric';
    if ($value < $low_thresh) {
        $class .= ' finops-cell-metric--low';
    } elseif ($value > $high_thresh) {
        $class .= ' finops-cell-metric--high';
    } else {
        $class .= ' finops-cell-metric--medium';
    }

    return (new CSpan(round($value, 1) . '%'))->addClass($class);
}

// Helper: Get waste badge class.
function getWasteBadgeClass($level): string {
    switch ($level) {
        case 'HIGH':   return 'finops-badge--high';
        case 'MEDIUM': return 'finops-badge--medium';
        case 'LOW':    return 'finops-badge--low';
        default:       return 'finops-badge--healthy';
    }
}

// Helper: Get trend indicator.
function getTrendBrutalist($cpu_trend, $ram_trend) {
    $items = [];

    if ($cpu_trend !== null) {
        $sign = $cpu_trend >= 0 ? '+' : '';
        $class = $cpu_trend >= 0 ? 'finops-trend-up' : 'finops-trend-down';
        $items[] = (new CDiv([
            (new CSpan('CPU:'))->addClass('finops-text-muted'),
            ' ',
            (new CSpan($sign . round($cpu_trend, 1) . '%'))->addClass($class)
        ]))->addClass('finops-trend-item');
    }

    if ($ram_trend !== null) {
        $sign = $ram_trend >= 0 ? '+' : '';
        $class = $ram_trend >= 0 ? 'finops-trend-up' : 'finops-trend-down';
        $items[] = (new CDiv([
            (new CSpan('RAM:'))->addClass('finops-text-muted'),
            ' ',
            (new CSpan($sign . round($ram_trend, 1) . '%'))->addClass($class)
        ]))->addClass('finops-trend-item');
    }

    if (empty($items)) {
        return (new CSpan('N/A'))->addClass('finops-cell-metric--na');
    }

    return (new CDiv($items))->addClass('finops-trend');
}

// Build table rows.
foreach ($results as $r) {
    $waste_level_raw = ($r['waste_score'] !== null)
        ? (($r['waste_score'] >= 80) ? 'HIGH' : (($r['waste_score'] >= 60) ? 'MEDIUM' : (($r['waste_score'] >= 40) ? 'LOW' : 'HEALTHY')))
        : 'HEALTHY';

    $row_class = '';
    if ($waste_level_raw === 'HIGH') {
        $row_class = 'finops-row-high-waste';
    }

    // Waste badge.
    $waste_badge = ($r['waste_score'] !== null)
        ? (new CSpan([
            $r['waste_score'],
            ' (',
            $r['waste_level'],
            ')'
        ]))->addClass('finops-badge ' . getWasteBadgeClass($waste_level_raw))
        : (new CSpan('N/A'))->addClass('finops-cell-metric--na');

    // Efficiency badge.
    $eff_class = 'finops-badge--healthy';
    if ($r['efficiency_score'] !== null) {
        if ($r['efficiency_score'] < 40) {
            $eff_class = 'finops-badge--high';
        } elseif ($r['efficiency_score'] < 70) {
            $eff_class = 'finops-badge--medium';
        } else {
            $eff_class = 'finops-badge--healthy';
        }
    }

    $eff_badge = ($r['efficiency_score'] !== null)
        ? (new CSpan([
            $r['efficiency_score'],
            '%'
        ]))->addClass('finops-badge ' . $eff_class)
        : (new CSpan('N/A'))->addClass('finops-cell-metric--na');

    $row = new CRow([
        (new CCol($r['host_name']))->addClass('finops-cell-host'),
        (new CCol($r['host_groups']))->addClass('finops-cell-group'),
        (new CCol(formatPctBrutalist($r['cpu_avg'], 20, 60)))->addClass('finops-cell-metric'),
        (new CCol(formatPctBrutalist($r['cpu_max'], 60, 85)))->addClass('finops-cell-metric'),
        (new CCol(formatPctBrutalist($r['cpu_p95'] ?? null, 60, 85)))->addClass('finops-cell-metric'),
        (new CCol(formatPctBrutalist($r['ram_avg'], 40, 80)))->addClass('finops-cell-metric'),
        (new CCol(formatPctBrutalist($r['ram_max'], 80, 95)))->addClass('finops-cell-metric'),
        (new CCol(formatPctBrutalist($r['ram_p95'] ?? null, 80, 95)))->addClass('finops-cell-metric'),
        (new CCol(formatNetworkBrutalist($r['net_in_avg'])))->addClass('finops-cell-metric'),
        (new CCol(formatNetworkBrutalist($r['net_out_avg'])))->addClass('finops-cell-metric'),
        (new CCol(
            ($r['load_avg'] !== null)
                ? (new CSpan($r['load_avg']))->addClass('finops-cell-metric')
                : (new CSpan('N/A'))->addClass('finops-cell-metric--na')
        ))->addClass('finops-cell-metric'),
        (new CCol($waste_badge))->addClass('finops-text-center'),
        (new CCol($eff_badge))->addClass('finops-text-center'),
        // Right-sizing columns.
        (new CCol(
            ($r['cpu_count'] !== null)
                ? (new CSpan($r['cpu_count'].' vCPU'))->addClass('finops-cell-metric')
                : (new CSpan('N/A'))->addClass('finops-cell-metric--na')
        ))->addClass('finops-cell-metric'),
        (new CCol(
            ($r['cpu_recommended'] !== null)
                ? (new CSpan($r['cpu_recommended'].' vCPU'))->addClass('finops-cell-metric finops-cell-metric--low')
                : (new CSpan('—'))->addClass('finops-cell-metric--na')
        ))->addClass('finops-cell-metric'),
        (new CCol(
            ($r['ram_total_gb'] !== null)
                ? (new CSpan($r['ram_total_gb'].' GB'))->addClass('finops-cell-metric')
                : (new CSpan('N/A'))->addClass('finops-cell-metric--na')
        ))->addClass('finops-cell-metric'),
        (new CCol(
            ($r['ram_recommended_gb'] !== null)
                ? (new CSpan($r['ram_recommended_gb'].' GB'))->addClass('finops-cell-metric finops-cell-metric--low')
                : (new CSpan('—'))->addClass('finops-cell-metric--na')
        ))->addClass('finops-cell-metric'),
        (new CCol(getTrendBrutalist($r['cpu_trend'], $r['ram_trend']))),
        (new CCol(
            (new CSpan($r['recommendation']))->addClass('finops-recommendation')
        )),
    ]);

    if ($row_class) {
        $row->addClass($row_class);
    }

    $table->addRow($row);
}

// Empty state.
if (empty($results)) {
    $table->addRow(
        (new CCol(
            (new CDiv([
                (new CTag('h3', true, _('No Data Available')))->addClass('finops-empty-title'),
                (new CSpan(_('Select host groups and apply filters to see cost analysis.')))->addClass('finops-empty-text')
            ]))->addClass('finops-empty-state')
        ))->setColSpan(19)
    );
}

// Build page structure.
$page = (new CHtmlPage())
    ->setTitle(_('Infrastructure Cost Analyzer'))
    ->setDocUrl('https://www.zabbix.com/documentation/');

// Header section.
$header_section = (new CDiv([
    (new CDiv([
        (new CDiv([
            (new CDiv('Fn'))->addClass('finops-logo'),
            (new CDiv([
                (new CTag('h1', true, _('Infrastructure Cost Analyzer')))->addClass('finops-title'),
                (new CSpan(_('Resource utilization & cost optimization analysis')))->addClass('finops-subtitle')
            ]))->addClass('finops-title-group')
        ]))->addClass('finops-title-block'),
        (new CDiv(
            (new CLink(_('Export CSV'), $csv_url->getUrl()))
                ->addClass('finops-btn finops-btn-accent')
        ))->addClass('finops-header-actions')
    ]))->addClass('finops-header-inner')
]))->addClass('finops-header');

// Filter section.
$filter_section = (new CDiv(
    (new CDiv($filter_form))->addClass('finops-filter-inner')
))->addClass('finops-filter-section');

// Summary section.
$summary_section = (new CDiv($summary))->addClass('finops-summary-section');

// Table section with header.
$table_section = (new CDiv([
    (new CDiv([
        (new CSpan(_('Analysis Results')))->addClass('finops-section-title'),
        (new CSpan($total_hosts . ' ' . _('hosts')))->addClass('finops-section-meta')
    ]))->addClass('finops-section-header'),
    (new CDiv($table))->addClass('finops-table-wrapper')
]))->addClass('finops-table-section');

// Main container.
$container = (new CDiv([
    $header_section,
    $filter_section,
    $summary_section,
    $table_section
]))->addClass('finops-container');

$page->addItem($container);
$page->show();
