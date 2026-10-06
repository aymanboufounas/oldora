<?php
session_start();
session_unset();    // تفريغ المتغيرات
session_destroy();  // تدمير الجلسة بالكامل
header('location: login-user.php');
exit();
?>