<?php
session_start();
require_once 'config.php';
$distributorList = [];
$sqlDistributorList = "
    SELECT d.CompanyID, c.CompanyName
    FROM Distributor d
    JOIN Company c ON d.CompanyID = c.CompanyID
    ORDER BY c.CompanyName
";
$stmt = $conn->prepare($sqlDistributorList);
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $distributorList[] = $row;
}

// get selected distributor and filters
$distributorID = 0;
if (isset($_GET['company_id'])) {
    $distributorID = (int)$_GET['company_id'];
} elseif (isset($_POST['company_id'])) {
    $distributorID = (int)$_POST['company_id'];
}

$errorMessage   = "";
$distributorInfo = null;
$hasDistributor = false;

// date range filters
$startDate = isset($_GET['start_date']) && $_GET['start_date'] !== ''
    ? $_GET['start_date'] : '2022-01-01';
$endDate   = isset($_GET['end_date']) && $_GET['end_date'] !== ''
    ? $_GET['end_date']   : '2025-12-31';

// init arrays so theyll be ready
$routeOptions           = [];
$productOptions         = [];
$volumeOverTime         = [];
$onTimeByRoute          = [];
$statusDistribution     = [];
$disruptionExposure     = [];
$shipmentsOut           = [];
$productsHandled        = [];
$routeSummary           = [];
$totalVolumeAllProducts = 0;

// route and product filter params
$routeParam        = isset($_GET['route']) ? $_GET['route'] : 'all';
$selectedRoute     = 'all';
$routeFromID       = null;
$routeToID         = null;

if ($routeParam !== 'all' && strpos($routeParam, '-') !== false) {
    list($fromStr, $toStr) = explode('-', $routeParam, 2);
    $routeFromID = (int)$fromStr;
    $routeToID   = (int)$toStr;
    if ($routeFromID > 0 && $routeToID > 0) {
        $selectedRoute = $routeFromID . '-' . $routeToID;
    }
}

$selectedProductID = isset($_GET['product_id']) && $_GET['product_id'] !== ''
    ? (int)$_GET['product_id'] : 0;

// if distributor selected, validate and load data
if ($distributorID > 0) {
    // Check that this company exists and is a Distributor
    $sqlCheck = "
        SELECT c.CompanyID, c.CompanyName, c.Type,
               l.CountryName, l.City, l.ContinentName
        FROM Company c
        JOIN Location l ON c.LocationID = l.LocationID
        WHERE c.CompanyID = ?
    ";
    $stmt = $conn->prepare($sqlCheck);
    $stmt->execute([$distributorID]);
    $info = $stmt->fetch();
    if (!$info) {
        $errorMessage = "Selected company not found.";
    } else {
        if ($info['type'] !== 'Distributor') {
            $errorMessage = "Selected company is not a Distributor.";
        } else {
            $distributorInfo = $info;
            $hasDistributor  = true;
        }
    }
}

if ($hasDistributor) {
// get available routes and products
    $sqlRoutes = "
        SELECT DISTINCT
            s.SourceCompanyID,
            s.DestinationCompanyID,
            src.CompanyName AS SourceName,
            dst.CompanyName AS DestName
        FROM Shipping s
        JOIN Company src ON s.SourceCompanyID = src.CompanyID
        JOIN Company dst ON s.DestinationCompanyID = dst.CompanyID
        WHERE s.DistributorID = ?
          AND s.PromisedDate BETWEEN ? AND ?
        ORDER BY SourceName, DestName
    ";
    $stmt = $conn->prepare($sqlRoutes);
    $stmt->execute([$distributorID, $startDate, $endDate]);
    $routeOptions = $stmt->fetchAll();

    $sqlProducts = "
        SELECT DISTINCT
            p.ProductID,
            p.ProductName
        FROM Shipping s
        JOIN Product p ON s.ProductID = p.ProductID
        WHERE s.DistributorID = ?
          AND s.PromisedDate BETWEEN ? AND ?
        ORDER BY p.ProductName
    ";
    $stmt = $conn->prepare($sqlProducts);
    $stmt->execute([$distributorID, $startDate, $endDate]);
    $productOptions = $stmt->fetchAll();

// get volume by time period
    $sqlVolumeTime = "
        SELECT
            TO_CHAR(s.PromisedDate, 'YYYY-MM') AS Period,
            SUM(s.Quantity) AS TotalQuantity
        FROM Shipping s
        WHERE s.DistributorID = ?
          AND s.PromisedDate BETWEEN ? AND ?
    ";
    
    // Append route filter if selected
    $volumeParams = [$distributorID, $startDate, $endDate];
    if ($selectedRoute !== 'all' && $routeFromID > 0 && $routeToID > 0) {
        $sqlVolumeTime .= "  AND s.SourceCompanyID = ?\n          AND s.DestinationCompanyID = ?\n";
        $volumeParams[] = $routeFromID;
        $volumeParams[] = $routeToID;
    }
    // Append product filter if selected
    if ($selectedProductID > 0) {
        $sqlVolumeTime .= "  AND s.ProductID = ?\n";
        $volumeParams[] = $selectedProductID;
    }
    
    $sqlVolumeTime .= "
        GROUP BY TO_CHAR(s.PromisedDate, 'YYYY-MM')
        ORDER BY Period
    ";
    
    $stmt = $conn->prepare($sqlVolumeTime);
    $stmt->execute($volumeParams);
    while ($row = $stmt->fetch()) {
        $volumeOverTime[] = $row;
    }

// get on-time rate per route
    $sqlOnTimeRoute = "
        SELECT
            s.SourceCompanyID,
            s.DestinationCompanyID,
            src.CompanyName AS SourceName,
            dst.CompanyName AS DestName,
            COUNT(*) AS NumShipments,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate
                     THEN 1 ELSE 0 END) AS OnTimeShipments,
            (100.0 * SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate
                              THEN 1 ELSE 0 END) / COUNT(*)) AS OnTimePercent
        FROM Shipping s
        JOIN Company src ON s.SourceCompanyID = src.CompanyID
        JOIN Company dst ON s.DestinationCompanyID = dst.CompanyID
        WHERE s.DistributorID = ?
          AND s.PromisedDate BETWEEN ? AND ?
    ";
    
    $onTimeParams = [$distributorID, $startDate, $endDate];
    if ($selectedRoute !== 'all' && $routeFromID > 0 && $routeToID > 0) {
        $sqlOnTimeRoute .= "  AND s.SourceCompanyID = ?\n          AND s.DestinationCompanyID = ?\n";
        $onTimeParams[] = $routeFromID;
        $onTimeParams[] = $routeToID;
    }
    if ($selectedProductID > 0) {
        $sqlOnTimeRoute .= "  AND s.ProductID = ?\n";
        $onTimeParams[] = $selectedProductID;
    }
    
    $sqlOnTimeRoute .= "
        GROUP BY s.SourceCompanyID, s.DestinationCompanyID, src.CompanyName, dst.CompanyName
        ORDER BY OnTimePercent ASC
    ";
    
    $stmt = $conn->prepare($sqlOnTimeRoute);
    $stmt->execute($onTimeParams);
    while ($row = $stmt->fetch()) {
        $onTimeByRoute[] = $row;
    }

// get shipment statuses
    $sqlStatus = "
        SELECT
            CASE
                WHEN s.ActualDate IS NULL AND s.PromisedDate < CURRENT_DATE THEN 'Delayed'
                WHEN s.ActualDate IS NULL THEN 'In transit'
                ELSE 'Delivered'
            END AS StatusLabel,
            COUNT(*) AS NumShipments
        FROM Shipping s
        WHERE s.DistributorID = ?
          AND s.PromisedDate BETWEEN ? AND ?
    ";
    
    $statusParams = [$distributorID, $startDate, $endDate];
    if ($selectedRoute !== 'all' && $routeFromID > 0 && $routeToID > 0) {
        $sqlStatus .= "  AND s.SourceCompanyID = ?\n          AND s.DestinationCompanyID = ?\n";
        $statusParams[] = $routeFromID;
        $statusParams[] = $routeToID;
    }
    if ($selectedProductID > 0) {
        $sqlStatus .= "  AND s.ProductID = ?\n";
        $statusParams[] = $selectedProductID;
    }
    
    $sqlStatus .= "
        GROUP BY StatusLabel
    ";
    
    $stmt = $conn->prepare($sqlStatus);
    $stmt->execute($statusParams);
    while ($row = $stmt->fetch()) {
        $statusDistribution[] = $row;
    }

// get disruption exposure per route
    $sqlExposure = "
        SELECT
            src.CompanyName AS SourceName,
            dst.CompanyName AS DestName,
            COUNT(*) AS TotalDisruptions,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighImpact,
            (COUNT(*) + 2 * SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END)) AS ExposureScore
        FROM Shipping s
        JOIN Company src ON s.SourceCompanyID = src.CompanyID
        JOIN Company dst ON s.DestinationCompanyID = dst.CompanyID
        JOIN ImpactsCompany ic
            ON ic.AffectedCompanyID IN (src.CompanyID, dst.CompanyID)
        JOIN DisruptionEvent de ON de.EventID = ic.EventID
        WHERE s.DistributorID = ?
          AND s.PromisedDate BETWEEN ? AND ?
          AND de.EventDate BETWEEN ? AND ?
    ";
    
    $exposureParams = [$distributorID, $startDate, $endDate, $startDate, $endDate];
    if ($selectedRoute !== 'all' && $routeFromID > 0 && $routeToID > 0) {
        $sqlExposure .= "  AND s.SourceCompanyID = ?\n          AND s.DestinationCompanyID = ?\n";
        $exposureParams[] = $routeFromID;
        $exposureParams[] = $routeToID;
    }
    if ($selectedProductID > 0) {
        $sqlExposure .= "  AND s.ProductID = ?\n";
        $exposureParams[] = $selectedProductID;
    }
    
    $sqlExposure .= "
        GROUP BY src.CompanyName, dst.CompanyName
        ORDER BY ExposureScore DESC
    ";
    
    $stmt = $conn->prepare($sqlExposure);
    $stmt->execute($exposureParams);
    while ($row = $stmt->fetch()) {
        $disruptionExposure[] = $row;
    }

// get shipments that are currently out
    $sqlOut = "
        SELECT
            s.ShipmentID,
            s.PromisedDate,
            s.ActualDate,
            s.Quantity,
            src.CompanyName AS SourceName,
            dst.CompanyName AS DestName,
            p.ProductName
        FROM Shipping s
        JOIN Company src ON s.SourceCompanyID = src.CompanyID
        JOIN Company dst ON s.DestinationCompanyID = dst.CompanyID
        JOIN Product p ON s.ProductID = p.ProductID
        WHERE s.DistributorID = ?
          AND s.PromisedDate BETWEEN ? AND ?
          AND s.ActualDate IS NULL
    ";
    
    $outParams = [$distributorID, $startDate, $endDate];
    if ($selectedRoute !== 'all' && $routeFromID > 0 && $routeToID > 0) {
        $sqlOut .= "  AND s.SourceCompanyID = ?\n          AND s.DestinationCompanyID = ?\n";
        $outParams[] = $routeFromID;
        $outParams[] = $routeToID;
    }
    if ($selectedProductID > 0) {
        $sqlOut .= "  AND s.ProductID = ?\n";
        $outParams[] = $selectedProductID;
    }
    
    $sqlOut .= "
        ORDER BY s.PromisedDate
    ";
    
    $stmt = $conn->prepare($sqlOut);
    $stmt->execute($outParams);
    while ($row = $stmt->fetch()) {
        $shipmentsOut[] = $row;
    }

// get all products handled
    $sqlProductsHandled = "
        SELECT
            p.ProductID,
            p.ProductName,
            SUM(s.Quantity) AS TotalVolume
        FROM Shipping s
        JOIN Product p ON s.ProductID = p.ProductID
        WHERE s.DistributorID = ?
          AND s.PromisedDate BETWEEN ? AND ?
    ";
    
    $productsParams = [$distributorID, $startDate, $endDate];
    if ($selectedRoute !== 'all' && $routeFromID > 0 && $routeToID > 0) {
        $sqlProductsHandled .= "  AND s.SourceCompanyID = ?\n          AND s.DestinationCompanyID = ?\n";
        $productsParams[] = $routeFromID;
        $productsParams[] = $routeToID;
    }
    if ($selectedProductID > 0) {
        $sqlProductsHandled .= "  AND s.ProductID = ?\n";
        $productsParams[] = $selectedProductID;
    }
    
    $sqlProductsHandled .= "
        GROUP BY p.ProductID, p.ProductName
        ORDER BY TotalVolume DESC
    ";
    
    $stmt = $conn->prepare($sqlProductsHandled);
    $stmt->execute($productsParams);
    while ($row = $stmt->fetch()) {
        $productsHandled[] = $row;
        $totalVolumeAllProducts += (int)$row['totalvolume'];
    }

// get route summary stats
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
    ";
    
    $routeParams = [$distributorID, $startDate, $endDate];
    if ($selectedRoute !== 'all' && $routeFromID > 0 && $routeToID > 0) {
        $sqlRouteSummary .= "  AND s.SourceCompanyID = ?\n          AND s.DestinationCompanyID = ?\n";
        $routeParams[] = $routeFromID;
        $routeParams[] = $routeToID;
    }
    if ($selectedProductID > 0) {
        $sqlRouteSummary .= "  AND s.ProductID = ?\n";
        $routeParams[] = $selectedProductID;
    }
    
    $sqlRouteSummary .= "
        GROUP BY src.CompanyName, dst.CompanyName
        ORDER BY TotalQuantity DESC
    ";
    
    $stmt = $conn->prepare($sqlRouteSummary);
    $stmt->execute($routeParams);
    while ($row = $stmt->fetch()) {
        $routeSummary[] = $row;
    }
}

// close db (data is in php arrays now, PDO auto-closes)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Distributor Detail</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="distributors.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header>
    <h1>Supply Chain Manager - Distributor Details</h1>
    <nav>
        <a href="overview.php">Overview</a> |
        <a href="companies.php">Companies</a> |
        <a href="disruptions.php">Disruptions</a> |
	<a href="transactions.php">Transactions</a> |
	<a href="distributors.php">Distributor Details</a> |
	<a href="logout.php">Logout</a> |
    </nav>

</header>

<main>
    <?php if ($hasDistributor): ?>
        <section>
            <h2>Distributor: <?php echo htmlspecialchars($distributorInfo['companyname']); ?></h2>
            <p>
                Location:
                <?php
                echo htmlspecialchars($distributorInfo['city']) . ", " .
                     htmlspecialchars($distributorInfo['countryname']) . " (" .
                     htmlspecialchars($distributorInfo['continentname']) . ")";
                ?>
            </p>
        </section>
    <?php else: ?>
        <section>
            <h2>Select a Distributor</h2>
            <p>Use the form below to choose a distributor and date range. Then click <strong>Apply Filters</strong>.</p>
        </section>
    <?php endif; ?>

    <?php if ($errorMessage !== ""): ?>
        <section>
            <p class="error-message"><strong><?php echo htmlspecialchars($errorMessage); ?></strong></p>
        </section>
    <?php endif; ?>

    <!-- filter section -->
    <section class="filter-bar">
        <h2>Filters</h2>
        <form method="get" action="distributors.php">
            <label>Distributor:
                <select name="company_id" required>
                    <option value="">-- choose distributor --</option>
                    <?php foreach ($distributorList as $d): ?>
                        <option value="<?php echo (int)$d['CompanyID']; ?>"
                            <?php if ($distributorID === (int)$d['CompanyID']) echo "selected"; ?>>
                            <?php echo htmlspecialchars($d['CompanyName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Start Date:
                <input type="date" name="start_date"
                       value="<?php echo htmlspecialchars($startDate); ?>">
            </label>

            <label>End Date:
                <input type="date" name="end_date"
                       value="<?php echo htmlspecialchars($endDate); ?>">
            </label>

            <?php if ($hasDistributor): ?>
                <label>Route:
                    <select name="route">
                        <option value="all">All routes</option>
                        <?php foreach ($routeOptions as $r):
                            $value = (int)$r['SourceCompanyID'] . "-" . (int)$r['DestinationCompanyID'];
                            $label = $r['SourceName'] . " → " . $r['DestName'];
                            ?>
                            <option value="<?php echo htmlspecialchars($value); ?>"
                                <?php if ($selectedRoute === $value) echo "selected"; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Product:
                    <select name="product_id">
                        <option value="">All products</option>
                        <?php foreach ($productOptions as $p): ?>
                            <option value="<?php echo (int)$p['ProductID']; ?>"
                                <?php if ($selectedProductID === (int)$p['ProductID']) echo "selected"; ?>>
                                <?php echo htmlspecialchars($p['ProductName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <button type="submit">Apply Filters</button>
        </form>
<a href="distributors.php">Reset</a>
    </section>

    <?php if ($hasDistributor): ?>
        <!-- charts go here -->
        <section>
            <h2>Analytics Plots</h2>

            <h3>Shipment Volume Over Time</h3>
            <canvas id="volumeTimeChart" height="80"></canvas>

            <h3>On-Time Delivery Rate by Route</h3>
            <canvas id="onTimeRouteChart" height="80"></canvas>

            <h3>Shipment Status Distribution</h3>
            <canvas id="statusChart" height="80"></canvas>

            <h3>Disruption Exposure by Route</h3>
            <canvas id="exposureChart" height="80"></canvas>
        </section>

        <!-- data tables -->
        <section>
            <h2>Shipments Currently Out</h2>
            <?php if (!empty($shipmentsOut)): ?>
                <table>
                    <thead>
                    <tr>
                        <th>Shipment ID</th>
                        <th>Route</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Promised Date</th>
                        <th>ETA</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($shipmentsOut as $s):
                        $status = ($s['promiseddate'] < date('Y-m-d')) ? 'Delayed' : 'In transit';
                        $routeLabel = $s['sourcename'] . " → " . $s['destname'];
                        ?>
                        <tr>
                            <td><?php echo (int)$s['shipmentid']; ?></td>
                            <td><?php echo htmlspecialchars($routeLabel); ?></td>
                            <td><?php echo htmlspecialchars($s['productname']); ?></td>
                            <td><?php echo (int)$s['quantity']; ?></td>
                            <td><?php echo htmlspecialchars($s['promiseddate']); ?></td>
                            <td><?php echo htmlspecialchars($s['promiseddate']); ?></td>
                            <td><?php echo htmlspecialchars($status); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No shipments currently out under the selected filters.</p>
            <?php endif; ?>
        </section>

        <section>
            <h2>Products Handled</h2>
            <?php if (!empty($productsHandled)): ?>
                <table>
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Volume</th>
                        <th>% of Distributor’s Volume</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($productsHandled as $p):
                        $volume = (int)$p['totalvolume'];
                        $pct = ($totalVolumeAllProducts > 0)
                            ? (100.0 * $volume / $totalVolumeAllProducts) : 0;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['productname']); ?></td>
                            <td><?php echo $volume; ?></td>
                            <td><?php echo number_format($pct, 1); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No products found for this distributor under the selected filters.</p>
            <?php endif; ?>
        </section>

        <section>
            <h2>Route Shipment Summary</h2>
            <?php if (!empty($routeSummary)): ?>
                <table>
                    <thead>
                    <tr>
                        <th>Route</th>
                        <th>Number of Shipments</th>
                        <th>Total Quantity</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($routeSummary as $r):
                        $routeLabel = $r['sourcename'] . " → " . $r['destname'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($routeLabel); ?></td>
                            <td><?php echo (int)$r['numshipments']; ?></td>
                            <td><?php echo (int)$r['TotalQuantity']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No shipments found for this distributor under the selected filters.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
       
    <?php endif; ?>
</main>

<!-- send php data to js -->
<script>
    const volumeOverTimeData       = <?php echo json_encode($volumeOverTime); ?>;
    const onTimeByRouteData        = <?php echo json_encode($onTimeByRoute); ?>;
    const statusDistributionData   = <?php echo json_encode($statusDistribution); ?>;
    const disruptionExposureData   = <?php echo json_encode($disruptionExposure); ?>;
</script>

<script>
// chart 1: volume over time
(function() {
    const ctx = document.getElementById('volumeTimeChart');
    if (!ctx || !volumeOverTimeData) return;

    const labels = volumeOverTimeData.map(function(row) { return row.Period; });
    const values = volumeOverTimeData.map(function(row) { return Number(row.TotalQuantity); });

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Shipment Volume',
                data: values
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: true } },
            scales: { y: { beginAtZero: true } }
        }
    });
})();

// chart 2: on-time by route
(function() {
    const ctx = document.getElementById('onTimeRouteChart');
    if (!ctx || !onTimeByRouteData) return;

    const labels = onTimeByRouteData.map(function(row) {
        return row.SourceName + ' → ' + row.DestName;
    });
    const values = onTimeByRouteData.map(function(row) {
        return Number(row.OnTimePercent);
    });

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'On-Time Delivery %',
                data: values
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 100 }
            }
        }
    });
})();

// chart 3: status distribution
(function() {
    const ctx = document.getElementById('statusChart');
    if (!ctx || !statusDistributionData) return;

    const labels = statusDistributionData.map(function(row) { return row.StatusLabel; });
    const values = statusDistributionData.map(function(row) { return Number(row.NumShipments); });

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Number of Shipments',
                data: values
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
})();

// Chart 4: disruption exposure
(function() {
    const ctx = document.getElementById('exposureChart');
    if (!ctx || !disruptionExposureData) return;

    const labels = disruptionExposureData.map(function(row) {
        return row.SourceName + ' → ' + row.DestName;
    });
    const values = disruptionExposureData.map(function(row) {
        return Number(row.ExposureScore);
    });

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Disruption Exposure Score',
                data: values
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
})();
</script>

</body>
</html>
