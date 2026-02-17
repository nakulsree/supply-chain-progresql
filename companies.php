<?php
session_start();
require_once 'config.php';

// FILTERS FROM GET
$searchName   = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$typeFilter   = isset($_GET['type']) ? $_GET['type'] : 'All';
$tierFilter   = isset($_GET['tier']) ? $_GET['tier'] : 'All';
$regionFilter = isset($_GET['region']) ? $_GET['region'] : 'All';

// DROPDOWN OPTIONS: REGIONS
$regions = [];
$sqlRegions = "
    SELECT DISTINCT ContinentName 
    FROM Location 
    ORDER BY ContinentName
";
$stmt = $conn->prepare($sqlRegions);
$stmt->execute();
$regions_result = $stmt->fetchAll();
foreach ($regions_result as $row) {
    $regions[] = $row['ContinentName'];
}

// MAIN QUERY: COMPANY LIST + KPIs
$companies = [];

$sql = "
SELECT
    c.CompanyID,
    c.CompanyName,
    c.Type,
    c.TierLevel,
    l.ContinentName AS Region,
    COUNT(DISTINCT sp.ProductID) AS NumProducts,
    CASE 
        WHEN COUNT(s.ShipmentID) = 0 THEN NULL
        ELSE 100.0 * SUM(
            CASE 
                WHEN s.ActualDate IS NOT NULL 
                     AND s.ActualDate <= s.PromisedDate THEN 1 
                ELSE 0 
            END
        ) / COUNT(s.ShipmentID)
    END AS OnTimeRate
FROM Company c
JOIN Location l 
    ON c.LocationID = l.LocationID
LEFT JOIN SuppliesProduct sp 
    ON sp.SupplierID = c.CompanyID
LEFT JOIN Shipping s
    ON s.SourceCompanyID = c.CompanyID
WHERE 1 = 1
";

// Build dynamic WHERE clauses
$params = [];

if ($searchName !== '') {
    $sql .= " AND c.CompanyName LIKE ?";
    $params[] = '%' . $searchName . '%';
}

if ($typeFilter !== 'All') {
    $sql .= " AND c.Type = ?";
    $params[] = $typeFilter;
}

if ($tierFilter !== 'All') {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $tierFilter;
}

if ($regionFilter !== 'All') {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $regionFilter;
}

$sql .= "
GROUP BY 
    c.CompanyID,
    c.CompanyName,
    c.Type,
    c.TierLevel,
    l.ContinentName
ORDER BY c.CompanyName
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Supply Chain – Company Explorer</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="companies.css">
</head>
<body>
<header>
    <h1>Supply Chain Manager - Company Explorer</h1>
    <nav>
        <a href="overview.php">Overview</a> |
        <a href="companies.php">Companies</a> |
        <a href="disruptions.php">Disruptions</a> |
	<a href="transactions.php">Transactions</a> |
	<a href="distributors.php">Distributor Details</a> |
	<a href="index.php">Logout</a> |
    </nav>
</header>

<main>
  <h2>Search &amp; Filters</h2>
  <form method="get" action="companies.php" class="filter-bar">
    <label>
      Search by company name:
      <input type="text" name="search_name"
             value="<?php echo htmlspecialchars($searchName); ?>">
    </label>

    <label>
      Type:
      <select name="type">
        <option value="All"<?php if ($typeFilter==='All') echo ' selected'; ?>>All</option>
        <option value="Manufacturer"<?php if ($typeFilter==='Manufacturer') echo ' selected'; ?>>Manufacturer</option>
        <option value="Distributor"<?php if ($typeFilter==='Distributor') echo ' selected'; ?>>Distributor</option>
        <option value="Retailer"<?php if ($typeFilter==='Retailer') echo ' selected'; ?>>Retailer</option>
      </select>
    </label>

    <label>
      Tier:
      <select name="tier">
        <option value="All"<?php if ($tierFilter==='All') echo ' selected'; ?>>All</option>
        <option value="1"<?php if ($tierFilter==='1') echo ' selected'; ?>>1</option>
        <option value="2"<?php if ($tierFilter==='2') echo ' selected'; ?>>2</option>
        <option value="3"<?php if ($tierFilter==='3') echo ' selected'; ?>>3</option>
      </select>
    </label>

    <label>
      Region:
      <select name="region">
        <option value="All"<?php if ($regionFilter==='All') echo ' selected'; ?>>All</option>
        <?php foreach ($regions as $r): ?>
          <option value="<?php echo htmlspecialchars($r); ?>"
            <?php if ($regionFilter === $r) echo ' selected'; ?>>
            <?php echo htmlspecialchars($r); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <button type="submit">Apply</button>
  </form>

  <h2>Results</h2>
  <p class="small">
    Click any row to open the full company detail view.
  </p>

  <div class="table-scroll-container">
  <table>
    <thead>
      <tr>
        <th>Company Name</th>
        <th>Type</th>
        <th>Tier</th>
        <th>Region</th>
        <th>#Products</th>
        <th>On-time rate (%)</th>
        <th>View</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($companies)): ?>
        <tr><td colspan="7">No companies found for the selected filters.</td></tr>
      <?php else: ?>
        <?php foreach ($companies as $row): ?>
          <tr class="clickable-row"
              data-link="company_view.php?company_id=<?php echo (int)$row['CompanyID']; ?>">
            <td><?php echo htmlspecialchars($row['CompanyName']); ?></td>
            <td><?php echo htmlspecialchars($row['Type']); ?></td>
            <td><?php echo htmlspecialchars($row['TierLevel']); ?></td>
            <td><?php echo htmlspecialchars($row['Region']); ?></td>
            <td><?php echo (int)$row['NumProducts']; ?></td>
            <td>
              <?php
              if ($row['OnTimeRate'] === null) {
                  echo 'N/A';
              } else {
                  echo number_format($row['OnTimeRate'], 1);
              }
              ?>
            </td>
            <td>
              <a href="company_view.php?company_id=<?php echo (int)$row['CompanyID']; ?>">
                View
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const rows = document.querySelectorAll('tr.clickable-row');
  rows.forEach(function (row) {
    row.addEventListener('click', function (e) {
      // ignore clicks directly on links to avoid double navigation
      if (e.target.tagName.toLowerCase() === 'a') return;
      const link = row.getAttribute('data-link');
      if (link) {
        window.location.href = link;
      }
    });
  });
});
</script>
</body>
</html>