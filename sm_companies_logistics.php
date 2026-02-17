<?php
session_start();
require_once 'config.php';

// for feedback messages
$addMessage = "";

// handle adding a new company
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    $companyName = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
    $companyType = isset($_POST['company_type']) ? $_POST['company_type'] : '';
    $tierLevel   = isset($_POST['tier_level']) ? $_POST['tier_level'] : '';
    $locationID  = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
    $factoryCap  = isset($_POST['factory_capacity']) ? (int)$_POST['factory_capacity'] : 0;

    if ($companyName === '' || $companyType === '' || $tierLevel === '' || $locationID <= 0) {
        $addMessage = "Please fill in all required fields.";
    } else {
        // Insert into Company
        $sqlInsertCompany = "
            INSERT INTO Company (CompanyName, LocationID, TierLevel, Type)
            VALUES (?, ?, ?, ?);
        ";

        $stmt = $conn->prepare($sqlInsertCompany);
        if ($stmt->execute([$companyName, $locationID, $tierLevel, $companyType])) {
            $newCompanyID = $conn->lastInsertId();

            // Insert into subtype table
            if ($companyType === 'Manufacturer') {
                $sqlSub = "INSERT INTO Manufacturer (CompanyID, FactoryCapacity)
                           VALUES (?, ?);";
                $stmtSub = $conn->prepare($sqlSub);
                $stmtSub->execute([$newCompanyID, $factoryCap]);
            } elseif ($companyType === 'Distributor') {
                $sqlSub = "INSERT INTO Distributor (CompanyID)
                           VALUES (?);";
                $stmtSub = $conn->prepare($sqlSub);
                $stmtSub->execute([$newCompanyID]);
            } elseif ($companyType === 'Retailer') {
                $sqlSub = "INSERT INTO Retailer (CompanyID)
                           VALUES (?);";
                $stmtSub = $conn->prepare($sqlSub);
                $stmtSub->execute([$newCompanyID]);
            }

            $addMessage = "New company '" . htmlspecialchars($companyName) . "' added successfully.";
        } else {
            $addMessage = "Error adding company.";
        }
    }
}

// shipment date filters
$shipStart = isset($_GET['ship_start']) ? $_GET['ship_start'] : '2022-01-01';
$shipEnd   = isset($_GET['ship_end'])   ? $_GET['ship_end']   : '2025-12-31';

$selectedDistributorID = isset($_GET['distributor_id']) ? (int)$_GET['distributor_id'] : 0;

// get all locations for dropdown
$locationList = array();

$sqlLocations = "
SELECT 
    LocationID,
    CountryName,
    City,
    ContinentName
FROM Location
ORDER BY ContinentName, CountryName, City;
";

$stmt = $conn->prepare($sqlLocations);
$stmt->execute();
while ($row = $stmt->fetch()) {
    $locationList[] = $row;
}

// get top distributors by volume
$topDistributors = array();

$sqlTopDistributors = "
SELECT 
    d.CompanyID,
    c.CompanyName,
    COUNT(*) AS NumShipments,
    SUM(s.Quantity) AS TotalQuantity
FROM Shipping s
JOIN Distributor d ON s.DistributorID = d.CompanyID
JOIN Company c    ON d.CompanyID = c.CompanyID
WHERE s.PromisedDate BETWEEN ? AND ?
GROUP BY d.CompanyID, c.CompanyName
ORDER BY TotalQuantity DESC;
";

$stmt = $conn->prepare($sqlTopDistributors);
$stmt->execute([$shipStart, $shipEnd]);
while ($row = $stmt->fetch()) {
    $topDistributors[] = $row;
}

// get all distributors for dropdown
$distributorList = array();

$sqlDistributorList = "
SELECT d.CompanyID, c.CompanyName
FROM Distributor d
JOIN Company c ON d.CompanyID = c.CompanyID
ORDER BY c.CompanyName;
";

$stmt = $conn->prepare($sqlDistributorList);
$stmt->execute();
while ($row = $stmt->fetch()) {
    $distributorList[] = $row;
}

// get shipments for the selected distributor
$distributorShipments = array();
$routeSummary = array();

if ($selectedDistributorID > 0) {
    $sqlDistributorShipments = "
    SELECT 
        s.ShipmentID,
        s.PromisedDate,
        s.ActualDate,
        s.Quantity,
        src.CompanyName AS SourceName,
        dst.CompanyName AS DestName
    FROM Shipping s
    JOIN Company src ON s.SourceCompanyID = src.CompanyID
    JOIN Company dst ON s.DestinationCompanyID = dst.CompanyID
    WHERE s.DistributorID = ?
      AND s.PromisedDate BETWEEN ? AND ?
    ORDER BY s.PromisedDate DESC;
    ";

    $stmt = $conn->prepare($sqlDistributorShipments);
    $stmt->execute([$selectedDistributorID, $shipStart, $shipEnd]);
    while ($row = $stmt->fetch()) {
        $distributorShipments[] = $row;
    }

    // also get route summary
    $sqlRouteSummary = "
    SELECT 
        src.CompanyName AS SourceName,
        dst.CompanyName AS DestName,
        COUNT(*) AS NumShipments,
        SUM(s.Quantity) AS TotalQuantity
    FROM Shipping s
    JOIN Company src ON s.SourceCompanyID = src.CompanyID
    JOIN Company dst ON s.DestinationCompanyID = dst.CompanyID
    WHERE s.DistributorID = ?
      AND s.PromisedDate BETWEEN ? AND ?
    GROUP BY src.CompanyName, dst.CompanyName
    ORDER BY NumShipments DESC;
    ";

    $stmt = $conn->prepare($sqlRouteSummary);
    $stmt->execute([$selectedDistributorID, $shipStart, $shipEnd]);
    while ($row = $stmt->fetch()) {
        $routeSummary[] = $row;
    }
}

// get distributors sorted by average delay
$delayRows = array();

$sqlDelay = "
SELECT 
    d.CompanyID,
    c.CompanyName,
    AVG(EXTRACT(DAY FROM (s.ActualDate - s.PromisedDate))) AS AvgDelay,
    COUNT(*) AS NumShipments,
    SUM(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) AS OnTimeShipments,
    (100.0 * SUM(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) / COUNT(*)) AS OnTimePercent
FROM Shipping s
JOIN Distributor d ON s.DistributorID = d.CompanyID
JOIN Company c    ON d.CompanyID = c.CompanyID
WHERE s.PromisedDate BETWEEN ? AND ?
  AND s.ActualDate IS NOT NULL
GROUP BY d.CompanyID, c.CompanyName
ORDER BY AvgDelay DESC;
";

$stmt = $conn->prepare($sqlDelay);
$stmt->execute([$shipStart, $shipEnd]);
while ($row = $stmt->fetch()) {
    $delayRows[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Senior Manager – Companies & Logistics</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="sm_companies_logistics.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header>
  <h1>Senior Manager – Companies & Logistics</h1>
  <nav>
    <a href="sm_overview.php">Overview</a> |
    <a href="sm_disruptions.php">Disruptions &amp; Risk</a> |
    <a href="sm_companies_logistics.php">Companies &amp; Logistics</a> |
    <a href="index.php">Logout</a>
  </nav>
</header>

<section class="filter-bar">
  <h2>Shipment Filters</h2>
  <form method="get" action="sm_companies_logistics.php">
    <label>Shipment Start Date:
      <input type="date" name="ship_start" value="<?php echo htmlspecialchars($shipStart); ?>">
    </label>
    <label>Shipment End Date:
      <input type="date" name="ship_end" value="<?php echo htmlspecialchars($shipEnd); ?>">
    </label>
    <button type="submit">Apply Filters</button>
  </form>
</section>

<section>
  <h2>Top Distributors by Shipment Volume</h2>

  <canvas id="topDistributorsChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Distributor</th>
        <th>Number of Shipments</th>
        <th>Total Quantity Shipped</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($topDistributors as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['companyname']); ?></td>
          <td><?php echo (int)$row['numshipments']; ?></td>
          <td><?php echo (int)$row['totalquantity']; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section>
  <h2>Distributor Detail & Routes</h2>

  <form method="get" action="sm_companies_logistics.php">
    <input type="hidden" name="ship_start" value="<?php echo htmlspecialchars($shipStart); ?>">
    <input type="hidden" name="ship_end" value="<?php echo htmlspecialchars($shipEnd); ?>">

    <label>Select Distributor:
      <select name="distributor_id">
        <option value="">-- choose distributor --</option>
        <?php foreach ($distributorList as $d): ?>
          <option value="<?php echo (int)$d['companyid']; ?>"
            <?php if ($selectedDistributorID == (int)$d['companyid']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($d['companyname']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">View Shipments</button>
  </form>

  <?php if ($selectedDistributorID && !empty($distributorShipments)): ?>
    <h3>Shipments for Selected Distributor</h3>
    <div class="table-scroll-container">
    <table>
      <thead>
        <tr>
          <th>Shipment ID</th>
          <th>Promised Date</th>
          <th>Actual Date</th>
          <th>Source</th>
          <th>Destination</th>
          <th>Quantity</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($distributorShipments as $row): ?>
          <tr>
            <td><?php echo (int)$row['shipmentid']; ?></td>
            <td><?php echo htmlspecialchars($row['promiseddate']); ?></td>
            <td><?php echo htmlspecialchars($row['actualdate']); ?></td>
            <td><?php echo htmlspecialchars($row['sourcename']); ?></td>
            <td><?php echo htmlspecialchars($row['destname']); ?></td>
            <td><?php echo (int)$row['quantity']; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (!empty($routeSummary)): ?>
      <h3>Route Summary for Selected Distributor</h3>
      <div class="table-scroll-container">
      <table>
        <thead>
          <tr>
            <th>Source</th>
            <th>Destination</th>
            <th>Number of Shipments</th>
            <th>Total Quantity</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($routeSummary as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['sourcename']); ?></td>
              <td><?php echo htmlspecialchars($row['destname']); ?></td>
              <td><?php echo (int)$row['numshipments']; ?></td>
              <td><?php echo (int)$row['totalquantity']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>

  <?php elseif ($selectedDistributorID): ?>
    <p>No shipments found for this distributor in the selected date range.</p>
  <?php endif; ?>
</section>

<section>
  <h2>Distributors Sorted by Average Delay</h2>

  <canvas id="delayChart" height="80"></canvas>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Distributor</th>
        <th>Average Delay (days)</th>
        <th>Number of Shipments</th>
        <th>On-Time Shipments</th>
        <th>On-Time Percentage</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($delayRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['companyname']); ?></td>
          <td><?php echo number_format($row['avgdelay'], 2); ?></td>
          <td><?php echo (int)$row['numshipments']; ?></td>
          <td><?php echo (int)$row['ontimeshipments']; ?></td>
          <td><?php echo number_format($row['ontimepercent'], 1); ?>%</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section>
  <h2>Add a New Company</h2>

  <?php if ($addMessage): ?>
    <p><strong><?php echo htmlspecialchars($addMessage); ?></strong></p>
  <?php endif; ?>

  <form method="post" action="sm_companies_logistics.php">
    <label>Company Name:
      <input type="text" name="company_name" required>
    </label>
    <br>

    <label>Company Type:
      <select name="company_type" required>
        <option value="">-- choose type --</option>
        <option value="Manufacturer">Manufacturer</option>
        <option value="Distributor">Distributor</option>
        <option value="Retailer">Retailer</option>
      </select>
    </label>
    <br>

    <label>Tier Level:
      <select name="tier_level" required>
        <option value="">-- choose tier --</option>
        <option value="1">Tier 1</option>
        <option value="2">Tier 2</option>
        <option value="3">Tier 3</option>
      </select>
    </label>
    <br>

    <label>Location:
      <select name="location_id" required>
        <option value="">-- choose location --</option>
        <?php foreach ($locationList as $loc): ?>
          <option value="<?php echo (int)$loc['locationid']; ?>">
            <?php
              echo htmlspecialchars(
                $loc['continentname'] . " - " . $loc['countryname'] . " - " . $loc['city']
              );
            ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <br>

    <label>Factory Capacity (for Manufacturers only):
      <input type="number" name="factory_capacity" min="0">
    </label>
    <br>

    <button type="submit" name="add_company">Add Company</button>
  </form>
</section>

<!-- pass data to js -->
<script>
  const topDistributorsData = <?php echo json_encode($topDistributors); ?>;
  const delayData           = <?php echo json_encode($delayRows); ?>;
</script>

<script>
  // chart: top distributors by quantity
  (function() {
    const ctx = document.getElementById('topDistributorsChart');
    if (!ctx || !topDistributorsData) return;

    const labels = topDistributorsData.map(function(r) { return r.companyname; });
    const values = topDistributorsData.map(function(r) { return Number(r.totalquantity); });

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Total Quantity Shipped',
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

  // chart: avg delay by distributor
  (function() {
    const ctx = document.getElementById('delayChart');
    if (!ctx || !delayData) return;

    const labels = delayData.map(function(r) { return r.companyname; });
    const values = delayData.map(function(r) { return Number(r.avgdelay); });

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Average Delay (days)',
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
</script>

</body>
</html>
