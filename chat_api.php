<?php
header('Content-Type: application/json');
require_once 'config.php';

// Retrieve user message
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = $input['message'] ?? '';

if (empty($userMessage)) {
    echo json_encode(['error' => 'Message is empty']);
    exit();
}

// Get the latest analysis results from DB
try {
    $stmt = $pdo->query("SELECT raw_results FROM mcdm_history ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $rawResults = $row ? $row['raw_results'] : '';
} catch (Exception $e) {
    $rawResults = '';
}

if (empty($rawResults)) {
    echo json_encode(['error' => 'No analysis data found in history. Please run the analysis first.']);
    exit();
}

// Define prompt
$prompt = "You are an expert MCDM Consultant. Below is the JSON result of a hybrid MCDM analysis " .
          "comparing weighting methods (MEREC, LOPCOW) and ranking methods (MABAC, OCRA). " .
          "Alternatives are A1 to A16, representing these countries in order: " .
          "Iraq, Turkey, Lebanon, Israel, Saudi Arabia, Oman, UAE, Qatar, Kuwait, Bahrain, Egypt, India, Pakistan, Bangladesh, Sri Lanka, Nepal.\n\n" .
          "Analysis JSON:\n" . $rawResults . "\n\n" .
          "User Question:\n" . $userMessage . "\n\n" .
          "Provide a clear, analytical, and professional response. If the user asks in Indonesian, reply in Indonesian. " .
          "If they ask in English, reply in English. Focus on interpreting weights, rankings, consistency (Spearman), and stability.";

// Call Gemini API
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
    $reply = $responseData['candidates'][0]['content']['parts'][0]['text'];
    echo json_encode(['reply' => $reply]);
} else {
    // Handle error or quota issues
    $errMsg = $responseData['error']['message'] ?? 'Unknown API Error';
    echo json_encode(['error' => 'AI assistant unavailable: ' . $errMsg]);
}
