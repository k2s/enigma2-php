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

    public function __construct($host, $user="root", $passwd="")
    {
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

    public function put($fn, $target)
    {

    }

    public function putBouquets($path)
    {

    }
}