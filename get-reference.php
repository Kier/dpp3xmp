<?php

require_once('Dpp3Xmp.php');

$converter = new Dpp3Xmp(500);
$converter->buildReference(new SplFileInfo($argv[1]), null, $error);
if ($error)
{
	$converter->write($error);
}
exit;