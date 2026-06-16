<?php

// Database Setup
$dbFile = __DIR__ . '/mcdm.db';
$dbExists = file_exists($dbFile);
$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!$dbExists) {
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($schema);
}

$apiKey = "YOUR_GEMINI_API_KEY"; // Placeholder for Gemini API Key

function callAIConsultant($jsonData, $apiKey) {
    if ($apiKey == "YOUR_GEMINI_API_KEY") {
        return "Please configure your Gemini API Key in processor.php to get AI Consultant insights.";
    }

    $prompt = "Act as an MCDM Consultant. I have analyzed a dataset using MEREC, LOPCOW, MABAC, and OCRA. " .
              "The results and Spearman correlations are as follows in JSON: " . $jsonData . 
              " Please provide a short, professional, and human-readable summary explaining why the winning combination (best_combination) was chosen and interpret the consistency of the methods.";
              
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;
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
    if(isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        return $responseData['candidates'][0]['content']['parts'][0]['text'];
    }
    return "Failed to retrieve AI analysis. " . $response;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['csvFile']) && $_FILES['csvFile']['error'] == 0) {
        $file = $_FILES['csvFile']['tmp_name'];
        $datasetName = $_FILES['csvFile']['name'];
        
        $criteriaTypesRaw = $_POST['criteriaTypes'];
        $criteriaTypes = array_map('trim', explode(',', $criteriaTypesRaw));

        $matrix = [];
        $alternatives = [];
        $criteriaNames = [];
        
        if (($handle = fopen($file, "r")) !== FALSE) {
            $row = 0;
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row == 0) {
                    $criteriaNames = array_slice($data, 1);
                } else {
                    $alternatives[] = $data[0];
                    $matrix[] = array_map('floatval', array_slice($data, 1));
                }
                $row++;
            }
            fclose($handle);
        }
        
        $inputData = [
            "criteria_types" => $criteriaTypes,
            "alternatives" => $alternatives,
            "matrix" => $matrix,
            "criteria_names" => $criteriaNames
        ];
        
        $jsonInput = json_encode($inputData);
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );
        
        $process = proc_open('python mcdm_engine.py', $descriptorspec, $pipes);
        $pythonOutput = "";
        if (is_resource($process)) {
            fwrite($pipes[0], $jsonInput);
            fclose($pipes[0]);
            
            $pythonOutput = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            
            proc_close($process);
        }
        
        if (empty($pythonOutput)) {
            die("Error executing Python script. Stderr: " . htmlspecialchars($stderr));
        }
        
        $results = json_decode($pythonOutput, true);
        if (isset($results['error'])) {
            die("Python Engine Error: " . htmlspecialchars($results['error']));
        }
        
        // Call AI
        $aiSummary = callAIConsultant($pythonOutput, $apiKey);
        
        // Save to DB
        $stmt = $pdo->prepare("INSERT INTO mcdm_history (dataset_name, best_combination, spearman_scores, raw_results, ai_consultant_summary) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $datasetName,
            $results['best_combination'],
            json_encode($results['spearman_correlation']),
            $pythonOutput,
            $aiSummary
        ]);
        
    } else {
        die("Please upload a valid CSV file.");
    }
} else {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCDM Results Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Convert Markdown from Gemini to HTML if needed -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
</head>
<body>
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-gradient">Analysis Results</h2>
            <a href="index.php" class="btn btn-outline-light">New Analysis</a>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="glass-card ai-consultant-card p-4">
                    <h4 class="fw-bold text-gradient mb-3">🤖 AI Consultant Insights</h4>
                    <div id="ai-content"><?php echo htmlspecialchars($aiSummary); ?></div>
                    <script>
                        document.getElementById('ai-content').innerHTML = marked.parse(document.getElementById('ai-content').textContent);
                    </script>
                    <div class="mt-3">
                        <span class="badge bg-primary">Best Combination: <?php echo htmlspecialchars($results['best_combination']); ?></span>
                        <span class="badge bg-secondary">Stability Score: <?php echo number_format($results['stability_score'], 4); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="glass-card h-100">
                    <h5 class="fw-bold mb-3">Criteria Weights</h5>
                    <div class="table-responsive">
                        <table class="table table-glass">
                            <thead>
                                <tr>
                                    <th>Criteria</th>
                                    <th>MEREC</th>
                                    <th>LOPCOW</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($criteriaNames as $i => $name): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($name); ?></td>
                                    <td><?php echo number_format($results['weights']['MEREC'][$i], 4); ?></td>
                                    <td><?php echo number_format($results['weights']['LOPCOW'][$i], 4); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="glass-card h-100">
                    <h5 class="fw-bold mb-3">Spearman Correlation Heatmap</h5>
                    <canvas id="spearmanChart"></canvas>
                </div>
            </div>
        </div>

        <div class="glass-card mb-4">
            <h5 class="fw-bold mb-3">Alternative Rankings</h5>
            <div class="table-responsive">
                <table class="table table-glass">
                    <thead>
                        <tr>
                            <th>Alternative</th>
                            <th>MEREC-MABAC</th>
                            <th>MEREC-OCRA</th>
                            <th>LOPCOW-MABAC</th>
                            <th>LOPCOW-OCRA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($alternatives as $i => $alt): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($alt); ?></td>
                            <td><?php echo $results['ranks']['MEREC-MABAC'][$i]; ?></td>
                            <td><?php echo $results['ranks']['MEREC-OCRA'][$i]; ?></td>
                            <td><?php echo $results['ranks']['LOPCOW-MABAC'][$i]; ?></td>
                            <td><?php echo $results['ranks']['LOPCOW-OCRA'][$i]; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            // Prepare Data for Chart.js
            const spearmanData = <?php echo json_encode($results['spearman_correlation']); ?>;
            const labels = Object.keys(spearmanData);
            const chartLabels = labels.map((name, idx) => `C${idx + 1}`);
            
            // Bar chart showing average correlation per combination
            const avgCorrs = labels.map(k1 => {
                const vals = Object.values(spearmanData[k1]);
                return vals.reduce((a, b) => a + b, 0) / vals.length;
            });

            const ctx = document.getElementById('spearmanChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Avg Spearman Correlation',
                        data: avgCorrs,
                        backgroundColor: 'rgba(139, 92, 246, 0.5)',
                        borderColor: 'rgba(139, 92, 246, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 1,
                            grid: { color: 'rgba(255,255,255,0.1)' },
                            ticks: { color: '#94a3b8' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#94a3b8' }
                        }
                    },
                    plugins: {
                        legend: { labels: { color: '#f8fafc' } },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const idx = context[0].dataIndex;
                                    return labels[idx];
                                }
                            }
                        }
                    }
                }
            });
        </script>
    </div>
</body>
</html>
