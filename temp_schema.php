<?php require_once 'db.php'; $res = $conn->query('DESCRIBE inscripciones_grupales'); while($row = $res->fetch_assoc()) { echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Default'] . "
"; }
