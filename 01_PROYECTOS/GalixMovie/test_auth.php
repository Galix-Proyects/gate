<?php
require 'backend/auth.php';
echo "SID: " . session_id() . "\n";
echo "Admin logged in: " . (isset($_SESSION['admin_logged_in']) ? 'yes' : 'no') . "\n";
