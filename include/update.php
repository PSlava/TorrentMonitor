<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";

if ( ! Sys::checkAuth())
  die(header('Location: ../'));
if (session_id() !== '') session_write_close();

Update::main();  
?>
