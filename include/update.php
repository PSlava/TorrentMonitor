<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";

if ( ! Sys::checkAuth())
  die(header('Location: ../'));

Update::main();  
?>
