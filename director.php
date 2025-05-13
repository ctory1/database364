<?php
session_start(); // Start session to track last action

// Check if this is a new session by looking for a 'session_started' flag
if (!isset($_SESSION['session_started'])) {
    // This is a new session, reset last_action and set the flag
    unset($_SESSION['last_action']);
    $_SESSION['session_started'] = time(); // Mark the session as started with a timestamp
}

// Reset show_all to false on every page load unless explicitly toggled
if (!isset($_POST['toggle_show_all'])) {
    $_SESSION['show_all_directors'] = false;
}

// Handle button click to toggle show_all for directors
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_show_all'])) {
    $_SESSION['show_all_directors'] = !$_SESSION['show_all_directors'];
}

// Database connection
$host = 'localhost';
$dbname = 'movie_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle CRUD actions
$action = isset($_GET['action']) ? $_GET['action'] : 'read';
$error_message = '';
$edit_Director = null;

// Function to save last action
function saveLastAction($type, $data) {
    $_SESSION['last_action'] = [
        'type' => $type,
        'data' => $data,
        'timestamp' => time()
    ];
}

// CREATE: Add a new Director
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $BirthYear = (int)$_POST['BirthYear'];
    $FirstName = trim($_POST['FirstName']);
    $MiddleName = trim($_POST['MiddleName']) ?: null;
    $LastName = trim($_POST['LastName']);

    // Validation
    if (empty($FirstName) || empty($LastName) || empty($BirthYear)) {
        $error_message = "Error: First Name, Last Name, and Birth Year are required!";
    } else if ($BirthYear < 1900 || $BirthYear > date('Y')) {
        $error_message = "Error: Birth year must be between 1900 and " . date('Y') . "!";
    } else {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Director WHERE BirthYear = ? AND FirstName = ? AND LastName = ?");
        $checkStmt->execute([$BirthYear, $FirstName, $LastName]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            $error_message = "Error: A Director with this name and birth year already exists!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO Director (BirthYear, FirstName, MiddleName, LastName) VALUES (?, ?, ?, ?)");
            $stmt->execute([$BirthYear, $FirstName, $MiddleName, $LastName]);
            $lastId = $pdo->lastInsertId();
            saveLastAction('create', ['DirectorId' => $lastId]);
            header("Location: director.php");
            exit;
        }
    }
}

// UPLOAD CSV: Process CSV file and populate database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_csv') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($fileTmpPath, 'r');
        $added_ids = [];
        if ($handle !== false) {
            fgetcsv($handle, 1000, ";", '"', '\\'); // Skip header row
            $rowNum = 1;
            while (($data = fgetcsv($handle, 1000, ";", '"', '\\')) !== false) {
                $rowNum++;
                if (count($data) >= 5) {
                    $FirstName = trim($data[1]);
                    $MiddleName = trim($data[2]);
                    $LastName = trim($data[3]);
                    $BirthYear = (int)trim($data[4]);

                    if (empty($FirstName) || empty($LastName) || empty($BirthYear)) {
                        $error_message .= "Row $rowNum: Missing required field(s) - " . implode(';', $data) . "<br>";
                        continue;
                    }
                    if ($BirthYear < 1900 || $BirthYear > date('Y')) {
                        $error_message .= "Row $rowNum: Invalid birth year '$BirthYear' (must be 1900-" . date('Y') . ") for '$FirstName' - " . implode(';', $data) . "<br>";
                        continue;
                    }

                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Director WHERE BirthYear = ? AND FirstName = ? AND LastName = ?");
                    $checkStmt->execute([$BirthYear, $FirstName, $LastName]);
                    $exists = $checkStmt->fetchColumn();

                    if ($exists == 0) {
                        $stmt = $pdo->prepare("INSERT INTO Director (BirthYear, FirstName, MiddleName, LastName) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$BirthYear, $FirstName, $MiddleName, $LastName]);
                        $added_ids[] = $pdo->lastInsertId();
                    }
                } else {
                    $error_message .= "Row $rowNum: Invalid format, expected 5 columns, got " . count($data) . " - " . implode(';', $data) . "<br>";
                }
            }
            fclose($handle);
            if (!empty($added_ids)) {
                saveLastAction('upload_csv', ['ids' => $added_ids]);
            }
        }
    }
    if (empty($error_message)) {
        header("Location: director.php");
        exit;
    }
}

// EDIT: Load Director data for editing
if ($action === 'edit' && isset($_GET['DirectorId'])) {
    $DirectorId = $_GET['DirectorId'];
    $stmt = $pdo->prepare("SELECT * FROM Director WHERE DirectorId = ?");
    $stmt->execute([$DirectorId]);
    $edit_Director = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_Director) {
        $error_message = "Director not found!";
    }
}

// UPDATE: Handle edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    $DirectorId = $_POST['DirectorId'];
    $BirthYear = (int)$_POST['BirthYear'];
    $FirstName = trim($_POST['FirstName']);
    $MiddleName = trim($_POST['MiddleName']) ?: null;
    $LastName = trim($_POST['LastName']);

    // Validation
    if (empty($FirstName) || empty($LastName) || empty($BirthYear)) {
        $error_message = "Error: First Name, Last Name, and Birth Year are required!";
    } elseif ($BirthYear < 1900 || $BirthYear > date('Y')) {
        $error_message = "Error: Birth year must be between 1900 and " . date('Y') . "!";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM Director WHERE DirectorId = ?");
        $stmt->execute([$DirectorId]);
        $original_data = $stmt->fetch(PDO::FETCH_ASSOC);

        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Director WHERE BirthYear = ? AND FirstName = ? AND LastName = ? AND DirectorId != ?");
        $checkStmt->execute([$BirthYear, $FirstName, $LastName, $DirectorId]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            $error_message = "Error: Another Director with this name and birth year already exists!";
        } else {
            $stmt = $pdo->prepare("UPDATE Director SET BirthYear = ?, FirstName = ?, MiddleName = ?, LastName = ? WHERE DirectorId = ?");
            $stmt->execute([$BirthYear, $FirstName, $MiddleName, $LastName, $DirectorId]);
            saveLastAction('update', ['DirectorId' => $DirectorId, 'original' => $original_data]);
            header("Location: director.php");
            exit;
        }
    }
}

// DELETE: Remove an Director
if ($action === 'delete' && isset($_GET['DirectorId'])) {
    $DirectorId = $_GET['DirectorId'];
    $stmt = $pdo->prepare("SELECT * FROM Director WHERE DirectorId = ?");
    $stmt->execute([$DirectorId]);
    $deleted_Director = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("DELETE FROM Director WHERE DirectorId=?");
    $stmt->execute([$DirectorId]);
    saveLastAction('delete', $deleted_Director);
    header("Location: director.php");
    exit;
}

// UNDO: Revert last action
if ($action === 'undo' && isset($_SESSION['last_action'])) {
    $last_action = $_SESSION['last_action'];
    
    switch ($last_action['type']) {
        case 'create':
            $stmt = $pdo->prepare("DELETE FROM Director WHERE DirectorId = ?");
            $stmt->execute([$last_action['data']['DirectorId']]);
            break;
            
        case 'update':
            $data = $last_action['data']['original'];
            $stmt = $pdo->prepare("UPDATE Directors SET BirthYear = ?, FirstName = ?, MiddleName = ?, LastName = ? WHERE DirectorId = ?");
            $stmt->execute([$data['DirectorId'], $data['BirthYear'], $data['FirstName'], $data['MiddleName'], $data['LastName']]);
            break;
            
        case 'delete':
            $data = $last_action['data'];
            $stmt = $pdo->prepare("INSERT INTO Director (DirectorId, BirthYear, FirstName, MiddleName, LastName) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$data['DirectorId'], $data['BirthYear'], $data['FirstName'], $data['MiddleName'], $data['LastName']]);
            break;
    }
    unset($_SESSION['last_action']);
    header("Location: director.php");
    exit;
}

// READ: Fetch all Directors from the database
$stmt = $pdo->prepare("SELECT * FROM Director");
$stmt->execute();
$Directors = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Director Database CRUD</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div style="background-color: #195db5; color: white; padding: 25px; text-align: center; font-size: 20px;" class="fancyText">
        Welcome to the Director Database!
    </div>
    <h2 class="center" id="teachy">Welcome to the Directors table site! Here you can add new Directors to the database.</h2>
    <div class="center">
        <button onclick="document.location='index.php'" class="hover">Movies page</button>
        <button onclick="document.location='actors.php'" class="hover">Actors page</button>
        <button onclick="document.location='genre.php'" class="hover"> Genres page </button>
        <button onclick="document.location='review.php'" class="hover"> Reviews page </button>
        <button onclick="document.location='moviecast.php'" class="hover"> Movie Cast page </button>
    </div>
    <br>
    
    <?php if (!empty($error_message)): ?>
        <p class="error"><?php echo $error_message; ?></p>
    <?php endif; ?>

    <?php if (isset($_SESSION['last_action'])): ?>
        <button class="undo-btn" onclick="if(confirm('Undo last action?')) window.location.href='?action=undo'">Undo Last Action</button>
    <?php else: ?>
        <button class="undo-btn" disabled>No Action to Undo</button>
    <?php endif; ?>

    <div class="form-container">
        <h2>Add a New Director</h2>
        <form method="POST" action="?action=create">
            <input type="number" name="BirthYear" placeholder="Birth Year" min="1900" max="<?php echo date('Y'); ?>" required>
            <input type="text" name="FirstName" placeholder="First Name" required>
            <input type="text" name="MiddleName" placeholder="Middle Name (optional)">
            <input type="text" name="LastName" placeholder="Last Name" required>
            <input type="submit" value="Add Director" class="hover">
        </form>
    </div>

    <?php if ($edit_Director): ?>
        <div class="form-container">
            <h2>Edit Director</h2>
            <form method="POST" action="?action=update">
                <input type="hidden" name="DirectorId" value="<?php echo htmlspecialchars($edit_Director['DirectorId']); ?>">
                <input type="number" name="BirthYear" value="<?php echo htmlspecialchars($edit_Director['BirthYear']); ?>" min="1900" max="<?php echo date('Y'); ?>" required>
                <input type="text" name="FirstName" value="<?php echo htmlspecialchars($edit_Director['FirstName']); ?>" required>
                <input type="text" name="MiddleName" value="<?php echo htmlspecialchars($edit_Director['MiddleName'] ?? ''); ?>" placeholder="Middle Name (optional)">
                <input type="text" name="LastName" value="<?php echo htmlspecialchars($edit_Director['LastName']); ?>" required>
                <input type="submit" value="Update Director" class="hover">
                <a href="Director.php">Cancel</a>
            </form>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <br>
        <h2>Upload Directors CSV File</h2>
        <form method="POST" action="?action=upload_csv" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <input type="submit" value="Upload" class="hover">
        </form>
    </div>

    <div class="table-container">
    <h2 style="text-align:center;">All Directors</h2>
    <?php if (empty($Directors)): ?>
        <p>No Directors in the database yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>DirectorId</th>
                    <th>Birth Year</th>
                    <th>First Name</th>
                    <th>Middle Name</th>
                    <th>Last Name</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Use session variable to determine display, defaulting to 5 directors
                $show_all = $_SESSION['show_all_directors'] ?? false;
                $directors_to_display = $show_all ? $Directors : array_slice($Directors, 0, 5);
                
                foreach ($directors_to_display as $Director): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($Director['DirectorId']); ?></td>
                        <td><?php echo htmlspecialchars($Director['BirthYear']); ?></td>
                        <td><?php echo htmlspecialchars($Director['FirstName']); ?></td>
                        <td><?php echo htmlspecialchars($Director['MiddleName'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($Director['LastName']); ?></td>
                        <td>
                            <a href="?action=edit&DirectorId=<?php echo $Director['DirectorId']; ?>">Edit</a>
                            <a href="?action=delete&DirectorId=<?php echo $Director['DirectorId']; ?>" onclick="return confirm('Are you sure you want to delete this Director?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($Directors) > 5): ?>
            <form method="POST" style="margin-top: 10px;">
                <input type="hidden" name="toggle_show_all" value="1">
                <button type="submit" class="hover">
                    <?php echo $show_all ? 'Show Less' : 'Show More'; ?>
                </button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
