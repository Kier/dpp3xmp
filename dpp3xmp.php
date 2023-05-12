<?php

require_once('src/Dpp3Xmp.php');

$converter = new Dpp3Xmp(500);
$path = $argv[1];

if (!file_exists($path))
{
	$converter->write("Unable to read specified file path {$path}");
	exit;
}

if (is_file($path))
{
	$file = new SplFileInfo($path);
	$converter->buildReference($file, null, $error);
	if ($error)
	{
		$converter->write($error);
	}
	exit;
}

$converter->convertDPPtoXMP($path);
exit;

// TODO: option to skip files that already have .xmp
// TODO: ability to take wildcards?