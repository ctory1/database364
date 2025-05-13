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
    $_SESSION['show_all_genres'] = false;
}

// Handle button click to toggle show_all for genres
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_show_all'])) {
    $_SESSION['show_all_genres'] = !$_SESSION['show_all_genres'];
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
$edit_genre = null;

// Function to save last action
function saveLastAction($type, $data) {
    $_SESSION['last_action'] = [
        'type' => $type,
        'data' => $data,
        'timestamp' => time()
    ];
}

// CREATE: Add a new genre
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create' && isset($_POST['GenreName'])) {
    $GenreName = trim($_POST['GenreName']);

    // Validation
    if (empty($GenreName)) {
        $error_message = "Error: Genre Name is required!";
    } elseif (is_numeric($GenreName) && (int)$GenreName == $GenreName) {
        $error_message = "Error: Genre Name cannot be an integer!";
    } else {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Genre WHERE GenreName = ?");
        $checkStmt->execute([$GenreName]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            $error_message = "Error: A genre with this name already exists!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO Genre (GenreName) VALUES (?)");
            $stmt->execute([$GenreName]);
            $lastId = $pdo->lastInsertId();
            saveLastAction('create', ['GenreId' => $lastId]);
            header("Location: genre.php");
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
                if (count($data) >= 2) {
                    $GenreName = trim($data[1]);

                    if (empty($GenreName)) {
                        $error_message .= "Row $rowNum: Missing Genre Name - " . implode(';', $data) . "<br>";
                        continue;
                    }
                    if (is_numeric($GenreName) && (int)$GenreName == $GenreName) {
                        $error_message .= "Row $rowNum: Genre Name cannot be an integer ('$GenreName') - " . implode(';', $data) . "<br>";
                        continue;
                    }

                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Genre WHERE GenreName = ?");
                    $checkStmt->execute([$GenreName]);
                    $exists = $checkStmt->fetchColumn();

                    if ($exists == 0) {
                        $stmt = $pdo->prepare("INSERT INTO Genre (GenreName) VALUES (?)");
                        $stmt->execute([$GenreName]);
                        $added_ids[] = $pdo->lastInsertId();
                    }
                } else {
                    $error_message .= "Row $rowNum: Invalid format, expected 2 columns, got " . count($data) . " - " . implode(';', $data) . "<br>";
                }
            }
            fclose($handle);
            if (!empty($added_ids)) {
                saveLastAction('upload_csv', ['ids' => $added_ids]);
            }
        }
    }
    if (empty($error_message)) {
        header("Location: genre.php");
        exit;
    }
}

// EDIT: Load genre data for editing
if ($action === 'edit' && isset($_GET['GenreId'])) {
    $GenreId = $_GET['GenreId'];
    $stmt = $pdo->prepare("SELECT * FROM Genre WHERE GenreId = ?");
    $stmt->execute([$GenreId]);
    $edit_genre = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_genre) {
        $error_message = "Genre not found!";
    }
}

// UPDATE: Handle edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    $GenreId = $_POST['GenreId'];
    $GenreName = trim($_POST['GenreName']);

    // Validation
    if (empty($GenreName)) {
        $error_message = "Error: Genre Name is required!";
    } elseif (is_numeric($GenreName) && (int)$GenreName == $GenreName) {
        $error_message = "Error: Genre Name cannot be an integer!";
    } else {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Genre WHERE GenreName = ? AND GenreId != ?");
        $checkStmt->execute([$GenreName, $GenreId]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            $error_message = "Error: Another genre with this name already exists!";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM Genre WHERE GenreId = ?");
            $stmt->execute([$GenreId]);
            $original_data = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE Genre SET GenreName = ? WHERE GenreId = ?");
            $stmt->execute([$GenreName, $GenreId]);
            saveLastAction('update', ['GenreId' => $GenreId, 'original' => $original_data]);
            header("Location: genre.php");
            exit;
        }
    }
}

// DELETE: Remove a genre
if ($action === 'delete' && isset($_GET['GenreId'])) {
    $GenreId = $_GET['GenreId'];
    $stmt = $pdo->prepare("SELECT * FROM Genre WHERE GenreId = ?");
    $stmt->execute([$GenreId]);
    $deleted_genre = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("DELETE FROM Genre WHERE GenreId = ?");
    $stmt->execute([$GenreId]);
    saveLastAction('delete', $deleted_genre);
    header("Location: genre.php");
    exit;
}

// UNDO: Revert last action
if ($action === 'undo' && isset($_SESSION['last_action'])) {
    $last_action = $_SESSION['last_action'];

    switch ($last_action['type']) {
        case 'create':
            $stmt = $pdo->prepare("DELETE FROM Genre WHERE GenreId = ?");
            $stmt->execute([$last_action['data']['GenreId']]);
            break;

        case 'upload_csv':
            $stmt = $pdo->prepare("DELETE FROM Genre WHERE GenreId IN (" . implode(',', array_fill(0, count($last_action['data']['ids']), '?')) . ")");
            $stmt->execute($last_action['data']['ids']);
            break;

        case 'update':
            $data = $last_action['data']['original'];
            $stmt = $pdo->prepare("UPDATE Genre SET GenreName = ? WHERE GenreId = ?");
            $stmt->execute([$data['GenreName'], $data['GenreId']]);
            break;

        case 'delete':
            $data = $last_action['data'];
            $stmt = $pdo->prepare("INSERT INTO Genre (GenreId, GenreName) VALUES (?, ?)");
            $stmt->execute([$data['GenreId'], $data['GenreName']]);
            break;
    }
    unset($_SESSION['last_action']);
    header("Location: genre.php");
    exit;
}

// READ: Fetch all genres from the database
$stmt = $pdo->prepare("SELECT * FROM Genre");
$stmt->execute();
$genres = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Genre Database CRUD</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div style="background-color: #195db5; color: white; padding: 25px; text-align: center; font-size: 20px;" class="fancyText">
        Welcome to the Genre Database!
    </div>
    <h2 class="center" id="teachy">Welcome to the Genres table site! Here you can add new genres to the database.</h2>
    <div class="center">
        <button onclick="document.location='index.php'" class="hover">Movies page</button>
        <button onclick="document.location='actors.php'" class="hover">Actors page</button>
        <button onclick="document.location='director.php'" class="hover">Directors page</button>
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
        <h2>Add a New Genre</h2>
        <form method="POST" action="?action=create">
            <input type="text" name="GenreName" placeholder="Genre Name" required>
            <input type="submit" value="Add Genre" class="hover">
        </form>
    </div>

    <?php if ($edit_genre): ?>
        <div class="form-container">
            <h2>Edit Genre</h2>
            <form method="POST" action="?action=update">
                <input type="hidden" name="GenreId" value="<?php echo htmlspecialchars($edit_genre['GenreId']); ?>">
                <input type="text" name="GenreName" value="<?php echo htmlspecialchars($edit_genre['GenreName']); ?>" required>
                <input type="submit" value="Update Genre" class="hover">
                <a href="genre.php">Cancel</a>
            </form>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <br>
        <h2>Upload Genres CSV File</h2>
        <form method="POST" action="?action=upload_csv" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <input type="submit" value="Upload" class="hover">
        </form>
    </div>

    <div class="table-container">
        <h2 style="text-align:center;">All Genres</h2>
        <?php if (empty($genres)): ?>
            <p>No genres in the database yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>GenreId</th>
                        <th>Genre Name</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Use session variable to determine display, defaulting to 5 genres
                    $show_all = $_SESSION['show_all_genres'] ?? false;
                    $genres_to_display = $show_all ? $genres : array_slice($genres, 0, 5);

                    foreach ($genres_to_display as $genre): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($genre['GenreId']); ?></td>
                            <td><?php echo htmlspecialchars($genre['GenreName']); ?></td>
                            <td>
                                <a href="?action=edit&GenreId=<?php echo $genre['GenreId']; ?>">Edit</a>
                                <a href="?action=delete&GenreId=<?php echo $genre['GenreId']; ?>" onclick="return confirm('Are you sure you want to delete this genre?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($genres) > 5): ?>
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
