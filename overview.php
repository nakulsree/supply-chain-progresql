<?php
session_start();

// Set timezone to prevent date() warnings- was being a problem before
date_default_timezone_set('America/Indiana/Indianapolis');
require_once 'config.php';


// Total companies
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM Company");
$stmt->execute();
$row = $stmt->fetch();
$total_companies = ($row && isset($row['count'])) ? $row['count'] : 0;

// Total shipments
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM Shipping");
$stmt->execute();
$row = $stmt->fetch();
$total_shipments = ($row && isset($row['count'])) ? $row['count'] : 0;

// on-time delivery rate
$stmt = $conn->prepare("
  SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN ActualDate IS NOT NULL AND ActualDate <= PromisedDate THEN 1 ELSE 0 END) as on_time
  FROM Shipping
");
$stmt->execute();
$row = $stmt->fetch();
$on_time_rate = ($row && isset($row['total']) && $row['total'] > 0) ? round(($row['on_time'] / $row['total']) * 100, 1) : 0;

// active disruptions
$stmt = $conn->prepare("SELECT COUNT(DISTINCT EventID) as count FROM DisruptionEvent WHERE EventRecoveryDate IS NULL");
$stmt->execute();
$row = $stmt->fetch();
$active_disruptions = ($row && isset($row['count'])) ? $row['count'] : 0;

// Database connection is managed by config.php/PDO
// PDO doesn't require explicit close like MySQLi
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Supply Chain Manager – Overview</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="overview.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header>
    <h1>Supply Chain Manager - Overview</h1>
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
  <h2>Network Overview</h2>
  
  <!-- key metrics cards -->
  <div class="metrics-row">
    <div class="metric-card">
      <h3>Total Companies</h3>
      <p class="metric-value"><?php echo $total_companies; ?></p>
    </div>
    <div class="metric-card">
      <h3>Total Shipments</h3>
      <p class="metric-value"><?php echo $total_shipments; ?></p>
    </div>
    <div class="metric-card">
      <h3>On-Time Delivery Rate</h3>
      <p class="metric-value"><?php echo $on_time_rate; ?>%</p>
    </div>
    <div class="metric-card">
      <h3>Active Disruptions</h3>
      <p class="metric-value alert-value"><?php echo $active_disruptions; ?></p>
    </div>
  </div>


</div>



</body>
</html>
