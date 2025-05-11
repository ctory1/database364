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
    $_SESSION['show_all'] = false;
}

// Handle button click to toggle show_all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_show_all'])) {
    $_SESSION['show_all'] = !$_SESSION['show_all'];
}

// Database connection
$host = '138.49.184.47';
$dbname = 'toryfter1794_movie_db';
$username = '';
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
$edit_movie = null;

// Function to save last action
function saveLastAction($type, $data) {
    $_SESSION['last_action'] = [
        'type' => $type,
        'data' => $data,
        'timestamp' => time()
    ];
}

// CREATE: Add a new movie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $Title = trim($_POST['Title']);
    $Release_Year = (int)$_POST['Release_Year'];
    $GenreId = (int)$_POST['GenreId'];
    $Duration = (int)$_POST['Duration'];
    $DirectorId = (int)$_POST['DirectorId'];

    // Validation
    if (empty($Title) || empty($Release_Year) || empty($GenreId) || empty($Duration) || empty($DirectorId)) {
        $error_message = "Error: All fields are required!";
    } elseif ($Release_Year < 1900 || $Release_Year > date('Y')) {
        $error_message = "Error: Release year must be between 1900 and " . date('Y') . "!";
    } elseif ($Duration <= 0) {
        $error_message = "Error: Duration must be greater than 0!";
    } else {
        // Check if GenreId exists
        $genreCheck = $pdo->prepare("SELECT COUNT(*) FROM Genre WHERE GenreId = ?");
        $genreCheck->execute([$GenreId]);
        if ($genreCheck->fetchColumn() == 0) {
            $error_message = "Error: Invalid Genre selected!";
        } else {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Movie WHERE Title = ? AND DirectorId = ? AND Release_Year = ?");
            $checkStmt->execute([$Title, $DirectorId, $Release_Year]);
            $exists = $checkStmt->fetchColumn();

            if ($exists > 0) {
                $error_message = "Error: A movie with this Title, Director, and release year already exists!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO Movie (Title, Release_Year, GenreId, Duration, DirectorId) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$Title, $Release_Year, $GenreId, $Duration, $DirectorId]);
                $lastId = $pdo->lastInsertId();
                saveLastAction('create', ['MovieId' => $lastId]);
                header("Location: index.php");
                exit;
            }
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
                if (count($data) >= 6) {
                    $Title = trim($data[1]);
                    $DirectorId = (int)trim($data[2]);
                    $Release_Year = (int)trim($data[3]);
                    $GenreId = (int)trim($data[4]);
                    $Duration = (int)trim($data[5]);

                    if (empty($Title) || empty($Release_Year) || empty($GenreId) || empty($Duration) || empty($DirectorId)) {
                        $error_message .= "Row $rowNum: Missing required field(s) - " . implode(';', $data) . "<br>";
                        continue;
                    }
                    if ($Release_Year < 1900 || $Release_Year > date('Y')) {
                        $error_message .= "Row $rowNum: Invalid release year '$Release_Year' (must be 1900-" . date('Y') . ") for '$Title' - " . implode(';', $data) . "<br>";
                        continue;
                    }
                    if ($Duration <= 0) {
                        $error_message .= "Row $rowNum: Invalid Duration '$Duration' (must be > 0) for '$Title' - " . implode(';', $data) . "<br>";
                        continue;
                    }

                    $genreCheck = $pdo->prepare("SELECT COUNT(*) FROM Genre WHERE GenreId = ?");
                    $genreCheck->execute([$GenreId]);
                    if ($genreCheck->fetchColumn() == 0) {
                        $error_message .= "Row $rowNum: Invalid GenreId '$GenreId' for '$Title' - " . implode(';', $data) . "<br>";
                        continue;
                    }

                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Movie WHERE Title = ? AND DirectorId = ? AND Release_Year = ?");
                    $checkStmt->execute([$Title, $DirectorId, $Release_Year]);
                    $exists = $checkStmt->fetchColumn();

                    if ($exists == 0) {
                        $stmt = $pdo->prepare("INSERT INTO Movie (Title, Release_Year, GenreId, Duration, DirectorId) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$Title, $Release_Year, $GenreId, $Duration, $DirectorId]);
                        $added_ids[] = $pdo->lastInsertId();
                    }
                } else {
                    $error_message .= "Row $rowNum: Invalid format, expected 6 columns, got " . count($data) . " - " . implode(';', $data) . "<br>";
                }
            }
            fclose($handle);
            if (!empty($added_ids)) {
                saveLastAction('upload_csv', ['ids' => $added_ids]);
            }
        }
    }
    if (empty($error_message)) {
        header("Location: index.php");
        exit;
    }
}

// EDIT: Load movie data for editing
if ($action === 'edit' && isset($_GET['id'])) {
    $MovieId = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM Movie WHERE MovieId = ?");
    $stmt->execute([$MovieId]);
    $edit_movie = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_movie) {
        $error_message = "Movie not found!";
    }
}

// UPDATE: Handle edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    $MovieId = $_POST['MovieId'];
    $Title = trim($_POST['Title']);
    $Release_Year = (int)$_POST['Release_Year'];
    $GenreId = (int)$_POST['GenreId'];
    $Duration = (int)$_POST['Duration'];
    $DirectorId = (int)$_POST['DirectorId'];

    // Validation
    if (empty($Title) || empty($Release_Year) || empty($GenreId) || empty($Duration) || empty($DirectorId)) {
        $error_message = "Error: All fields are required!";
    } elseif ($Release_Year < 1900 || $Release_Year > date('Y')) {
        $error_message = "Error: Release year must be between 1900 and " . date('Y') . "!";
    } elseif ($Duration <= 0) {
        $error_message = "Error: Duration must be greater than 0!";
    } else {
        $genreCheck = $pdo->prepare("SELECT COUNT(*) FROM Genre WHERE GenreId = ?");
        $genreCheck->execute([$GenreId]);
        if ($genreCheck->fetchColumn() == 0) {
            $error_message = "Error: Invalid Genre selected!";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM Movie WHERE MovieId = ?");
            $stmt->execute([$MovieId]);
            $original_data = $stmt->fetch(PDO::FETCH_ASSOC);

            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Movie WHERE Title = ? AND DirectorId = ? AND Release_Year = ? AND MovieId != ?");
            $checkStmt->execute([$Title, $DirectorId, $Release_Year, $MovieId]);
            $exists = $checkStmt->fetchColumn();

            if ($exists > 0) {
                $error_message = "Error: Another movie with this Title, Director, and release year already exists!";
            } else {
                $stmt = $pdo->prepare("UPDATE Movie SET Title = ?, Release_Year = ?, GenreId = ?, Duration = ?, DirectorId = ? WHERE MovieId = ?");
                $stmt->execute([$Title, $Release_Year, $GenreId, $Duration, $DirectorId, $MovieId]);
                saveLastAction('update', ['MovieId' => $MovieId, 'original' => $original_data]);
                header("Location: index.php");
                exit;
            }
        }
    }
}

// DELETE: Remove a movie
if ($action === 'delete' && isset($_GET['id'])) {
    $MovieId = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM Movie WHERE MovieId = ?");
    $stmt->execute([$MovieId]);
    $deleted_movie = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("DELETE FROM Movie WHERE MovieId = ?");
    $stmt->execute([$MovieId]);
    saveLastAction('delete', $deleted_movie);
    header("Location: index.php");
    exit;
}

// UNDO: Revert last action
if ($action === 'undo' && isset($_SESSION['last_action'])) {
    $last_action = $_SESSION['last_action'];
    
    switch ($last_action['type']) {
        case 'create':
            $stmt = $pdo->prepare("DELETE FROM Movie WHERE MovieId = ?");
            $stmt->execute([$last_action['data']['MovieId']]);
            break;
            
        case 'upload_csv':
            $stmt = $pdo->prepare("DELETE FROM Movie WHERE MovieId IN (" . implode(',', array_fill(0, count($last_action['data']['ids']), '?')) . ")");
            $stmt->execute($last_action['data']['ids']); // Fixed: Pass the array directly
            break;
            
        case 'update':
            $data = $last_action['data']['original'];
            $stmt = $pdo->prepare("UPDATE Movie SET Title = ?, Release_Year = ?, GenreId = ?, Duration = ?, DirectorId = ? WHERE MovieId = ?");
            $stmt->execute([$data['Title'], $data['Release_Year'], $data['GenreId'], $data['Duration'], $data['DirectorId'], $data['MovieId']]);
            break;
            
        case 'delete':
            $data = $last_action['data'];
            $stmt = $pdo->prepare("INSERT INTO Movie (MovieId, Title, Release_Year, GenreId, Duration, DirectorId) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['MovieId'], $data['Title'], $data['Release_Year'], $data['GenreId'], $data['Duration'], $data['DirectorId']]);
            break;
    }
    unset($_SESSION['last_action']);
    header("Location: index.php");
    exit;
}

// READ: Fetch all movies (no need for GenreName in table)
$stmt = $pdo->prepare("SELECT * FROM Movie");
$stmt->execute();
$Movies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all directors for dropdown
$directorStmt = $pdo->prepare("SELECT DirectorId, FirstName, LastName FROM Director");
$directorStmt->execute();
$directors = $directorStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all genres for dropdown
$genreStmt = $pdo->prepare("SELECT GenreId, GenreName FROM Genre");
$genreStmt->execute();
$genres = $genreStmt->fetchAll(PDO::FETCH_ASSOC);

//latest titles: newest first
$latest_titles = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['show_latest_titles'])) {
    $stmt = $pdo->prepare("
    SELECT Movie.Title, Movie.Release_Year 
    FROM Movie
    ORDER BY Movie.Release_Year DESC");
    $stmt->execute();
    $latest_titles = $stmt->fetchAll(PDO::FETCH_ASSOC);;
}

//list all genres with movies
$all_genres_with_movies = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['show_genres_with_movies'])) {
    $stmt = $pdo->prepare("SELECT Movie.Title, Genre.GenreName AS Genre
    FROM Movie LEFT JOIN Genre
    ON Movie.GenreId = Genre.GenreId");
    $stmt->execute();
    $all_genres_with_movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// above average ratings: alphabetical order
$above_average_ratings = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['show_good_ratings'])) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT Movie.Title
        FROM Movie JOIN Review
        ON Review.MovieId = Movie.MovieId
        WHERE Review.Rating > (SELECT avg(Rating) FROM Review)
        ORDER BY Movie.Title ASC");
    $stmt->execute();
    $above_average_ratings = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// all genres with over 100 movies
$popular_genres = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['show_popular_genres'])) {
    $stmt = $pdo->prepare("
        SELECT Genre.GenreName, count(Movie.MovieId) AS NumMovies
        FROM Genre JOIN Movie
        ON Genre.GenreId = Movie.GenreId
        GROUP BY Genre.GenreName
        HAVING count(Movie.MovieId) > 100");
    $stmt->execute();
    $popular_genres = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// count number of movies for each genre
$count_genres_with_movies = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['count_genres_with_movies'])) {
    $stmt = $pdo->prepare("SELECT Genre.GenreName AS GenreName, count(Movie.MovieId) AS MovieCount
        FROM Genre LEFT JOIN Movie
        ON Genre.GenreId = Movie.GenreId
        GROUP BY Genre.GenreName");
    $stmt->execute();
    $count_genres_with_movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// movies with genreid 2 or 5
$selected_genre_movies = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['show_selected_genre_movies'])) {
    $stmt = $pdo->prepare("SELECT Title, GenreId FROM Movie WHERE GenreId IN (2,5)");
    $stmt->execute();
    $selected_genre_movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// top 5 rated movies
$best_rated_movies = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['show_best_rated_movies'])) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT Movie.Title, Review.Rating
            FROM Movie JOIN Review
            ON Review.MovieId = Movie.MovieId
            ORDER BY Review.Rating DESC
            LIMIT 5");
    $stmt->execute();
    $best_rated_movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// list all movies with actors and genres, skip first 10 and return next 10
$movies_actors_genres_11_to_20 = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['show_movies_actors_genres_11_to_20'])) {
    $stmt = $pdo->prepare("
        SELECT Movie.Title, Actor.FirstName, Actor.LastName, Genre.GenreName
        FROM Movie JOIN MovieCast
        ON MovieCast.MovieId = Movie.MovieId
        JOIN Actor
        ON MovieCast.ActorId = Actor.ActorId
        LEFT JOIN Genre
        ON Movie.GenreId = Genre.GenreId
        LIMIT 10 OFFSET 10");
    $stmt->execute();
    $movies_actors_genres_11_to_20 = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// list all movies and reviews in one result set
$movies_with_rating_8_9_10 = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&  isset($_POST['show_movies_with_ratings_of_8_9_10'])) {
    $stmt = $pdo->prepare("
        SELECT Title, ReviewerName, Rating
	    FROM Movie 
	    JOIN Review ON Movie.MovieId = Review.MovieId
	    WHERE Review.Rating IN (8,9,10)
    	ORDER BY Rating ASC");
    $stmt->execute();
    $movies_with_rating_8_9_10 = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Movie Database CRUD</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div style="background-color: #195db5; color: white; padding: 25px; text-align: center; font-size: 20px;" class="fancyText">
        Welcome to the Movie Database!
    </div>
    <h2 class="center" id="teachy"> Welcome to the Movies table site! Here you can add new movies into the database. </h2>
    <div class="center">
        <button onclick="document.location='actors.php'" class="hover"> Actors page </button>
        <button onclick="document.location='director.php'" class="hover"> Directors page </button>
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
        <form method="POST">
            <button type="submit" name="show_latest_titles" class="hover">Show Latest Titles</button>
        </form>

        <?php if (!empty($latest_titles)): ?>
    <h3>Latest Movie Titles:</h3>
    <ul>
        <?php foreach ($latest_titles as $title): ?>
            <li><?php echo htmlspecialchars($title['Title'] . ' (' . $title['Release_Year'] . ')'); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
    </div>

    <div class="form-container">
        <form method="POST">
            <button type="submit" name="show_popular_genres" class="hover">Show Genres with 100 Movies</button>
        </form>

        <?php if (!empty($popular_genres)): ?>
            <h3>Genres with 100 Movies:</h3>
            <u1>
                <?php foreach ($popular_genres as $title): ?>
                    <li><?php echo htmlspecialchars($title['GenreName'] . ': ' . $title['NumMovies']); ?></li>
                <?php endforeach; ?>
            </u1>
        <?php endif; ?>
    </div>

    <div class="form-container">
        <form method="POST">
            <button type="submit" name="show_good_ratings" class="hover">Show Movies with Higher-Than-Average Ratings</button>
        </form>

        <?php if (!empty($above_average_ratings)): ?>
            <h3>Movies With Above Average Ratings:</h3>
            <u1>
                <?php foreach ($above_average_ratings as $title): ?>
                    <li><?php echo htmlspecialchars($title); ?></li>
                <?php endforeach; ?>
            </u1>
        <?php endif; ?>
    </div>

    <div class="form-container">
        <form method="POST">
            <button type="submit" name="count_genres_with_movies" class="hover">Count Number of Movies for Each Genre</button>
        </form>

        <?php if (!empty($count_genres_with_movies)): ?>
            <h3>Amount of Movies Per Genre:</h3>
            <u1>
                <?php foreach ($count_genres_with_movies as $result): ?>
                    <li><?php echo htmlspecialchars($result['GenreName'] . ', ' . $result['MovieCount']); ?></li>
                <?php endforeach; ?>
            </u1>
        <?php endif; ?>
    </div>

    <div class="form-container">
        <form method="POST">
            <button type="submit" name="show_movies_actors_genres_11_to_20" class="hover">Show All Movies with their Actors and Genres, Skip 10 and List Next 10</button>
        </form>

        <?php if (!empty($movies_actors_genres_11_to_20)): ?>
            <h3>Movies with Actors and Genres 11 To 20:</h3>
            <u1>
                <?php foreach ($movies_actors_genres_11_to_20 as $result): ?>
                    <li><?php echo htmlspecialchars($result['Title'] . ', ' . $result['FirstName'] . ' ' . $result['LastName'] . ', ' . $result['GenreName']); ?></li>
                <?php endforeach; ?>
            </u1>
        <?php endif; ?>
    </div>

    <div class="form-container">
        <form method="POST">
            <button type="submit" name="show_selected_genre_movies" class="hover">Show Movies with GenreId 2 or 5</button>
        </form>

        <?php if (!empty($selected_genre_movies)): ?>
    <h3>Movies with GenreId 2 or 5:</h3>
    <ul>
        <?php foreach ($selected_genre_movies as $movie): ?>
            <li><?php echo htmlspecialchars($movie['Title'] . ' (GenreId: ' . $movie['GenreId'] . ')'); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
    </div>

    <div class="form-container">
        <form method="POST">
            <button type="submit" name="show_best_rated_movies" class="hover">Show Top 5 Rated Movies</button>
        </form>

        <?php if (!empty($best_rated_movies)): ?>
            <h3>Top 5 Rated Movies:</h3>
            <u1>
                <?php foreach ($best_rated_movies as $title): ?>
                    <li><?php echo htmlspecialchars($title['Title'] . ': ' . $title['Rating'] . '/10'); ?></li>
                <?php endforeach; ?>
            </u1>
        <?php endif; ?>
    </div>

    <div class="form-container">
        <form method="POST">
            <button type="submit" name="show_genres_with_movies" class="hover">Show All Genres With Movies</button>
        </form>

        <?php if (!empty($all_genres_with_movies)): ?>
            <h3>All Genres With Movies:</h3>
            <u1>
                <?php foreach ($all_genres_with_movies as $title): ?>
                    <li><?php echo htmlspecialchars($title['Title'] . ', ' . $title['Genre']); ?></li>
                <?php endforeach; ?>
            </u1>
        <?php endif; ?>
    </div>
    
    <div class="form-container">
        <form method="POST">
            <button type="submit" name="show_movies_with_ratings_of_8_9_10" class="hover">Show Movies, Reviewers, Ratings with Ratings 8, 9, or 10</button>
        </form>

        <?php if (!empty($movies_with_rating_8_9_10)): ?>
            <h3>Movies, Reviewers, Ratings with Rating 8, 9, or 10:</h3>
            <u1>
                <?php foreach ($movies_with_rating_8_9_10 as $result): ?>
                    <li><?php echo htmlspecialchars($result['Title'] . ', ' . $result['ReviewerName'] . ', ' . $result['Rating']); ?></li>
                <?php endforeach; ?>
            </u1>
        <?php endif; ?>
    </div>

    <div class="form-container">
        <h2>Add a New Movie</h2>
        <form method="POST" action="?action=create">
            <input type="text" name="Title" placeholder="Title" required>
            <input type="number" name="Release_Year" placeholder="Release Year" min="1900" max="<?php echo date('Y'); ?>" required>
            <input type="number" name="Duration" placeholder="Duration (minutes)" min="1" required>
            <select name="DirectorId" required>
                <option value="">Select Director</option>
                <?php foreach ($directors as $director): ?>
                    <option value="<?php echo $director['DirectorId']; ?>">
                        <?php echo htmlspecialchars($director['FirstName'] . ' ' . $director['LastName']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="GenreId" required>
                <option value="">Select Genre</option>
                <?php foreach ($genres as $genre): ?>
                    <option value="<?php echo $genre['GenreId']; ?>">
                        <?php echo htmlspecialchars($genre['GenreName']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="submit" value="Add Movie" class="hover">
        </form>
    </div>

    <?php if ($edit_movie): ?>
        <div class="form-container">
            <h2>Edit Movie</h2>
            <form method="POST" action="?action=update">
                <input type="hidden" name="MovieId" value="<?php echo htmlspecialchars($edit_movie['MovieId']); ?>">
                <input type="text" name="Title" value="<?php echo htmlspecialchars($edit_movie['Title']); ?>" required>
                <input type="number" name="Release_Year" value="<?php echo htmlspecialchars($edit_movie['Release_Year']); ?>" min="1900" max="<?php echo date('Y'); ?>" required>
                <input type="number" name="Duration" value="<?php echo htmlspecialchars($edit_movie['Duration']); ?>" min="1" required>
                <select name="DirectorId" required>
                    <option value="">Select Director</option>
                    <?php foreach ($directors as $director): ?>
                        <option value="<?php echo $director['DirectorId']; ?>" <?php echo $director['DirectorId'] == $edit_movie['DirectorId'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($director['FirstName'] . ' ' . $director['LastName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="GenreId" required>
                    <option value="">Select Genre</option>
                    <?php foreach ($genres as $genre): ?>
                        <option value="<?php echo $genre['GenreId']; ?>" <?php echo $genre['GenreId'] == $edit_movie['GenreId'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($genre['GenreName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" value="Update Movie" class="hover">
                <a href="index.php">Cancel</a>
            </form>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <br>
        <h2>Upload Movies CSV File</h2>
        <form method="POST" action="?action=upload_csv" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <input type="submit" value="Upload" class="hover">
        </form>
    </div>

    <div class="table-container">
        <h2 style="text-align:center;">All Movies</h2>
        <?php if (empty($Movies)): ?>
            <p>No movies in the database yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>MovieId</th>
                        <th>Title</th>
                        <th>Release Year</th>
                        <th>Duration (minutes)</th>
                        <th>DirectorId</th>
                        <th>GenreId</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Use session variable to determine display, defaulting to 5 movies
                    $show_all = $_SESSION['show_all'] ?? false;
                    $movies_to_display = $show_all ? $Movies : array_slice($Movies, 0, 5);
                    
                    foreach ($movies_to_display as $movie): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($movie['MovieId']); ?></td>
                            <td><?php echo htmlspecialchars($movie['Title']); ?></td>
                            <td><?php echo htmlspecialchars($movie['Release_Year']); ?></td>
                            <td><?php echo htmlspecialchars($movie['Duration']); ?></td>
                            <td><?php echo htmlspecialchars($movie['DirectorId']); ?></td>
                            <td><?php echo htmlspecialchars($movie['GenreId']); ?></td>
                            <td>
                                <a href="?action=edit&id=<?php echo $movie['MovieId']; ?>">Edit</a>
                                <a href="?action=delete&id=<?php echo $movie['MovieId']; ?>" onclick="return confirm('Are you sure you want to delete this movie?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($Movies) > 5): ?>
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
