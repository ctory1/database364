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
    $_SESSION['show_all_reviews'] = false;
}

// Handle button click to toggle show_all for reviews
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_show_all'])) {
    $_SESSION['show_all_reviews'] = !$_SESSION['show_all_reviews'];
}

// Database connection
$host = '138.49.184.47';
$dbname = 'toryfter1794_movie_db';
$username = '';
$password = ';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle CRUD actions
$action = isset($_GET['action']) ? $_GET['action'] : 'read';
$error_message = '';
$edit_review = null;

// Function to save last action
function saveLastAction($type, $data) {
    $_SESSION['last_action'] = [
        'type' => $type,
        'data' => $data,
        'timestamp' => time()
    ];
}

// CREATE: Add a new review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $Rating = (int)$_POST['Rating'];
    $ReviewerName = trim($_POST['ReviewerName']);
    $MovieId = (int)$_POST['MovieId'];

    // Validation
    if (empty($Rating) || empty($ReviewerName) || empty($MovieId)) {
        $error_message = "Error: Rating, Reviewer Name, and Movie ID are required!";
    } elseif ($Rating < 1 || $Rating > 10) {
        $error_message = "Error: Rating must be between 1 and 10!";
    } else {
        // Check if MovieId exists in the Movie table
        $checkMovieStmt = $pdo->prepare("SELECT COUNT(*) FROM Movie WHERE MovieId = ?");
        $checkMovieStmt->execute([$MovieId]);
        if ($checkMovieStmt->fetchColumn() == 0) {
            $error_message = "Error: Invalid Movie ID! The movie does not exist.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO Review (Rating, ReviewerName, MovieId) VALUES (?, ?, ?)");
            $stmt->execute([$Rating, $ReviewerName, $MovieId]);
            $lastId = $pdo->lastInsertId();
            saveLastAction('create', ['ReviewId' => $lastId]);
            header("Location: review.php");
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
                if (count($data) >= 4) {
                    $Rating = (int)trim($data[1]);
                    $ReviewerName = trim($data[2]);
                    $MovieId = (int)trim($data[3]);

                    if (empty($Rating) || empty($ReviewerName) || empty($MovieId)) {
                        $error_message .= "Row $rowNum: Missing required field(s) - " . implode(';', $data) . "<br>";
                        continue;
                    }
                    if ($Rating < 1 || $Rating > 10) {
                        $error_message .= "Row $rowNum: Invalid rating '$Rating' (must be 1-10) - " . implode(';', $data) . "<br>";
                        continue;
                    }

                    $checkMovieStmt = $pdo->prepare("SELECT COUNT(*) FROM Movie WHERE MovieId = ?");
                    $checkMovieStmt->execute([$MovieId]);
                    if ($checkMovieStmt->fetchColumn() == 0) {
                        $error_message .= "Row $rowNum: Invalid Movie ID '$MovieId' - " . implode(';', $data) . "<br>";
                        continue;
                    }

                    $stmt = $pdo->prepare("INSERT INTO Review (Rating, ReviewerName, MovieId) VALUES (?, ?, ?)");
                    $stmt->execute([$Rating, $ReviewerName, $MovieId]);
                    $added_ids[] = $pdo->lastInsertId();
                } else {
                    $error_message .= "Row $rowNum: Invalid format, expected 4 columns, got " . count($data) . " - " . implode(';', $data) . "<br>";
                }
            }
            fclose($handle);
            if (!empty($added_ids)) {
                saveLastAction('upload_csv', ['ids' => $added_ids]);
            }
        }
    }
    if (empty($error_message)) {
        header("Location: review.php");
        exit;
    }
}

// EDIT: Load review data for editing
if ($action === 'edit' && isset($_GET['ReviewId'])) {
    $ReviewId = $_GET['ReviewId'];
    $stmt = $pdo->prepare("SELECT * FROM Review WHERE ReviewId = ?");
    $stmt->execute([$ReviewId]);
    $edit_review = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_review) {
        $error_message = "Review not found!";
    }
}

// UPDATE: Handle edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    $ReviewId = $_POST['ReviewId'];
    $Rating = (int)$_POST['Rating'];
    $ReviewerName = trim($_POST['ReviewerName']);
    $MovieId = (int)$_POST['MovieId'];

    // Validation
    if (empty($Rating) || empty($ReviewerName) || empty($MovieId)) {
        $error_message = "Error: Rating, Reviewer Name, and Movie ID are required!";
    } elseif ($Rating < 1 || $Rating > 10) {
        $error_message = "Error: Rating must be between 1 and 10!";
    } else {
        $checkMovieStmt = $pdo->prepare("SELECT COUNT(*) FROM Movie WHERE MovieId = ?");
        $checkMovieStmt->execute([$MovieId]);
        if ($checkMovieStmt->fetchColumn() == 0) {
            $error_message = "Error: Invalid Movie ID! The movie does not exist.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM Review WHERE ReviewId = ?");
            $stmt->execute([$ReviewId]);
            $original_data = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE Review SET Rating = ?, ReviewerName = ?, MovieId = ? WHERE ReviewId = ?");
            $stmt->execute([$Rating, $ReviewerName, $MovieId, $ReviewId]);
            saveLastAction('update', ['ReviewId' => $ReviewId, 'original' => $original_data]);
            header("Location: review.php");
            exit;
        }
    }
}

// DELETE: Remove a review
if ($action === 'delete' && isset($_GET['ReviewId'])) {
    $ReviewId = $_GET['ReviewId'];
    $stmt = $pdo->prepare("SELECT * FROM Review WHERE ReviewId = ?");
    $stmt->execute([$ReviewId]);
    $deleted_review = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("DELETE FROM Review WHERE ReviewId = ?");
    $stmt->execute([$ReviewId]);
    saveLastAction('delete', $deleted_review);
    header("Location: review.php");
    exit;
}

// UNDO: Revert last action
if ($action === 'undo' && isset($_SESSION['last_action'])) {
    $last_action = $_SESSION['last_action'];

    switch ($last_action['type']) {
        case 'create':
            $stmt = $pdo->prepare("DELETE FROM Review WHERE ReviewId = ?");
            $stmt->execute([$last_action['data']['ReviewId']]);
            break;

        case 'upload_csv':
            $stmt = $pdo->prepare("DELETE FROM Review WHERE ReviewId IN (" . implode(',', array_fill(0, count($last_action['data']['ids']), '?')) . ")");
            $stmt->execute($last_action['data']['ids']);
            break;

        case 'update':
            $data = $last_action['data']['original'];
            $stmt = $pdo->prepare("UPDATE Review SET Rating = ?, ReviewerName = ?, MovieId = ? WHERE ReviewId = ?");
            $stmt->execute([$data['Rating'], $data['ReviewerName'], $data['MovieId'], $data['ReviewId']]);
            break;

        case 'delete':
            $data = $last_action['data'];
            $stmt = $pdo->prepare("INSERT INTO Review (ReviewId, Rating, ReviewerName, MovieId) VALUES (?, ?, ?, ?)");
            $stmt->execute([$data['ReviewId'], $data['Rating'], $data['ReviewerName'], $data['MovieId']]);
            break;
    }
    unset($_SESSION['last_action']);
    header("Location: review.php");
    exit;
}

// READ: Fetch all reviews from the database
$stmt = $pdo->prepare("SELECT * FROM Review");
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all movies for dropdown
$movieStmt = $pdo->prepare("SELECT MovieId, Title FROM Movie");
$movieStmt->execute();
$movies = $movieStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review Database CRUD</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div style="background-color: #195db5; color: white; padding: 25px; text-align: center; font-size: 20px;" class="fancyText">
        Welcome to the Review Database!
    </div>
    <h2 class="center" id="teachy">Welcome to the Reviews table site! Here you can add new reviews to the database.</h2>
    <div class="center">
        <button onclick="document.location='index.php'" class="hover">Movies page</button>
        <button onclick="document.location='actors.php'" class="hover">Actors page</button>
        <button onclick="document.location='director.php'" class="hover">Directors page</button>
        <button onclick="document.location='genre.php'" class="hover">Genres page</button>
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
        <h2>Add a New Review</h2>
        <form method="POST" action="?action=create">
            <input type="number" name="Rating" placeholder="Rating (1-10)" min="1" max="10" required>
            <input type="text" name="ReviewerName" placeholder="Reviewer Name" required>
            <select name="MovieId" required>
                <option value="">Select Movie</option>
                <?php foreach ($movies as $movie): ?>
                    <option value="<?php echo $movie['MovieId']; ?>">
                        <?php echo htmlspecialchars($movie['MovieId'] . ' - ' . $movie['Title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="submit" value="Add Review" class="hover">
        </form>
    </div>

    <?php if ($edit_review): ?>
        <div class="form-container">
            <h2>Edit Review</h2>
            <form method="POST" action="?action=update">
                <input type="hidden" name="ReviewId" value="<?php echo htmlspecialchars($edit_review['ReviewId']); ?>">
                <input type="number" name="Rating" value="<?php echo htmlspecialchars($edit_review['Rating']); ?>" min="1" max="10" required>
                <input type="text" name="ReviewerName" value="<?php echo htmlspecialchars($edit_review['ReviewerName']); ?>" required>
                <select name="MovieId" required>
                    <option value="">Select Movie</option>
                    <?php foreach ($movies as $movie): ?>
                        <option value="<?php echo $movie['MovieId']; ?>" <?php echo $movie['MovieId'] == $edit_review['MovieId'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($movie['MovieId'] . ' - ' . $movie['Title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" value="Update Review" class="hover">
                <a href="review.php">Cancel</a>
            </form>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <br>
        <h2>Upload Reviews CSV File</h2>
        <form method="POST" action="?action=upload_csv" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <input type="submit" value="Upload" class="hover">
        </form>
    </div>

    <div class="table-container">
        <h2 style="text-align:center;">All Reviews</h2>
        <?php if (empty($reviews)): ?>
            <p>No reviews in the database yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ReviewId</th>
                        <th>Rating</th>
                        <th>Reviewer Name</th>
                        <th>MovieId</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Use session variable to determine display, defaulting to 5 reviews
                    $show_all = $_SESSION['show_all_reviews'] ?? false;
                    $reviews_to_display = $show_all ? $reviews : array_slice($reviews, 0, 5);

                    foreach ($reviews_to_display as $review): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($review['ReviewId']); ?></td>
                            <td><?php echo htmlspecialchars($review['Rating']); ?></td>
                            <td><?php echo htmlspecialchars($review['ReviewerName']); ?></td>
                            <td><?php echo htmlspecialchars($review['MovieId']); ?></td>
                            <td>
                                <a href="?action=edit&ReviewId=<?php echo $review['ReviewId']; ?>">Edit</a>
                                <a href="?action=delete&ReviewId=<?php echo $review['ReviewId']; ?>" onclick="return confirm('Are you sure you want to delete this review?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($reviews) > 5): ?>
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
