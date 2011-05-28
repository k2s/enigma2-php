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
    protected $_transponders = array();
    protected $_services = array();
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
        if (!preg_match("@eDVB services /(\d)/@", $line, $version)) {
            return false;
        }
        return $version[1];
    }

    /**
     * Load content of lamedb file
     *
     * @abstract
     * @param  string|stream $source
     * @param  boolean       $checkVersion
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
            if (false !== ($s = trim(fgets($source)))) {
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
            //var_dump($service);die;
            fputs($f, "$service->networkId,$service->packageName,$service->name,$service->sid\n");
        }
        fclose($f);
    }

    public function getKeyByPackageServiceName($packageName, $serviceName)
    {
        $mapKey = strtoupper($packageName."#".$serviceName);
        if (!array_key_exists($mapKey, $this->_mapName)) {
            echo "not found $mapKey, alternatives ".implode(", ", $this->getSimilar($packageName, $serviceName))."</b><br/>";
            return false;
        }
        return $this->_mapName[$mapKey];
    }

    public function getSimilar($packageName, $serviceName)
    {
        $res = array();
        $packageName = strtoupper($packageName);
        $serviceName = strtoupper($serviceName);
        foreach ($this->_mapName as $key=>$v) {
            $add = false;
            $p = explode("#", $key);
            if (strstr($p[1], $serviceName)) {
                $add = true;
            }

            if ($add) {
                $res[] = $key;
            }
        }
        return $res;
    }

    public function getKeyByFrequency($freq)
    {
        return false;
    }

    public function getService($key)
    {
        return $this->_services[$key];
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
        while (false !== ($s = trim(fgets($source)))) {
            if ($s == "transponders") {
                break;
            }
        }
        // read transponders
        while (false !== ($l1 = trim(fgets($source)))) {
            if ($l1 == "end") {
                break;
            }
            // TODO maybe we need to loop until '/' found
            $data = trim(fgets($source));
            $slash = trim(fgets($source));
            if ($slash != '/') {
                throw new LameDb_Exception("transponder definition does not end with '/''");
            }

            // parse and store transponder
            $transponder = $this->_createTransponder(array($l1, $data));
            $this->_transponders[$transponder->getKey()] = $transponder;
        }
    }

    protected function _loadServices($source)
    {
        // find begin of service definition
        while (false !== ($s = trim(fgets($source)))) {
            if ($s == "services") {
                break;
            }
        }
        // read services
        while (false !== ($l1 = trim(fgets($source)))) {
            if ($l1 == "end") {
                break;
            }
            $serviceName = trim(fgets($source));
            $l3 = fgets($source);

            $service = $this->_createService(array($l1, $serviceName, $l3));

            // store service
            $key = $service->getKey();
            if (!array_key_exists($key, $this->_services)) {
                $this->_services[$key] = $service;
            }

            // store mapping to judge service by name
            $mapKey = strtoupper($service->packageName . "#" . $service->name);
            if (array_key_exists($mapKey, $this->_mapName)) {

            }
            $this->_mapName[$mapKey] = $key;
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
    public $sid;
    public $transporterId;
    public $frequency;
    public $symbol_rate;
    public $polarization;
    public $fec_inner;
    public $position;
    public $inversion;
    public $flags;
    public $system;
    public $modulation;
    public $rolloff;
    public $pilot;

    public function __construct()
    {

    }

    /**
     * Return unique key for transponder
     * @return string
     */
    public function getKey()
    {
        return strtoupper($this->namespace . '#' . $this->sid . '#' . $this->transporterId);
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
        list($t->namespace, $t->transporterId, $t->sid) = explode(":", $line1);
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
                    $t->fec_inner,
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