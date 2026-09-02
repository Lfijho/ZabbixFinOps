# Changelog

All notable changes to Zabbix FinOps Toolkit will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Selectable analysis windows: 1 hour, 1, 2, 5, 7, 14 and 30 days
- Raw history queries for the 1-hour window, including the current unconsolidated hour
- CSV export now preserves the selected analysis window

### Changed
- Growth trend intervals and minimum sample requirements now adapt to the selected window

## [1.0.0] - 2026-03-09

### Added
- Initial release of the Infrastructure Cost Analyzer module
- Waste Score calculation: `100 - ((cpu_avg + ram_avg) / 2)`
- Efficiency Score calculation: `(cpu_avg + ram_avg) / 2`
- 30-day historical analysis using Zabbix `trends` and `trends_uint` tables
- Growth trend detection comparing first week vs last week averages
- Smart safeguards preventing false recommendations when disk, network or load are saturated
- Metrics analyzed: CPU utilization, memory utilization, disk usage, network I/O, load average
- Support for multiple RAM item keys: `vm.memory.utilization` and `vm.memory.size[pavailable]` (auto-inverted)
- Exact match for CPU key `system.cpu.util` to avoid matching sub-metrics
- Host group filter with multiselect dropdown
- Sortable columns: Waste Score, Efficiency Score, CPU Avg, RAM Avg
- Top 10 most underutilized servers highlighted
- Color-coded badges: green (healthy), yellow (moderate), red (high waste)
- CSV export with UTF-8 BOM for Excel compatibility
- Summary cards showing total hosts analyzed, potentially oversized count, and high waste count
- Menu integration under Monitoring → Infrastructure Cost Analyzer
- Compatible with Zabbix 7.0.0 through 7.4.x
