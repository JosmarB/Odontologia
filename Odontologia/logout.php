<?php
session_start();
session_unset();
session_destroy();
header("Location: /Odontologia/templates/login.php");
exit;
