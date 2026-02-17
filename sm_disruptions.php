<?php
session_start();
require_once 'config.php';

// FILTERS
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '2022-01-01';
$endDate   = isset($_GET['end_date'])   ? $_GET['end_date']   : '2025-12-31';

$selectedEventID   = isset($_GET['event_id'])   ? (int)$_GET['event_id']   : 0;
$selectedCompanyID = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;

// QUERY 1: Regional disruption overview
$regionalRows = [];

$sqlRegional = "
SELECT 
    l.ContinentName AS Region,
    COUNT(DISTINCT de.EventID) AS TotalEvents,
    COUNT(DISTINCT CASE 
        WHEN ic.ImpactLevel = 'High' THEN de.EventID
        ELSE NULL
    END) AS HighImpactEvents
FROM DisruptionEvent de
JOIN ImpactsCompany ic
    ON de.EventID = ic.EventID
JOIN Company c
    ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l
    ON c.LocationID = l.LocationID
WHERE de.EventDate BETWEEN ? AND ?
GROUP BY l.ContinentName
ORDER BY HighImpactEvents DESC, TotalEvents DESC;
";

$stmt = $conn->prepare($sqlRegional);
$stmt->execute([$startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $regionalRows[] = $row;
}

// QUERY 2: Most critical companies
$criticalRows = [];

$sqlCritical = "
SELECT 
    c.CompanyID,
    c.CompanyName,
    COALESCE(ds.DownstreamCount, 0) AS DownstreamCount,
    COALESCE(hi.HighImpactCount, 0) AS HighImpactCount,
    (COALESCE(ds.DownstreamCount, 0) * COALESCE(hi.HighImpactCount, 0)) AS Criticality
FROM Company c
LEFT JOIN (
    SELECT 
        UpstreamCompanyID AS CompanyID,
        COUNT(DISTINCT DownstreamCompanyID) AS DownstreamCount
    FROM DependsOn
    GROUP BY UpstreamCompanyID
) ds ON c.CompanyID = ds.CompanyID
LEFT JOIN (
    SELECT 
        ic.AffectedCompanyID AS CompanyID,
        COUNT(DISTINCT ic.EventID) AS HighImpactCount
    FROM ImpactsCompany ic
    JOIN DisruptionEvent de
        ON ic.EventID = de.EventID
    WHERE ic.ImpactLevel = 'High'
      AND de.EventDate BETWEEN ? AND ?
    GROUP BY ic.AffectedCompanyID
) hi ON c.CompanyID = hi.CompanyID
WHERE (COALESCE(ds.DownstreamCount, 0) * COALESCE(hi.HighImpactCount, 0)) > 0
ORDER BY Criticality DESC
LIMIT 20;
";

$stmt = $conn->prepare($sqlCritical);
$stmt->execute([$startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $criticalRows[] = $row;
}

// QUERY 3: Event list for dropdown
$eventList = [];

$sqlEventList = "
SELECT 
    de.EventID,
    de.EventDate,
    dc.CategoryName
FROM DisruptionEvent de
JOIN DisruptionCategory dc
    ON de.CategoryID = dc.CategoryID
ORDER BY de.EventDate DESC
LIMIT 100;
";

$stmt = $conn->prepare($sqlEventList);
$stmt->execute();
while ($row = $stmt->fetch()) {
    $eventList[] = $row;
}

//QUERY 3b: Companies affected by selected event
$affectedRows = [];

if ($selectedEventID > 0) {
    $sqlAffected = "
    SELECT 
        c.CompanyName,
        c.Type,
        l.ContinentName AS Region,
        l.City,
        ic.ImpactLevel
    FROM ImpactsCompany ic
    JOIN Company c
        ON ic.AffectedCompanyID = c.CompanyID
    JOIN Location l
        ON c.LocationID = l.LocationID
    WHERE ic.EventID = ?
    ORDER BY ic.ImpactLevel DESC, c.CompanyName;
    ";
    $stmt = $conn->prepare($sqlAffected);
    $stmt->execute([$selectedEventID]);
    while ($row = $stmt->fetch()) {
        $affectedRows[] = $row;
    }
}

// QUERY 4: Company list for dropdown (disruptions per company)
$companyList = [];

$sqlCompanyList = "SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName;";
$stmt = $conn->prepare($sqlCompanyList);
$stmt->execute();
while ($row = $stmt->fetch()) {
    $companyList[] = $row;
}

// QUERY 4b: All disruptions for selected company
$companyDisruptionRows = [];

if ($selectedCompanyID > 0) {
    $sqlCompanyDisruptions = "
    SELECT 
        de.EventID,
        de.EventDate,
        de.EventRecoveryDate,
        dc.CategoryName,
        ic.ImpactLevel,
        EXTRACT(DAY FROM (de.EventRecoveryDate::timestamp - de.EventDate::timestamp)) AS RecoveryDays
    FROM ImpactsCompany ic
    JOIN DisruptionEvent de
        ON ic.EventID = de.EventID
    JOIN DisruptionCategory dc
        ON de.CategoryID = dc.CategoryID
    WHERE ic.AffectedCompanyID = ?
      AND de.EventDate BETWEEN ? AND ?
    ORDER BY de.EventDate DESC;
    ";

    $stmt = $conn->prepare($sqlCompanyDisruptions);
    $stmt->execute([$selectedCompanyID, $startDate, $endDate]);
    while ($row = $stmt->fetch()) {
        $companyDisruptionRows[] = $row;
    }
}

// QUERY 5: Avg recovery time by region & category (extra)
$recoveryRows = [];

$sqlRecovery = "
SELECT 
    l.ContinentName AS Region,
    dc.CategoryName,
    AVG(EXTRACT(DAY FROM (de.EventRecoveryDate::timestamp - de.EventDate::timestamp))) AS AvgRecoveryDays,
    COUNT(DISTINCT de.EventID) AS NumEvents
FROM DisruptionEvent de
JOIN DisruptionCategory dc
    ON de.CategoryID = dc.CategoryID
JOIN ImpactsCompany ic
    ON de.EventID = ic.EventID
JOIN Company c
    ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l
    ON c.LocationID = l.LocationID
WHERE de.EventDate BETWEEN ? AND ?
GROUP BY l.ContinentName, dc.CategoryName
HAVING COUNT(DISTINCT de.EventID) > 0
ORDER BY AvgRecoveryDays DESC;
";

$stmt = $conn->prepare($sqlRecovery);
$stmt->execute([$startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $recoveryRows[] = $row;
}

// QUERY 6: Disruption exposure by region (Total + 2*High Impact)
$exposureRows = [];

$sqlExposure = "
SELECT 
    l.ContinentName AS Region,
    COUNT(DISTINCT de.EventID) AS TotalDisruptions,
    COUNT(DISTINCT CASE WHEN ic.ImpactLevel = 'High' THEN de.EventID ELSE NULL END) AS HighImpactCount,
    (COUNT(DISTINCT de.EventID) + 2 * COUNT(DISTINCT CASE WHEN ic.ImpactLevel = 'High' THEN de.EventID ELSE NULL END)) AS DisruptionExposure
FROM DisruptionEvent de
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l ON c.LocationID = l.LocationID
WHERE de.EventDate BETWEEN ? AND ?
GROUP BY l.ContinentName
ORDER BY DisruptionExposure DESC;
";

$stmt = $conn->prepare($sqlExposure);
$stmt->execute([$startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $exposureRows[] = $row;
}

// QUERY 7: Disruptions by category
$categoryRows = [];

$sqlCategory = "
SELECT 
    dc.CategoryName,
    COUNT(DISTINCT de.EventID) AS Count,
    AVG(EXTRACT(DAY FROM (de.EventRecoveryDate::timestamp - de.EventDate::timestamp))) AS AvgRecoveryDays
FROM DisruptionEvent de
JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
WHERE de.EventDate BETWEEN ? AND ?
AND de.EventRecoveryDate IS NOT NULL
GROUP BY dc.CategoryName
ORDER BY Count DESC;
";

$stmt = $conn->prepare($sqlCategory);
$stmt->execute([$startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $categoryRows[] = $row;
}

// QUERY 8: Impact level distribution
$impactRows = [];

$sqlImpact = "
SELECT 
    ic.ImpactLevel,
    COUNT(DISTINCT ic.EventID) AS Count
FROM ImpactsCompany ic
JOIN DisruptionEvent de ON ic.EventID = de.EventID
WHERE de.EventDate BETWEEN ? AND ?
GROUP BY ic.ImpactLevel;
";

$stmt = $conn->prepare($sqlImpact);
$stmt->execute([$startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $impactRows[] = $row;
}

// QUERY 9: Top affected companies
$topAffectedRows = [];

$sqlTopAffected = "
SELECT 
    c.CompanyName,
    COUNT(DISTINCT de.EventID) AS DisruptionCount,
    COUNT(DISTINCT CASE WHEN ic.ImpactLevel = 'High' THEN de.EventID ELSE NULL END) AS HighImpactCount
FROM DisruptionEvent de
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
WHERE de.EventDate BETWEEN ? AND ?
GROUP BY c.CompanyID, c.CompanyName
ORDER BY DisruptionCount DESC
LIMIT 15;
";

$stmt = $conn->prepare($sqlTopAffected);
$stmt->execute([$startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $topAffectedRows[] = $row;
}

//  CLOSE CONNECTION (PDO auto-closes)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Senior Manager – Disruptions & Risk</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="sm_disruptions.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header>
  <h1>Senior Manager – Disruptions & Risk</h1>
  <nav>
    <a href="sm_overview.php">Overview</a> |
    <a href="sm_disruptions.php">Disruptions &amp; Risk</a> |
    <a href="sm_companies_logistics.php">Companies &amp; Logistics</a> |
    <a href="logout.php">Logout</a>
  </nav>
</header>

<section class="filter-bar">
  <h2>Filters</h2>
  <form method="get" action="sm_disruptions.php">
    <label>Disruption Start Date:
      <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
    </label>
    <label>Disruption End Date:
      <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
    </label>
    <button type="submit">Apply Date Filter</button>
  </form>
</section>

<section>
  <h2>Regional Disruption Overview</h2>

  <canvas id="regionalChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Region</th>
        <th>Total Events</th>
        <th>High Impact Events</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($regionalRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['region']); ?></td>
          <td><?php echo (int)$row['totalevents']; ?></td>
          <td><?php echo (int)$row['highimpactevents']; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section>
  <h2>Most Critical Companies</h2>

  <canvas id="criticalChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Company</th>
        <th>Downstream Companies</th>
        <th>High-Impact Events</th>
        <th>Criticality Score</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($criticalRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['companyname']); ?></td>
          <td><?php echo (int)$row['downstreamcount']; ?></td>
          <td><?php echo (int)$row['highimpactcount']; ?></td>
          <td><?php echo (int)$row['criticality']; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section>
  <h2>Companies Affected by Specific Disruption Event</h2>

  <form method="get" action="sm_disruptions.php">
    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">

    <label>Select Event:
      <select name="event_id">
        <option value="">-- choose event --</option>
        <?php foreach ($eventList as $e): ?>
          <option value="<?php echo (int)$e['eventid']; ?>"
            <?php if ($selectedEventID == (int)$e['eventid']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($e['eventid'] . " | " . $e['eventdate'] . " | " . $e['categoryname']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">View Affected Companies</button>
  </form>

  <?php if ($selectedEventID && !empty($affectedRows)): ?>
    <div class="table-scroll-container">
    <table>
      <thead>
        <tr>
          <th>Company</th>
          <th>Type</th>
          <th>Region</th>
          <th>City</th>
          <th>Impact Level</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($affectedRows as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['companyname']); ?></td>
            <td><?php echo htmlspecialchars($row['type']); ?></td>
            <td><?php echo htmlspecialchars($row['region']); ?></td>
            <td><?php echo htmlspecialchars($row['city']); ?></td>
            <td><?php echo htmlspecialchars($row['impactlevel']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php elseif ($selectedEventID): ?>
    <p>No companies found for this event.</p>
  <?php endif; ?>
</section>

<section>
  <h2>All Disruptions for a Specific Company</h2>

  <form method="get" action="sm_disruptions.php">
    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">

    <label>Select Company:
      <select name="company_id">
        <option value="">-- choose company --</option>
        <?php foreach ($companyList as $c): ?>
          <option value="<?php echo (int)$c['companyid']; ?>"
            <?php if ($selectedCompanyID == (int)$c['companyid']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($c['companyname']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">View Disruptions</button>
  </form>

  <?php if ($selectedCompanyID && !empty($companyDisruptionRows)): ?>
    <table>
      <thead>
        <tr>
          <th>Event ID</th>
          <th>Event Date</th>
          <th>Recovery Date</th>
          <th>Category</th>
          <th>Impact Level</th>
          <th>Recovery Time (days)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companyDisruptionRows as $row): ?>
          <tr>
            <td><?php echo (int)$row['eventid']; ?></td>
            <td><?php echo htmlspecialchars($row['eventdate']); ?></td>
            <td><?php echo htmlspecialchars($row['eventrecoverydate']); ?></td>
            <td><?php echo htmlspecialchars($row['categoryname']); ?></td>
            <td><?php echo htmlspecialchars($row['impactlevel']); ?></td>
            <td><?php echo (int)$row['recoverydays']; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php elseif ($selectedCompanyID): ?>
    <p>No disruptions found for this company in the selected date range.</p>
  <?php endif; ?>
</section>

<section>
  <h2>Average Recovery Time by Region and Category</h2>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Region</th>
        <th>Category</th>
        <th>Average Recovery Time (days)</th>
        <th>Number of Events</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recoveryRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['region']); ?></td>
          <td><?php echo htmlspecialchars($row['categoryname']); ?></td>
          <td><?php echo number_format($row['avgrecoverydays'], 2); ?></td>
          <td><?php echo (int)$row['numevents']; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<!-- NEW SECTION: Disruption Exposure -->
<section>
  <h2>Disruption Exposure by Region</h2>
  <p class="metric-label">Disruption Exposure = Total Disruptions + 2 × High Impact Events</p>
  
  <canvas id="exposureChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Region</th>
        <th>Total Disruptions</th>
        <th>High Impact Events</th>
        <th>Exposure Score</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($exposureRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['region'] ?? ''); ?></td>
          <td><?php echo (int)($row['totaldisruptions'] ?? 0); ?></td>
          <td><?php echo (int)($row['highimpactcount'] ?? 0); ?></td>
          <td><?php echo (int)($row['disruptionexposure'] ?? 0); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<!-- NEW SECTION: Disruptions by Category -->
<section>
  <h2>Disruptions by Category</h2>
  
  <canvas id="categoryChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Category</th>
        <th>Count</th>
        <th>Avg Recovery Time (days)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($categoryRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['categoryname']); ?></td>
          <td><?php echo (int)($row['count'] ?? 0); ?></td>
          <td><?php echo number_format($row['avgrecoverydays'], 1); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<!-- NEW SECTION: Impact Level Distribution -->
<section>
  <h2>Impact Level Distribution</h2>
  
  <canvas id="impactChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Impact Level</th>
        <th>Count</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($impactRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['impactlevel'] ?? ''); ?></td>
          <td><?php echo (int)($row['count'] ?? 0); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<!-- NEW SECTION: Top Affected Companies -->
<section>
  <h2>Top Affected Companies</h2>
  
  <canvas id="affectedChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Company</th>
        <th>Total Disruptions</th>
        <th>High Impact Events</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($topAffectedRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['companyname'] ?? ''); ?></td>
          <td><?php echo (int)($row['disruptioncount'] ?? 0); ?></td>
          <td><?php echo (int)($row['highimpactcount'] ?? 0); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<!-- Pass data from PHP to JS -->
<script>
  const regionalData     = <?php echo json_encode($regionalRows); ?>;
  const criticalData     = <?php echo json_encode($criticalRows); ?>;
  const exposureData     = <?php echo json_encode($exposureRows); ?>;
  const categoryData      = <?php echo json_encode($categoryRows); ?>;
  const impactData        = <?php echo json_encode($impactRows); ?>;
  const affectedData      = <?php echo json_encode($topAffectedRows); ?>;
</script>

<script>
  // Chart 1: Regional Disruption Overview (Stacked Bar Chart)
  (function() {
    const ctx = document.getElementById('regionalChart');
    if (!ctx || !regionalData) return;

    const labels = regionalData.map(r => r.region);
    const total  = regionalData.map(r => Number(r.totalevents));
    const high   = regionalData.map(r => Number(r.highimpactevents));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'High Impact Events',
            data: high,
            backgroundColor: 'rgba(33, 150, 243, 0.8)'
          },
          {
            label: 'Other Events',
            data: total.map((t, i) => t - high[i]),
            backgroundColor: 'rgba(33, 150, 243, 0.5)'
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

  //  Chart 2: Most Critical Companies (Criticality) 
  (function() {
    const ctx = document.getElementById('criticalChart');
    if (!ctx || !criticalData) return;

    const labels = criticalData.map(r => r.companyname);
    const values = criticalData.map(r => Number(r.criticality));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Criticality Score',
          data: values
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // Chart 3: Disruption Exposure by Region
  (function() {
    const ctx = document.getElementById('exposureChart');
    if (!ctx || !exposureData) return;

    const labels = exposureData.map(r => r.region);
    const values = exposureData.map(r => Number(r.disruptionexposure));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Exposure Score',
          data: values,
          backgroundColor: 'rgba(33, 150, 243, 0.7)'
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // Chart 4: Disruptions by Category
  (function() {
    const ctx = document.getElementById('categoryChart');
    if (!ctx || !categoryData) return;

    const labels = categoryData.map(r => r.categoryname);
    const values = categoryData.map(r => Number(r.count));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Count',
          data: values,
          backgroundColor: 'rgba(33, 150, 243, 0.7)'
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // Chart 5: Impact Level Distribution (Bar)
  (function() {
    const ctx = document.getElementById('impactChart');
    if (!ctx || !impactData) return;

    const labels = impactData.map(r => r.impactlevel);
    const values = impactData.map(r => Number(r.count));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Count',
          data: values,
          backgroundColor: [
            'rgba(33, 150, 243, 0.5)',
            'rgba(33, 150, 243, 0.65)',
            'rgba(33, 150, 243, 0.8)'
          ]
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // Chart 6: Top Affected Companies (Stacked)
  (function() {
    const ctx = document.getElementById('affectedChart');
    if (!ctx || !affectedData) return;

    const labels = affectedData.map(r => r.companyname);
    const total = affectedData.map(r => Number(r.disruptioncount));
    const high = affectedData.map(r => Number(r.highimpactcount));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'High Impact',
            data: high,
            backgroundColor: 'rgba(33, 150, 243, 0.8)'
          },
          {
            label: 'Other',
            data: total.map((t, i) => t - high[i]),
            backgroundColor: 'rgba(33, 150, 243, 0.5)'
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
_