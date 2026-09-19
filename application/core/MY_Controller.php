<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/* load the MX_Controller class */
require APPPATH . 'third_party/MX/Controller.php';

#[AllowDynamicProperties]
class MY_Controller extends MX_Controller {

    public function __construct() {
        parent::__construct();
    }
}
