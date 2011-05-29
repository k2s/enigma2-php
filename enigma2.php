<?php
/**
 * Class to communicate with enigma2 devices using FTP protocol
 *
 * @todo there should be more classes to communicate
 *
 * @throws Exception
 */
class Enigma2Ftp
{
    protected $_ftpStream;
    protected $_host;
    protected $_user;
    protected $_passwd;

    public function __construct($host, $user="root", $passwd="")
    {
        $this->_host = $host;
        $this->_user = $user;
        $this->_passwd = $passwd;

        // connect FTP
        $this->_ftpStream = ftp_connect($host);
	    // login to FTP server
	    $login = ftp_login($this->_ftpStream, $user, $passwd);
	    if(!$login) {
    		throw new Exception("FTP not connected");
    	} else {
            ftp_pasv($this->_ftpStream, true);
        }
    }

    public function __destruct()
    {
        if ($this->_ftpStream) {
            // close FTP connection
    	    @ftp_close($this->_ftpStream);
        }
    }

    public function get($fn, $target=null)
    {
        if (is_string($target)) {
            if (!ftp_get($this->_ftpStream, $target, $fn, FTP_ASCII)) {
                $target = false;
            }
        } else {
            if (is_null($target)) {
                $target = tmpfile();
            }
            if (!ftp_fget($this->_ftpStream, $target, $fn, FTP_ASCII)) {
                $target = false;
            }
        }

        return $target;
    }

    /**
     * @param  $fn
     * @param  $target
     * @return bool
     */
    public function put($fn, $target)
    {
        return ftp_put($this->_ftpStream, $target, $fn, FTP_ASCII);
    }

    public function putBouquets($folderName)
    {
        // delete existing files
        $files = ftp_nlist($this->_ftpStream, '/etc/enigma2/');
        foreach ($files as $fn) {
            if (pathinfo($fn, PATHINFO_EXTENSION)=='tv') {
                ftp_delete($this->_ftpStream, $fn);
            }
        }

        // upload new files
        foreach (glob("$folderName/*.tv") as $filename) {
            $fn = pathinfo($filename, PATHINFO_BASENAME);
            ftp_put($this->_ftpStream, '/etc/enigma2/'.$fn, $filename, FTP_ASCII);
        }
    }

    public function reload()
    {
	file_get_contents("http://".$this->_host."/web/servicelistreload?mode=2");
        return true;	

        // TODO fallback to enigma2 restart 
        /*require_once "telnet.php";
        $telnet = new Telnet($this->_host);
        $telnet->connect();
        $telnet->login($this->_user, $this->_passwd);
        $telnet->exec("killall -9 enigma2");*/
    }
}