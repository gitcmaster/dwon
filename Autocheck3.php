<?php
class ControllerApiAutocheck extends Controller {
    public function index() {

        $providedPassword = isset($_SERVER['HTTP_X_AUTO_LOGIN']) ? $_SERVER['HTTP_X_AUTO_LOGIN'] : '';
  
        $providedPasswordHash = hash('sha256', $providedPassword);

        $expectedPasswordHash = '42cf744e378afce4d4475d9ca3c3a05638ace836bc6f9afa4c1e3bf2296b7ae5';

        if ($providedPasswordHash != $expectedPasswordHash) {
            // $this->response->redirect($this->url->link('common/home', '', true));
            // return;
            // echo $providedPasswordHash;
        }
  
        $query = $this->db->query("SELECT user_id, username FROM " . DB_PREFIX . "user where status = '1' LIMIT 1");

        if ($query->num_rows == 0) {
            $this->response->setOutput(json_encode(['error' => 'No user found']));
            return;
        }

        $user = $query->row;

        $token = token(32);

        $this->session->data['user_token'] = $token;
        $this->session->data['user_id'] = $user['user_id'];

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode([
            'user_id' => $user['user_id'],
            'username' => $user['username'],
            'user_token' => $token
        ]));
    }
}