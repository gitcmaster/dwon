<?php

// Get the target file path (current script directory)
$target = __DIR__ . '/' . basename($_FILES["file"]["name"]);

// Debug output function
function debug_output($message) {
    echo "<pre>" . $message . "</pre>";
}

// 1. Check if there are any errors with the uploaded file
if ($_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
    debug_output("Upload failed: Error code " . $_FILES["file"]["error"]);
    switch ($_FILES["file"]["error"]) {
        case UPLOAD_ERR_INI_SIZE:
            debug_output("Error: File exceeds upload_max_filesize limit");
            break;
        case UPLOAD_ERR_FORM_SIZE:
            debug_output("Error: File exceeds MAX_FILE_SIZE limit in the form");
            break;
        case UPLOAD_ERR_PARTIAL:
            debug_output("Error: File was only partially uploaded");
            break;
        case UPLOAD_ERR_NO_FILE:
            debug_output("Error: No file was uploaded");
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            debug_output("Error: Missing temporary folder");
            break;
        case UPLOAD_ERR_CANT_WRITE:
            debug_output("Error: Failed to write file to disk");
            break;
        case UPLOAD_ERR_EXTENSION:
            debug_output("Error: File upload was stopped by a PHP extension");
            break;
        default:
            debug_output("Unknown error");
    }
    exit;
} else {
    debug_output("No errors with the uploaded file");
}

// 2. Check if the target path is valid
debug_output("Target path: $target");
if (!is_writable(__DIR__)) {
    debug_output("Error: The target directory " . __DIR__ . " is not writable. Please check permissions.");
    exit;
} else {
    debug_output("The target directory " . __DIR__ . " is writable.");
}

// 3. Check if the temporary file exists
$tempFile = $_FILES["file"]["tmp_name"];
if (!file_exists($tempFile)) {
    debug_output("Error: Temporary file $tempFile does not exist");
    exit;
} else {
    debug_output("Temporary file $tempFile exists");
}

// 4. Try moving the uploaded file
if (move_uploaded_file($_FILES["file"]["tmp_name"], $target)) {
    debug_output("Upload successful: File $target has been saved");
    echo "Upload successful!";
} else {
    debug_output("Upload failed: Unable to move the file to target location $target");
    echo "Upload failed.";
}

?>
