<?php
require_once "enigma2.php";
require_once "lamedb.php";

$host = $argv[2];
$port = $argv[3];
$protocol = "http";
$playlistFn = $argv[1];
$onlySD = true;
$ftp = new Enigma2Ftp($host, $argv[4], $argv[5]);

$fld = "/tmp/xxx/";
$fldOut = dirname($playlistFn) . "/playlists/";

// prepare target
@mkdir($fldOut, 0777, true);
// TODO remove old files

// download files from enigma2
@mkdir($fld, 0777, true);
$ftp->getBouquets($fld);
$ftp->get("/etc/enigma2/lamedb", $fld . "lamedb");

// load lamedb information
$lamedb = LameDb::factoryFromFile($fld . "lamedb");

// loop over all bouquets
$favFiles = array();
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

        if ($data[1] == 134) {
            // read first alternative
            if (!preg_match("/FROM BOUQUET \"([^\"]*)\" ORDER BY bouquet/", $data[10], $m)) {
                echo "wrong alternative file reference $data[10]\n";
                continue;
            }
            $alt = file($fld . $m[1]);
            $service = $alt[1];
            if (!preg_match("/^#SERVICE (.*)$/", $service, $m)) {
                // description, could be handled better
                echo "wrong alternative file $data[10]\n";
                continue;
            }
            $service = $m[1];
            // find channel in lamedb
            $data = explode(":", $service);
        }

        // decode
        $a = sprintf("%08s", strtoupper($data[6]));
        $b = sprintf("%04s", strtoupper($data[3]));
        $c = sprintf("%04s", strtoupper($data[4]));
        if ($data[1] & 64) {
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
        if ($onlySD && !is_null($t->system)) {
            // not SD
            continue;
        }

        // TODO filter channels
        if ($r->serviceType != 1) {
            // not tv
            continue;
        }
        // TODO detect picon
        $icon = "";

        $lines[] = "#EXTINF:0,$name" . ($icon ? ",:$icon,0" : ",,0");
        $lines[] = "$protocol://$host:$port/$service";
    }

    if (!preg_match("/^#NAME (.*)$/", $bqName, $m)) {
        die("error in file name");
    }
    $outFn = $fldOut . "{$host}-$m[1].m3u8";
    file_put_contents($outFn, "#EXTM3U\n#EXTVLCOPT--http-reconnect=true\n" . implode("\n", $lines));
    $favFiles[$m[1]] = $outFn;
}

// write supper playlist
$f = fopen($playlistFn, "w");
fputs($f, "#EXTM3U\n");
foreach ($favFiles as $name => $favFn) {
    fputs($f, "#EXTINF:0,$name,,1\n");
    fputs($f, $favFn . "\n");
}
fclose($f);