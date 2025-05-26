<?php
// v3/init_db.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dbFile = __DIR__ . '/data.db';
$isNewDb = !file_exists($dbFile);

try {
    // Connect to SQLite database. If it doesn't exist, it will be created.
    $pdo = new PDO('sqlite:' . $dbFile);
    // Set error mode to exceptions for easier error handling.
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Successfully connected to SQLite database: " . realpath($dbFile) . "<br>";

    // SQL to create upload_attempts table
    $sqlUploadAttempts = "
    CREATE TABLE IF NOT EXISTS upload_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_upload_id TEXT UNIQUE,
        arvan_tus_url TEXT,
        arvan_file_id TEXT,
        original_filename TEXT,
        persistent_temp_filepath TEXT,
        total_filesize INTEGER,
        current_offset_on_arvan INTEGER DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending_creation', 
        target_channel_id TEXT,
        video_title TEXT,
        video_description TEXT,
        last_error_message TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );";

    // SQL to create arvan_videos table
    $sqlArvanVideos = "
    CREATE TABLE IF NOT EXISTS arvan_videos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        arvan_video_id TEXT UNIQUE NOT NULL,
        arvan_channel_id TEXT,
        title TEXT,
        description TEXT,
        arvan_player_url TEXT,
        arvan_hls_playlist_url TEXT,
        arvan_dash_playlist_url TEXT,
        arvan_thumbnail_url TEXT,
        arvan_video_url_origin TEXT,
        arvan_config_url TEXT,
        mp4_links_json TEXT,
        original_filename_uploaded TEXT,
        filesize_bytes INTEGER,
        duration_seconds INTEGER,
        arvan_status TEXT,
        arvan_created_at TEXT,    -- Timestamp from Arvan API (video object creation)
        arvan_completed_at TEXT,  -- Timestamp from Arvan API (processing completed)
        db_created_at TEXT NOT NULL, -- Timestamp of DB record creation
        db_updated_at TEXT NOT NULL  -- Timestamp of DB record update
    );";

    // Execute the SQL statements
    $pdo->exec($sqlUploadAttempts);
    echo "Table 'upload_attempts' checked/created successfully.<br>";

    $pdo->exec($sqlArvanVideos);
    echo "Table 'arvan_videos' checked/created successfully.<br>";

    // Optional: Create indexes for frequently queried columns
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_upload_attempts_status ON upload_attempts (status);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_upload_attempts_client_id ON upload_attempts (client_upload_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_arvan_videos_channel_id ON arvan_videos (arvan_channel_id);");
    echo "Indexes checked/created.<br>";


    if ($isNewDb) {
        echo "Database file data.db was newly created.<br>";
    } else {
        echo "Database file data.db already existed. Tables checked/created.<br>";
    }
    echo "Database initialization complete! You can now remove or restrict access to this init_db.php script.<br>";

} catch (PDOException $e) {
    // Handle connection or query errors
    echo "Database Error: " . $e->getMessage() . "<br>";
    die();
}

?>