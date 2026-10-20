<?php
$path='/source/plugin.json';
$old=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
$new=json_decode(file_get_contents('/source/packaging/plugin-release.json'),true,flags:JSON_THROW_ON_ERROR);
if(isset($old['meta']))$new['meta']=$old['meta'];
file_put_contents($path,json_encode($new,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
