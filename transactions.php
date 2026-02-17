<?php
session_start();
require_once 'config.php';

// FILTERS (from GET)
$today = date('Y-m-d');

$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '2022-01-01';
$endDate   = isset($_GET['end_date'])   ? $_GET['end_date']   : $today;

$originCompanyID      = isset($_GET['origin_company'])      ? (int)$_GET['origin_company']      : 0;
$destinationCompanyID = isset($_GET['destination_company']) ? (int)$_GET['destination_company'] : 0;
$regionFilter         = isset($_GET['region'])              ? trim($_GET['region'])              : '';
$transactionType      = isset($_GET['trans_type'])          ? $_GET['trans_type']                : 'All';
$statusFilter         = isset($_GET['status'])              ? $_GET['status']                    : 'All';

// Store for use with parameterized queries
$params_main = [];


// DROPDOWN DATA

// Company list
$companyList = [];
$sqlCompanyList = "SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName";
$stmt = $conn->prepare($sqlCompanyList);
$stmt->execute();
$companyList = $stmt->fetchAll();

// Region list 
$regionList = [];
$sqlRegionList = "SELECT DISTINCT ContinentName AS Region FROM Location ORDER BY ContinentName";
$stmt = $conn->prepare($sqlRegionList);
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $regionList[] = $row['region'];
}


// WHERE CLAUSES

$shippingConditions   = [];
$receivingConditions  = [];
$adjustmentConditions = [];

// Date range filters (per table)
$shippingConditions[]   = "s.PromisedDate BETWEEN ? AND ?";
$receivingConditions[]  = "r.ReceivedDate BETWEEN ? AND ?";
$adjustmentConditions[] = "a.AdjustmentDate BETWEEN ? AND ?";

// Origin / Destination company filters (apply where they make sense)
if ($originCompanyID > 0) {
    $shippingConditions[]   = "s.SourceCompanyID = ?";
    $receivingConditions[]  = "s.SourceCompanyID = ?";  // shipping side of receiving
    $adjustmentConditions[] = "a.CompanyID = ?";        // adjustment at this company
}

if ($destinationCompanyID > 0) {
    $shippingConditions[]  = "s.DestinationCompanyID = ?";
    $receivingConditions[] = "r.ReceiverCompanyID = ?";
    // for adjustments we treat company as both origin/dest
    $adjustmentConditions[] = "a.CompanyID = ?";
}

// Region filter (match either origin or destination region for Shipping/Receiving,
// and company region for adjustments)
if ($regionFilter !== '') {
    $shippingConditions[]  = "(locSrc.ContinentName = ? OR locDest.ContinentName = ?)";
    $receivingConditions[] = "(locSrc.ContinentName = ? OR locRecv.ContinentName = ?)";
    $adjustmentConditions[] = "locAdj.ContinentName = ?";
}

// Transaction type flags
$includeShipping   = ($transactionType === 'All' || $transactionType === 'Shipping');
$includeReceiving  = ($transactionType === 'All' || $transactionType === 'Receiving');
$includeAdjustment = ($transactionType === 'All' || $transactionType === 'Adjustment');


// UNION QUERY: FLAT TRANSACTION VIEW
// Each SELECT returns:
//   Date | TransType | Origin | Destination | Product | Quantity |
//   PromisedDate | DeliveryDate | DelayDays | Status | DistributorID

$selectParts = [];

// Shipping rows
if ($includeShipping) {
    $shippingWhere = implode(" AND ", $shippingConditions);

    $shippingStatusCase = "
        CASE
            WHEN s.ActualDate IS NULL AND CURRENT_DATE <= s.PromisedDate THEN 'In transit'
            WHEN s.ActualDate IS NULL AND CURRENT_DATE >  s.PromisedDate THEN 'Delayed'
            WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 'Delivered'
            WHEN s.ActualDate IS NOT NULL AND s.ActualDate >  s.PromisedDate THEN 'Delayed'
            ELSE 'Unknown'
        END
    ";

    $selectParts[] = "
        SELECT
            s.PromisedDate AS Date,
            'Shipping' AS TransType,
            src.CompanyName AS Origin,
            dst.CompanyName AS Destination,
            p.ProductName AS Product,
            s.Quantity AS Quantity,
            s.PromisedDate AS PromisedDate,
            s.ActualDate AS DeliveryDate,
            CASE
                WHEN s.ActualDate IS NOT NULL THEN EXTRACT(DAY FROM (s.ActualDate::timestamp - s.PromisedDate::timestamp))
                WHEN s.ActualDate IS NULL AND CURRENT_DATE > s.PromisedDate THEN EXTRACT(DAY FROM (CURRENT_DATE::timestamp - s.PromisedDate::timestamp))
                ELSE NULL
            END AS DelayDays,
            $shippingStatusCase AS Status,
            s.DistributorID AS DistributorID
        FROM Shipping s
        JOIN InventoryTransaction it
            ON s.TransactionID = it.TransactionID
        JOIN Company src
            ON s.SourceCompanyID = src.CompanyID
        JOIN Company dst
            ON s.DestinationCompanyID = dst.CompanyID
        JOIN Product p
            ON s.ProductID = p.ProductID
        JOIN Location locSrc
            ON src.LocationID = locSrc.LocationID
        JOIN Location locDest
            ON dst.LocationID = locDest.LocationID
        WHERE $shippingWhere
    ";
}

// Receiving rows
if ($includeReceiving) {
    $receivingWhere = implode(" AND ", $receivingConditions);

    $selectParts[] = "
        SELECT
            r.ReceivedDate AS Date,
            'Receiving' AS TransType,
            src.CompanyName AS Origin,
            recv.CompanyName AS Destination,
            p.ProductName AS Product,
            r.QuantityReceived AS Quantity,
            s.PromisedDate AS PromisedDate,
            r.ReceivedDate AS DeliveryDate,
            EXTRACT(DAY FROM (r.ReceivedDate::timestamp - s.PromisedDate::timestamp)) AS DelayDays,
            CASE
                WHEN r.ReceivedDate <= s.PromisedDate THEN 'Delivered'
                WHEN r.ReceivedDate >  s.PromisedDate THEN 'Delayed'
                ELSE 'Unknown'
            END AS Status,
            s.DistributorID AS DistributorID
        FROM Receiving r
        JOIN InventoryTransaction it
            ON r.TransactionID = it.TransactionID
        JOIN Shipping s
            ON r.ShipmentID = s.ShipmentID
        JOIN Company src
            ON s.SourceCompanyID = src.CompanyID
        JOIN Company recv
            ON r.ReceiverCompanyID = recv.CompanyID
        JOIN Product p
            ON s.ProductID = p.ProductID
        JOIN Location locSrc
            ON src.LocationID = locSrc.LocationID
        JOIN Location locRecv
            ON recv.LocationID = locRecv.LocationID
        WHERE $receivingWhere
    ";
}

// Inventory adjustments
if ($includeAdjustment) {
    $adjustmentWhere = implode(" AND ", $adjustmentConditions);

    $selectParts[] = "
        SELECT
            a.AdjustmentDate AS Date,
            'Adjustment' AS TransType,
            c.CompanyName AS Origin,
            c.CompanyName AS Destination,
            p.ProductName AS Product,
            a.QuantityChange AS Quantity,
            a.AdjustmentDate AS PromisedDate,
            NULL AS DeliveryDate,
            NULL AS DelayDays,
            'Adjustment' AS Status,
            NULL AS DistributorID
        FROM InventoryAdjustment a
        JOIN InventoryTransaction it
            ON a.TransactionID = it.TransactionID
        JOIN Company c
            ON a.CompanyID = c.CompanyID
        JOIN Product p
            ON a.ProductID = p.ProductID
        JOIN Location locAdj
            ON c.LocationID = locAdj.LocationID
        WHERE $adjustmentWhere
    ";
}

$unionSql = '';
if (!empty($selectParts)) {
    $unionSql = implode("\nUNION ALL\n", $selectParts);
}

// FINAL QUERY
$transactionRows = [];
$statusWhere = '';
$params_trans = [$startDate, $endDate]; // Initial params for date range

if ($statusFilter !== 'All' && $statusFilter !== '') {
    $statusWhere = "WHERE base.Status = ?";
    $params_trans[] = $statusFilter;
}

if ($unionSql !== '') {
    $fromUnion = "FROM ( $unionSql ) AS base";

    $sqlTransactions = "
        SELECT
            base.Date,
            base.TransType,
            base.Origin,
            base.Destination,
            base.Product,
            base.Quantity,
            base.PromisedDate,
            base.DeliveryDate,
            base.DelayDays,
            base.Status,
            base.DistributorID
        $fromUnion
        $statusWhere
        ORDER BY base.Date DESC
    ";

    // Build parameter array for UNION query - parameters must follow the order of ? in SQL
    $params_union = [];
    
    // Add params for each included transaction type in order
    if ($includeShipping) {
        // For Shipping: date range params
        $params_union[] = $startDate;
        $params_union[] = $endDate;
        // Add origin/destination company params if set
        if ($originCompanyID > 0) {
            $params_union[] = $originCompanyID;
        }
        if ($destinationCompanyID > 0) {
            $params_union[] = $destinationCompanyID;
        }
        // Add region params if set
        if ($regionFilter !== '') {
            $params_union[] = $regionFilter;
            $params_union[] = $regionFilter;
        }
    }
    
    if ($includeReceiving) {
        // For Receiving: date range params
        $params_union[] = $startDate;
        $params_union[] = $endDate;
        // Add origin/destination company params if set
        if ($originCompanyID > 0) {
            $params_union[] = $originCompanyID;
        }
        if ($destinationCompanyID > 0) {
            $params_union[] = $destinationCompanyID;
        }
        // Add region params if set
        if ($regionFilter !== '') {
            $params_union[] = $regionFilter;
            $params_union[] = $regionFilter;
        }
    }
    
    if ($includeAdjustment) {
        // For Adjustment: date range params
        $params_union[] = $startDate;
        $params_union[] = $endDate;
        // Add origin/destination company params if set
        if ($originCompanyID > 0) {
            $params_union[] = $originCompanyID;
        }
        if ($destinationCompanyID > 0) {
            $params_union[] = $destinationCompanyID;
        }
        // Add region params if set
        if ($regionFilter !== '') {
            $params_union[] = $regionFilter;
        }
    }
    
    // Add status filter param if needed
    if ($statusFilter !== 'All' && $statusFilter !== '') {
        $params_union[] = $statusFilter;
    }

    $stmt = $conn->prepare($sqlTransactions);
    $stmt->execute($params_union);
    while ($row = $stmt->fetch()) {
        $transactionRows[] = $row;
    }

    $metrics = [
        'TotalVolume'  => 0,
        'OnTimeRate'   => null,
        'DelayedCount' => 0
    ];

    $sqlAgg = "
        SELECT
            SUM(CASE WHEN base.TransType IN ('Shipping','Receiving')
                     THEN base.Quantity ELSE 0 END) AS TotalVolume,
            SUM(CASE WHEN base.TransType IN ('Shipping','Receiving')
                     AND base.DelayDays IS NOT NULL
                     AND base.DelayDays <= 0
                     THEN 1 ELSE 0 END) AS OnTimeShipments,
            SUM(CASE WHEN base.TransType IN ('Shipping','Receiving')
                     THEN 1 ELSE 0 END) AS TotalShipments,
            SUM(CASE WHEN base.Status = 'Delayed' THEN 1 ELSE 0 END) AS DelayedCount
        $fromUnion
        $statusWhere
    ";
    $stmt = $conn->prepare($sqlAgg);
    $stmt->execute($params_union);
    if ($row = $stmt->fetch()) {
        $totalVolume      = (int)$row['totalvolume'];
        $onTimeShipments  = (int)$row['ontimeshipments'];
        $totalShipments   = (int)$row['totalshipments'];
        $delayedCount     = (int)$row['delayedcount'];

        $metrics['TotalVolume']  = $totalVolume;
        $metrics['DelayedCount'] = $delayedCount;
        if ($totalShipments > 0) {
            $metrics['OnTimeRate'] = ($onTimeShipments / $totalShipments) * 100.0;
        } else {
            $metrics['OnTimeRate'] = null;
        }
    }


    // SUMMARY FOR CHARTS

    $summaryByType   = [];
    $summaryByStatus = [];

    $sqlByType = "
        SELECT
            base.TransType,
            SUM(base.Quantity) AS TotalQty
        $fromUnion
        $statusWhere
        GROUP BY base.TransType
        ORDER BY base.TransType
    ";
    $stmt = $conn->prepare($sqlByType);
    $stmt->execute($params_union);
    while ($row = $stmt->fetch()) {
        $summaryByType[] = $row;
    }

    $sqlByStatus = "
        SELECT
            base.Status,
            COUNT(*) AS Cnt
        $fromUnion
        $statusWhere
        GROUP BY base.Status
        ORDER BY base.Status
    ";
    $stmt = $conn->prepare($sqlByStatus);
    $stmt->execute($params_union);
    while ($row = $stmt->fetch()) {
        $summaryByStatus[] = $row;
    }

    // PRODUCTS HANDLED 
    $productsByVolume = [];
    $sqlProducts = "
        SELECT
            base.Product,
            SUM(base.Quantity) AS TotalQty
        $fromUnion
        $statusWhere
        GROUP BY base.Product
        ORDER BY TotalQty DESC
        LIMIT 8
    ";
    $stmt = $conn->prepare($sqlProducts);
    $stmt->execute($params_union);
    while ($row = $stmt->fetch()) {
        $productsByVolume[] = $row;
    }

   
    // DELIVERY PERFORMANCE 
   
    $deliveryPerformance = [
        'onTime' => 0,
        'delayed' => 0
    ];
    $sqlPerformance = "
        SELECT
            SUM(CASE WHEN base.TransType IN ('Shipping','Receiving') AND base.DelayDays IS NOT NULL AND base.DelayDays <= 0 THEN 1 ELSE 0 END) AS OnTimeCount,
            SUM(CASE WHEN base.TransType IN ('Shipping','Receiving') AND base.Status = 'Delayed' THEN 1 ELSE 0 END) AS DelayedCount
        $fromUnion
    ";
    $stmt = $conn->prepare($sqlPerformance);
    $stmt->execute($params_union);
    $row = $stmt->fetch();
    if ($row) {
        $deliveryPerformance['onTime'] = (int)$row['ontimecount'];
        $deliveryPerformance['delayed'] = (int)$row['delayedcount'];
    }

    
    // DISRUPTION EXPOSURE 
    $disruptionExposure = 0;
    
 
    $companyIds = [];
    $sqlCompanies = "
        SELECT DISTINCT 
            CASE 
                WHEN base.TransType = 'Adjustment' THEN NULL
                ELSE COALESCE(src.CompanyID, COALESCE(dst.CompanyID, adj.CompanyID))
            END AS CompanyID
        $fromUnion
    ";
    
  
    $sqlDisruption = "
        SELECT
            COUNT(DISTINCT CASE WHEN ic.ImpactLevel = 'High' THEN de.EventID ELSE NULL END) AS HighCount,
            COUNT(DISTINCT de.EventID) AS TotalCount
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic ON de.EventID = ic.EventID
        WHERE de.EventDate >= ? AND de.EventDate <= ?
    ";
    
    $stmt = $conn->prepare($sqlDisruption);
    $stmt->execute([$startDate, $endDate]);
    $row = $stmt->fetch();
    if ($row) {
        $highCount = (int)$row['highcount'];
        $totalCount = (int)$row['totalcount'];
        $disruptionExposure = $totalCount + (2 * $highCount);
    }

  
    // TRANSACTION STATUS OVER TIME 
    $transactionsByDate = [];
    $sqlByDate = "
        SELECT
            DATE_TRUNC('month', base.Date)::DATE AS Month,
            base.Status,
            COUNT(*) AS Cnt
        $fromUnion
        GROUP BY DATE_TRUNC('month', base.Date), base.Status
        ORDER BY Month DESC
        LIMIT 12
    ";
    $stmt = $conn->prepare($sqlByDate);
    $stmt->execute($params_union);
    while ($row = $stmt->fetch()) {
        $transactionsByDate[] = $row;
    }

} else {
    // No selects included (unlikely, but just in case)
    $metrics         = ['TotalVolume' => 0, 'OnTimeRate' => null, 'DelayedCount' => 0];
    $summaryByType   = [];
    $summaryByStatus = [];
    $productsByVolume = [];
    $deliveryPerformance = ['onTime' => 0, 'delayed' => 0];
    $disruptionExposure = 0;
    $transactionsByDate = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Supply Chain – Transactions</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="transactions.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header>
  <h1>Supply Chain Manager – Transactions</h1>
    <nav>
        <a href="overview.php">Overview</a> |
        <a href="companies.php">Companies</a> |
        <a href="disruptions.php">Disruptions</a> |
	<a href="transactions.php">Transactions</a> |
	<a href="distributors.php">Distributor Details</a> |
	<a href="index.php">Logout</a> |
    </nav>

</header>

<div class="container">

<section class="filter-bar">
  <h2>Filters</h2>
  <form method="get" action="transactions.php">
    <label>Start Date:
      <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
    </label>
    <label>End Date:
      <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
    </label>

    <label>Origin Company:
      <select name="origin_company">
        <option value="0">All</option>
        <?php foreach ($companyList as $c): ?>
          <option value="<?php echo (int)$c['companyid']; ?>"
            <?php if ($originCompanyID === (int)$c['companyid']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($c['companyname']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Destination Company:
      <select name="destination_company">
        <option value="0">All</option>
        <?php foreach ($companyList as $c): ?>
          <option value="<?php echo (int)$c['companyid']; ?>"
            <?php if ($destinationCompanyID === (int)$c['companyid']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($c['companyname']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Region (origin or destination):
      <select name="region">
        <option value="">All</option>
        <?php foreach ($regionList as $reg): ?>
          <option value="<?php echo htmlspecialchars($reg); ?>"
            <?php if ($regionFilter === $reg) echo 'selected'; ?>>
            <?php echo htmlspecialchars($reg); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Transaction Type:
      <select name="trans_type">
        <option value="All"        <?php if ($transactionType === 'All')        echo 'selected'; ?>>All</option>
        <option value="Shipping"   <?php if ($transactionType === 'Shipping')   echo 'selected'; ?>>Shipping</option>
        <option value="Receiving"  <?php if ($transactionType === 'Receiving')  echo 'selected'; ?>>Receiving</option>
        <option value="Adjustment" <?php if ($transactionType === 'Adjustment') echo 'selected'; ?>>Adjustment</option>
      </select>
    </label>

    <label>Status:
      <select name="status">
        <option value="All"        <?php if ($statusFilter === 'All')        echo 'selected'; ?>>All</option>
        <option value="In transit" <?php if ($statusFilter === 'In transit') echo 'selected'; ?>>In transit</option>
        <option value="Delivered"  <?php if ($statusFilter === 'Delivered')  echo 'selected'; ?>>Delivered</option>
        <option value="Delayed"    <?php if ($statusFilter === 'Delayed')    echo 'selected'; ?>>Delayed</option>
        <option value="Adjustment" <?php if ($statusFilter === 'Adjustment') echo 'selected'; ?>>Adjustment</option>
      </select>
    </label>

    <button type="submit">Apply Filters</button>
  </form>
</section>

<section>
  <h2>Current Filter – Summary Metrics</h2>
  <div class="summary-cards">
    <div class="summary-card">
      <h3>Total Shipment Volume</h3>
      <p><?php echo number_format($metrics['TotalVolume']); ?></p>
    </div>
    <div class="summary-card">
      <h3>On-Time Delivery Rate</h3>
      <p>
        <?php
        if ($metrics['OnTimeRate'] === null) {
            echo "N/A";
        } else {
            echo number_format($metrics['OnTimeRate'], 1) . "%";
        }
        ?>
      </p>
    </div>
    <div class="summary-card">
      <h3># of Delayed Shipments</h3>
      <p><?php echo number_format($metrics['DelayedCount']); ?></p>
    </div>
    <div class="summary-card">
      <h3>Disruption Exposure</h3>
      <p><?php echo number_format($disruptionExposure); ?></p>
    </div>
  </div>
</section>

<section>
  <h2>Transactions</h2>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Type</th>
        <th>Origin</th>
        <th>Destination</th>
        <th>Product</th>
        <th>Quantity</th>
        <th>Promised Date</th>
        <th>Delivery Date</th>
        <th>Delay (days)</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($transactionRows)): ?>
        <tr><td colspan="10">No transactions match the selected filters.</td></tr>
      <?php else: ?>
        <?php foreach ($transactionRows as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['date'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($row['transtype'] ?? ''); ?></td>
            <td>
              <?php
              // If you have a distributor-specific page, you can link to it here.
              // Example: if this Origin is a Distributor, link to distributor page.
              echo htmlspecialchars($row['origin'] ?? '');
              ?>
            </td>
            <td><?php echo htmlspecialchars($row['destination'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($row['product'] ?? ''); ?></td>
            <td><?php echo (int)($row['quantity'] ?? 0); ?></td>
            <td><?php echo htmlspecialchars($row['promiseddate'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($row['deliverydate'] ?? ''); ?></td>
            <td><?php echo $row['delaydays'] === null ? '' : (int)$row['delaydays']; ?></td>
            <td><?php echo htmlspecialchars($row['status'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<section>
  <h2>Visualizations</h2>

  <h3>Shipment Volume by Transaction Type</h3>
  <canvas id="byTypeChart" height="80"></canvas>

  <h3>Status Distribution</h3>
  <canvas id="statusChart" height="80"></canvas>

  <h3>Top Products Handled</h3>
  <canvas id="productsChart" height="80"></canvas>

  <h3>Delivery Performance</h3>
  <canvas id="performanceChart" height="80"></canvas>
</section>

</div>

<!-- Pass PHP data to JS -->
<script>
  const summaryByTypeData   = <?php echo json_encode($summaryByType); ?>;
  const summaryByStatusData = <?php echo json_encode($summaryByStatus); ?>;
  const productsByVolumeData = <?php echo json_encode($productsByVolume); ?>;
  const deliveryPerformanceData = <?php echo json_encode($deliveryPerformance); ?>;
</script>

<script>
  // Chart 1: Volume by Transaction Type
  (function () {
    const ctx = document.getElementById('byTypeChart');
    if (!ctx || !summaryByTypeData) return;

    const labels = summaryByTypeData.map(r => r.transtype);
    const values = summaryByTypeData.map(r => Number(r.totalqty));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Total Quantity',
          data: values,
          backgroundColor: 'rgba(33, 150, 243, 0.5)',
          borderColor: 'rgb(33, 150, 243)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // Chart 2: Status Distribution
  (function () {
    const ctx = document.getElementById('statusChart');
    if (!ctx || !summaryByStatusData) return;

    const labels = summaryByStatusData.map(r => r.status);
    const values = summaryByStatusData.map(r => Number(r.cnt));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: '# Transactions',
          data: values,
          backgroundColor: 'rgba(33, 150, 243, 0.5)',
          borderColor: 'rgb(33, 150, 243)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  })();

  // Chart 3: Top Products Handled
  (function () {
    const ctx = document.getElementById('productsChart');
    if (!ctx || !productsByVolumeData || productsByVolumeData.length === 0) return;

    const labels = productsByVolumeData.map(r => r.product);
    const values = productsByVolumeData.map(r => Number(r.totalqty));

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Quantity Handled',
          data: values,
          backgroundColor: 'rgba(33, 150, 243, 0.5)',
          borderColor: 'rgb(33, 150, 243)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
      }
    });
  })();

  // Chart 4: Delivery Performance 
  (function () {
    const ctx = document.getElementById('performanceChart');
    if (!ctx) return;

    const onTime = deliveryPerformanceData.onTime || 0;
    const delayed = deliveryPerformanceData.delayed || 0;
    const total = onTime + delayed;

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['On-Time', 'Delayed'],
        datasets: [{
          label: 'Shipments',
          data: [onTime, delayed],
          backgroundColor: [
            'rgba(33, 150, 243, 0.5)',
            'rgba(33, 150, 243, 0.5)'
          ],
          borderColor: [
            'rgb(33, 150, 243)',
            'rgb(33, 150, 243)'
          ],
          borderWidth: 1
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