<?php
/**
 * Main class to manipulate lamedb files
 *
 * @throws LameDb_Exception
 */
abstract class LameDb
{
    /**
     * lamedb version to use if not specified
     */
    const DEFAULT_VERSION = 4;
    /**
     * lamedb to use if not specified
     * @var int
     */
    protected $_versionAccepted = array(4);
    /**
     * Version of loaded lamedb file
     * @var int
     */
    protected $_version;
    /**
     * @var Transponder[]
     */
    protected $_transponders = array();
    /**
     * @var Service[]
     */
    protected $_services = array();
    /**
     * @var array
     */
    protected $_mapName = array();

    /**
     * Create
     *
     * @static
     * @throws LameDb_Exception
     * @param int $version
     * @return LameDb
     */
    static function factory($version = null)
    {
        if (is_null($version)) {
            $version = self::DEFAULT_VERSION;
        }
        switch ($version)
        {
            case 4:
                return new LameDb4($version);
            default:
                throw new LameDb_Exception("lamedb version '$version' is not unsupported.");
        }
    }

    /**
     * Load existing lamedb file and return appropriate lamedb class
     *
     * @static
     * @param  string|stream $source
     * @return LameDb
     */
    static function factoryFromFile($source)
    {
        if (is_string($source)) {
            $source = fopen($source, "r");
        }
        // check header and obtain version info
        if (false !== ($s = trim(fgets($source)))) {
            $version = self::parseVersion($s);
            if (false === $version) {
                throw new LameDb_Exception("wrong header '$s'");
            }
        } else {
            throw new LameDb_Exception("lamedb file is empty");
        }
        // factory the correct class
        $lamedb = self::factory($version);
        $lamedb->load($source, false);
        return $lamedb;
    }

    /**
     * Parse first line
     *
     * This method should never be overridden by subclasses, should be fixed here instead.
     *
     * @static
     * @param  string $line
     * @return int FALSE if problem
     */
    final static function parseVersion($line)
    {
        $version = array();
        if (!preg_match("@eDVB services /(\\d)/@", $line, $version)) {
            return false;
        }
        return $version[1];
    }

    /**
     * Load content of lamedb file
     *
     * @abstract
     * @param  string|stream $source
     * @param  bool          $checkVersion
     * @return void
     */
    function load($source, $checkVersion = true)
    {
        if (is_string($source)) {
            $source = fopen($source, "r");
            // make sure version will be checked
            $checkVersion = true;
        }
        if ($checkVersion) {
            if (false !== ($s = trim($this->_fgets($source)))) {
                $version = self::parseVersion($s);
                if (false === $version) {
                    throw new LameDb_Exception("wrong header '$s'");
                }
                if (in_array($version, $this->_versionAccepted)) {
                    throw new LameDb_Exception("you can't load lamedb version '$version' with class " . get_class($this));
                }
            } else {
                throw new LameDb_Exception("lamedb file is empty");
            }
        }

        // load transponders
        $this->_loadTransponders($source);
        // load services
        $this->_loadServices($source);
    }

    public function exportToTxt($fn)
    {
        $f = fopen($fn, "w");
        foreach ($this->_services as $k => $service) {
            $t = $this->getTransponder($service->getTransponderKey());
            //var_dump($t);die;
            $data = array(
                //$service->networkId,
                $service->packageName,
                $service->name,
                $service->sid,
                implode(",", array($t->getSatelliteName(), $t->frequency, $t->getPolarizationCode(), $t->getFecStr()))
            );
            fputs($f, implode(";", $data)."\n");
        }
        fclose($f);
    }

    /**
     * @param string $packageName
     * @param string $serviceName
     * @return string[]
     */
    public function getKeyByPackageServiceName($packageName, $serviceName)
    {
        $packageName = mb_strtoupper($packageName);
        $serviceName = mb_strtoupper($serviceName);
        if (array_key_exists($serviceName, $this->_mapName)) {
            // found based on name
            $a1 = $this->_mapName[$serviceName];
            if (count($a1)==1) {
                // there is only 1 package
                return current($a1);
            } else {
                // check also package name
                if (array_key_exists($packageName, $a1)) {
                    return $a1[$packageName];
                } else {
                    // package not found, using first package found
                    return current($a1);
                }
            }
        }
        // not found
        echo "<b>$serviceName/$packageName:</b> ".$this->getSimilar($serviceName).'<br />';
        return false;
    }

    public function getSimilar($serviceName)
    {
        $res = array();
        $sound = soundex($serviceName);
        foreach ($this->_mapName as $serviceName=>$a) {
            if ($sound==soundex($serviceName)) {
                foreach ($a as $packageName=>$b)
                $res[] = "$packageName/$serviceName";
            }
        }
        return implode(",", $res);
    }

    public function getKeyByFrequency($freq)
    {
        // TODO not clear now
        $freq = strtoupper(trim($freq));
        foreach ($this->_services as $key=>$service) {
            $t = $this->getTransponder($service->getTransponderKey());
            $fkey = implode(",", array($t->getSatelliteName(), $t->frequency, $t->getPolarizationCode(), $t->getFecStr()));
            if ($fkey==$freq) {
                return $key;
            }
        }


        return false;
    }

    public function getService($key)
    {
        if (array_key_exists($key, $this->_services)) {
            return $this->_services[$key];
        } else {
            return false;
        }
    }

    /**
     * @param string $key
     * @return Transponder
     */
    public function getTransponder($key)
    {
        if (array_key_exists($key, $this->_transponders)) {
            return $this->_transponders[$key];
        } else {
            return false;
        }
    }

    /**
     * Default constructor
     *
     * @param int $version
     */
    final public function __construct($version)
    {
        $this->_version = $version;
    }

    protected function _loadTransponders($source)
    {
        // find begin of transponders
        while (false !== ($s = trim($this->_fgets($source)))) {
            if ($s == "transponders") {
                break;
            }
        }
        // read transponders
        while (false !== ($l1 = trim($this->_fgets($source)))) {
            if ($l1 == "end") {
                break;
            }
            // TODO maybe we need to loop until '/' found
            $data = trim($this->_fgets($source));
            $slash = trim($this->_fgets($source));
            if ($slash != '/') {
                throw new LameDb_Exception("transponder definition does not end with '/''");
            }

            // parse and store transponder
            $transponder = $this->_createTransponder(array($l1, $data));
            $this->_transponders[$transponder->getKey()] = $transponder;
        }
    }

    protected function _fgets($source)
    {
        $s = fgets($source);
        // TODO non-breaking space, this should be improved
        $s = str_replace(chr(194).chr(134), '', $s);
        $s = str_replace(chr(194).chr(135), '', $s);
        return $s;
    }

    protected function _loadServices($source)
    {
        // find begin of service definition
        while (false !== ($s = trim($this->_fgets($source)))) {
            if ($s == "services") {
                break;
            }
        }
        // read services
        while (false !== ($l1 = trim($this->_fgets($source)))) {
            if ($l1 == "end") {
                break;
            }
            $serviceName = trim($this->_fgets($source));

            //echo $serviceName.": ".mb_detect_encoding($serviceName)."<br/>";

            $l3 = $this->_fgets($source);

            $service = $this->_createService(array($l1, $serviceName, $l3));

            // store service
            $key = $service->getKey();
            if (!array_key_exists($key, $this->_services)) {
                $this->_services[$key] = $service;
            }

            // store mapping to judge service by name
            $name = $service->name;
            $packageName = $service->packageName;
            $name = strtoupper($name);
            $packageName = strtoupper($packageName);
            if (array_key_exists($name, $this->_mapName)) {
                if (array_key_exists($packageName, $this->_mapName[$name])) {
                    $this->_mapName[$name][$packageName][] = $key;
                } else {
                    $this->_mapName[$name][$packageName] = array($key);
                }
            } else {
                $this->_mapName[$name] = array($packageName=>array($key));
            }
        }
    }

    /**
     * @abstract
     * @param  array $data
     * @return Transponder
     */
    abstract protected function _createTransponder($data);

    /**
     * @abstract
     * @param  array $data
     * @return Service
     */
    abstract protected function _createService($data);
}

/**
 * Specialized exception to recognize problems raised in lamedb classes
 */
class LameDb_Exception extends Exception
{
}

class Transponder
{
    public $namespace;
    public $networkId;
    public $transporterId;
    public $frequency;
    public $symbol_rate;
    public $polarization;
    public $fec;
    public $position;
    public $inversion;
    public $flags;
    public $system;
    public $modulation;
    public $rolloff;
    public $pilot;

    protected $_mapPolarization = array(
        0=>array('H', 'Horizontal'),
        1=>array('V', 'Vertical'),
        2=>array('L', 'Circular Left'),
        3=>array('R', 'Circular Right'),
    );
    protected $_mapFEC = array(
        0=>'Auto',
        1=>'1/2',
        2=>'2/3',
        3=>'3/4',
        4=>'5/6',
        5=>'7/8',
        6=>'8/9',
        7=>'3/5',
        8=>'4/5',
        9=>'9/10'
    );

    /**
     * Return unique key for transponder
     * @return string
     */
    public function getKey()
    {
        return strtoupper($this->namespace . ':' . $this->transporterId . ':' . $this->networkId);
    }

    public function getPolarizationCode()
    {
        return $this->_mapPolarization[$this->polarization][0];
    }
    public function getPolarizationStr()
    {
        return $this->_mapPolarization[$this->polarization][1];
    }
    public function getFecStr()
    {
        return $this->_mapFEC[$this->fec];
    }
    public function getSatelliteName()
    {
        // TODO we need to support loading of satellites.xml
        return $this->position;
    }
}

class Service
{
    public $myName;
    public $name;
    public $packageName;
    public $sid;
    public $namespace;
    public $transporterId;
    public $networkId;
    public $serviceType; // 1: TV, 2: Radio, 25: HDTV, other:data
    public $hmm2;

    public function getKey()
    {
        // TODO normalize namespace and sid
        return strtoupper($this->namespace . '#' . $this->sid . '#' . $this->transporterId);
        //return $this->packageName . "#" . $this->name;
    }

    public function getTransponderKey()
    {
//        var_dump($this);
        return strtoupper($this->namespace . ':' . $this->transporterId . ':' . $this->networkId);
    }
}

class LameDb4 extends LameDb
{
    /**
     * lamedb to use if not specified
     * @var int
     */
    protected $_versionsAccepted = array(4);

    protected function _createTransponder($data)
    {
        // get parameters
        list($line1, $line2) = $data;

        //
        $t = new Transponder();
        // data from transporter key
        //echo $line1."<br>";
        list($t->namespace, $t->transporterId, $t->networkId) = explode(":", $line1);
        // other data
        $line2 = trim($line2);
        switch ($line2[0]) {
            case 's':
                $a = explode(":", trim(substr($line2,1)));
                if (count($a)>11) {
                    throw new LameDb_Exception("too many parameters in transponder data '$line2'");
                }
                @list(
                    $t->frequency,
                    $t->symbol_rate,
                    $t->polarization,
                    $t->fec,
                    $t->position,
                    $t->inversion,
                    $t->flags,
                    $t->system,
                    $t->modulation,
                    $t->rolloff,
                    $t->pilot
                    ) = $a;
                break;
            default:
                throw new LameDb_Exception("unknown transponder data line '$line2'");
        }

        return $t;
    }

    protected function _createService($data)
    {
        // get parameters
        list($l1, $serviceName, $l3) = $data;

        // check hex values http://www.mathsisfun.com/binary-decimal-hexadecimal-converter.html
        $packageName = "no-package";
        $l1 = explode(":", trim($l1));
        $l3 = explode(",", trim($l3));
        // TODO don't know about cacheIDs
        foreach ($l3 as &$v) {
            if ($v[0] == "p" && $v[1] == ":") {
                $a = explode(",", $v);
                $packageName = trim(substr($a[0], 2));
                if (!$packageName) {
                    $packageName = "no-provider";
                }
                $v = null;
            }
        }

        $service = new Service();
        $service->name = $serviceName;
        $service->packageName = $packageName;
        $service->sid = $l1[0];
        $service->namespace = $l1[1];
        $service->transporterId = $l1[2];
        $service->networkId = $l1[3];
        $service->serviceType = $l1[4];
        $service->hmm2 = $l1[5];

        return $service;
    }
}