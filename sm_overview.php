<?php
session_start();

/*

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SeniorManager') {
    header('Location: index.php');
    exit;
}

*/
require_once 'config.php';

//FILTERS (from GET)
$startYear = isset($_GET['start_year']) ? (int)$_GET['start_year'] : 2022;
$endYear   = isset($_GET['end_year'])   ? (int)$_GET['end_year']   : 2025;

$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '2022-01-01';
$endDate   = isset($_GET['end_date'])   ? $_GET['end_date']   : '2025-12-31';

$selectedCompanyID = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;

// QUERY 1: Avg financial health by company
$healthByCompanyRows = [];

$sqlHealthByCompany = "
SELECT 
    c.CompanyID,
    c.CompanyName,
    c.Type,
    l.ContinentName AS Region,
    l.City,
    AVG(f.HealthScore) AS AvgHealthScore
FROM Company c
JOIN FinancialReport f 
    ON c.CompanyID = f.CompanyID
JOIN Location l
    ON c.LocationID = l.LocationID
WHERE f.RepYear BETWEEN ? AND ?
GROUP BY 
    c.CompanyID, 
    c.CompanyName, 
    c.Type, 
    l.ContinentName,
    l.City
ORDER BY AvgHealthScore DESC
";

$stmt = $conn->prepare($sqlHealthByCompany);
$stmt->execute([$startYear, $endYear]);
foreach ($stmt->fetchAll() as $row) {
    $healthByCompanyRows[] = $row;
}

// QUERY 1b: Avg financial health by TYPE
$byTypeRows = [];

$sqlByType = "
SELECT 
    c.Type,
    AVG(f.HealthScore) AS AvgHealthScore
FROM Company c
JOIN FinancialReport f 
    ON c.CompanyID = f.CompanyID
WHERE f.RepYear BETWEEN ? AND ?
GROUP BY c.Type
ORDER BY AvgHealthScore DESC
";

$stmt = $conn->prepare($sqlByType);
$stmt->execute([$startYear, $endYear]);
foreach ($stmt->fetchAll() as $row) {
    $byTypeRows[] = $row;
}

// QUERY 2: Avg financial health by REGION
$regionRows = [];

$sqlRegionHealth = "
SELECT 
    l.ContinentName AS Region,
    AVG(f.HealthScore) AS AvgHealthScore,
    COUNT(DISTINCT c.CompanyID) AS NumCompanies
FROM Company c
JOIN Location l
    ON c.LocationID = l.LocationID
JOIN FinancialReport f
    ON c.CompanyID = f.CompanyID
WHERE f.RepYear BETWEEN ? AND ?
GROUP BY l.ContinentName
ORDER BY AvgHealthScore DESC
";

$stmt = $conn->prepare($sqlRegionHealth);
$stmt->execute([$startYear, $endYear]);
foreach ($stmt->fetchAll() as $row) {
    $regionRows[] = $row;
}

//Company list for dropdown
$companyList = [];

$sqlCompanyList = "SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName";
$stmt = $conn->prepare($sqlCompanyList);
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $companyList[] = $row;
}

//QUERY 2b: Time series for selected company
$companyDetailRows = [];

if ($selectedCompanyID > 0) {
    $sqlCompanyDetail = "
    SELECT 
        f.Quarter,
        f.RepYear,
        f.HealthScore
    FROM FinancialReport f
    WHERE f.CompanyID = ?
    ORDER BY f.RepYear, f.Quarter
    ";
    $stmt = $conn->prepare($sqlCompanyDetail);
    $stmt->execute([$selectedCompanyID]);
    $companyDetailRows = $stmt->fetchAll();
}

//QUERY 3: Disruption frequency over time (monthly)
$disruptionRows = [];

$sqlDisruptionFreq = "
SELECT 
    DATE_TRUNC('month', de.EventDate)::DATE AS MonthStart,
    COUNT(DISTINCT de.EventID) AS TotalEvents,
    COUNT(DISTINCT CASE 
        WHEN ic_high.EventID IS NOT NULL THEN de.EventID 
        ELSE NULL 
    END) AS HighImpactEvents
FROM DisruptionEvent de
LEFT JOIN ImpactsCompany ic_high
    ON de.EventID = ic_high.EventID
   AND ic_high.ImpactLevel = 'High'
WHERE de.EventDate BETWEEN ? AND ?
GROUP BY DATE_TRUNC('month', de.EventDate)
ORDER BY MonthStart
";

$stmt = $conn->prepare($sqlDisruptionFreq);
$stmt->execute([$startDate, $endDate]);
foreach ($stmt->fetchAll() as $row) {
    $disruptionRows[] = $row;
}

// Database connection is managed by config.php/PDO
// PDO doesn't require explicit close like MySQLi
?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Senior Manager – Overview</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="sm_overview.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header>
  <h1>Senior Manager – Overview</h1>
  <nav>
    <a href="sm_overview.php">Overview</a> |
    <a href="sm_disruptions.php">Disruptions &amp; Risk</a> |
    <a href="sm_companies_logistics.php">Companies &amp; Logistics</a> |
    <a href="logout.php">Logout</a>
  </nav>
</header>

<section class="filter-bar">
  <h2>Filters</h2>
  <form method="get" action="sm_overview.php">
    <label>Financial Start Year:
      <input type="number" name="start_year" value="<?php echo htmlspecialchars($startYear); ?>">
    </label>
    <label>Financial End Year:
      <input type="number" name="end_year" value="<?php echo htmlspecialchars($endYear); ?>">
    </label>
    <label>Disruption Start Date:
      <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
    </label>
    <label>Disruption End Date:
      <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
    </label>
    <button type="submit">Apply Filters</button>
  </form>
</section>

<section>
  <h2>Average Financial Health by Company</h2>

  <canvas id="healthByCompanyChart" height="100"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Company</th>
        <th>Type</th>
        <th>Region</th>
        <th>City</th>
        <th>Average Health Score</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($healthByCompanyRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['companyname']); ?></td>
          <td><?php echo htmlspecialchars($row['type']); ?></td>
          <td><?php echo htmlspecialchars($row['region']); ?></td>
          <td><?php echo htmlspecialchars($row['city']); ?></td>
          <td><?php echo number_format($row['avghealthscore'], 2); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <h3>By-Type Summary</h3>

  <canvas id="byTypeChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Type</th>
        <th>Average Health Score</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($byTypeRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['type']); ?></td>
          <td><?php echo number_format($row['avghealthscore'], 2); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section>
  <h2>Company Financials by Region</h2>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Region</th>
        <th>Average Health Score</th>
        <th>Number of Companies</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($regionRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['region']); ?></td>
          <td><?php echo number_format($row['avghealthscore'], 2); ?></td>
          <td><?php echo (int)$row['numcompanies']; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3>Company Detail</h3>

  <form method="get" action="sm_overview.php">
    <input type="hidden" name="start_year" value="<?php echo htmlspecialchars($startYear); ?>">
    <input type="hidden" name="end_year" value="<?php echo htmlspecialchars($endYear); ?>">
    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">

    <label>Select Company:
      <select name="company_id">
        <option value="">-- choose --</option>
        <?php foreach ($companyList as $c): ?>
          <option value="<?php echo (int)$c['companyid']; ?>"
            <?php if ($selectedCompanyID == (int)$c['companyid']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($c['companyname']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">View</button>
  </form>

  <?php if ($selectedCompanyID && !empty($companyDetailRows)): ?>
    <canvas id="companyDetailChart" height="80"></canvas>

    <div class="table-scroll-container">
    <table>
      <thead>
        <tr>
          <th>Year</th>
          <th>Quarter</th>
          <th>Health Score</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companyDetailRows as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['repyear']); ?></td>
            <td><?php echo htmlspecialchars($row['quarter']); ?></td>
            <td><?php echo number_format($row['healthscore'], 2); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php elseif ($selectedCompanyID): ?>
    <p>No financial data found for that company.</p>
  <?php endif; ?>
</section>

<section>
  <h2>Disruption Frequency Over Time</h2>

  <canvas id="disruptionChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Month</th>
        <th>Total Events</th>
        <th>High Impact Events</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($disruptionRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['monthstart']); ?></td>
          <td><?php echo (int)$row['totalevents']; ?></td>
          <td><?php echo (int)$row['highimpactevents']; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<!-- Pass PHP data to JS -->
<script>
  const healthByCompanyData = <?php echo json_encode($healthByCompanyRows); ?>;
  const byTypeData          = <?php echo json_encode($byTypeRows); ?>;
  const regionData          = <?php echo json_encode($regionRows); ?>;
  const disruptionData      = <?php echo json_encode($disruptionRows); ?>;
  const companyDetailData   = <?php echo json_encode($companyDetailRows); ?>;
</script>

<script>
  // Chart 1: Average Health by Company (top 10)
  (function () {
    const ctx = document.getElementById('healthByCompanyChart');
    if (!ctx || !healthByCompanyData) return;

    const topN = 10;
    const sliced = healthByCompanyData.slice(0, topN);
    const labels = sliced.map(r => r.companyname);
    const values = sliced.map(r => Number(r.avghealthscore));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Avg Health Score',
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

  // Chart 2: Average Health by Type
  (function () {
    const ctx = document.getElementById('byTypeChart');
    if (!ctx || !byTypeData) return;

    const labels = byTypeData.map(r => r.type);
    const values = byTypeData.map(r => Number(r.avghealthscore));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Avg Health Score',
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

  // Chart 3: Company Detail (line chart)
  (function () {
    const ctx = document.getElementById('companyDetailChart');
    if (!ctx || !companyDetailData || companyDetailData.length === 0) return;

    const labels = companyDetailData.map(r => r.repyear + ' Q' + r.quarter);
    const values = companyDetailData.map(r => Number(r.healthscore));

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'Health Score',
          data: values,
          tension: 0.2
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // Chart 4: Disruption Frequency Over Time
  (function () {
    const ctx = document.getElementById('disruptionChart');
    if (!ctx || !disruptionData) return;

    const labels = disruptionData.map(r => r.monthstart);
    const totalValues = disruptionData.map(r => Number(r.totalevents));
    const highValues  = disruptionData.map(r => Number(r.highimpactevents));

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Total Events',
            data: totalValues,
            tension: 0.2
          },
          {
            label: 'High Impact Events',
            data: highValues,
            tension: 0.2
          }
        ]
      },
      options: {
        responsive: true,
        scales: { y: { beginAtZero: true } }
      }
    });
  })();
</script>

</body>
</html>
