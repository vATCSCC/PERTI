<?php

include(dirname(__DIR__, 1) . '/load/config.php');
include(dirname(__DIR__, 1) . '/load/connect.php');

if (session_status() == PHP_SESSION_NONE) {
    include('tables.php');

    foreach ($tables as $data) {
        // Setting Values
        $name = $data['name'];
        $columns = $data['columns'];
    
        try {
            
            $conn_pdo->beginTransaction();
    
            $sh = $conn_pdo->prepare("DESCRIBE $name");
    
            if ($sh->execute());
    
        } catch (PDOException $e) {
            $conn_pdo->rollback();
    
            // Setting Values
            $collect = "";

            // FOREACH: Processing Rows for table creation
            foreach ($columns as $column) {
                $vars = explode(':', $column);
            
                if (isset($vars[2])) {
                    $collect = $collect . "`$vars[0]` $vars[1]($vars[2]) NOT NULL,";
                } else {
                    $collect = $collect . "`$vars[0]` $vars[1] NOT NULL,";
                }
            }

            $query = "CREATE TABLE `$name` (`id` int(6) NOT NULL AUTO_INCREMENT,
            $collect 
            `updated_at` TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(), 
            `created_at` TIMESTAMP DEFAULT NOW() NOT NULL, 
            PRIMARY KEY(`id`))";

            if (mysqli_query($conn_sqli, $query)) {
                
            } else {
                echo mysqli_error($conn_sqli);
            }
        }
    }
}

?>