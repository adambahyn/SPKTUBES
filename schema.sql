CREATE TABLE IF NOT EXISTS mcdm_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dataset_name TEXT NOT NULL,
    execution_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    best_combination TEXT,
    spearman_scores TEXT,
    raw_results TEXT,
    ai_consultant_summary TEXT
);
