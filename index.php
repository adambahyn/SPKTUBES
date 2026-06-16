<?php
require_once 'config.php';

// Check if all 4 CSV files are in the folder
$requiredFiles = [
    "LOPCOW - MABAC.csv",
    "LOPCOW - OCRA.csv",
    "MEREC - MABAC.csv",
    "MEREC - OCRA.csv"
];

$missingFiles = [];
foreach ($requiredFiles as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        $missingFiles[] = $file;
    }
}

$results = null;
$error = null;
$aiSummary = "Please run the analysis to generate AI insights.";

if (empty($missingFiles)) {
    // Execute Python script to process files
    $descriptorspec = array(
        0 => array("pipe", "r"),
        1 => array("pipe", "w"),
        2 => array("pipe", "w")
    );
    
    $process = proc_open('python process_files.py', $descriptorspec, $pipes);
    $pythonOutput = "";
    $stderr = "";
    if (is_resource($process)) {
        fclose($pipes[0]);
        $pythonOutput = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
    }
    
    if (empty($pythonOutput)) {
        $error = "Error executing Python script. Stderr: " . $stderr;
    } else {
        $results = json_decode($pythonOutput, true);
        if (isset($results['error'])) {
            $error = "Python Engine Error: " . $results['error'];
        } else {
            // Check if we already have it in the db history
            $stmt = $pdo->prepare("SELECT ai_consultant_summary FROM mcdm_history WHERE best_combination = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$results['best_combination']]);
            $history = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($history && !empty($history['ai_consultant_summary']) && strpos($history['ai_consultant_summary'], 'quota') === false) {
                $aiSummary = $history['ai_consultant_summary'];
            } else {
                // Call AI
                $aiSummary = callInitialAIConsultant($pythonOutput, $apiKey);
                
                // Save to DB history
                $stmtSave = $pdo->prepare("INSERT INTO mcdm_history (dataset_name, best_combination, spearman_scores, raw_results, ai_consultant_summary) VALUES (?, ?, ?, ?, ?)");
                $stmtSave->execute([
                    "Journal Study Case (Automatic Scan)",
                    $results['best_combination'],
                    json_encode($results['spearman_correlation']),
                    $pythonOutput,
                    $aiSummary
                ]);
            }
        }
    }
}

function callInitialAIConsultant($jsonData, $apiKey) {
    if ($apiKey == "YOUR_GEMINI_API_KEY") {
        return "Configure your API Key in config.php to fetch AI Consultant insights.";
    }

    $prompt = "Act as an MCDM Consultant. I have analyzed a journal study case using MEREC, LOPCOW, MABAC, and OCRA. " .
              "The results and Spearman correlations are as follows in JSON: " . $jsonData . 
              " Please provide a short, professional, and human-readable summary in Indonesian explaining why the winning combination (best_combination) was chosen and interpret the consistency of the methods.";
              
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=" . $apiKey;
    $data = array(
        "contents" => array(
            array("parts" => array(array("text" => $prompt)))
        )
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    curl_close($ch);

    $responseData = json_decode($response, true);
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        return $responseData['candidates'][0]['content']['parts'][0]['text'];
    }
    
    $errMsg = $responseData['error']['message'] ?? 'Quota exceeded or API error.';
    return "AI Summary unavailable: " . $errMsg;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCDM Journal Study Analyzer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <!-- MathJax for rendering mathematical formulas -->
    <script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
    <script>
    MathJax = {
      tex: {
        inlineMath: [['$', '$'], ['\\(', '\\)']]
      }
    };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
</head>
<body>
    <!-- Premium Spinner Loading Screen Overlay -->
    <div id="loadingOverlay" class="d-none">
        <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
        <h5 class="text-white fw-bold" data-translate="loadingText">Menghitung Ulang Model SPK...</h5>
        <p class="text-muted small" data-translate="loadingSub">Sistem sedang memproses algoritma MEREC, LOPCOW, MABAC, dan OCRA di server Python.</p>
    </div>

    <!-- Print Only Academic Header -->
    <div class="print-only text-center d-none" style="margin-bottom: 3rem;">
        <h4 class="fw-bold mb-1 text-dark" style="font-family: 'Times New Roman', Georgia, serif; letter-spacing: 1.5px; font-size: 14pt;" id="print-header-top">TUGAS BESAR AKHIR SEMESTER - MATA KULIAH SPK</h4>
        <h2 class="fw-bold my-3 text-dark" style="font-family: 'Times New Roman', Georgia, serif; border-top: 2.5px double #000; border-bottom: 2.5px double #000; padding: 12px 0; font-size: 20pt; letter-spacing: 0.5px;" id="print-header-mid">LAPORAN ANALISIS SISTEM PENDUKUNG KEPUTUSAN (SPK)</h2>
        <p class="mb-4" style="font-family: 'Times New Roman', Georgia, serif; font-size: 12pt; color: #1e293b; font-style: italic;" id="print-header-bot">
            Evaluasi Transisi Energi Regional SREB Menggunakan Metode Hybrid MEREC-MABAC, MEREC-OCRA, LOPCOW-MABAC, dan LOPCOW-OCRA
        </p>
        <div class="row text-start my-4" style="font-family: 'Times New Roman', Georgia, serif; font-size: 11pt; max-width: 650px; margin: 0 auto; line-height: 1.6; border: 1px solid #94a3b8; padding: 15px; background-color: #f8fafc;">
            <div class="col-6">
                <span id="print-stud-label"><strong>Program Studi:</strong> Teknik Informatika / Sistem Informasi</span><br>
                <span id="print-db-label"><strong>Basis Data:</strong> 16 Negara Alternatif (14 Kriteria)</span>
            </div>
            <div class="col-6 text-end">
                <span id="print-date-label"><strong>Tanggal Analisis:</strong> <?php echo date('d F Y'); ?></span><br>
                <span id="print-status-label"><strong>Status Dokumen:</strong> Hasil Terverifikasi Sistem</span>
            </div>
        </div>
        <hr style="border-top: 1px solid #000; opacity: 1; margin-top: 30px;">
    </div>

    <div class="container py-5">
        <!-- Dashboard Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 header-container">
            <h1 class="h2 fw-bold text-gradient m-0" data-translate="title">MCDM Journal Study Case</h1>
            <div class="d-flex gap-2 btn-controls">
                <button class="btn btn-outline-custom" id="lang-toggle-btn" onclick="toggleLanguage()">🌐 EN | ID</button>
                <button class="btn btn-outline-custom" onclick="exportToExcel()" data-translate="exportExcel">📥 Ekspor Excel</button>
                <button class="btn btn-outline-custom" onclick="window.print()" data-translate="printPdf">🖨️ Cetak / Simpan PDF</button>
            </div>
        </div>

        <?php if (!empty($missingFiles)): ?>
            <div class="glass-card text-center py-5">
                <h4 class="text-danger fw-bold mb-3">⚠️ Missing Dataset CSV Files</h4>
                <p class="text-muted">Please ensure the following 4 files are present in the directory: <code>c:\laragon\www\SPKTUBES\</code></p>
                <ul class="list-group list-group-flush bg-transparent d-inline-block text-start mb-4">
                    <?php foreach ($missingFiles as $f): ?>
                        <li class="list-group-item bg-transparent text-white border-secondary">- <?php echo htmlspecialchars($f); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($error): ?>
            <div class="glass-card text-center py-5">
                <h4 class="text-danger fw-bold mb-3">⚠️ Error Processing Data</h4>
                <p class="text-muted"><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php else: ?>
            
            <!-- Interactive Criteria and Simulation Control Panel -->
            <div class="glass-card mb-4 no-print">
                <div class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#criteriaSettingsCollapse" aria-expanded="false" style="cursor: pointer;">
                    <h5 class="fw-bold m-0 text-gradient" data-translate="criteriaSimHeader">⚙️ Simulasi Kriteria & Analisis Sensitivitas (Interaktif)</h5>
                    <span class="text-secondary small" data-translate="criteriaSimSub">Klik untuk Buka/Tutup Control Panel</span>
                </div>
                <div class="collapse" id="criteriaSettingsCollapse">
                    <hr class="border-secondary my-3">
                    <p class="text-muted small" data-translate="criteriaSimDesc">Aktifkan/nonaktifkan kriteria atau ubah tipenya (Benefit/Cost) secara real-time. Bobot MEREC/LOPCOW dan ranking alternatif akan dikalkulasi ulang secara otomatis di Python.</p>
                    
                    <div class="row g-3" id="criteria-toggles-container">
                        <!-- Populated by JS -->
                    </div>
                    
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button class="btn btn-outline-custom btn-sm" onclick="resetToBaseline()" data-translate="resetBaseline">Reset ke Baseline</button>
                        <button class="btn btn-primary-custom btn-sm" onclick="applySimulation()" data-translate="applySim">Terapkan Simulasi</button>
                    </div>
                </div>
            </div>

            <!-- Simulation Alert Banner (Shown only when parameters are customized) -->
            <div id="simulationAlert" class="alert alert-warning d-none mb-4" role="alert">
                <div class="d-flex justify-content-between align-items-center">
                    <div id="simulation-alert-text">
                        <strong>⚠️ Mode Simulasi Aktif:</strong> Anda sedang melihat hasil perhitungan model kustom. Kriteria yang dinonaktifkan/diubah tipenya telah dikalkulasi ulang secara dinamis.
                    </div>
                    <button class="btn btn-outline-custom btn-sm fw-bold" onclick="resetToBaseline()" data-translate="backToBaseline">Kembali ke Baseline Jurnal</button>
                </div>
            </div>

            <!-- AI Consultant Summary -->
            <div class="row mb-4 ai-section">
                <div class="col-12">
                    <div class="glass-card ai-consultant-card p-4">
                        <h4 class="fw-bold text-gradient mb-3" data-translate="aiInsights">🤖 AI Consultant Insights</h4>
                        <div id="ai-content"><?php echo htmlspecialchars($aiSummary); ?></div>
                        <div class="mt-3">
                            <span class="badge bg-primary" id="best-combo-badge">Best Method: <?php echo htmlspecialchars($results['best_combination']); ?></span>
                            <span class="badge bg-secondary" id="stability-score-badge">Stability Score: <?php echo number_format($results['stability_scores'][$results['best_combination']], 4); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alternative Rankings comparison -->
            <div class="glass-card mb-4 rankings-card">
                <h5 class="fw-bold mb-3" data-translate="rankingsHeader">Alternative Rankings Comparison (Study Results)</h5>
                <p class="text-muted small no-print" data-translate="rankingsTip">Tip: Klik nama negara di tabel untuk menampilkan Radar Chart perbandingan kriteria di bagian bawah.</p>
                <div class="table-responsive">
                    <table class="table table-glass text-center align-middle">
                        <thead>
                            <tr>
                                <th class="text-start" data-translate="tableColCountry">Alternative (Country)</th>
                                <th>LOPCOW-MABAC</th>
                                <th>LOPCOW-OCRA</th>
                                <th>MEREC-MABAC</th>
                                <th>MEREC-OCRA</th>
                                <th class="text-info" data-translate="tableColJournal">Jurnal Rank</th>
                                <th class="text-warning" data-translate="tableColBorda">BORDA Rank</th>
                            </tr>
                        </thead>
                        <tbody id="rankings-table-body">
                            <!-- Populated by JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Radar Profile Card -->
            <div class="glass-card mb-4 radar-card" id="radar-profile-card">
                <div class="row align-items-center">
                    <div class="col-lg-7">
                        <h5 class="fw-bold mb-3 text-gradient" data-translate="radarHeader">📊 Profil Kinerja Alternatif (Radar Chart)</h5>
                        <p class="text-muted small" data-translate="radarDesc">Bandingkan profil skor kriteria alternatif terpilih terhadap rata-rata regional 16 negara SREB (berdasarkan normalisasi MABAC skala 0–1, semakin tinggi semakin bagus).</p>
                        <div class="d-flex align-items-center gap-2 mb-3 select-radar-container">
                            <span class="small text-muted" data-translate="radarSelectLabel">Pilih Negara:</span>
                            <select class="form-select d-inline-block" id="radar-country-select" style="max-width: 200px; display: inline-block;">
                                <!-- Populated dynamically -->
                            </select>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-glass table-sm text-center small align-middle" style="font-size: 0.75rem;">
                                <thead>
                                    <tr id="radar-table-header"></tr>
                                </thead>
                                <tbody>
                                    <tr id="radar-table-row-selected"></tr>
                                    <tr id="radar-table-row-average"></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-5 text-center mt-3 mt-lg-0">
                        <div class="chart-container" style="position: relative; height: 320px; width: 100%; max-width: 400px; margin: 0 auto;">
                            <canvas id="radarChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weights & Stats row -->
            <div class="row mb-4 weights-stats-row">
                <div class="col-md-6 mb-3 mb-md-0">
                    <div class="glass-card h-100">
                        <h5 class="fw-bold mb-3" data-translate="weightsHeader">Criteria Weights Comparison</h5>
                        <div class="table-responsive">
                            <table class="table table-glass text-center align-middle">
                                <thead>
                                    <tr>
                                        <th class="text-start" data-translate="radarTableCrit">Criteria</th>
                                        <th>MEREC</th>
                                        <th>LOPCOW</th>
                                    </tr>
                                </thead>
                                <tbody id="weights-table-body">
                                    <!-- Populated by JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="glass-card h-100">
                        <h5 class="fw-bold mb-3" data-translate="statsHeader">Descriptive Weight Statistics</h5>
                        <div class="table-responsive">
                            <table class="table table-glass mb-4 align-middle">
                                <thead>
                                    <tr>
                                        <th data-translate="radarTableCrit">Metric</th>
                                        <th>MEREC</th>
                                        <th>LOPCOW</th>
                                    </tr>
                                </thead>
                                <tbody id="stats-table-body">
                                    <!-- Populated by JS -->
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small" id="stats-note-text">
                            <strong>Note:</strong> Standard Deviation (SD) yang lebih rendah pada LOPCOW menunjukkan bobot kriteria terbagi secara lebih merata (homogen). Entropy yang lebih tinggi pada LOPCOW mengonfirmasi diversifikasi informasi yang lebih merata dibanding MEREC.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Spearman Consistency Matrix -->
            <div class="glass-card mb-4 spearman-card">
                <h5 class="fw-bold mb-3" data-translate="spearmanHeader">Uji Konsistensi: Spearman Correlation Matrix</h5>
                <div class="table-responsive">
                    <table class="table table-glass text-center align-middle">
                        <thead id="spearman-table-header">
                            <!-- Populated dynamically -->
                        </thead>
                        <tbody id="spearman-table-body">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>
                <div class="mt-2 text-muted small">
                    <span class="badge bg-success me-2">&nbsp;</span> Hubungan korelasi sangat signifikan (p &lt; 0.01)
                    <span class="badge bg-warning ms-3 me-2">&nbsp;</span> Hubungan korelasi signifikan (p &lt; 0.05)
                </div>
            </div>

            <!-- Stability & Sensitivity row -->
            <div class="row mb-4 stability-sensitivity-row">
                <div class="col-md-6 mb-3 mb-md-0">
                    <div class="glass-card h-100">
                        <h5 class="fw-bold mb-3" data-translate="stabilityHeader">Uji Stabilitas: Exclude-Alternative Stability Index</h5>
                        <div class="table-responsive">
                            <table class="table table-glass align-middle">
                                <thead>
                                    <tr>
                                        <th data-translate="stabilityColMethod">Combination Method</th>
                                        <th data-translate="stabilityColScore">Stability Index (Mean Spearman)</th>
                                        <th data-translate="stabilityColStatus">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="stability-table-body">
                                    <!-- Populated by JS -->
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small mt-2" id="stability-note-text">
                            <strong>Metodologi Uji Stabilitas:</strong> Dilakukan dengan mengeliminasi satu-persatu alternatif (dari 16 negara), lalu menghitung ulang bobot dan peringkat. Nilai stabilitas adalah rata-rata koefisien korelasi Spearman antara peringkat asli dan peringkat setelah satu negara dihilangkan.
                        </p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="glass-card h-100">
                        <h5 class="fw-bold mb-3" data-translate="sensHeader">Uji Sensitivitas & Konsistensi Visual</h5>
                        <div class="mb-4" style="position: relative; height: 180px;">
                            <canvas id="spearmanChart"></canvas>
                        </div>
                        <div style="position: relative; height: 180px;">
                            <canvas id="sensitivityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SPK Math Walkthrough Step-by-Step Card -->
            <div class="glass-card mb-4 math-card">
                <h5 class="fw-bold mb-3 text-gradient" data-translate="walkthroughHeader">🔬 SPK Mathematical Walkthrough (Transparansi Perhitungan)</h5>
                <p class="text-muted small" data-translate="walkthroughDesc">Pilih negara dan kriteria untuk melacak persis bagaimana angka desimal dihitung secara akademis oleh sistem.</p>
                
                <div class="row g-3 mb-4 select-walkthrough-container">
                    <div class="col-md-6 col-lg-3">
                        <label class="small text-muted mb-1 d-block" data-translate="radarSelectLabel">Pilih Negara:</label>
                        <select class="form-select" id="walkthrough-country-select" onchange="updateMathWalkthrough()">
                            <!-- Populated dynamically -->
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="small text-muted mb-1 d-block" data-translate="walkSelectCriteria">Pilih Kriteria:</label>
                        <select class="form-select" id="walkthrough-criteria-select" onchange="updateMathWalkthrough()">
                            <!-- Populated dynamically -->
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="small text-muted mb-1 d-block" data-translate="walkSelectWeight">Pilih Metode Pembobotan:</label>
                        <select class="form-select" id="walkthrough-weight-select" onchange="updateMathWalkthrough()">
                            <option value="merec" selected>MEREC Weighting</option>
                            <option value="lopcow">LOPCOW Weighting</option>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="small text-muted mb-1 d-block" data-translate="walkSelectRank">Pilih Metode Perankingan:</label>
                        <select class="form-select" id="walkthrough-rank-select" onchange="updateMathWalkthrough()">
                            <option value="mabac" selected>MABAC Ranking</option>
                            <option value="ocra">OCRA Ranking</option>
                        </select>
                    </div>
                </div>
                
                <div class="p-4 rounded bg-black-25 border border-secondary" id="walkthrough-content" style="background: rgba(0, 0, 0, 0.2);">
                    <!-- Populated dynamically -->
                </div>
            </div>

            <!-- Interactive AI Chat Widget -->
            <div class="row chat-section no-print">
                <div class="col-12">
                    <div class="glass-card p-4">
                        <h4 class="fw-bold text-gradient mb-3" data-translate="chatHeader">💬 Tanya AI Consultant</h4>
                        <p class="text-muted small" data-translate="chatDesc">Tanyakan apa saja mengenai perbandingan bobot, peringkat negara, atau interpretasi korelasi Spearman dari data di atas.</p>
                        
                        <div id="chat-history" class="mb-3 p-3 rounded" style="background: rgba(15, 23, 42, 0.1); max-height: 300px; overflow-y: auto;">
                            <div class="text-muted small mb-2 text-center" id="chat-start-msg">Chat started. Ask your question below.</div>
                        </div>

                        <div class="input-group">
                            <input type="text" id="chat-input" class="form-control" placeholder="Tanyakan tentang hasil analisis..." onkeydown="if(event.key==='Enter') sendChatMessage()">
                            <button class="btn btn-primary-custom" type="button" id="chat-send" onclick="sendChatMessage()">Kirim</button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                // Core Reactive State
                let currentData = <?php echo json_encode($results); ?>;
                let baselineData = <?php echo json_encode($results); ?>;
                
                // Chart instances
                let spearmanChartInstance = null;
                let sensitivityChartInstance = null;
                let radarChartInstance = null;

                // Translation Dictionary
                const translations = {
                    en: {
                        title: "MCDM Journal Study Case",
                        exportExcel: "📥 Export Excel",
                        printPdf: "🖨️ Print / Save PDF",
                        criteriaSimHeader: "⚙️ Criteria Simulation & Sensitivity Analysis (Interactive)",
                        criteriaSimSub: "Click to Open/Close Control Panel",
                        criteriaSimDesc: "Enable/disable criteria or modify their types (Benefit/Cost) in real-time. MEREC/LOPCOW weights and alternative rankings will be automatically recalculated in Python.",
                        resetBaseline: "Reset to Baseline",
                        applySim: "Apply Simulation",
                        simulationActive: "⚠️ Simulation Mode Active: You are viewing custom model calculations. Disabled/modified criteria have been dynamically recalculated.",
                        backToBaseline: "Return to Journal Baseline",
                        aiInsights: "🤖 AI Consultant Insights",
                        bestMethod: "Best Method: ",
                        stabilityScore: "Stability Score: ",
                        rankingsHeader: "Alternative Rankings Comparison (Study Results)",
                        rankingsTip: "Tip: Click on a country's name in the table to display its criteria Radar Chart profile below.",
                        tableColCountry: "Country / Alternative",
                        tableColJournal: "Journal Rank",
                        tableColBorda: "BORDA Rank",
                        radarHeader: "📊 Alternative Performance Profile (Radar Chart)",
                        radarDesc: "Compare the criteria scores profile of the selected country against the regional average of all 16 SREB alternatives (based on MABAC normalization scale 0-1, higher is better).",
                        radarSelectLabel: "Select Country:",
                        radarTableCrit: "Criteria",
                        radarTableAvg: "Average",
                        weightsHeader: "Criteria Weights Comparison",
                        statsHeader: "Descriptive Weight Statistics",
                        statsNote: "Note: Lower Standard Deviation (SD) in LOPCOW indicates that criteria weights are distributed more evenly (homogeneous). Higher Entropy in LOPCOW confirms a more balanced information diversification than MEREC.",
                        statsMin: "Minimum Weight",
                        statsMax: "Maximum Weight",
                        statsSd: "Standard Deviation (SD)",
                        statsEntropy: "Weight Entropy",
                        spearmanHeader: "Uji Konsistensi: Spearman Correlation Matrix",
                        spearmanDesc: "Consistency check of ranking methods using Spearman Rank Correlation matrix.",
                        stabilityHeader: "Uji Stabilitas: Exclude-Alternative Stability Index",
                        stabilityDesc: "Stability testing methodology: Done by eliminating one alternative at a time (from 16 countries), then recalculating weights and rankings. The stability score is the average Spearman correlation coefficient between original and perturbed rankings.",
                        stabilityColMethod: "Method Combination",
                        stabilityColScore: "Average Correlation (Stability)",
                        stabilityVeryStable: "Very Stable",
                        stabilityStable: "Stable",
                        sensHeader: "Uji Sensitivitas & Konsistensi Visual",
                        walkthroughHeader: "🔬 SPK Mathematical Walkthrough (Calculation Transparency)",
                        walkthroughDesc: "Select country and criteria to track exactly how decimal values are academically computed by the system.",
                        walkSelectCountry: "Select Country:",
                        walkSelectCriteria: "Select Criteria:",
                        walkSelectWeight: "Select Weighting Method:",
                        walkSelectRank: "Select Ranking Method:",
                        chatHeader: "💬 Ask AI Consultant",
                        chatDesc: "Ask any question about weight comparisons, country rankings, or interpretation of Spearman correlations of the data above.",
                        chatPlaceholder: "Ask about analysis results...",
                        chatSend: "Send",
                        chatStart: "Chat started. Ask your question below.",
                        loadingText: "Recalculating SPK Model...",
                        loadingSub: "The system is processing MEREC, LOPCOW, MABAC, and OCRA algorithms on Python server."
                    },
                    id: {
                        title: "MCDM Journal Study Case",
                        exportExcel: "📥 Ekspor Excel",
                        printPdf: "🖨️ Cetak / Simpan PDF",
                        criteriaSimHeader: "⚙️ Simulasi Kriteria & Analisis Sensitivitas (Interaktif)",
                        criteriaSimSub: "Klik untuk Buka/Tutup Control Panel",
                        criteriaSimDesc: "Aktifkan/nonaktifkan kriteria atau ubah tipenya (Benefit/Cost) secara real-time. Bobot MEREC/LOPCOW dan ranking alternatif akan dikalkulasi ulang secara otomatis di Python.",
                        resetBaseline: "Reset ke Baseline",
                        applySim: "Terapkan Simulasi",
                        simulationActive: "⚠️ Mode Simulasi Aktif: Anda sedang melihat hasil perhitungan model kustom. Kriteria yang dinonaktifkan/diubah tipenya telah dikalkulasi ulang secara dinamis.",
                        backToBaseline: "Kembali ke Baseline Jurnal",
                        aiInsights: "🤖 AI Consultant Insights",
                        bestMethod: "Best Method: ",
                        stabilityScore: "Stability Score: ",
                        rankingsHeader: "Alternative Rankings Comparison (Study Results)",
                        rankingsTip: "Tip: Klik nama negara di tabel untuk menampilkan Radar Chart perbandingan kriteria di bagian bawah.",
                        tableColCountry: "Negara / Alternatif",
                        tableColJournal: "Peringkat Jurnal",
                        tableColBorda: "Peringkat BORDA",
                        radarHeader: "📊 Profil Kinerja Alternatif (Radar Chart)",
                        radarDesc: "Bandingkan profil skor kriteria alternatif terpilih terhadap rata-rata regional 16 negara SREB (berdasarkan normalisasi MABAC skala 0–1, semakin tinggi semakin bagus).",
                        radarSelectLabel: "Pilih Negara:",
                        radarTableCrit: "Kriteria",
                        radarTableAvg: "Rata-rata",
                        weightsHeader: "Criteria Weights Comparison",
                        statsHeader: "Descriptive Weight Statistics",
                        statsNote: "Note: Standard Deviation (SD) yang lebih rendah pada LOPCOW menunjukkan bobot kriteria terbagi secara lebih merata (homogen). Entropy yang lebih tinggi pada LOPCOW mengonfirmasi diversifikasi informasi yang lebih merata dibanding MEREC.",
                        statsMin: "Bobot Minimum",
                        statsMax: "Bobot Maksimum",
                        statsSd: "Standar Deviasi (SD)",
                        statsEntropy: "Entropi Bobot",
                        spearmanHeader: "Uji Konsistensi: Spearman Correlation Matrix",
                        spearmanDesc: "Pemeriksaan konsistensi metode peringkat menggunakan matriks Korelasi Peringkat Spearman.",
                        stabilityHeader: "Uji Stabilitas: Exclude-Alternative Stability Index",
                        stabilityDesc: "Metodologi Uji Stabilitas: Dilakukan dengan mengeliminasi satu-persatu alternatif (dari 16 negara), lalu menghitung ulang bobot dan peringkat. Nilai stabilitas adalah rata-rata koefisien korelasi Spearman antara peringkat asli dan peringkat setelah satu negara dihilangkan.",
                        stabilityColMethod: "Kombinasi Metode",
                        stabilityColScore: "Rata-rata Korelasi (Stabilitas)",
                        stabilityVeryStable: "Sangat Stabil",
                        stabilityStable: "Stabil",
                        sensHeader: "Uji Sensitivitas & Konsistensi Visual",
                        walkthroughHeader: "🔬 SPK Mathematical Walkthrough (Transparansi Perhitungan)",
                        walkthroughDesc: "Pilih negara dan kriteria untuk melacak persis bagaimana angka desimal dihitung secara akademis oleh sistem.",
                        walkSelectCountry: "Pilih Negara:",
                        walkSelectCriteria: "Pilih Kriteria:",
                        walkSelectWeight: "Pilih Metode Pembobotan:",
                        walkSelectRank: "Pilih Metode Perankingan:",
                        chatHeader: "💬 Tanya AI Consultant",
                        chatDesc: "Tanyakan apa saja mengenai perbandingan bobot, peringkat negara, atau interpretasi korelasi Spearman dari data di atas.",
                        chatPlaceholder: "Tanyakan tentang hasil analisis...",
                        chatSend: "Kirim",
                        chatStart: "Chat dimulai. Ajukan pertanyaan Anda di bawah.",
                        loadingText: "Menghitung Ulang Model SPK...",
                        loadingSub: "Sistem sedang memproses algoritma MEREC, LOPCOW, MABAC, dan OCRA di server Python."
                    }
                };

                let currentLang = localStorage.getItem('lang') || 'id'; // Default to Indonesian

                function toggleLanguage() {
                    currentLang = (currentLang === 'id') ? 'en' : 'id';
                    localStorage.setItem('lang', currentLang);
                    updateUILanguage();
                }

                function updateUILanguage() {
                    // Update all elements with data-translate
                    document.querySelectorAll('[data-translate]').forEach(el => {
                        const key = el.dataset.translate;
                        if (translations[currentLang] && translations[currentLang][key]) {
                            el.innerHTML = translations[currentLang][key];
                        }
                    });

                    // Update input placeholders
                    const chatInput = document.getElementById('chat-input');
                    if (chatInput) {
                        chatInput.placeholder = translations[currentLang].chatPlaceholder;
                    }

                    // Update chat start message
                    const chatStartMsg = document.getElementById('chat-start-msg');
                    if (chatStartMsg) {
                        chatStartMsg.textContent = translations[currentLang].chatStart;
                    }

                    // Update language toggle button text
                    const langBtn = document.getElementById('lang-toggle-btn');
                    if (langBtn) {
                        langBtn.innerHTML = `🌐 ${currentLang.toUpperCase()} | ${currentLang === 'id' ? 'ID' : 'EN'}`;
                    }

                    // Update print header
                    const printHeaderTop = document.getElementById('print-header-top');
                    const printHeaderMid = document.getElementById('print-header-mid');
                    const printHeaderBot = document.getElementById('print-header-bot');
                    if (currentLang === 'en') {
                        if (printHeaderTop) printHeaderTop.textContent = "FINAL SEMESTER PROJECT - DECISION SUPPORT SYSTEMS COURSE";
                        if (printHeaderMid) printHeaderMid.textContent = "DECISION SUPPORT SYSTEM (DSS) ANALYSIS REPORT";
                        if (printHeaderBot) printHeaderBot.textContent = "Evaluation of SREB Regional Energy Transition Using Hybrid MEREC-MABAC, MEREC-OCRA, LOPCOW-MABAC, and LOPCOW-OCRA Methods";
                    } else {
                        if (printHeaderTop) printHeaderTop.textContent = "TUGAS BESAR AKHIR SEMESTER - MATA KULIAH SPK";
                        if (printHeaderMid) printHeaderMid.textContent = "LAPORAN ANALISIS SISTEM PENDUKUNG KEPUTUSAN (SPK)";
                        if (printHeaderBot) printHeaderBot.textContent = "Evaluasi Transisi Energi Regional SREB Menggunakan Metode Hybrid MEREC-MABAC, MEREC-OCRA, LOPCOW-MABAC, dan LOPCOW-OCRA";
                    }
                    
                    // Update print study labels
                    const printStudLabel = document.getElementById('print-stud-label');
                    const printDateLabel = document.getElementById('print-date-label');
                    const printStatusLabel = document.getElementById('print-status-label');
                    if (printStudLabel) {
                        printStudLabel.innerHTML = `<strong>${currentLang === 'id' ? 'Program Studi' : 'Study Program'}:</strong> ${currentLang === 'id' ? 'Teknik Informatika / Sistem Informasi' : 'Informatics / Information Systems'}`;
                    }
                    if (printDateLabel) {
                        printDateLabel.innerHTML = `<strong>${currentLang === 'id' ? 'Tanggal Analisis' : 'Analysis Date'}:</strong> ${new Date().toLocaleDateString(currentLang === 'id' ? 'id-ID' : 'en-US', { day: 'numeric', month: 'long', year: 'numeric' })}`;
                    }
                    if (printStatusLabel) {
                        printStatusLabel.innerHTML = `<strong>${currentLang === 'id' ? 'Status Dokumen' : 'Document Status'}:</strong> ${currentLang === 'id' ? 'Hasil Terverifikasi Sistem' : 'System Verified Result'}`;
                    }

                    // Update stats note
                    const statsNoteText = document.getElementById('stats-note-text');
                    if (statsNoteText) {
                        statsNoteText.innerHTML = `<strong>Note:</strong> ${translations[currentLang].statsNote}`;
                    }

                    // Update stability note
                    const stabilityNoteText = document.getElementById('stability-note-text');
                    if (stabilityNoteText) {
                        stabilityNoteText.innerHTML = `<strong>${currentLang === 'id' ? 'Metodologi Uji Stabilitas:' : 'Stability Testing Methodology:'}</strong> ${translations[currentLang].stabilityDesc}`;
                    }

                    // Update simulation alert text if active
                    const simAlertText = document.getElementById('simulation-alert-text');
                    if (simAlertText) {
                        simAlertText.innerHTML = currentLang === 'id' 
                            ? '<strong>⚠️ Mode Simulasi Aktif:</strong> Anda sedang melihat hasil perhitungan model kustom. Kriteria yang dinonaktifkan/diubah tipenya telah dikalkulasi ulang secara dinamis.' 
                            : '<strong>⚠️ Simulation Mode Active:</strong> You are viewing custom model calculations. Disabled/modified criteria have been dynamically recalculated.';
                    }

                    // Re-render and update Charts & Tables
                    if (currentData) {
                        renderDashboard(currentData);
                    }
                }

                window.addEventListener('DOMContentLoaded', () => {
                    // Stick to Light Theme
                    const body = document.body;
                    body.classList.add('light-theme');

                    // Render Markdown initially
                    const aiContentEl = document.getElementById('ai-content');
                    if (aiContentEl && typeof marked !== 'undefined') {
                        aiContentEl.innerHTML = marked.parse(aiContentEl.textContent);
                    }

                    // Build initial dashboard views
                    updateUILanguage();
                    renderCriteriaToggles();
                });

                function showLoading(show) {
                    const overlay = document.getElementById('loadingOverlay');
                    if (show) {
                        overlay.classList.remove('d-none');
                        overlay.classList.add('d-flex');
                    } else {
                        overlay.classList.add('d-none');
                        overlay.classList.remove('d-flex');
                    }
                }

                function renderCriteriaToggles() {
                    const container = document.getElementById('criteria-toggles-container');
                    if (!container) return;
                    container.innerHTML = '';
                    
                    const rawNames = currentData.all_criteria_names_raw || currentData.criteria_names;
                    const disabled = currentData.disabled_criteria || [];
                    const activeNames = currentData.criteria_names;
                    const activeTypes = currentData.criteria_types;
                    
                    rawNames.forEach((name, idx) => {
                        const isActive = !disabled.includes(name);
                        let type = 'benefit';
                        if (isActive) {
                            const activeIdx = activeNames.indexOf(name);
                            type = activeTypes[activeIdx];
                        } else {
                            const costCriteria = ["A4", "A5", "A13", "A14"];
                            const code = `A${idx+1}`;
                            type = costCriteria.includes(code) ? 'cost' : 'benefit';
                        }
                        
                        const col = document.createElement('div');
                        col.className = 'col-md-6 col-lg-3';
                        col.innerHTML = `
                            <div class="p-3 rounded border h-100" style="background: rgba(15, 23, 42, 0.03); border-color: rgba(15, 23, 42, 0.1) !important;">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input criteria-active-toggle" type="checkbox" id="toggle-active-${idx}" data-name="${name}" ${isActive ? 'checked' : ''}>
                                    <label class="form-check-label fw-bold small text-truncate d-inline-block" style="max-width: 140px; color: var(--text-main);" for="toggle-active-${idx}">
                                        A${idx+1} - ${name}
                                    </label>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="small text-muted">Tipe:</span>
                                    <select class="form-select form-select-sm criteria-type-select" id="select-type-${idx}" data-name="${name}" ${!isActive ? 'disabled' : ''}>
                                        <option value="benefit" ${type === 'benefit' ? 'selected' : ''}>Benefit (+)</option>
                                        <option value="cost" ${type === 'cost' ? 'selected' : ''}>Cost (-)</option>
                                    </select>
                                </div>
                            </div>
                        `;
                        container.appendChild(col);
                        
                        const toggleEl = col.querySelector('.criteria-active-toggle');
                        const selectEl = col.querySelector('.criteria-type-select');
                        toggleEl.addEventListener('change', () => {
                            selectEl.disabled = !toggleEl.checked;
                        });
                    });
                }

                function applySimulation() {
                    const disabled_criteria = [];
                    const criteria_types = {};
                    
                    const activeToggles = document.querySelectorAll('.criteria-active-toggle');
                    const typeSelects = document.querySelectorAll('.criteria-type-select');
                    
                    activeToggles.forEach(toggle => {
                        const name = toggle.dataset.name;
                        if (!toggle.checked) {
                            disabled_criteria.push(name);
                        }
                    });
                    
                    typeSelects.forEach(select => {
                        const name = select.dataset.name;
                        if (!select.disabled) {
                            criteria_types[name] = select.value;
                        }
                    });
                    
                    showLoading(true);
                    
                    fetch('recalculate_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            disabled_criteria: disabled_criteria,
                            criteria_types: criteria_types
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        showLoading(false);
                        if (data.error) {
                            alert('Recalculation error: ' + data.error);
                        } else {
                            currentData = data;
                            renderDashboard(currentData);
                        }
                    })
                    .catch(err => {
                        showLoading(false);
                        alert('Network error during recalculation: ' + err);
                    });
                }

                function resetToBaseline() {
                    currentData = JSON.parse(JSON.stringify(baselineData));
                    renderCriteriaToggles();
                    renderDashboard(currentData);
                }

                function selectCountryForRadar(idx) {
                    const select = document.getElementById('radar-country-select');
                    if (select) {
                        select.value = idx;
                        renderRadarChart(idx);
                        
                        // Scroll to radar card smoothly
                        document.getElementById('radar-profile-card').scrollIntoView({ behavior: 'smooth' });
                    }
                }

                function renderDashboard(data) {
                    // Toggle simulation banner
                    const banner = document.getElementById('simulationAlert');
                    if (data.is_simulation) {
                        banner.classList.remove('d-none');
                    } else {
                        banner.classList.add('d-none');
                    }

                    // Update best combination and stability score badges
                    document.getElementById('best-combo-badge').innerText = (currentLang === 'id' ? 'Metode Terbaik: ' : 'Best Method: ') + data.best_combination;
                    document.getElementById('stability-score-badge').innerText = (currentLang === 'id' ? 'Skor Stabilitas: ' : 'Stability Score: ') + data.stability_scores[data.best_combination].toFixed(4);

                    // Populate Rankings Table
                    const rankingsBody = document.getElementById('rankings-table-body');
                    rankingsBody.innerHTML = '';
                    data.alternatives.forEach((alt, idx) => {
                        const tr = document.createElement('tr');
                        tr.className = 'cursor-pointer';
                        tr.setAttribute('onclick', `selectCountryForRadar(${idx})`);
                        tr.innerHTML = `
                            <td class="fw-bold text-gradient text-start">${alt}</td>
                            <td>${data.ranks['LOPCOW-MABAC'][idx]}</td>
                            <td>${data.ranks['LOPCOW-OCRA'][idx]}</td>
                            <td>${data.ranks['MEREC-MABAC'][idx]}</td>
                            <td>${data.ranks['MEREC-OCRA'][idx]}</td>
                            <td class="text-info fw-bold">${data.ranks['Rank Jurnal'][idx]}</td>
                            <td class="text-warning fw-bold">${data.ranks['BORDA'][idx]}</td>
                        `;
                        rankingsBody.appendChild(tr);
                    });

                    // Populate Weights Table
                    const weightsBody = document.getElementById('weights-table-body');
                    weightsBody.innerHTML = '';
                    data.criteria_names.forEach((name, idx) => {
                        const rawNames = data.all_criteria_names_raw || data.criteria_names;
                        const globalIdx = rawNames.indexOf(name);
                        const code = `A${globalIdx + 1}`;
                        const type = data.criteria_types[idx];
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td class="text-start"><span class="badge bg-secondary me-2">${code}</span> ${name} <span class="badge bg-dark">${type.toUpperCase()}</span></td>
                            <td class="fw-bold">${data.weights.MEREC[idx].toFixed(4)}</td>
                            <td>${data.weights.LOPCOW[idx].toFixed(4)}</td>
                        `;
                        weightsBody.appendChild(tr);
                    });

                    // Populate Stats Table
                    const statsBody = document.getElementById('stats-table-body');
                    statsBody.innerHTML = `
                        <tr>
                            <td class="fw-bold">${currentLang === 'id' ? 'Bobot Minimum' : 'Minimum Weight'}</td>
                            <td class="fw-bold text-gradient">${data.weight_statistics.MEREC.min.toFixed(4)}</td>
                            <td>${data.weight_statistics.LOPCOW.min.toFixed(4)}</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">${currentLang === 'id' ? 'Bobot Maksimum' : 'Maximum Weight'}</td>
                            <td class="fw-bold text-gradient">${data.weight_statistics.MEREC.max.toFixed(4)}</td>
                            <td>${data.weight_statistics.LOPCOW.max.toFixed(4)}</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">${currentLang === 'id' ? 'Standar Deviasi (SD)' : 'Standard Deviation (SD)'}</td>
                            <td class="fw-bold text-gradient">${data.weight_statistics.MEREC.sd.toFixed(4)}</td>
                            <td>${data.weight_statistics.LOPCOW.sd.toFixed(4)}</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">${currentLang === 'id' ? 'Entropi Bobot' : 'Weight Entropy'}</td>
                            <td class="fw-bold text-gradient">${data.weight_statistics.MEREC.entropy.toFixed(4)}</td>
                            <td>${data.weight_statistics.LOPCOW.entropy.toFixed(4)}</td>
                        </tr>
                    `;

                    // Populate Spearman Table
                    const spearmanHeader = document.getElementById('spearman-table-header');
                    const spearmanBody = document.getElementById('spearman-table-body');
                    spearmanHeader.innerHTML = '';
                    spearmanBody.innerHTML = '';
                    
                    const methods = Object.keys(data.spearman_correlation);
                    
                    // Header row
                    const trh = document.createElement('tr');
                    trh.innerHTML = `<th class="text-start">${currentLang === 'id' ? 'Metode' : 'Method'}</th>` + methods.map(m => `<th>${m}</th>`).join('');
                    spearmanHeader.appendChild(trh);
                    
                    // Body rows
                    methods.forEach(m1 => {
                        const tr = document.createElement('tr');
                        let cellsHtml = `<td class="text-start fw-bold">${m1}</td>`;
                        
                        methods.forEach(m2 => {
                            const coef = data.spearman_correlation[m1][m2].coefficient;
                            const p = data.spearman_correlation[m1][m2].p_value;
                            
                            let bgColor = "transparent";
                            if (coef === 1.0) bgColor = "rgba(255, 255, 255, 0.05)";
                            else if (p < 0.01) bgColor = "rgba(25, 135, 84, 0.18)";
                            else if (p < 0.05) bgColor = "rgba(255, 193, 7, 0.18)";
                            
                            cellsHtml += `
                                <td style="background-color: ${bgColor};">
                                    <div class="fw-bold">${coef.toFixed(4)}</div>
                                    <div class="text-muted small" style="font-size: 0.65rem;">p=${p < 0.001 ? '<0.001' : p.toFixed(4)}</div>
                                </td>
                            `;
                        });
                        tr.innerHTML = cellsHtml;
                        spearmanBody.appendChild(tr);
                    });

                    // Populate Stability Table
                    const stabilityBody = document.getElementById('stability-table-body');
                    stabilityBody.innerHTML = '';
                    Object.entries(data.stability_scores).forEach(([combo, score]) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td class="fw-bold">${combo}</td>
                            <td class="fw-bold text-gradient">${score.toFixed(4)}</td>
                            <td>
                                <span class="badge ${score > 0.95 ? 'bg-success' : 'bg-warning'}">
                                    ${score > 0.95 ? (currentLang === 'id' ? 'Sangat Stabil' : 'Very Stable') : (currentLang === 'id' ? 'Stabil' : 'Stable')}
                                </span>
                            </td>
                        `;
                        stabilityBody.appendChild(tr);
                    });

                    // Populate Select Dropdowns
                    populateDropdowns(data);

                    // Re-render and update Charts
                    updateCharts(data);

                    // Update walkthrough panel
                    updateMathWalkthrough();
                }

                function populateDropdowns(data) {
                    const radarSelect = document.getElementById('radar-country-select');
                    const walkCountrySelect = document.getElementById('walkthrough-country-select');
                    const walkCriteriaSelect = document.getElementById('walkthrough-criteria-select');
                    
                    const savedRadarVal = radarSelect.value || "3"; // default Israel
                    const savedWalkCountry = walkCountrySelect.value || "0";
                    const savedWalkCriteria = walkCriteriaSelect.value || "0";

                    radarSelect.innerHTML = '';
                    walkCountrySelect.innerHTML = '';
                    walkCriteriaSelect.innerHTML = '';

                    data.alternatives.forEach((alt, idx) => {
                        const opt1 = new Option(alt, idx);
                        const opt2 = new Option(alt, idx);
                        radarSelect.add(opt1);
                        walkCountrySelect.add(opt2);
                    });

                    data.criteria_names.forEach((name, idx) => {
                        const rawNames = data.all_criteria_names_raw || data.criteria_names;
                        const globalIdx = rawNames.indexOf(name);
                        const opt = new Option(`A${globalIdx + 1} - ${name}`, idx);
                        walkCriteriaSelect.add(opt);
                    });

                    radarSelect.value = data.alternatives[savedRadarVal] ? savedRadarVal : "0";
                    walkCountrySelect.value = data.alternatives[savedWalkCountry] ? savedWalkCountry : "0";
                    walkCriteriaSelect.value = data.criteria_names[savedWalkCriteria] ? savedWalkCriteria : "0";

                    // Bind radar select listener
                    radarSelect.onchange = () => renderRadarChart(parseInt(radarSelect.value));
                    
                    // Render initial radar chart
                    renderRadarChart(parseInt(radarSelect.value));
                }

                function updateCharts(data) {
                    const body = document.body;
                    const getTickColor = () => body.classList.contains('light-theme') ? '#334155' : '#cbd5e1';
                    const getGridColor = () => body.classList.contains('light-theme') ? 'rgba(15, 23, 42, 0.1)' : 'rgba(255, 255, 255, 0.15)';

                    // Avg Spearman correlation chart
                    const labels = Object.keys(data.spearman_correlation);
                    const avgCorrs = labels.map(k1 => {
                        const vals = Object.values(data.spearman_correlation[k1]).map(v => v.coefficient);
                        return vals.reduce((a, b) => a + b, 0) / vals.length;
                    });

                    if (spearmanChartInstance) spearmanChartInstance.destroy();
                    const ctx = document.getElementById('spearmanChart').getContext('2d');
                    spearmanChartInstance = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: currentLang === 'id' ? 'Rata-rata Korelasi Spearman' : 'Avg Spearman Correlation',
                                data: avgCorrs,
                                backgroundColor: 'rgba(139, 92, 246, 0.6)',
                                borderColor: 'rgba(139, 92, 246, 1)',
                                borderWidth: 1,
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 1,
                                    grid: { color: getGridColor() },
                                    ticks: { color: getTickColor() }
                                },
                                x: {
                                    grid: { display: false },
                                    ticks: { color: getTickColor() }
                                }
                            },
                            plugins: {
                                legend: { labels: { color: getTickColor() } }
                            }
                        }
                    });

                    // Sensitivity Chart
                    const xLabels = data.sensitivity_perturbations.map(p => (p * 100 > 0 ? '+' : '') + (p * 100) + '%');
                    if (sensitivityChartInstance) sensitivityChartInstance.destroy();
                    const ctx2 = document.getElementById('sensitivityChart').getContext('2d');
                    sensitivityChartInstance = new Chart(ctx2, {
                        type: 'line',
                        data: {
                            labels: xLabels,
                            datasets: [
                                {
                                    label: 'MEREC-MABAC',
                                    data: data.sensitivity_results['MEREC-MABAC'],
                                    borderColor: '#6366f1',
                                    backgroundColor: 'transparent',
                                    tension: 0.1
                                },
                                {
                                    label: 'MEREC-OCRA',
                                    data: data.sensitivity_results['MEREC-OCRA'],
                                    borderColor: '#a855f7',
                                    backgroundColor: 'transparent',
                                    tension: 0.1
                                },
                                {
                                    label: 'LOPCOW-MABAC',
                                    data: data.sensitivity_results['LOPCOW-MABAC'],
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'transparent',
                                    tension: 0.1
                                },
                                {
                                    label: 'LOPCOW-OCRA',
                                    data: data.sensitivity_results['LOPCOW-OCRA'],
                                    borderColor: '#10b981',
                                    backgroundColor: 'transparent',
                                    tension: 0.1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    min: 0.8,
                                    max: 1.0,
                                    grid: { color: getGridColor() },
                                    ticks: { color: getTickColor() }
                                },
                                x: {
                                    grid: { color: getGridColor() },
                                    ticks: { color: getTickColor() }
                                }
                            },
                            plugins: {
                                legend: { labels: { color: getTickColor() } }
                            }
                        }
                    });
                }

                function renderRadarChart(countryIdx) {
                    const body = document.body;
                    const getTickColor = () => body.classList.contains('light-theme') ? '#334155' : '#cbd5e1';
                    const getGridColor = () => body.classList.contains('light-theme') ? 'rgba(15, 23, 42, 0.1)' : 'rgba(255, 255, 255, 0.15)';

                    const countryName = currentData.alternatives[countryIdx];
                    const numCriteria = currentData.criteria_names.length;
                    
                    // Radar Labels (e.g. A1, A2, A3)
                    const rawNames = currentData.all_criteria_names_raw || currentData.criteria_names;
                    const labels = currentData.criteria_names.map(name => {
                        const globalIdx = rawNames.indexOf(name);
                        return `A${globalIdx + 1}`;
                    });

                    // 1. Get selected country scores (using MABAC normalized values which are always 0-1)
                    const selectedScores = currentData.intermediates.mabac_merec.N[countryIdx];

                    // 2. Get average scores
                    const averageScores = [];
                    for (let j = 0; j < numCriteria; j++) {
                        let sum = 0;
                        for (let i = 0; i < currentData.alternatives.length; i++) {
                            sum += currentData.intermediates.mabac_merec.N[i][j];
                        }
                        averageScores.push(sum / currentData.alternatives.length);
                    }

                    // Render Radar Chart
                    if (radarChartInstance) radarChartInstance.destroy();
                    const ctx = document.getElementById('radarChart').getContext('2d');
                    
                    radarChartInstance = new Chart(ctx, {
                        type: 'radar',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: countryName,
                                    data: selectedScores,
                                    backgroundColor: 'rgba(168, 85, 247, 0.2)',
                                    borderColor: 'rgba(168, 85, 247, 0.8)',
                                    borderWidth: 2,
                                    pointBackgroundColor: 'rgba(168, 85, 247, 1)'
                                },
                                {
                                    label: currentLang === 'id' ? 'Rata-rata Regional' : 'Regional Average',
                                    data: averageScores,
                                    backgroundColor: 'rgba(148, 163, 184, 0.1)',
                                    borderColor: 'rgba(148, 163, 184, 0.6)',
                                    borderWidth: 1.5,
                                    borderDash: [4, 4],
                                    pointBackgroundColor: 'rgba(148, 163, 184, 1)'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                r: {
                                    grid: { color: getGridColor() },
                                    angleLines: { color: getGridColor() },
                                    ticks: { display: false },
                                    pointLabels: { color: getTickColor(), font: { size: 10 } }
                                }
                            },
                            plugins: {
                                legend: { labels: { color: getTickColor(), font: { size: 11 } } }
                            }
                        }
                    });

                    // Build Radar values table
                    const headerRow = document.getElementById('radar-table-header');
                    const selectedRow = document.getElementById('radar-table-row-selected');
                    const averageRow = document.getElementById('radar-table-row-average');

                    headerRow.innerHTML = `<th>${currentLang === 'id' ? 'Kriteria' : 'Criteria'}</th>` + labels.map(l => `<th>${l}</th>`).join('');
                    selectedRow.innerHTML = `<td class="fw-bold">${countryName}</td>` + selectedScores.map(s => `<td>${s.toFixed(2)}</td>`).join('');
                    averageRow.innerHTML = `<td class="text-muted">${currentLang === 'id' ? 'Rata-rata' : 'Average'}</td>` + averageScores.map(s => `<td class="text-muted">${s.toFixed(2)}</td>`).join('');
                }

                function updateMathWalkthrough() {
                    const selectCountry = document.getElementById('walkthrough-country-select');
                    const selectCriteria = document.getElementById('walkthrough-criteria-select');
                    const selectWeight = document.getElementById('walkthrough-weight-select');
                    const selectRank = document.getElementById('walkthrough-rank-select');
                    
                    if (!selectCountry || !selectCriteria || !selectCountry.value) return;

                    const altIdx = parseInt(selectCountry.value);
                    const critIdx = parseInt(selectCriteria.value);
                    const weightMethod = selectWeight ? selectWeight.value : 'merec';
                    const rankMethod = selectRank ? selectRank.value : 'mabac';
                    
                    const altName = currentData.alternatives[altIdx];
                    const critName = currentData.criteria_names[critIdx];
                    const rawVal = currentData.decision_matrix[altIdx][critIdx];
                    const isSim = currentData.is_simulation;
                    
                    const rawNames = currentData.all_criteria_names_raw || currentData.criteria_names;
                    const globalIdx = rawNames.indexOf(critName);
                    const critCode = `A${globalIdx + 1}`;
                    const type = currentData.criteria_types[critIdx];
                    
                    // Min and Max
                    const colValues = currentData.decision_matrix.map(row => row[critIdx]);
                    const minVal = Math.min(...colValues);
                    const maxVal = Math.max(...colValues);

                    // Build Left Column (Weighting Method)
                    let leftColHtml = '';
                    let w_active = 0;

                    if (weightMethod === 'merec') {
                        // MEREC calculations
                        let merecNormFormula = '';
                        let merecNormCalc = '';
                        const n_merec = currentData.intermediates.N_merec[altIdx][critIdx];
                        if (type === 'benefit') {
                            merecNormFormula = `y_{ij} = \\frac{\\min_{i}(x_{ij})}{x_{ij}}`;
                            merecNormCalc = `y_{${altIdx+1},${critCode}} = \\frac{${minVal.toFixed(3)}}{${rawVal.toFixed(3)}} = ${n_merec.toFixed(6)}`;
                        } else {
                            merecNormFormula = `y_{ij} = \\frac{x_{ij}}{\\max_{i}(x_{ij})}`;
                            merecNormCalc = `y_{${altIdx+1},${critCode}} = \\frac{${rawVal.toFixed(3)}}{${maxVal.toFixed(3)}} = ${n_merec.toFixed(6)}`;
                        }
                        
                        const s_merec = currentData.intermediates.S_merec[altIdx];
                        const s_prime_merec = currentData.intermediates.S_prime_merec[altIdx][critIdx];
                        const e_merec = currentData.intermediates.E_merec[critIdx];
                        w_active = currentData.weights.MEREC[critIdx];

                        const step1Title = currentLang === 'id' ? 'Langkah 1: Nilai Mentah & Tipe' : 'Step 1: Raw Value & Type';
                        const step1Desc = currentLang === 'id' 
                            ? `Alternatif <b>${altName}</b> pada kriteria <b>${critCode} - ${critName}</b> memiliki nilai mentah:` 
                            : `Alternative <b>${altName}</b> on criterion <b>${critCode} - ${critName}</b> has raw value:`;
                        
                        const step2Title = currentLang === 'id' ? 'Langkah 2: Normalisasi Matriks ($y_{ij}$)' : 'Step 2: Matrix Normalization ($y_{ij}$)';
                        const step2Desc = currentLang === 'id' 
                            ? `Rumus normalisasi MEREC untuk kriteria ${type.toUpperCase()}:` 
                            : `MEREC normalization formula for ${type.toUpperCase()} criterion:`;
                        
                        const step3Title = currentLang === 'id' ? "Langkah 3: Skor Kinerja Awal ($S_i$) & Tanpa Kriteria j ($S'_{ij}$)" : "Step 3: Initial aggregate score ($S_i$) & Aggregate without criterion j ($S'_{ij}$)";
                        const step3Desc = currentLang === 'id'
                            ? `Skor agregasi logaritma natural untuk kriteria utuh ($S_i$) dan setelah mengeliminasi kriteria ${critCode} ($S'_{ij}$):`
                            : `Natural logarithm aggregate score for full criteria ($S_i$) and after eliminating criterion ${critCode} ($S'_{ij}$):`;
                        
                        const step4Title = currentLang === 'id' ? 'Langkah 4: Removal Effect ($E_j$) & Bobot Akhir ($w_j$)' : 'Step 4: Removal Effect ($E_j$) & Final Weight ($w_j$)';
                        const step4Desc = currentLang === 'id'
                            ? `Deviasi total seluruh negara ($n=16$) akibat hilangnya kriteria ${critCode} adalah efek eliminasi ($E_j$). Bobot $w_j$ adalah hasil normalisasi $E_j$:`
                            : `Total deviation of all countries ($n=16$) due to the removal of criterion ${critCode} is the elimination effect ($E_j$). Weight $w_j$ is the normalized $E_j$:`;

                        leftColHtml = `
                            <h6 class="text-gradient fw-bold mb-3">${currentLang === 'id' ? '1. Pembobotan MEREC (Multicriteria Removal Effects)' : '1. MEREC Weighting (Multicriteria Removal Effects)'}</h6>
                            
                            <div class="mb-3">
                                <span class="badge bg-secondary mb-2">${step1Title}</span>
                                <p class="small text-muted m-0">${step1Desc}</p>
                                <div class="math-box">
                                    $x_{i,j} = ${rawVal.toFixed(3)}$ | ${currentLang === 'id' ? 'Kriteria bertipe' : 'Criterion type'}: <b>${type.toUpperCase()}</b>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-secondary mb-2">${step2Title}</span>
                                <p class="small text-muted m-0">${step2Desc}</p>
                                <div class="math-box">
                                    $$${merecNormFormula}$$
                                    ${currentLang === 'id' ? 'Dengan' : 'With'} $\\min = ${minVal.toFixed(3)}$ ${currentLang === 'id' ? 'dan' : 'and'} $\\max = ${maxVal.toFixed(3)}$:<br>
                                    $$${merecNormCalc}$$
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-secondary mb-2">${step3Title}</span>
                                <p class="small text-muted m-0">${step3Desc}</p>
                                <div class="math-box">
                                    $S_{\\text{${altName}}} = \\ln\\left(1 + \\frac{1}{${currentData.criteria_names.length}} \\sum_{k=1}^{${currentData.criteria_names.length}} |\\ln(y_{i,k})|\\right) = ${s_merec.toFixed(6)}$<br>
                                    $S'_{i,${critCode}} = \\ln\\left(1 + \\frac{1}{${currentData.criteria_names.length}} \\sum_{k \\neq ${critIdx+1}} |\\ln(y_{i,k})|\\right) = ${s_prime_merec.toFixed(6)}$
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-secondary mb-2">${step4Title}</span>
                                <p class="small text-muted m-0">${step4Desc}</p>
                                <div class="math-box">
                                    $E_{${critCode}} = \\sum_{i=1}^{16} |S'_{i,${critCode}} - S_i| = ${e_merec.toFixed(6)}$<br>
                                    $w_{${critCode}} = \\frac{E_{${critCode}}}{\\sum E} = ${w_active.toFixed(6)}$ ${!isSim ? (currentLang === 'id' ? '<span class="text-info">(Menggunakan bobot baseline jurnal)</span>' : '<span class="text-info">(Using journal baseline weight)</span>') : ''}
                                </div>
                            </div>
                        `;
                    } else {
                        // LOPCOW calculations
                        const sigma_j = currentData.intermediates.sigmas_lopcow[critIdx];
                        const mean_j = currentData.intermediates.means_lopcow[critIdx];
                        const p_ij = currentData.intermediates.P_lopcow[altIdx][critIdx];
                        const e_lopcow_j = currentData.intermediates.E_lopcow[critIdx];
                        const sumE_lopcow = currentData.intermediates.E_lopcow.reduce((a, b) => a + b, 0);
                        w_active = currentData.weights.LOPCOW[critIdx];

                        const step1Title = currentLang === 'id' ? 'Langkah 1: Standar Deviasi ($\\sigma_j$) & Rata-rata ($\\mu_j$)' : 'Step 1: Standard Deviation ($\\sigma_j$) & Mean ($\\mu_j$)';
                        const step1Desc = currentLang === 'id'
                            ? `Standar deviasi dan rata-rata kriteria <b>${critCode} - ${critName}</b> dari seluruh alternatif:`
                            : `Standard deviation and mean of criterion <b>${critCode} - ${critName}</b> across all alternatives:`;
                            
                        const step2Title = currentLang === 'id' ? 'Langkah 2: Transformasi Matriks ($p_{ij}$)' : 'Step 2: Matrix Transformation ($p_{ij}$)';
                        const step2Desc = currentLang === 'id'
                            ? `Transformasi persentase perubahan logaritma deviasi alternatif <b>${altName}</b> terhadap rata-rata:`
                            : `Logarithmic percentage change deviation transformation of alternative <b>${altName}</b> relative to the mean:`;
                            
                        const step3Title = currentLang === 'id' ? 'Langkah 3: Efek Entropi Informasi ($E_j$) & Bobot Akhir ($w_j$)' : 'Step 3: Information Entropy Effect ($E_j$) & Final Weight ($w_j$)';
                        const step3Desc = currentLang === 'id'
                            ? `Menjumlahkan seluruh deviasi baris untuk efek entropi kriteria $E_j$, lalu dibagi dengan total seluruh kriteria:`
                            : `Summing all row deviations for entropy effect $E_j$, then dividing by the total of all criteria:`;

                        leftColHtml = `
                            <h6 class="text-gradient fw-bold mb-3">${currentLang === 'id' ? '1. Pembobotan LOPCOW (Logarithmic Percentage Change-of-Weight)' : '1. LOPCOW Weighting (Logarithmic Percentage Change-of-Weight)'}</h6>
                            
                            <div class="mb-3">
                                <span class="badge bg-secondary mb-2">${step1Title}</span>
                                <p class="small text-muted m-0">${step1Desc}</p>
                                <div class="math-box">
                                    $\\mu_{${critCode}} = \\frac{1}{16}\\sum_{i=1}^{16} x_{i,${critCode}} = ${mean_j.toFixed(4)}$<br>
                                    $\\sigma_{${critCode}} = \\sqrt{\\frac{1}{16}\\sum_{i=1}^{16} (x_{i,${critCode}} - \\mu_{${critCode}})^2} = ${sigma_j.toFixed(4)}$
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-secondary mb-2">${step2Title}</span>
                                <p class="small text-muted m-0">${step2Desc}</p>
                                <div class="math-box">
                                    $$p_{ij} = \\ln\\left(1 + \\frac{|x_{ij} - \\mu_j|}{\\sigma_j}\\right)$$
                                    ${currentLang === 'id' ? 'Substitusi Nilai' : 'Value Substitution'}:<br>
                                    $$p_{${altIdx+1},${critCode}} = \\ln\\left(1 + \\frac{|${rawVal.toFixed(3)} - ${mean_j.toFixed(4)}|}{${sigma_j.toFixed(4)}}\\right) = ${p_ij.toFixed(6)}$$
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-secondary mb-2">${step3Title}</span>
                                <p class="small text-muted m-0">${step3Desc}</p>
                                <div class="math-box">
                                    $E_{${critCode}} = \\sum_{i=1}^{16} p_{i,${critCode}} = ${e_lopcow_j.toFixed(6)}$<br>
                                    $\\sum_{k=1}^{${currentData.criteria_names.length}} E_k = ${sumE_lopcow.toFixed(6)}$<br>
                                    $w_{${critCode}} = \\frac{E_{${critCode}}}{\\sum E} = \\frac{${e_lopcow_j.toFixed(6)}}{${sumE_lopcow.toFixed(6)}} = ${w_active.toFixed(6)}$
                                </div>
                            </div>
                        `;
                    }

                    // Build Right Column (Ranking Method)
                    let rightColHtml = '';

                    if (rankMethod === 'mabac') {
                        const mabacInter = (weightMethod === 'merec') ? currentData.intermediates.mabac_merec : currentData.intermediates.mabac_lopcow;
                        const mabacN = mabacInter.N[altIdx][critIdx];
                        const mabacV = mabacInter.V[altIdx][critIdx];
                        const mabacG = mabacInter.G[critIdx];
                        const mabacQ = mabacInter.Q[altIdx][critIdx];
                        const mabacScore = mabacInter.scores[altIdx];
                        const mabacRank = (weightMethod === 'merec') ? currentData.ranks["MEREC-MABAC"][altIdx] : currentData.ranks["LOPCOW-MABAC"][altIdx];
                        const comboLabel = (weightMethod === 'merec') ? 'MEREC-MABAC' : 'LOPCOW-MABAC';

                        const mabacStep1Title = currentLang === 'id' ? 'Matriks Normalisasi MABAC ($n_{ij}$)' : 'MABAC Normalization Matrix ($n_{ij}$)';
                        const mabacStep1Desc = currentLang === 'id'
                            ? `Normalisasi linear kriteria ${type.toUpperCase()} skala 0-1:`
                            : `Linear normalization of ${type.toUpperCase()} criterion on a 0-1 scale:`;
                        
                        const mabacStep2Title = currentLang === 'id' ? 'Matriks Tertimbang ($v_{ij}$)' : 'Weighted Matrix ($v_{ij}$)';
                        const mabacStep2Desc = currentLang === 'id'
                            ? `Mengalikan nilai normalisasi dengan bobot kriteria terhitung ($w_{${critCode}} = ${w_active.toFixed(6)}$):`
                            : `Multiplying normalized value with calculated criterion weight ($w_{${critCode}} = ${w_active.toFixed(6)}$):`;
                        
                        const mabacStep3Title = currentLang === 'id' ? 'Jarak ke Border Approximation Area ($q_{ij}$) & Skor Akhir' : 'Distance to Border Approximation Area ($q_{ij}$) & Final Score';
                        const mabacStep3Desc = currentLang === 'id'
                            ? `Mengukur jarak ke BAA ($g_j = ${mabacG.toFixed(4)}$) lalu menjumlahkan seluruh elemen kriteria untuk mendapatkan skor $S_i$:`
                            : `Measuring distance to BAA ($g_j = ${mabacG.toFixed(4)}$) then summing all criteria elements to get score $S_i$:`;

                        rightColHtml = `
                            <h6 class="text-gradient fw-bold mb-3">${currentLang === 'id' ? '2. Perankingan Metode MABAC' : '2. MABAC Ranking Method'} (${comboLabel})</h6>
                            
                            <div class="mb-3">
                                <span class="badge bg-primary mb-2">${mabacStep1Title}</span>
                                <p class="small text-muted m-0">${mabacStep1Desc}</p>
                                <div class="math-box">
                                    ${type === 'benefit' 
                                        ? `$$n_{ij} = \\frac{x_{ij} - \\min_i(x_{ij})}{\\max_i(x_{ij}) - \\min_i(x_{ij})}$$` 
                                        : `$$n_{ij} = \\frac{\\max_i(x_{ij}) - x_{ij}}{\\max_i(x_{ij}) - \\min_i(x_{ij})}$$`}
                                    ${currentLang === 'id' ? 'Substitusi nilai dengan' : 'Value substitution with'} $\\min = ${minVal.toFixed(3)}$ ${currentLang === 'id' ? 'dan' : 'and'} $\\max = ${maxVal.toFixed(3)}$:<br>
                                    ${type === 'benefit' 
                                        ? `$$n_{${altIdx+1},${critCode}} = \\frac{${rawVal.toFixed(3)} - ${minVal.toFixed(3)}}{${maxVal.toFixed(3)} - ${minVal.toFixed(3)}} = ${mabacN.toFixed(4)}$$` 
                                        : `$$n_{${altIdx+1},${critCode}} = \\frac{${maxVal.toFixed(3)} - ${rawVal.toFixed(3)}}{${maxVal.toFixed(3)} - ${minVal.toFixed(3)}} = ${mabacN.toFixed(4)}$$`}
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-primary mb-2">${mabacStep2Title}</span>
                                <p class="small text-muted m-0">${mabacStep2Desc}</p>
                                <div class="math-box">
                                    $$v_{ij} = w_j \\times (n_{ij} + 1)$$
                                    $$v_{${altIdx+1},${critCode}} = ${w_active.toFixed(6)} \\times (${mabacN.toFixed(4)} + 1) = ${mabacV.toFixed(4)}$$
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-primary mb-2">${mabacStep3Title}</span>
                                <p class="small text-muted m-0">${mabacStep3Desc}</p>
                                <div class="math-box">
                                    $$q_{ij} = v_{ij} - g_j$$
                                    $$q_{${altIdx+1},${critCode}} = ${mabacV.toFixed(4)} - ${mabacG.toFixed(4)} = ${mabacQ.toFixed(4)}$$<br>
                                    ${currentLang === 'id' ? 'Skor Akhir' : 'Final Score'} $S_{\\text{${altName}}} = \\sum_{j=1}^{${currentData.criteria_names.length}} q_{i,j} =$ <b>${mabacScore.toFixed(4)}</b> (${currentLang === 'id' ? 'Peringkat' : 'Rank'}: <b>#${mabacRank}</b>)
                                </div>
                            </div>
                        `;
                    } else {
                        const ocraInter = (weightMethod === 'merec') ? currentData.intermediates.ocra_merec : currentData.intermediates.ocra_lopcow;
                        const ocraIVal = ocraInter.I_val[altIdx];
                        const ocraOVal = ocraInter.O_val[altIdx];
                        const ocraIBar = ocraInter.I_bar[altIdx];
                        const ocraOBar = ocraInter.O_bar[altIdx];
                        const ocraScore = ocraInter.scores[altIdx];
                        const ocraRank = (weightMethod === 'merec') ? currentData.ranks["MEREC-OCRA"][altIdx] : currentData.ranks["LOPCOW-OCRA"][altIdx];
                        const comboLabel = (weightMethod === 'merec') ? 'MEREC-OCRA' : 'LOPCOW-OCRA';
                        
                        const minI = Math.min(...ocraInter.I_val);
                        const minO = Math.min(...ocraInter.O_val);
                        const sumIBarOBar = ocraInter.I_bar.map((val, idx) => val + ocraInter.O_bar[idx]);
                        const minSum = Math.min(...sumIBarOBar);

                        const ocraStep1Title = currentLang === 'id' ? 'Akumulasi Performansi Relatif ($I_i$ & $O_i$)' : 'Relative Performance Accumulation ($I_i$ & $O_i$)';
                        const ocraStep1Desc = currentLang === 'id'
                            ? `Menjumlahkan performa terbobot untuk kriteria cost ($I$) dan kriteria benefit ($O$):`
                            : `Summing weighted performance for cost criteria ($I$) and benefit criteria ($O$):`;
                        
                        const ocraStep2Title = currentLang === 'id' ? 'Perhitungan Skala Relatif ($\\bar{I}_i$ & $\\bar{O}_i$)' : 'Relative Scale Calculation ($\\bar{I}_i$ & $\\bar{O}_i$)';
                        const ocraStep2Desc = currentLang === 'id'
                            ? `Mengukur deviasi performa terhadap alternatif dengan performa terburuk (minimum):`
                            : `Measuring performance deviation relative to the worst (minimum) performing alternative:`;
                        
                        const ocraStep3Title = currentLang === 'id' ? 'Skor Kompetitif Akhir ($P_i$)' : 'Final Competitive Rating ($P_i$)';
                        const ocraStep3Desc = currentLang === 'id'
                            ? `Menggabungkan skala relatif dan dinormalisasi terhadap nilai minimum:`
                            : `Combining relative scales and normalizing against the minimum value:`;

                        rightColHtml = `
                            <h6 class="text-gradient fw-bold mb-3">${currentLang === 'id' ? '2. Perankingan Metode OCRA' : '2. OCRA Ranking Method'} (${comboLabel})</h6>
                            
                            <div class="mb-3">
                                <span class="badge bg-primary mb-2">${ocraStep1Title}</span>
                                <p class="small text-muted m-0">${ocraStep1Desc}</p>
                                <div class="math-box">
                                    $$I_i = \\sum_{j \\in \\text{cost}} w_j \\frac{\\max_k(x_{kj}) - x_{ij}}{\\min_k(x_{kj})}$$
                                    $$O_i = \\sum_{j \\in \\text{benefit}} w_j \\frac{x_{ij} - \\min_k(x_{kj})}{\\min_k(x_{kj})}$$
                                    ${currentLang === 'id' ? 'Substitusi nilai kriteria' : 'Criterion value substitution'} ${critCode} ${currentLang === 'id' ? 'untuk alternatif' : 'for alternative'} <b>${altName}</b>:<br>
                                    ${type === 'benefit' 
                                        ? `Term benefit ${critCode}: $$w_{${critCode}} \\frac{x_{${altIdx+1},${critCode}} - \\min_k(x_{k,${critCode}})}{\\min_k(x_{k,${critCode}})} = ${w_active.toFixed(6)} \\times \\frac{${rawVal.toFixed(3)} - ${minVal.toFixed(3)}}{${minVal.toFixed(3)}} = ${((w_active * (rawVal - minVal)) / minVal).toFixed(6)}$$` 
                                        : `Term cost ${critCode}: $$w_{${critCode}} \\frac{\\max_k(x_{k,${critCode}}) - x_{${altIdx+1},${critCode}}}{\\min_k(x_{k,${critCode}})} = ${w_active.toFixed(6)} \\times \\frac{${maxVal.toFixed(3)} - ${rawVal.toFixed(3)}}{${minVal.toFixed(3)}} = ${((w_active * (maxVal - rawVal)) / minVal).toFixed(6)}$$`}<br>
                                    ${currentLang === 'id' ? 'Total Akumulasi Seluruh Kriteria' : 'Total Accumulation of All Criteria'}:<br>
                                    $I_{\\text{${altName}}} = ${ocraIVal.toFixed(3)}$ | $O_{\\text{${altName}}} = ${ocraOVal.toFixed(3)}$
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-primary mb-2">${ocraStep2Title}</span>
                                <p class="small text-muted m-0">${ocraStep2Desc}</p>
                                <div class="math-box">
                                    $$\\bar{I}_i = I_i - \\min_k(I_k)$$
                                    $$\\bar{O}_i = O_i - \\min_k(O_k)$$
                                    ${currentLang === 'id' ? 'Substitusi nilai untuk' : 'Value substitution for'} <b>${altName}</b> ${currentLang === 'id' ? 'dengan' : 'with'} $\\min_k(I_k) = ${minI.toFixed(3)}$ ${currentLang === 'id' ? 'dan' : 'and'} $\\min_k(O_k) = ${minO.toFixed(3)}$:<br>
                                    $$\\bar{I}_{\\text{${altName}}} = ${ocraIVal.toFixed(3)} - ${minI.toFixed(3)} = ${ocraIBar.toFixed(3)}$$<br>
                                    $$\\bar{O}_{\\text{${altName}}} = ${ocraOVal.toFixed(3)} - ${minO.toFixed(3)} = ${ocraOBar.toFixed(3)}$$
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="badge bg-primary mb-2">${ocraStep3Title}</span>
                                <p class="small text-muted m-0">${ocraStep3Desc}</p>
                                <div class="math-box">
                                    $$P_i = (\\bar{I}_i + \\bar{O}_i) - \\min_k(\\bar{I}_k + \\bar{O}_k)$$
                                    ${currentLang === 'id' ? 'Substitusi nilai untuk' : 'Value substitution for'} <b>${altName}</b> ${currentLang === 'id' ? 'dengan' : 'with'} $\\min_k(\\bar{I}_k + \\bar{O}_k) = ${minSum.toFixed(3)}$:<br>
                                    $$P_{\\text{${altName}}} = (${ocraIBar.toFixed(3)} + ${ocraOBar.toFixed(3)}) - ${minSum.toFixed(3)} = ${ocraScore.toFixed(3)}$$<br>
                                    ${currentLang === 'id' ? 'Skor Akhir' : 'Final Score'} $P_{\\text{${altName}}} =$ <b>${ocraScore.toFixed(3)}</b> (${currentLang === 'id' ? 'Peringkat' : 'Rank'}: <b>#${ocraRank}</b>)
                                </div>
                            </div>
                        `;
                    }
                    
                    let html = `
                        <div class="row">
                            <div class="col-lg-6 border-end border-secondary pb-3 pb-lg-0">
                                ${leftColHtml}
                            </div>
                            <div class="col-lg-6 ps-lg-4">
                                ${rightColHtml}
                            </div>
                        </div>
                    `;
                    document.getElementById('walkthrough-content').innerHTML = html;
                    
                    // Trigger MathJax typeset to render LaTeX
                    if (window.MathJax) {
                        window.MathJax.typesetPromise();
                    }
                }

                function exportToExcel() {
                    let csv = '\uFEFF'; // UTF-8 BOM
                    csv += (currentLang === 'id' 
                        ? 'Negara / Alternatif,LOPCOW-MABAC,LOPCOW-OCRA,MEREC-MABAC,MEREC-OCRA,Peringkat Jurnal,Peringkat BORDA\n' 
                        : 'Country / Alternative,LOPCOW-MABAC,LOPCOW-OCRA,MEREC-MABAC,MEREC-OCRA,Journal Rank,BORDA Rank\n');
                    
                    const alts = currentData.alternatives;
                    alts.forEach((alt, i) => {
                        const r1 = currentData.ranks['LOPCOW-MABAC'][i];
                        const r2 = currentData.ranks['LOPCOW-OCRA'][i];
                        const r3 = currentData.ranks['MEREC-MABAC'][i];
                        const r4 = currentData.ranks['MEREC-OCRA'][i];
                        const r5 = currentData.ranks['Rank Jurnal'][i];
                        const r6 = currentData.ranks['BORDA'][i];
                        csv += `"${alt}",${r1},${r2},${r3},${r4},${r5},${r6}\n`;
                    });
                    
                    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.setAttribute('download', 'MCDM_SPK_Rankings_Report.csv');
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }

                // Chat Functionality
                function sendChatMessage() {
                    const inputEl = document.getElementById('chat-input');
                    const sendBtn = document.getElementById('chat-send');
                    const historyEl = document.getElementById('chat-history');
                    const query = inputEl.value.trim();

                    if (!query) return;

                    const userMsgDiv = document.createElement('div');
                    userMsgDiv.className = 'mb-3 text-end';
                    userMsgDiv.innerHTML = `<span class="d-inline-block p-2 rounded bg-primary text-white" style="max-width: 80%; text-align: left; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">${escapeHtml(query)}</span>`;
                    historyEl.appendChild(userMsgDiv);
                    historyEl.scrollTop = historyEl.scrollHeight;

                    inputEl.value = '';
                    inputEl.disabled = true;
                    sendBtn.disabled = true;

                    const thinkingDiv = document.createElement('div');
                    thinkingDiv.className = 'mb-3 text-start';
                    thinkingDiv.innerHTML = `<span class="d-inline-block p-2 rounded bg-secondary text-white" style="opacity: 0.7;">${currentLang === 'id' ? '🤔 AI sedang berpikir...' : '🤔 AI is thinking...'}</span>`;
                    historyEl.appendChild(thinkingDiv);
                    historyEl.scrollTop = historyEl.scrollHeight;

                    fetch('chat_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ message: query })
                    })
                    .then(res => res.json())
                    .then(data => {
                        historyEl.removeChild(thinkingDiv);
                        const aiMsgDiv = document.createElement('div');
                        aiMsgDiv.className = 'mb-3 text-start';
                        if (data.error) {
                            aiMsgDiv.innerHTML = `<span class="d-inline-block p-2 rounded bg-danger text-white">${escapeHtml(data.error)}</span>`;
                        } else {
                            const parsedReply = marked.parse(data.reply);
                            const activeTheme = body.classList.contains('light-theme') ? 'bg-light text-dark' : 'bg-dark text-white';
                            aiMsgDiv.innerHTML = `<div class="d-inline-block p-3 rounded border border-secondary ${activeTheme}" style="max-width: 85%; box-shadow: 0 2px 4px rgba(0,0,0,0.15);">${parsedReply}</div>`;
                        }
                        historyEl.appendChild(aiMsgDiv);
                        historyEl.scrollTop = historyEl.scrollHeight;
                    })
                    .catch(err => {
                        if (historyEl.contains(thinkingDiv)) {
                            historyEl.removeChild(thinkingDiv);
                        }
                        const errDiv = document.createElement('div');
                        errDiv.className = 'mb-3 text-start';
                        errDiv.innerHTML = `<span class="d-inline-block p-2 rounded bg-danger text-white">${currentLang === 'id' ? 'Gagal terhubung ke server.' : 'Error connecting to server.'}</span>`;
                        historyEl.appendChild(errDiv);
                        historyEl.scrollTop = historyEl.scrollHeight;
                    })
                    .finally(() => {
                        inputEl.disabled = false;
                        sendBtn.disabled = false;
                        inputEl.focus();
                    });
                }

                function escapeHtml(text) {
                    return text
                        .replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;")
                        .replace(/'/g, "&#039;");
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
