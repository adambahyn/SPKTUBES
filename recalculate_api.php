<?php
header('Content-Type: application/json');

$input = file_get_contents('php://input');

$descriptorspec = array(
    0 => array("pipe", "r"),
    1 => array("pipe", "w"),
    2 => array("pipe", "w")
);

$process = proc_open('python process_files.py --simulation', $descriptorspec, $pipes);
$pythonOutput = "";
$stderr = "";

if (is_resource($process)) {
    if (!empty($input)) {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    
    $pythonOutput = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    
    proc_close($process);
}

if (empty($pythonOutput)) {
    echo json_encode(["error" => "Error executing Python script. Stderr: " . $stderr]);
} else {
    echo $pythonOutput;
}
