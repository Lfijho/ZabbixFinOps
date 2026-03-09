# Zabbix FinOps Toolkit

[![Zabbix Version](https://img.shields.io/badge/Zabbix-7.0%20to%207.4-red.svg)](https://www.zabbix.com)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.0-777BB4.svg)](https://php.net)

A Zabbix frontend module that identifies **underutilized servers** by analyzing historical metrics, helping teams reduce infrastructure costs through data-driven right-sizing recommendations.

<img width="1600" height="757" alt="image" src="https://github.com/user-attachments/assets/f6b1a9f1-2d1e-41b8-927d-609997733727" />

##  Features

- **Waste Score & Efficiency Score** — quantifies how much each server is under or well-utilized
- **Growth Trend Detection** — compares first week vs. last week averages to avoid premature downsizing
- **Smart Safeguards** — won't recommend reduction if disk, network or load average are saturated
- **Sortable & Filterable Table** — filter by host group, sort by any score column
- **Top 10 Highlight** — visually highlights the most underutilized servers
- **Color-coded Badges** — 🟢 Healthy, 🟡 Moderate, 🔴 High waste at a glance
- **CSV Export** — one-click download for offline analysis or reporting
- **30-day Historical Analysis** — uses Zabbix `trends` tables for efficient queries
- **Right-Sizing Simulation** — suggests concrete vCPU and RAM targets based on P95 usage

## How It Works

The module queries the Zabbix `trends` and `trends_uint` tables for the last 30 days and calculates:

| Metric | Source Item Key |
|--------|----------------|
| CPU utilization (%) | `system.cpu.util` |
| Memory utilization (%) | `vm.memory.utilization` or `vm.memory.size[pavailable]` (auto-inverted) |
| Disk usage (%) | `vfs.fs.size[/,pused]` |
| Network In/Out | `net.if.in` / `net.if.out` |
| Load Average | `system.cpu.load` |

### Waste Score

```
waste_score = 100 - ((cpu_avg + ram_avg) / 2)
```

| Score | Level | Meaning |
|-------|-------|---------|
| ≥ 80 |  HIGH | Server is heavily underutilized |
| 60–79 |  MEDIUM | Potential for optimization |
| 40–59 |  LOW | Moderate usage |
| < 40 |  HEALTHY | Well utilized |

### Efficiency Score

```
efficiency_score = (cpu_avg + ram_avg) / 2
```

| Score | Level |
|-------|-------|
| 70–100 | Healthy usage |
| 40–69 | Can be optimized |
| 0–39 | High waste |

### P95 Peak (Percentile 95)

Instead of using the **absolute maximum** (which can be skewed by a single 1-hour spike in 720 hours), the module uses the **95th percentile** of hourly peaks.

```
Example: A server has 720 trend rows (30 days × 24 hours).
P95 ignores the top 5% (36 hours) of highest peaks.
If P95 is still high, it means the server regularly reaches that load — not just a rare spike.
```

The P95 value is calculated by sorting all hourly `value_max` entries ascending and picking the value at position `floor(count × 0.95)`.

| Metric | P95 Threshold | What it means |
|--------|--------------|---------------|
| CPU P95 | ≥ 60% | Server regularly hits high CPU — not safe to downsize |
| RAM P95 | ≥ 80% | Server regularly hits high RAM — not safe to downsize |

When P95 peaks are high but averages are low, the module shows: _"Server mostly idle but with periodic load spikes. Investigate spike patterns before downsizing."_

### Detection Rules

A server is flagged as **oversized** only when ALL conditions are met:

- CPU average < 20% **AND** CPU P95 < 60%
- RAM average < 40% **AND** RAM P95 < 80%
- Disk usage is NOT near saturation (< 85%)
- Network is NOT persistently high (< 100 MB/s avg)
- No growth trend projected to exceed thresholds (see below)

If any safeguard triggers, the module explains why reduction is not recommended.

### Trend Analysis

The module compares the **average of week 1** (days 1–7) against the **average of week 4** (days 24–30) to detect workload growth.

**Data quality gate:** Each week must have at least **24 hours** of trend data. If a host was recently added or trend data was purged, the trend shows "N/A" instead of producing misleading results.

**Projection-based blocking:** Instead of blocking on any small increase, the module projects forward:

```
cpu_projected = cpu_avg + cpu_trend
ram_projected = ram_avg + ram_trend
```

Downsizing is only blocked if the projected value **would reach or exceed the threshold** (CPU ≥ 20% or RAM ≥ 40%). This means a server at 5% CPU growing +5pp (projected 10%) is still flagged for reduction, while a server at 15% CPU growing +8pp (projected 23%) is correctly held back.

### Right-Sizing Simulation

Beyond detecting waste, the module suggests **concrete right-sizing targets** — answering the question every manager asks: *"If I reduce, what should I reduce to?"*

**Formula:**

```
recommended = P95_usage% × current_allocation × 1.3 (safety margin)
```

| Step | Description |
|------|-------------|
| 1. Read current specs | `system.cpu.num` (vCPUs) and `vm.memory.size[total]` (RAM bytes) |
| 2. Multiply P95 by allocation | How much the server actually needs at the 95th percentile |
| 3. Add 30% safety margin | Prevents cutting too close to real peak usage |
| 4. Round to common VM sizes | vCPU: 1, 2, 4, 8, 16, 32, 48, 64, 96, 128 — RAM: 2, 4, 8, 16, 32, 64, 128, 256, 512 GB |

**Example:**

| Host | vCPUs | vCPU Rec. | RAM | RAM Rec. |
|------|-------|-----------|-----|----------|
| api-prod-01 | 8 vCPU | 4 vCPU | 32 GB | 16 GB |

> A server with 8 vCPUs and CPU P95 of 21% → needs `0.21 × 8 × 1.3 = 2.18` → rounds to **4 vCPU**.

**Notes:**
- Recommendations only appear when the suggested size is **smaller** than current (no upsizing suggestions).
- If `system.cpu.num` or `vm.memory.size[total]` items are not available, the columns show "N/A".
- The "—" symbol means no reduction is recommended (current size is already optimal or near-optimal).

**Safeguards:**
- **P95 = 0%** → recommendation is skipped entirely. A 0% P95 usually indicates missing or broken metric data, so the module won't suggest a reduction based on unreliable numbers.
- **Minimum RAM: 2 GB** — even if the formula calculates a lower value, the module never recommends less than 2 GB. No server can run reliably below this threshold.
- **Minimum vCPU: 1** — the module never recommends less than 1 vCPU.
- Common VM sizes used for rounding: vCPU `1, 2, 4, 8, 16, 32, 48, 64, 96, 128` — RAM `2, 4, 8, 16, 32, 64, 128, 256, 512 GB`.

##  Requirements

- **Zabbix**: 7.0.0 to 7.4.x (tested on 7.4.7)
- **PHP**: 8.0 or higher
- Hosts must be monitored with standard OS templates (Linux by Zabbix Agent, etc.)
- Trends data must be available (at least 1 hour of collection for trends to populate)

##  Installation

Git Clone

```bash
cd /usr/share/zabbix/ui/modules/
git clone https://github.com/Lfijho/ZabbixFinOpsToolkit.git
```


### Enable the Module

1. Log in to the Zabbix frontend as an **Admin** user
2. Navigate to **Administration → General → Modules**
3. Click **Scan directory**
4. Find **"Zabbix FinOps Toolkit"** in the list
5. Click **Enable**

The module will appear in the menu under **Monitoring → Infrastructure Cost Analyzer**.

##  Usage

1. Navigate to **Monitoring → Infrastructure Cost Analyzer**
2. (Optional) Filter by host group using the multiselect dropdown
3. Click **Apply** to filter results
4. Click any column header (**Waste Score**, **Efficiency**, **CPU Avg**, **RAM Avg**) to sort
5. Click **Export CSV** to download the report

### Understanding the Results Table

| Column | Description |
|--------|-------------|
| Host | Server hostname |
| Host Group | Zabbix host group(s) |
| CPU Avg % | Average CPU utilization over 30 days |
| CPU Max % | Absolute peak CPU over 30 days |
| CPU P95 % | 95th percentile of hourly CPU peaks (ignores top 5% spikes) |
| RAM Avg % | Average memory utilization over 30 days |
| RAM Max % | Absolute peak memory over 30 days |
| RAM P95 % | 95th percentile of hourly RAM peaks (ignores top 5% spikes) |
| Disk Avg % | Average root filesystem usage |
| Net In / Net Out | Average network throughput |
| Load Avg | Average system load |
| Waste Score | How underutilized (higher = more waste) |
| Efficiency | How well utilized (higher = better) |
| Trend | CPU/RAM usage direction (+ growth, - decline) |
| Recommendation | Actionable suggestion |

##  Module Structure

```
ZabbixFinOpsToolkit/
├── manifest.json                          # Module metadata and action registration
├── Module.php                             # Menu registration (Monitoring → Infrastructure Cost Analyzer)
├── actions/
│   ├── CostAnalyzer.php                   # Main controller — queries trends, calculates scores
│   └── CostAnalyzerCsvExport.php          # CSV export controller
├── views/
│   ├── finops.costanalyzer.view.php       # HTML table view with filters and badges
│   └── finops.costanalyzer.csv.php        # CSV output template
├── assets/
│   └── css/
│       └── finops-toolkit.css             # Color indicators and card styles
├── docs/
│   └── screenshot-placeholder.png         # Screenshot (replace with actual)
├── README.md
├── CONTRIBUTING.md
├── CHANGELOG.md
└── LICENSE
```

##  Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

### Quick Start for Contributors

1. Fork this repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Make your changes
4. Test in a Zabbix 7.x environment
5. Submit a Pull Request

##  Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

##  License

This project is licensed under the **Apache License 2.0** — see the [LICENSE](LICENSE) file for details.

##  Acknowledgments

- [Zabbix](https://www.zabbix.com/) — the enterprise monitoring platform this module extends
- The FinOps community for inspiration on cloud/infrastructure cost optimization
