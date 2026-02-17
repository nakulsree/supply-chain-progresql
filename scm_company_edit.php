<?php

session_start();
require_once 'config.php';

function get_value($array, $key, $default) {
    return isset($array[$key]) ? $array[$key] : $default;
}

// read company id
$company_id = 0;
if (isset($_GET['company_id'])) {
    $company_id = (int) $_GET['company_id'];
} elseif (isset($_GET['CompanyID'])) {
    $company_id = (int) $_GET['CompanyID'];
} elseif (isset($_POST['company_id'])) {
    $company_id = (int) $_POST['company_id'];
} elseif (isset($_POST['CompanyID'])) {
    $company_id = (int) $_POST['CompanyID'];
}

$success_message = "";
$error_message   = "";
$field_errors    = array();

$company = array(
    'CompanyName' => '',
    'LocationID'  => '',
    'TierLevel'   => '',
    'Type'        => ''
);

// locations dropdown
$locations = array();
$loc_sql = "SELECT LocationID, City, CountryName, ContinentName
            FROM Location
            ORDER BY CountryName, City";
$loc_stmt = $conn->prepare($loc_sql);
$loc_stmt->execute();
while ($row = $loc_stmt->fetch()) {
    $locations[] = $row;
}
if (empty($locations)) {
    $error_message = "error loading locations.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $company_id > 0) {

    $name       = trim(get_value($_POST, 'CompanyName', ''));
    $locationId = get_value($_POST, 'LocationID', '');
    $tier       = get_value($_POST, 'TierLevel', '');
    $type       = get_value($_POST, 'Type', '');

    if ($name === '') {
        $field_errors['CompanyName'] = "company name is required.";
    }

    if ($locationId === '' || !ctype_digit($locationId)) {
        $field_errors['LocationID'] = "please select a valid location.";
    }

    $valid_tiers = array('1','2','3');
    if ($tier === '' || !in_array($tier, $valid_tiers, true)) {
        $field_errors['TierLevel'] = "tier level must be 1, 2, or 3.";
    }

    $valid_types = array('Manufacturer','Distributor','Retailer');
    if ($type === '' || !in_array($type, $valid_types, true)) {
        $field_errors['Type'] = "please select a valid company type.";
    }

    if (empty($field_errors)) {
        $company_id_int = (int)$company_id;
        $locationId_int = (int)$locationId;

        $sql = "
            UPDATE Company
            SET CompanyName = ?,
                LocationID  = ?,
                TierLevel   = ?,
                Type        = ?
            WHERE CompanyID = ?
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt->execute([$name, $locationId_int, $tier, $type, $company_id_int])) {
            $success_message = "company information updated successfully.";
        } else {
            $error_message = "error updating company.";
        }
    }

    $company['CompanyName'] = $name;
    $company['LocationID']  = $locationId;
    $company['TierLevel']   = $tier;
    $company['Type']        = $type;
}

// get data for company
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $company_id > 0 && $error_message === "") {
    $company_id_int = (int)$company_id;

    $sql = "
        SELECT CompanyName, LocationID, TierLevel, Type
        FROM Company
        WHERE CompanyID = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$company_id_int]);
    $result_data = $stmt->fetch();

    if ($result_data) {
        $company = $result_data;
    } else {
        $error_message = "company not found.";
    }
}

// close connection (PDO auto-closes)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Edit Company</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.4; }
        .container { max-width: 600px; margin: 0 auto; }
        .message { padding: 8px 12px; margin-bottom: 12px; border-radius: 4px; }
        .success { background-color: #d4edda; border: 1px solid #c3e6cb; }
        .error   { background-color: #f8d7da; border: 1px solid #f5c6cb; }
        label { display: block; margin: 8px 0 4px; }
        input[type="text"], select {
            width: 100%;
            padding: 6px;
            box-sizing: border-box;
        }
        button { margin-top: 12px; padding: 8px 16px; cursor: pointer; }
        .back-link { margin-top: 16px; display: inline-block; }
        .field-error { color: #b00020; font-size: 12px; margin-top: 2px; }
        .input-error { border: 1px solid #b00020; }
    </style>
</head>
<body>
<div class="container">
    <h1>Edit Company</h1>

    <?php if ($success_message !== ""): ?>
        <div class="message success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <?php if ($error_message !== ""): ?>
        <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if ($company_id > 0 && $error_message === ""): ?>
    <form method="post" action="">
        <input type="hidden" name="CompanyID" value="<?php echo (int)$company_id; ?>">

        <label for="CompanyName">company name</label>
        <input
            type="text"
            id="CompanyName"
            name="CompanyName"
            required
            class="<?php echo isset($field_errors['CompanyName']) ? 'input-error' : ''; ?>"
            value="<?php echo htmlspecialchars(isset($company['CompanyName']) ? $company['CompanyName'] : '', ENT_QUOTES); ?>"
        >
        <?php if (isset($field_errors['CompanyName'])): ?>
            <div class="field-error"><?php echo htmlspecialchars($field_errors['CompanyName']); ?></div>
        <?php endif; ?>

        <label for="LocationID">location</label>
        <select
            id="LocationID"
            name="LocationID"
            class="<?php echo isset($field_errors['LocationID']) ? 'input-error' : ''; ?>"
        >
            <option value="">-- select location --</option>
            <?php
            $currentLoc = isset($company['LocationID']) ? $company['LocationID'] : '';
            foreach ($locations as $loc) {
                $value = $loc['LocationID'];
               $label = $loc['City'] . ", " . $loc['CountryName'] . " (" . $loc['ContinentName'] . ")";
                $selected = ((string)$value === (string)$currentLoc) ? 'selected' : '';
                echo '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' .
                     htmlspecialchars($label) .
                     '</option>';
            }
            ?>
        </select>
        <?php if (isset($field_errors['LocationID'])): ?>
            <div class="field-error"><?php echo htmlspecialchars($field_errors['LocationID']); ?></div>
        <?php endif; ?>

        <label for="TierLevel">tier level</label>
        <select
            id="TierLevel"
            name="TierLevel"
            class="<?php echo isset($field_errors['TierLevel']) ? 'input-error' : ''; ?>"
        >
            <?php
            $tiers = array('1','2','3');
            $currentTier = isset($company['TierLevel']) ? $company['TierLevel'] : '';
            foreach ($tiers as $t) {
                $selected = ($t === $currentTier) ? 'selected' : '';
                echo '<option value="' . htmlspecialchars($t) . '" ' . $selected . '>' .
                     htmlspecialchars($t) .
                     '</option>';
            }
            ?>
        </select>
        <?php if (isset($field_errors['TierLevel'])): ?>
            <div class="field-error"><?php echo htmlspecialchars($field_errors['TierLevel']); ?></div>
        <?php endif; ?>

        <label for="Type">type</label>
        <select
            id="Type"
            name="Type"
            class="<?php echo isset($field_errors['Type']) ? 'input-error' : ''; ?>"
        >
            <?php
            $types = array('Manufacturer','Distributor','Retailer');
            $currentType = isset($company['Type']) ? $company['Type'] : '';
            foreach ($types as $t) {
                $selected = ($t === $currentType) ? 'selected' : '';
                echo '<option value="' . htmlspecialchars($t) . '" ' . $selected . '>' .
                     htmlspecialchars($t) .
                     '</option>';
            }
            ?>
        </select>
        <?php if (isset($field_errors['Type'])): ?>
            <div class="field-error"><?php echo htmlspecialchars($field_errors['Type']); ?></div>
        <?php endif; ?>

        <button type="submit">save changes</button>
    </form>

    <a class="back-link" href="companies.php">← back to companies</a>
    <?php endif; ?>
</div>
</body>
</html>
