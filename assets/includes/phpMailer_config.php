<?php
// +------------------------------------------------------------------------+
// | @author Aminul Islam
// | @author_url 1: http://www.vrbel.com
// | @author_email: admin@vrbel.com
// +------------------------------------------------------------------------+
// | Project Management
// | Copyright (c) 2022 Vrbel. All rights reserved.
// +------------------------------------------------------------------------+
require 'assets/libraries/PHPMailer-Master/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer;
