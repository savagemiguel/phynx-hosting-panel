<!DOCTYPE html>
<html>
<head>
	<title>Privileges</title>
    <link rel="stylesheet" href="includes/css/styles.css">
</head>
<body>

<?php
// Create connection
$conn = new mysqli("localhost", "root", "", "mysql");

// Check connection
if ($conn->connect_error) {
    die("Connection failed: {$conn->connect_error}");
}

// Define privilege categories
$privilege_categories = [
    'Data' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    'Structure' => ['CREATE', 'ALTER', 'INDEX', 'DROP', 'CREATE TEMPORARY TABLES', 'SHOW VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EXECUTE', 'CREATE VIEW', 'EVENT', 'TRIGGER'],
    'Administration' => ['GRANT', 'SUPER', 'PROCESS', 'RELOAD', 'SHUTDOWN', 'SHOW DATABASES', 'LOCK TABLES', 'REFERENCES', 'REPLICATION CLIENT', 'REPLICATION SLAVE', 'CREATE USER']
];

// Get all available privileges from MySQL
$query = "SHOW PRIVILEGES";
$result = $conn->query($query);
$available_privileges = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $available_privileges[] = $row['Privilege'];
    }
}

echo "<table class='privileges-table'>";
echo "<thead><tr><th>Data</th><th>Structure</th><th>Administration</th></tr></thead>";
echo "<tbody><tr>";

foreach ($privilege_categories as $category => $privileges) {
    echo "<td class='privilege-column'>";
    foreach ($privileges as $privilege) {
        echo "<label><input type='checkbox' name='privileges[]' value='$privilege'> $privilege</label><br />";
    }
    echo "</td>";
}

echo "</tr></tbody></table>";

?>

</body>
</html>