<?php
session_start();
session_unset();
session_destroy();
header("Location: secpanel111.php");
exit();
?>
