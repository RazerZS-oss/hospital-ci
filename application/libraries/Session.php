<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once BASEPATH . 'libraries/Session/Session.php';

#[AllowDynamicProperties]
class Session extends CI_Session {
    public function __construct(array $params = array()) {
        parent::__construct($params);
    }
}
