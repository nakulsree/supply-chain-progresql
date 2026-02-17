<?php
// set the timezone first so all datetime operations use indianapololis timezone- was causing problems beforehand
date_default_timezone_set('America/Indiana/Indianapolis');
require_once 'config.php';

//initialize all the filter variables - well use these for the WHERE clauses
$filter_start = "";
$filter_end = "";
$filter_region = "";
$filter_company = 0;
$filter_tier = "";

// otherwise default to 1 year ago
if (isset($_GET['start_date'])) {
    $filter_start = $_GET['start_date'];
} else {
    $filter_start = date('Y-m-d', strtotime('-1 year'));
}

if (isset($_GET['end_date'])) {
    $filter_end = $_GET['end_date'];
} else {
    $filter_end = date('Y-m-d');
}

if (isset($_GET['region'])) {
    $filter_region = $_GET['region'];
}

if (isset($_GET['company'])) {
    $filter_company = intval($_GET['company']);
}

// get tier level if provided
if (isset($_GET['tier'])) {
    $filter_tier = $_GET['tier'];
}

// escape all the filter values for use in queries
// (No longer needed with prepared statements, but kept for reference)
$start_escaped = $filter_start;
$end_escaped = $filter_end;
$region_escaped = $filter_region;
$tier_escaped = $filter_tier;

$start_dt = new DateTime($filter_start);
$end_dt = new DateTime($filter_end);
$interval = $start_dt->diff($end_dt);
$observation_months = ($interval->y * 12) + $interval->m + ($interval->d > 0 ? 1 : 0);
if($observation_months < 1) $observation_months = 1;

// initialize the arrays where can store the results from all our queries
$df_data = array();
$art_data = array();
$hdr_data = array();
$td_data = array();
$rrc_data = array();
$dsd_data = array('Low' => 0, 'Medium' => 0, 'High' => 0);
$ongoing_disruptions = array();

//disruption frequency - top 10 companies and how many disruptions per month
//join through ImpactsCompany to get the affected companies
$sql = "SELECT c.CompanyName, c.CompanyID, COUNT(DISTINCT de.EventID) as disruption_count
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate >= ? AND de.EventDate <= ?";

$params = [$filter_start, $filter_end];

if($filter_region) {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $filter_region;
}
if($filter_company > 0) {
    $sql .= " AND c.CompanyID = ?";
    $params[] = $filter_company;
}
if($filter_tier) {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $filter_tier;
}

$sql .= " GROUP BY c.CompanyID, c.CompanyName ORDER BY disruption_count DESC LIMIT 10";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
// calculate the disruption frequency by dividing count by number of months
while ($row = $stmt->fetch()) {
    $count = $row['disruption_count'];
    $freq = $count / $observation_months;
    $freq = round($freq, 2);
    $row['disruption_frequency'] = $freq;
    $df_data[] = $row;
}

// QUERY 2: average recovery time - get all disruptions and calculate how many days recovery took
// only includes disruptions that have been recovered (EventRecoveryDate is not null)
$sql = "SELECT EXTRACT(DAY FROM (de.EventRecoveryDate - de.EventDate)) as recovery_days
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate >= ? AND de.EventDate <= ?
         AND de.EventRecoveryDate IS NOT NULL";

$params = [$filter_start, $filter_end];

if($filter_region) {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $filter_region;
}
if($filter_company > 0) {
    $sql .= " AND c.CompanyID = ?";
    $params[] = $filter_company;
}
if($filter_tier) {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $filter_tier;
}

$recovery_times = array();
$stmt = $conn->prepare($sql);
$stmt->execute($params);
while ($row = $stmt->fetch()) {
    $recovery_times[] = intval($row['recovery_days']);
}

//   bin the recovery times into ranges: 0-5 days, 5-10 days, etc...
$bins = array('0-5' => 0, '5-10' => 0, '10-15' => 0, '15-20' => 0, '20+' => 0);
foreach($recovery_times as $days) {
    if($days >= 0 && $days < 5) {
        $bins['0-5'] = $bins['0-5'] + 1;
    } else if($days >= 5 && $days < 10) {
        $bins['5-10'] = $bins['5-10'] + 1;
    } else if($days >= 10 && $days < 15) {
        $bins['10-15'] = $bins['10-15'] + 1;
    } else if($days >= 15 && $days < 20) {
        $bins['15-20'] = $bins['15-20'] + 1;
    } else if($days >= 20) {
        $bins['20+'] = $bins['20+'] + 1;
    }
}

$art_data = $bins;
// calculate the overall average by dividing total recovery days by number of disruptions
$total_recovery_days = array_sum($recovery_times);
$total_disruptions = count($recovery_times);
if($total_disruptions > 0) {
    $overall_art = round($total_recovery_days / $total_disruptions, 2);
} else {
    $overall_art = 0;
}

// QUERY 3: high-impact disruption rate - what percent of disruptions for each company are high impact
// uses CASE WHEN to count only the high severity ones
$sql = "SELECT c.CompanyName,
         COUNT(DISTINCT CASE WHEN ic.ImpactLevel = 'High' THEN de.EventID END) as high_count,
         COUNT(DISTINCT de.EventID) as total_count
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate >= ? AND de.EventDate <= ?";

$params = [$filter_start, $filter_end];

if($filter_region) {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $filter_region;
}
if($filter_company > 0) {
    $sql .= " AND c.CompanyID = ?";
    $params[] = $filter_company;
}
if($filter_tier) {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $filter_tier;
}

$sql .= " GROUP BY c.CompanyID, c.CompanyName
          HAVING COUNT(DISTINCT de.EventID) > 0
          ORDER BY (COUNT(DISTINCT CASE WHEN ic.ImpactLevel = 'High' THEN de.EventID END) / COUNT(DISTINCT de.EventID)) DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
while ($row = $stmt->fetch()) {
    $total = $row['total_count'];
    $high = $row['high_count'];
    if($total > 0) {
        $rate = round(($high / $total) * 100, 1);
    } else {
        $rate = 0;
    }
    $company_name = $row['companyname'];
    $hdr_row = array();
    $hdr_row['company'] = $company_name;
    $hdr_row['high_rate'] = $rate;
    $hdr_data[] = $hdr_row;
}

// QUERY 4: total downtime - SUM up all the days each company was down
$sql = "SELECT c.CompanyName,
         SUM(EXTRACT(DAY FROM (de.EventRecoveryDate - de.EventDate))) as total_downtime_days
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate >= ? AND de.EventDate <= ?
         AND de.EventRecoveryDate IS NOT NULL";

$params = [$filter_start, $filter_end];

if($filter_region) {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $filter_region;
}
if($filter_company > 0) {
    $sql .= " AND c.CompanyID = ?";
    $params[] = $filter_company;
}
if($filter_tier) {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $filter_tier;
}

$sql .= " GROUP BY c.CompanyID, c.CompanyName ORDER BY total_downtime_days DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$td_data_raw = array();
while ($row = $stmt->fetch()) {
    $td_data_raw[] = $row;
}

// bin the total downtime days into ranges: 0-5 days, 5-10 days, etc...
$td_bins = array('0-5' => 0, '5-10' => 0, '10-15' => 0, '15-20' => 0, '20+' => 0);
foreach($td_data_raw as $row) {
    $days = intval($row['total_downtime_days']);
    if($days < 5) {
        $td_bins['0-5']++;
    } elseif($days < 10) {
        $td_bins['5-10']++;
    } elseif($days < 15) {
        $td_bins['10-15']++;
    } elseif($days < 20) {
        $td_bins['15-20']++;
    } else {
        $td_bins['20+']++;
    }
}
$td_data_chart = $td_bins;
$td_data = $td_data_raw;

// QUERY 5: regional risk concentration - basically a heatmap showing disruption counts by region and month
// this lets us see which regions have recurring problems
$sql = "SELECT l.ContinentName as region, TO_CHAR(de.EventDate, 'YYYY-MM') as month, COUNT(DISTINCT de.EventID) as disruption_count
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate >= ? AND de.EventDate <= ?";

$params = [$filter_start, $filter_end];

if($filter_region) {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $filter_region;
}
if($filter_company > 0) {
    $sql .= " AND c.CompanyID = ?";
    $params[] = $filter_company;
}
if($filter_tier) {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $filter_tier;
}

$sql .= " GROUP BY l.ContinentName, TO_CHAR(de.EventDate, 'YYYY-MM') ORDER BY l.ContinentName, TO_CHAR(de.EventDate, 'YYYY-MM')";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

// build a 2d array (region x month) to display as a heatmap later
$rrc_heatmap = array();
$all_regions = array();
$all_months = array();

while ($row = $stmt->fetch()) {
    $region = $row['region'];
    $month = $row['month'];
    $count = intval($row['disruption_count']);
    
    if(!isset($rrc_heatmap[$region])) {
        $rrc_heatmap[$region] = array();
    }
    $rrc_heatmap[$region][$month] = $count;
    
    if(!in_array($region, $all_regions)) $all_regions[] = $region;
    if(!in_array($month, $all_months)) $all_months[] = $month;
}

// sort the regions and months so the heatmap looks organized
sort($all_regions);
sort($all_months);

$rrc_data = array();
$total_rrc = 0;
foreach($rrc_heatmap as $region => $months) {
    $region_total = array_sum($months);
    $total_rrc += $region_total;
    $rrc_data[] = array('region' => $region, 'count' => $region_total);
}

// QUERY 6: disruption severity distribution - how many low, medium, and high impact events
$sql = "SELECT ic.ImpactLevel, COUNT(DISTINCT de.EventID) as severity_count
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate >= ? AND de.EventDate <= ?";

$params = [$filter_start, $filter_end];

if($filter_region) {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $filter_region;
}
if($filter_company > 0) {
    $sql .= " AND c.CompanyID = ?";
    $params[] = $filter_company;
}
if($filter_tier) {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $filter_tier;
}

$sql .= " GROUP BY ic.ImpactLevel";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
// populate the dsd_data array with counts from the query
while ($row = $stmt->fetch()) {
    $level = $row['ilevel'];
    $count = intval($row['severity_count']);
    if($level == 'Low') {
        $dsd_data['Low'] = $count;
    } else if($level == 'Medium') {
        $dsd_data['Medium'] = $count;
    } else if($level == 'High') {
        $dsd_data['High'] = $count;
    }
}

// QUERY 7: get ongoing disruptions - these are alerts for unrecovered events
$sql = "SELECT de.EventID, de.EventDate, dc.CategoryName, c.CompanyName, 
         ic.ImpactLevel, l.ContinentName
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         WHERE de.EventRecoveryDate IS NULL
         AND de.EventDate >= ? AND de.EventDate <= ?";

$params = [$filter_start, $filter_end];

if($filter_region) {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $filter_region;
}
if($filter_company > 0) {
    $sql .= " AND c.CompanyID = ?";
    $params[] = $filter_company;
}
if($filter_tier) {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $filter_tier;
}

$sql .= " ORDER BY de.EventDate DESC LIMIT 10";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
while ($row = $stmt->fetch()) {
    $ongoing_disruptions[] = $row;
}

// QUERY 8: get all distinct companies for the dropdown filter
$companies = array();
$sql = "SELECT DISTINCT CompanyID, CompanyName FROM Company ORDER BY CompanyName LIMIT 100";
$stmt = $conn->prepare($sql);
$stmt->execute();
while ($c = $stmt->fetch()) {
    $companies[] = $c;
}

// get all distinct regions for the dropdown filter
$all_region_list = array();
$sql = "SELECT DISTINCT l.ContinentName FROM Location l ORDER BY l.ContinentName";
$stmt = $conn->prepare($sql);
$stmt->execute();
while($r = $stmt->fetch()) {
    $all_region_list[] = $r['ContinentName'];
}

$regions = $all_region_list;

// Database connection is managed by config.php/PDO
// PDO doesn't require explicit close like MySQLi
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disruptions - Supply Chain Analytics</title>
    <!-- link to external CSS stylesheet -->
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="disruptions.css">
    <!-- import chart.js library from CDN for rendering the charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<header>
    <h1>Supply Chain Manager - Disruptions Analysis</h1>
    <nav>
        <a href="overview.php">Overview</a> |
        <a href="companies.php">Companies</a> |
        <a href="disruptions.php">Disruptions</a> |
	<a href="transactions.php">Transactions</a> |
	<a href="distributors.php">Distributor Details</a> |
	<a href="logout.php">Logout</a> |
    </nav>
</header>

<div class="container">

<section>
<h2>Filters</h2>
<form method="GET">
<label>Start Date:
<input type="date" name="start_date" value="<?php echo htmlspecialchars($filter_start); ?>">
</label>
<label>End Date:
<input type="date" name="end_date" value="<?php echo htmlspecialchars($filter_end); ?>">
</label>
<label>Region:
<select name="region">
<option value="">All Regions</option>
<?php
// populate region dropdown with all distinct regions from database
foreach($regions as $r) {
    $selected = ($filter_region === $r) ? 'selected' : '';
    echo '<option value="' . htmlspecialchars($r) . '" ' . $selected . '>' . htmlspecialchars($r) . '</option>';
}
?>
</select>
</label>
<label>Company:
<select name="company">
<option value="">All Companies</option>
<?php
// populate company dropdown with all companies
foreach($companies as $c) {
    $selected = ($filter_company == $c['CompanyID']) ? 'selected' : '';
    echo '<option value="' . $c['CompanyID'] . '" ' . $selected . '>' . htmlspecialchars($c['CompanyName']) . '</option>';
}
?>
</select>
</label>
<label>Tier Level:
<select name="tier">
<option value="">All Tiers</option>
<option value="1" <?php echo ($filter_tier === '1') ? 'selected' : ''; ?>>Tier 1</option>
<option value="2" <?php echo ($filter_tier === '2') ? 'selected' : ''; ?>>Tier 2</option>
<option value="3" <?php echo ($filter_tier === '3') ? 'selected' : ''; ?>>Tier 3</option>
</select>
</label>
<button type="submit">Apply Filters</button>
</form>
</section>

<!-- show active/ongoing disruptions if any exist -->
<?php if(count($ongoing_disruptions) > 0): ?>
<section>
<h2>Active/Ongoing Disruptions (Alerts)</h2>
<div class="table-scroll-container">
<table>
<tr><th>Company</th><th>Category</th><th>Region</th><th>Severity</th><th>Date</th></tr>
<?php foreach($ongoing_disruptions as $alert): ?>
<tr>
<td><?php echo htmlspecialchars($alert['companyname']); ?></td>
<td><?php echo htmlspecialchars($alert['categoryname']); ?></td>
<td><?php echo htmlspecialchars($alert['continentname']); ?></td>
<td><?php echo htmlspecialchars($alert['impactlevel']); ?></td>
<td><?php echo substr($alert['eventdate'], 0, 10); ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
</section>
<?php else: ?>
<!-- show message if there are no active disruptions -->
<div class="alert-message">No active disruptions in the selected date range.</div>
<?php endif; ?>
<!-- METRIC 1: Disruption Frequency - shows which companies have the most disruptions per month -->
<div class="metric-section">
<h3>Disruption Frequency (Top 10 Companies) - Disruptions per Month</h3>
<?php if(count($df_data) > 0): ?>
<canvas id="dfChart" height="100"></canvas>
<div class="table-scroll-container">
<table>
<tr><th>Company</th><th>Total Count</th><th>Frequency/Month</th></tr>
<?php foreach($df_data as $item): ?>
<tr>
<td><?php echo htmlspecialchars($item['companyname']); ?></td>
<td><?php echo intval($item['disruption_count']); ?></td>
<td><?php echo floatval($item['disruption_frequency']); ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php else: ?>
<p class="alert-message">No disruption data available</p>
<?php endif; ?>
</div>

<!-- METRIC 2: Average Recovery Time - histogram showing distribution of how long it takes to recover -->
<div class="metric-section">
<h3>Average Recovery Time - Distribution Histogram (days)</h3>
<?php if(count($recovery_times) > 0): ?>
<p class="metric-label">Overall ART: <span class="metric-highlight"><?php echo $overall_art; ?></span> days (<?php echo $total_disruptions; ?> disruptions in period)</p>
<canvas id="artChart" height="100"></canvas>
<div class="table-scroll-container">
<table>
<tr><th>Recovery Time Range</th><th>Count</th><th>Percentage</th></tr>
<?php foreach($art_data as $range => $count): ?>
<tr>
<td><?php echo htmlspecialchars($range); ?> days</td>
<td><?php echo intval($count); ?></td>
<td><?php echo $total_disruptions > 0 ? number_format(($count / $total_disruptions) * 100, 1) : 0; ?>%</td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php else: ?>
<p class="alert-message">No recovery data available</p>
<?php endif; ?>
</div>

<!-- METRIC 3: High-Impact Disruption Rate - what percentage of events are high severity for each company -->
<div class="metric-section">
<h3>High-Impact Disruption Rate (%)</h3>
<?php if(count($hdr_data) > 0): ?>
<div class="table-scroll-container">
<table>
<tr><th>Company</th><th>High %</th></tr>
<?php foreach($hdr_data as $item): ?>
<tr>
<td><?php echo htmlspecialchars($item['company']); ?></td>
<td><?php echo $item['high_rate']; ?>%</td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php else: ?>
<p class="alert-message">No disruption rate data available</p>
<?php endif; ?>
</div>

<!-- METRIC 4: Total Downtime - summing up all the days companies were down -->
<div class="metric-section">
<h3>Total Downtime (by Company, days)</h3>
<?php if(count($td_data) > 0): ?>
<canvas id="tdChart" height="100"></canvas>
<div class="table-scroll-container">
<table>
<tr><th>Company</th><th>Days</th></tr>
<?php foreach($td_data as $item): ?>
<tr>
<td><?php echo htmlspecialchars($item['companyname']); ?></td>
<td><?php echo intval($item['total_downtime_days']); ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php else: ?>
<p class="alert-message">No downtime data available</p>
<?php endif; ?>
</div>

<!-- METRIC 5: Regional Risk Concentration - 2D heatmap showing which regions have problems in which months -->
<div class="metric-section">
<h2>Regional Risk Concentration - 2D Heatmap (Disruptions by Region & Month)</h2>
<?php if(count($rrc_heatmap) > 0 && count($all_months) > 0): ?>
<div class="table-scroll-container">
<table class="heatmap-table">
<tr>
<th class="heatmap-header">Region</th>
<?php foreach($all_months as $month): ?>
<th class="heatmap-header"><?php echo htmlspecialchars($month); ?></th>
<?php endforeach; ?>
</tr>
<?php foreach($all_regions as $region): ?>
<tr>
<td class="heatmap-region"><?php echo htmlspecialchars($region); ?></td>
<?php foreach($all_months as $month): ?>
<?php 
// determine the background color based on disruption count - darker colors = more disruptions
$value = isset($rrc_heatmap[$region][$month]) ? $rrc_heatmap[$region][$month] : 0;
$bgColor = '';
if($value == 0) {
    $bgColor = '#ffffff';
}
if($value > 0 && $value <= 2) {
    $bgColor = '#e8f5e9';
}
if($value > 2 && $value <= 5) {
    $bgColor = '#a5d6a7';
}
if($value > 5 && $value <= 10) {
    $bgColor = '#ffd54f';
}
if($value > 10 && $value <= 15) {
    $bgColor = '#ffb74d';
}
if($value > 15) {
    $bgColor = '#e53935';
}
?>
<td class="heatmap-cell" style="background-color: <?php echo $bgColor; ?>;"><?php echo $value; ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php else: ?>
<p class="alert-message">No regional data available</p>
<?php endif; ?>
</div>

<!-- METRIC 6: Disruption Severity Distribution - pie chart showing counts of low/medium/high impact events -->
<div class="metric-section">
<h2>Disruption Severity Distribution</h2>
<?php if(isset($dsd_data['Low']) || isset($dsd_data['Medium']) || isset($dsd_data['High'])): ?>
<canvas id="dsdChart" height="100"></canvas>
<?php endif; ?>
<div class="table-scroll-container">
<table>
<tr><th>Severity</th><th>Count</th></tr>
<tr>
<td>Low</td>
<td><?php echo isset($dsd_data['Low']) ? intval($dsd_data['Low']) : 0; ?></td>
</tr>
<tr>
<td>Medium</td>
<td><?php echo isset($dsd_data['Medium']) ? intval($dsd_data['Medium']) : 0; ?></td>
</tr>
<tr>
<td>High</td>
<td><?php echo isset($dsd_data['High']) ? intval($dsd_data['High']) : 0; ?></td>
</tr>
</table>
</div>
</div>

</div>

<!-- pass PHP data to JS -->
<script>
  const dfData = <?php echo json_encode($df_data); ?>;
  const artData = <?php echo json_encode($art_data); ?>;
  const tdData = <?php echo json_encode($td_data_chart); ?>;
  const dsdData = <?php echo json_encode($dsd_data); ?>;
</script>

<script>
  // chart 1: disruption frequency
  (function() {
    const ctx = document.getElementById('dfChart');
    if (!ctx || !dfData || dfData.length === 0) return;

    const labels = dfData.map(d => d.companyname);
    const freqs = dfData.map(d => Number(d.disruption_frequency));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Disruptions per Month',
          data: freqs
        }]
      },
      options: {
        responsive: true,
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // chart 2: average recovery time
  (function() {
    const ctx = document.getElementById('artChart');
    if (!ctx || !artData) return;

    const labels = Object.keys(artData).map(k => k + ' days');
    const counts = Object.values(artData).map(v => Number(v));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Number of Disruptions',
          data: counts
        }]
      },
      options: {
        responsive: true,
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // chart 3: total downtime (histogram)
  (function() {
    const ctx = document.getElementById('tdChart');
    if (!ctx || !tdData) return;

    const labels = Object.keys(tdData).map(k => k + ' days');
    const counts = Object.values(tdData).map(v => Number(v));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Number of Companies',
          data: counts
        }]
      },
      options: {
        responsive: true,
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // chart 4: disruption severity distribution (stacked bar)
  (function() {
    const ctx = document.getElementById('dsdChart');
    if (!ctx || !dsdData) return;

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['Disruptions'],
        datasets: [
          {
            label: 'Low',
            data: [Number(dsdData.Low || 0)],
            backgroundColor: 'rgba(33, 150, 243, 0.5)'
          },
          {
            label: 'Medium',
            data: [Number(dsdData.Medium || 0)],
            backgroundColor: 'rgba(33, 150, 243, 0.65)'
          },
          {
            label: 'High',
            data: [Number(dsdData.High || 0)],
            backgroundColor: 'rgba(33, 150, 243, 0.8)'
          }
        ]
      },
      options: {
        responsive: true,
        scales: {
          x: { stacked: true },
          y: { stacked: true, beginAtZero: true }
        }
      }
    });
  })();
</script>

</body>
</html>
