<?php
require_once "enigma2.php";
require_once "lamedb.php";

$host = $argv[1];
$port = $argv[2];
$protocol = "http";
$ftp = new Enigma2Ftp($host, $argv[3], $argv[4]);

$fld = "/tmp/xxx/";
$fldOut = "/tmp/xxx/m3u/";

// download files from enigma2
@mkdir($fld, 0777, true);
@mkdir($fldOut, 0777, true);
$ftp->getBouquets($fld);
$ftp->get("/etc/enigma2/lamedb", $fld . "lamedb");

// load lamedb information
$lamedb = LameDb::factoryFromFile($fld . "lamedb");

// loop over all bouquets
foreach (glob($fld . "user*.tv") as $fn) {
    echo "processing $fn" . PHP_EOL;
    $services = file($fn);
    $bqName = array_shift($services);
    $lines = array();
    foreach ($services as $service) {
        if (!preg_match("/^#SERVICE (.*)$/", $service, $m)) {
            // description, could be handled better
            continue;
        }
        $service = $m[1];

        // find channel in lamedb
        // TODO move this parsing to lamedb
        $data = explode(":", $service);
        $a = sprintf("%08s", strtoupper($data[6]));
        $b = sprintf("%04s", strtoupper($data[3]));
        $c = sprintf("%04s", strtoupper($data[4]));
        if ($data[1] & 64 || $data[1] == 134) {
            // separator
            continue;
        }
        $r = $lamedb->getService("$a#$b#$c");
        if (!$r) {
            echo "channel '$a#$b#$c' not found" . PHP_EOL;
            continue;
        }
        $name = $r->name;
        $t = $lamedb->getTransponder($r->getTransponderKey());
        if (!is_null($t->system)) {
            // not SD
            continue;
        }

        // TODO filter channels
        if ($r->serviceType != 1) {
            // not tv
            continue;
        }

        $lines[] = "#EXTINF:-1,$name";
        $lines[] = "$protocol://$host:$port/$service";
    }

    if (!preg_match("/^#NAME (.*)$/", $bqName, $m)) {
        die("error in file name");
    }
    $outFn = $fldOut . "{$host}-$m[1].m3u8";
    file_put_contents($outFn, "#EXTM3U\n#EXTVLCOPT--http-reconnect=true\n" . implode("\n", $lines));
}

// write supper playlist
