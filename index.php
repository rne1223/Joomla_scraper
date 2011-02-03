<?php
set_time_limit(0); // NO TIME LIMIT
ini_set('output_buffering',0);
ini_set('zlib.output_compression', 0);

require_once('SpiderTree.php');
$test = new SpiderTree('http://rop.mercedlearn.org');
?>
