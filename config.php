<?php
// Shared configuration file
$apiKey = "YOUR_GEMINI_API_KEY";
$dbFile = __DIR__ . '/mcdm.db';

// Initialize Database connection
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create history table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcdm_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dataset_name TEXT NOT NULL,
        execution_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        best_combination TEXT,
        spearman_scores TEXT,
        raw_results TEXT,
        ai_consultant_summary TEXT
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
