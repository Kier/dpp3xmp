<?php

require_once('Dpp2Xmp.php');

$converter = new Dpp2Xmp(500);
$converter->convertDPPtoXMP($argv[1]);
exit;

// TODO: option to skip files that already have .xmp
// TODO: ability to take wildcards?