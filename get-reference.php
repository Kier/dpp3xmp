<?php

require_once('Dpp2Xmp.php');

$converter = new Dpp2Xmp(500);
$converter->buildReference(new SplFileInfo($argv[1]), null, $error);
if ($error)
{
	$converter->write($error);
}
exit;