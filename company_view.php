<?php
session_start();
require_once 'config.php';

// get stuff from the url
$companyID = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
if ($companyID <= 0) {
    die("No company selected.");
}

$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '2022-01-01';
$endDate   = isset($_GET['end_date'])   ? $_GET['end_date']   : '2025-12-31';

$direction       = isset($_GET['direction']) ? $_GET['direction'] : 'All';
$productFilterID = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$counterpartyID  = isset($_GET['counterparty_id']) ? (int)$_GET['counterparty_id'] : 0;
$statusFilter    = isset($_GET['status']) ? $_GET['status'] : 'All';

$companyInfo = null;

// fetch company info from db
$sqlInfo = "
SELECT 
    c.CompanyID,
    c.CompanyName,
    c.Type,
    c.TierLevel,
    l.CountryName,
    l.City,
    l.ContinentName,
    m.FactoryCapacity
FROM Company c
JOIN Location l 
    ON c.LocationID = l.LocationID
LEFT JOIN Manufacturer m
    ON m.CompanyID = c.CompanyID
WHERE c.CompanyID = ?
";

$stmt = $conn->prepare($sqlInfo);
$stmt->execute([$companyID]);
$companyInfo = $stmt->fetch();

// get upstream and downstream companies
$upstream = [];
$downstream = [];

$sqlUp = "
SELECT c2.CompanyID, c2.CompanyName
FROM DependsOn d
JOIN Company c2 
  ON d.UpstreamCompanyID = c2.CompanyID
WHERE d.DownstreamCompanyID = ?
ORDER BY c2.CompanyName
";
$stmt = $conn->prepare($sqlUp);
$stmt->execute([$companyID]);
while ($row = $stmt->fetch()) {
    $upstream[] = $row;
}

$sqlDown = "
SELECT c2.CompanyID, c2.CompanyName
FROM DependsOn d
JOIN Company c2 
  ON d.DownstreamCompanyID = c2.CompanyID
WHERE d.UpstreamCompanyID = ?
ORDER BY c2.CompanyName
";
$stmt = $conn->prepare($sqlDown);
$stmt->execute([$companyID]);
while ($row = $stmt->fetch()) {
    $downstream[] = $row;
}

// get financial stuff
$latestFinancial = null;
$financialHistory = [];

$sqlLatest = "
SELECT Quarter, RepYear, HealthScore
FROM FinancialReport
WHERE CompanyID = ?
ORDER BY RepYear DESC,
         CASE Quarter WHEN 'Q4' THEN 0 WHEN 'Q3' THEN 1 WHEN 'Q2' THEN 2 WHEN 'Q1' THEN 3 END
LIMIT 1
";
$stmt = $conn->prepare($sqlLatest);
$stmt->execute([$companyID]);
$latestFinancial = $stmt->fetch();

$sqlFinHist = "
SELECT Quarter, RepYear, HealthScore
FROM FinancialReport
WHERE CompanyID = ?
ORDER BY RepYear, CASE Quarter WHEN 'Q1' THEN 0 WHEN 'Q2' THEN 1 WHEN 'Q3' THEN 2 WHEN 'Q4' THEN 3 END
";
$stmt = $conn->prepare($sqlFinHist);
$stmt->execute([$companyID]);
while ($row = $stmt->fetch()) {
    $financialHistory[] = $row;
}

// check capacity and routes
$totalShippedQty = 0;
$utilizationPct = null;

if ($companyInfo['type'] === 'Manufacturer' && !empty($companyInfo['factorycapacity'])) {
    $sqlShipSum = "
        SELECT COALESCE(SUM(Quantity),0) AS TotalQty
        FROM Shipping
        WHERE SourceCompanyID = ?
          AND PromisedDate BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sqlShipSum);
    $stmt->execute([$companyID, $startDate, $endDate]);
    $row = $stmt->fetch();
    $totalShippedQty = (int)$row['totalqty'];
    if ($companyInfo['factorycapacity'] > 0) {
        // utilization calc: shipments divided by capacity
        $utilizationPct = min(100.0, 100.0 * $totalShippedQty / (float)$companyInfo['factorycapacity']);
    }
}

$routes = [];
if ($companyInfo['type'] === 'Distributor') {
    $sqlRoutes = "
    SELECT 
        fromC.CompanyName AS FromCompany,
        fromL.ContinentName AS FromRegion,
        toC.CompanyName AS ToCompany,
        toL.ContinentName AS ToRegion,
        COUNT(*) AS NumRoutes,
        COALESCE(AVG(s.Quantity),0) AS AvgVolume
    FROM OperatesLogistics ol
    JOIN Company fromC ON ol.FromCompanyID = fromC.CompanyID
    JOIN Location fromL ON fromC.LocationID = fromL.LocationID
    JOIN Company toC   ON ol.ToCompanyID = toC.CompanyID
    JOIN Location toL  ON toC.LocationID = toL.LocationID
    LEFT JOIN Shipping s
      ON s.DistributorID = ol.DistributorID
     AND s.SourceCompanyID = ol.FromCompanyID
     AND s.DestinationCompanyID = ol.ToCompanyID
    WHERE ol.DistributorID = ?
    GROUP BY fromC.CompanyName, fromL.ContinentName,
             toC.CompanyName, toL.ContinentName
    ORDER BY FromRegion, ToRegion, FromCompany, ToCompany
    ";
    $stmt = $conn->prepare($sqlRoutes);
    $stmt->execute([$companyID]);
    while ($row = $stmt->fetch()) {
        $routes[] = $row;
    }
}

// get products this company shipped
$productRows = [];
$totalProductQty = 0;

$sqlProducts = "
SELECT 
    p.ProductID,
    p.ProductName,
    p.Category,
    COALESCE(SUM(s.Quantity),0) AS TotalQty
FROM Shipping s
JOIN Product p ON s.ProductID = p.ProductID
WHERE s.SourceCompanyID = ?
  AND s.PromisedDate BETWEEN ? AND ?
GROUP BY p.ProductID, p.ProductName, p.Category
ORDER BY p.ProductName
";
$stmt = $conn->prepare($sqlProducts);
$stmt->execute([$companyID, $startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $row['totalqty'] = (int)$row['totalqty'];
    $totalProductQty += $row['totalqty'];
    $productRows[] = $row;
}

// figure out product diversity
$distinctProducts = count($productRows);
$top3SharePct = null;
if ($distinctProducts > 0 && $totalProductQty > 0) {
    usort($productRows, function($a, $b) {
        if ($a['totalqty'] == $b['totalqty']) {
            return 0;
        }
        return ($a['totalqty'] < $b['totalqty']) ? 1 : -1;
    });

    $top3Qty = 0;
    for ($i = 0; $i < min(3, count($productRows)); $i++) {
        $top3Qty += $productRows[$i]['totalqty'];
    }
    $top3SharePct = 100.0 * $top3Qty / $totalProductQty;
}

// sort products alphabetically
usort($productRows, function($a, $b) {
    return strcmp($a['productname'] ?? '', $b['productname'] ?? '');
});

// get dropdown options
$productOptions = [];
$sqlProdOptions = "SELECT ProductID, ProductName FROM Product ORDER BY ProductName";
$stmt = $conn->prepare($sqlProdOptions);
$stmt->execute([]);
while ($row = $stmt->fetch()) {
    $productOptions[] = $row;
}

$counterpartyOptions = [];
$sqlCp = "
SELECT CompanyID, CompanyName
FROM Company
WHERE CompanyID <> ?
ORDER BY CompanyName
";
$stmt = $conn->prepare($sqlCp);
$stmt->execute([$companyID]);
while ($row = $stmt->fetch()) {
    $counterpartyOptions[] = $row;
}

// get all transactions
$transactions = [];

// build filter parts - we'll handle these with param binding
// get shipping txns
if ($direction === 'All' || $direction === 'Shipping') {
    $sqlShip = "
    SELECT
        s.ShipmentID AS RowID,
        'Shipping' AS TxnType,
        s.PromisedDate AS TxnDate,
        src.CompanyName AS FromName,
        dest.CompanyName AS ToName,
        p.ProductName AS Product,
        s.Quantity,
        s.PromisedDate,
        s.ActualDate AS DeliveryDate,
        CASE 
          WHEN s.ActualDate IS NULL THEN 'In transit'
          WHEN s.ActualDate <= s.PromisedDate THEN 'On time'
          ELSE 'Late'
        END AS Status
    FROM Shipping s
    JOIN Company src  ON s.SourceCompanyID = src.CompanyID
    JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
    JOIN Product p    ON p.ProductID = s.ProductID
    WHERE s.SourceCompanyID = ?
      AND s.PromisedDate BETWEEN ? AND ?
    ";
    
    $params = [$companyID, $startDate, $endDate];
    
    if ($productFilterID > 0) {
        $sqlShip .= " AND p.ProductID = ? ";
        $params[] = $productFilterID;
    }
    
    if ($counterpartyID > 0) {
        $sqlShip .= " AND s.DestinationCompanyID = ? ";
        $params[] = $counterpartyID;
    }
    
    $stmt = $conn->prepare($sqlShip);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $transactions[] = $row;
    }
}

// get receiving txns
if ($direction === 'All' || $direction === 'Receiving') {
    $sqlRecv = "
    SELECT
        r.ReceivingID AS RowID,
        'Receiving' AS TxnType,
        r.ReceivedDate AS TxnDate,
        src.CompanyName AS FromName,
        dest.CompanyName AS ToName,
        p.ProductName AS Product,
        r.QuantityReceived AS Quantity,
        s.PromisedDate,
        r.ReceivedDate AS DeliveryDate,
        CASE 
          WHEN r.ReceivedDate <= s.PromisedDate THEN 'On time'
          ELSE 'Late'
        END AS Status
    FROM Receiving r
    JOIN Shipping s    ON r.ShipmentID = s.ShipmentID
    JOIN Company src   ON s.SourceCompanyID = src.CompanyID
    JOIN Company dest  ON s.DestinationCompanyID = dest.CompanyID
    JOIN Product p     ON s.ProductID = p.ProductID
    WHERE r.ReceiverCompanyID = ?
      AND r.ReceivedDate BETWEEN ? AND ?
    ";
    
    $params = [$companyID, $startDate, $endDate];
    
    if ($productFilterID > 0) {
        $sqlRecv .= " AND p.ProductID = ? ";
        $params[] = $productFilterID;
    }
    
    if ($counterpartyID > 0) {
        $sqlRecv .= " AND s.SourceCompanyID = ? ";
        $params[] = $counterpartyID;
    }
    
    $stmt = $conn->prepare($sqlRecv);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $transactions[] = $row;
    }
}

// get adjustments
if ($direction === 'All' || $direction === 'Adjustments') {
    $sqlAdj = "
    SELECT
        ia.AdjustmentID AS RowID,
        'Adjustment' AS TxnType,
        ia.AdjustmentDate AS TxnDate,
        c.CompanyName AS FromName,
        c.CompanyName AS ToName,
        p.ProductName AS Product,
        ia.QuantityChange AS Quantity,
        ia.AdjustmentDate AS PromisedDate,
        NULL AS DeliveryDate,
        'Adjusted' AS Status
    FROM InventoryAdjustment ia
    JOIN Company c ON ia.CompanyID = c.CompanyID
    JOIN Product p ON ia.ProductID = p.ProductID
    WHERE ia.CompanyID = ?
      AND ia.AdjustmentDate BETWEEN ? AND ?
    ";
    
    $params = [$companyID, $startDate, $endDate];
    
    if ($productFilterID > 0) {
        $sqlAdj .= " AND p.ProductID = ? ";
        $params[] = $productFilterID;
    }
    
    $stmt = $conn->prepare($sqlAdj);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $transactions[] = $row;
    }
}

// filter by status
if ($statusFilter !== 'All') {
    $transactions = array_values(array_filter($transactions, function ($row) use ($statusFilter) {
        return $row['status'] === $statusFilter;
    }));
}

// sort by date newest first
usort($transactions, function ($a, $b) {
    return strcmp($b['txndate'], $a['txndate']);
});

// get kpi stats
$onTimeRate = null;
$avgDelay   = null;
$stdDelay   = null;
$delayHistogram = [];

$sqlKpi = "
SELECT
    COUNT(*) AS TotalShipments,
    SUM(
        CASE 
          WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1
          ELSE 0
        END
    ) AS OnTimeCount,
    AVG(EXTRACT(DAY FROM (s.ActualDate::timestamp - s.PromisedDate::timestamp))) AS AvgDelay,
    STDDEV_SAMP(EXTRACT(DAY FROM (s.ActualDate::timestamp - s.PromisedDate::timestamp))) AS StdDelay
FROM Shipping s
WHERE s.SourceCompanyID = ?
  AND s.PromisedDate BETWEEN ? AND ?
  AND s.ActualDate IS NOT NULL
";
$stmt = $conn->prepare($sqlKpi);
$stmt->execute([$companyID, $startDate, $endDate]);
$row = $stmt->fetch();
if ($row['totalshipments'] > 0) {
    $onTimeRate = 100.0 * $row['ontimecount'] / $row['totalshipments'];
}
$avgDelay = $row['avgdelay'];
$stdDelay = $row['stddelay'];

$sqlDelayHist = "
SELECT 
    EXTRACT(DAY FROM (s.ActualDate::timestamp - s.PromisedDate::timestamp))::INT AS DelayDays,
    COUNT(*) AS Cnt
FROM Shipping s
WHERE s.SourceCompanyID = ?
  AND s.PromisedDate BETWEEN ? AND ?
  AND s.ActualDate IS NOT NULL
GROUP BY EXTRACT(DAY FROM (s.ActualDate::timestamp - s.PromisedDate::timestamp))
ORDER BY DelayDays
";
$stmt = $conn->prepare($sqlDelayHist);
$stmt->execute([$companyID, $startDate, $endDate]);
while ($row = $stmt->fetch()) {
    $delayHistogram[] = $row;
}

// get disruption events
$disruptionMonthly = [];
$disruptionList = [];

$sqlDisruptMonthly = "
SELECT 
    TO_CHAR(de.EventDate, 'YYYY-MM') AS MonthLabel,
    COUNT(*) AS NumEvents
FROM DisruptionEvent de
JOIN ImpactsCompany ic 
  ON de.EventID = ic.EventID
WHERE ic.AffectedCompanyID = ?
  AND de.EventDate >= CURRENT_DATE - INTERVAL '1 year'
GROUP BY TO_CHAR(de.EventDate, 'YYYY-MM')
ORDER BY MonthLabel
";
$stmt = $conn->prepare($sqlDisruptMonthly);
$stmt->execute([$companyID]);
while ($row = $stmt->fetch()) {
    $disruptionMonthly[] = $row;
}

$sqlDisruptList = "
SELECT 
    de.EventDate,
    de.EventRecoveryDate,
    dc.CategoryName,
    ic.ImpactLevel
FROM DisruptionEvent de
JOIN ImpactsCompany ic 
  ON de.EventID = ic.EventID
JOIN DisruptionCategory dc
  ON de.CategoryID = dc.CategoryID
WHERE ic.AffectedCompanyID = ?
  AND de.EventDate >= CURRENT_DATE - INTERVAL '1 year'
ORDER BY de.EventDate DESC
";
$stmt = $conn->prepare($sqlDisruptList);
$stmt->execute([$companyID]);
while ($row = $stmt->fetch()) {
    $eventDate    = $row['eventdate'];
    $recoveryDate = $row['eventrecoverydate'];
    $status       = ($recoveryDate === null || $recoveryDate === '0000-00-00') ? 'Ongoing' : 'Resolved';
    $recoveryTime = null;
    if ($recoveryDate && $recoveryDate !== '0000-00-00') {
        $sqlDays = "SELECT EXTRACT(DAY FROM (?::TIMESTAMP - ?::TIMESTAMP))::INT AS Days";
        $stmt2 = $conn->prepare($sqlDays);
        $stmt2->execute([$recoveryDate, $eventDate]);
        $tmp = $stmt2->fetch();
        $recoveryTime = $tmp['days'];
    }
    $row['status'] = $status;
    $row['recoverytime'] = $recoveryTime;
    $disruptionList[] = $row;
}

// prep chart data
$delayLabels = [];
$delayCounts = [];
foreach ($delayHistogram as $row) {
    $delayLabels[] = $row['delaydays'];
    $delayCounts[] = $row['cnt'];
}

$finLabels = [];
$finValues = [];
foreach ($financialHistory as $row) {
    $finLabels[] = $row['repyear'] . ' ' . $row['quarter'];
    $finValues[] = (float)$row['healthscore'];
}

$disruptLabels = [];
$disruptCounts = [];
foreach ($disruptionMonthly as $row) {
    $disruptLabels[] = $row['monthlabel'];
    $disruptCounts[] = (int)$row['numevents'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Company Detail – <?php echo htmlspecialchars($companyInfo['companyname']); ?></title>
  <link rel="stylesheet" href="styles.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>
<body>
<header>
  <h1>Supply Chain Manager – Company Detail</h1>
 <div class="back-button-container">
        <button type="button" onclick="window.location.href='companies.php';">
           Back to Company Explorer
        </button>
    </div>

</header>

<main>
  <!-- A. COMPANY INFO CARD -->
  <section>
    <h2>Company Info</h2>
    <div class="card">
      <h3><?php echo htmlspecialchars($companyInfo['companyname']); ?></h3>
      <p>
        <strong>Address:</strong>
        <?php
          echo htmlspecialchars($companyInfo['city']) . ', ' .
               htmlspecialchars($companyInfo['countryname']) . ' (' .
               htmlspecialchars($companyInfo['continentname']) . ')';
        ?><br>
        <strong>Type / Tier:</strong>
        <?php echo htmlspecialchars($companyInfo['type']); ?>
        &nbsp;–&nbsp; Tier <?php echo htmlspecialchars($companyInfo['tierlevel']); ?>
      </p>

      <p>
        <strong>Who they depend on (upstream):</strong>
        <?php if (empty($upstream)): ?>
          None recorded.
        <?php else: ?>
          <ul class="inline">
            <?php foreach ($upstream as $u): ?>
              <li>
                <a href="company_view.php?company_id=<?php echo (int)$u['companyid']; ?>">
                  <?php echo htmlspecialchars($u['companyname']); ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </p>

      <p>
        <strong>Who depends on them (downstream):</strong>
        <?php if (empty($downstream)): ?>
          None recorded.
        <?php else: ?>
          <ul class="inline">
            <?php foreach ($downstream as $d): ?>
              <li>
                <a href="company_view.php?company_id=<?php echo (int)$d['companyid']; ?>">
                  <?php echo htmlspecialchars($d['companyname']); ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </p>

      <p>
        <strong>Most recent financial status:</strong>
        <?php if ($latestFinancial): ?>
          <?php
            $score = (float)$latestFinancial['healthscore'];
            if ($score >= 75) {
                $label = "Healthy";
                $cls = "badge-ok";
            } elseif ($score >= 50) {
                $label = "Watch";
                $cls = "badge-warn";
            } else {
                $label = "At risk";
                $cls = "badge-risk";
            }
          ?>
          <span class="badge <?php echo $cls; ?>">
            <?php echo $label; ?> (<?php echo number_format($score,1); ?>)
            – <?php echo htmlspecialchars($latestFinancial['quarter']) . ' ' . htmlspecialchars($latestFinancial['repyear']); ?>
          </span>
        <?php else: ?>
          No financial reports recorded.
        <?php endif; ?>
      </p>

      <p>
        <a href="scm_company_edit.php?company_id=<?php echo $companyID; ?>">
          Edit company info
        </a>
      </p>
    </div>
  </section>

  <!-- B. CAPACITY / ROUTES -->
  <section>
    <h2>Capacity &amp; Logistics</h2>
    <div class="card">
      <?php if ($companyInfo['type'] === 'Manufacturer'): ?>
        <p>
          <strong>Factory capacity:</strong>
          <?php echo (int)$companyInfo['factorycapacity']; ?> units / week
        </p>
        <p>
          <strong>Total shipped in date range:</strong>
          <?php echo (int)$totalShippedQty; ?> units
        </p>
        <p>
          <strong>Utilization (rough):</strong>
          <?php echo $utilizationPct === null ? 'N/A' : number_format($utilizationPct,1) . '%'; ?>
        </p>
      <?php elseif ($companyInfo['type'] === 'Distributor'): ?>
        <p><strong>Unique routes operated:</strong> <?php echo count($routes); ?></p>
        <?php if (!empty($routes)): ?>
          <table>
            <thead>
              <tr>
                <th>Origin Company</th>
                <th>Origin Region</th>
                <th>Destination Company</th>
                <th>Destination Region</th>
                <th>Avg Volume (units)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($routes as $r): ?>
                <tr>
                  <td><?php echo htmlspecialchars($r['fromcompany']); ?></td>
                  <td><?php echo htmlspecialchars($r['fromregion']); ?></td>
                  <td><?php echo htmlspecialchars($r['tocompany']); ?></td>
                  <td><?php echo htmlspecialchars($r['toregion']); ?></td>
                  <td><?php echo number_format($r['avgvolume'],1); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p>No routes recorded for this distributor.</p>
        <?php endif; ?>
      <?php else: ?>
        <p>This company is a retailer; no capacity / route data specific to this view.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- C. PRODUCTS THEY SUPPLY -->
  <section>
    <h2>Products Supplied (as shipper)</h2>
    <div class="card">
      <p>
        <strong>Distinct products:</strong> <?php echo $distinctProducts; ?><br>
        <strong>Total shipped units:</strong> <?php echo (int)$totalProductQty; ?><br>
        <strong>% of volume from top 3 products:</strong>
        <?php echo $top3SharePct === null ? 'N/A' : number_format($top3SharePct,1) . '%'; ?>
      </p>
      <?php if (!empty($productRows)): ?>
        <table>
          <thead>
            <tr>
              <th>Product ID</th>
              <th>Product Name</th>
              <th>Category</th>
              <th>Volume share (%)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($productRows as $p): 
                    $share = ($totalProductQty > 0) ? 100.0 * $p['totalqty'] / $totalProductQty : 0;
            ?>
              <tr>
                <td><?php echo (int)$p['productid']; ?></td>
                <td><?php echo htmlspecialchars($p['productname']); ?></td>
                <td><?php echo htmlspecialchars($p['category']); ?></td>
                <td><?php echo number_format($share,1); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p>No shipments found in the selected date range.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- D. TRANSACTIONS -->
  <section>
    <h2>Transactions</h2>
    <form method="get" action="company_view.php">
      <input type="hidden" name="company_id" value="<?php echo $companyID; ?>">
      <label>
        Date from:
        <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
      </label>
      <label>
        to:
        <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
      </label>
      <label>
        Direction:
        <select name="direction">
          <option value="All"<?php if ($direction==='All') echo ' selected'; ?>>All</option>
          <option value="Shipping"<?php if ($direction==='Shipping') echo ' selected'; ?>>Shipping</option>
          <option value="Receiving"<?php if ($direction==='Receiving') echo ' selected'; ?>>Receiving</option>
          <option value="Adjustments"<?php if ($direction==='Adjustments') echo ' selected'; ?>>Adjustments</option>
        </select>
      </label>
      <label>
        Product:
        <select name="product_id">
          <option value="0">All</option>
          <?php foreach ($productOptions as $opt): ?>
            <option value="<?php echo (int)$opt['productid']; ?>"
              <?php if ($productFilterID === (int)$opt['productid']) echo 'selected'; ?>>
              <?php echo htmlspecialchars($opt['productname']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Counterparty:
        <select name="counterparty_id">
          <option value="0">All</option>
          <?php foreach ($counterpartyOptions as $opt): ?>
            <option value="<?php echo (int)$opt['companyid']; ?>"
              <?php if ($counterpartyID === (int)$opt['companyid']) echo 'selected'; ?>>
              <?php echo htmlspecialchars($opt['companyname']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Status:
        <select name="status">
          <option value="All"<?php if ($statusFilter==='All') echo ' selected'; ?>>All</option>
          <option value="On time"<?php if ($statusFilter==='On time') echo ' selected'; ?>>On time</option>
          <option value="Late"<?php if ($statusFilter==='Late') echo ' selected'; ?>>Late</option>
          <option value="In transit"<?php if ($statusFilter==='In transit') echo ' selected'; ?>>In transit</option>
          <option value="Adjusted"<?php if ($statusFilter==='Adjusted') echo ' selected'; ?>>Adjusted</option>
        </select>
      </label>
      <button type="submit">Apply</button>
    </form>

    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>From</th>
          <th>To</th>
          <th>Product</th>
          <th>Quantity</th>
          <th>Promised Date</th>
          <th>Delivery Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="9">No transactions for the selected filters.</td></tr>
        <?php else: ?>
          <?php foreach ($transactions as $t): ?>
            <tr>
              <td><?php echo htmlspecialchars($t['txndate']); ?></td>
              <td><?php echo htmlspecialchars($t['txntype']); ?></td>
              <td><?php echo htmlspecialchars($t['fromname']); ?></td>
              <td><?php echo htmlspecialchars($t['toname']); ?></td>
              <td><?php echo htmlspecialchars($t['product']); ?></td>
              <td><?php echo (int)$t['quantity']; ?></td>
              <td><?php echo htmlspecialchars($t['promiseddate']); ?></td>
              <td><?php echo htmlspecialchars($t['deliverydate']); ?></td>
              <td><?php echo htmlspecialchars($t['status']); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <!-- E. COMPANY KPIs -->
  <section>
    <h2>Company KPIs (<?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?>)</h2>
    <div class="kpi-grid">
      <div class="kpi-box">
        <div>On-time delivery rate</div>
        <div class="kpi-value">
          <?php echo $onTimeRate === null ? 'N/A' : number_format($onTimeRate,1) . '%'; ?>
        </div>
        <canvas id="kpiOnTimeChart" height="80"></canvas>
      </div>
      <div class="kpi-box">
        <div>Delay statistics (days)</div>
        <div class="kpi-value">
          Avg: <?php echo $avgDelay === null ? 'N/A' : number_format($avgDelay,2); ?>,
          σ: <?php echo $stdDelay === null ? 'N/A' : number_format($stdDelay,2); ?>
        </div>
        <canvas id="kpiDelayHist" height="80"></canvas>
      </div>
      <div class="kpi-box">
        <div>Financial health (last reports)</div>
        <canvas id="kpiFinancial" height="80"></canvas>
      </div>
      <div class="kpi-box">
        <div>Disruption events – past year</div>
        <canvas id="kpiDisruptions" height="80"></canvas>
      </div>
    </div>
  </section>

  <!-- F. DISRUPTION EVENT LIST -->
  <section>
    <h2>Disruption Events – Detail (Past 12 Months)</h2>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Severity</th>
          <th>Type</th>
          <th>Status</th>
          <th>Recovery time (days)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($disruptionList)): ?>
          <tr><td colspan="5">No disruption events impacting this company in the past year.</td></tr>
        <?php else: ?>
          <?php foreach ($disruptionList as $d): ?>
            <tr>
              <td><?php echo htmlspecialchars($d['eventdate']); ?></td>
              <td><?php echo htmlspecialchars($d['impactlevel']); ?></td>
              <td><?php echo htmlspecialchars($d['categoryname']); ?></td>
              <td><?php echo htmlspecialchars($d['status']); ?></td>
              <td>
                <?php echo $d['recoverytime'] === null ? 'N/A' : (int)$d['recoverytime']; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </section>
</main>

<script>
const onTimeRate   = <?php echo json_encode($onTimeRate); ?>;
const delayLabels  = <?php echo json_encode($delayLabels); ?>;
const delayCounts  = <?php echo json_encode($delayCounts); ?>;
const finLabels    = <?php echo json_encode($finLabels); ?>;
const finValues    = <?php echo json_encode($finValues); ?>;
const disruptLabels= <?php echo json_encode($disruptLabels); ?>;
const disruptCounts= <?php echo json_encode($disruptCounts); ?>;

document.addEventListener('DOMContentLoaded', function () {
  // On-time chart
  (function () {
    const canvas = document.getElementById('kpiOnTimeChart');
    if (!canvas || onTimeRate === null) return;
    const ctx = canvas.getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['On time','Other'],
        datasets: [{
          label: 'On-time delivery %',
          data: [onTimeRate, 100 - onTimeRate]
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero:true, max:100 } }
      }
    });
  })();

  // Delay histogram
  (function () {
    const canvas = document.getElementById('kpiDelayHist');
    if (!canvas || !delayLabels || delayLabels.length === 0) return;
    const ctx = canvas.getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: delayLabels,
        datasets: [{
          label: 'Count of shipments',
          data: delayCounts
        }]
      },
      options: {
        responsive: true,
        scales: {
          x: { title: { display:true, text:'Delay (days, Actual − Promised)' } },
          y: { beginAtZero:true, title:{ display:true, text:'Shipments' } }
        }
      }
    });
  })();

  // Financial health line chart
  (function () {
    const canvas = document.getElementById('kpiFinancial');
    if (!canvas || !finLabels || finLabels.length === 0) return;
    const ctx = canvas.getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: finLabels,
        datasets: [{
          label: 'Health score',
          data: finValues
        }]
      },
      options: {
        responsive: true,
        scales: {
          y: { beginAtZero:true, title:{ display:true, text:'Health score'} }
        }
      }
    });
  })();

  // Disruption monthly counts
  (function () {
    const canvas = document.getElementById('kpiDisruptions');
    if (!canvas || !disruptLabels || disruptLabels.length === 0) return;
    const ctx = canvas.getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: disruptLabels,
        datasets: [{
          label: 'Disruptions',
          data: disruptCounts
        }]
      },
      options: {
        responsive: true,
        scales: {
          y: { beginAtZero:true, title:{ display:true, text:'Events'} }
        }
      }
    });
  })();
});
</script>
</body>
</html>