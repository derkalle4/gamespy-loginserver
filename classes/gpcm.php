<?php

/**
 * Description of gpcm
 *
 * @author kalle
 */
class gpcm {

    private $socket;
    private $index;
    private $ip;
    private $port;
    private $challenge;
    private $session = NULL;
    private $user_id;
    private $user_name;
    // error messages
    // err 516 -> account name already in use
    // err 262 -> profile got deleted create a new one
    // err 261 -> profil ungültig
    // err 260 -> wrong password
    // err 259 -> wrong mail
    // err 258 -> name ungültig
    // err 257 -> no server connection
    private $error_msg_notcompatible = '\error\\err\263\fatal\\errmsg\Your Game is currently not compatible with our MasterServer. Visit GameShare.co for help!\id\1\final\\';
    private $error_msg_wronglogin = '\error\\err\261\fatal\\errmsg\Sorry, Username, E-Mail or Password wrong!\id\1\final\\';
    private $error_msg_wrongregister = '\error\\err\261\fatal\\errmsg\Sorry, Username, E-Mail or Password wrong!\id\1\final\\';
    // definitions
    private $SPECIAL_CHARS_DEF = ['=', '+', '/'];
    private $SPECIAL_CHARS_GSP = ['_', '[', ']'];

    /**
     * Constructor for gpcm class - initialize all necessary variables
     * @param type $socket
     * @param type $index
     * @param type $address
     */
    public function __construct($socket, $index, $address) {
        // set private variables
        $this->socket = $socket;
        $this->index = $index;
        $this->ip = $address['ip'];
        $this->port = $address['port'];
        // create challenge
        $this->challenge = tools::generateRandomString(10);
        // create session
        $this->session = tools::generateRandomInt(5);
        // tell our client about this great class
        $response = '\lc\1\challenge\\' . $this->challenge . '\id\1\final\\';
        socket_write($this->socket, $response);
        //log
        tools::log('initialized class for ' . $this->ip . ':' . $this->port);
    }

    /**
     * if the client got an timeout do stuff to let him disconnect correctly
     */
    public function timeout() {
        // database logout
        if ($this->session !== NULL) {
            $sql = "UPDATE users SET"
                    . " session='0',"
                    . " status='0',"
                    . " status_string='Offline',"
                    . " game=''"
                    . " WHERE session='" . database::esc($this->session, 'int') . "'";
            database::query($sql);
        }
        //log
        tools::log('class timeout for ' . $this->ip . ':' . $this->port);

        return true;
    }

    /**
     * the main loop for the client we handle I/O operations
     */
    public function loop() {
        $tmp = TRUE;
        // read from socket
        $input = @socket_read($this->socket, 1024000);
        if ($input !== FALSE) {
            if (!empty($input)) {
                // if there are multiple messages in one "packet"
                if (substr_count($input, '\final\\') > 1) {
                    $ex = explode('\final\\', $input);
                    foreach ($ex as $item) {
                        if (trim($item) !== '') {
                            if ($this->commands($item . '\final\\') === FALSE) {
                                $tmp = FALSE;
                            }
                        }
                    }
                } else {
                    $tmp = $this->commands($input);
                }
                return $tmp;
            }
        }
        if (socket_last_error($this->socket) > 0) {
            return FALSE;
        }
        return TRUE;
    }

    /**
     * 
     * @param type $value
     * @return type
     */
    private function intval32bits($value) {
        $value = ($value & 0xFFFFFFFF);

        if ($value & 0x80000000)
            $value = -((~$value & 0xFFFFFFFF) + 1);

        return $value;
    }

    /**
     * 
     * @param type $num
     * @return real
     */
    private function gslame($num) {
        $c = (($num >> 16) & 0xffff) * 0x41a7;
        $a = $this->intval32bits(($num & 0xffff) * 0x41a7 + (($c & 0x7fff) << 16));
        if ($a < 0) {
            $a &= 0x7fffffff;
            $a++;
        }
        $a += ($c >> 15);
        if ($a < 0) {
            $a &= 0x7fffffff;
            $a++;
        }
        return $a;
    }

    /**
     * 
     * @param type $text
     * @param type $key
     * @return type
     */
    public function xor_this($text, $key = 'GameSpy3D') {
        $outText = '';
        for ($i = 0; $i < strlen($text);) {
            for ($j = 0; ($j < strlen($key) && $i < strlen($text)); $j++, $i++) {
                $outText .= $text{$i} ^ $key{$j};
            }
        }
        return $outText;
    }

    /**
     * 
     * @param type $pass
     * @return type
     */
    private function gspassenc($pass) {
        $num = 0x79707367;   // "gspy"

        for ($i = 0; $i < strlen($pass); $i++) {
            $num = $this->gslame($num);
            $pass[$i] = chr(ord($pass[$i]) ^ ($num % 0xff));
        }
        return $pass;
    }

    /**
     * 
     * @param type $pass
     * @return type
     */
    private function passdecode($pass) {
        return $this->gspassenc(base64_decode(str_replace($this->SPECIAL_CHARS_GSP, $this->SPECIAL_CHARS_DEF, $pass)));
    }

    /**
     * 
     * @param type $pass
     * @return type
     */
    private function passencode($pass) {
        return str_replace($this->SPECIAL_CHARS_DEF, $this->SPECIAL_CHARS_GSP, base64_encode($this->gspassenc($pass)));
    }

    /**
     * 
     * @param type $input
     * @return boolean
     */
    private function commands($input) {
        $ex = explode("\\", trim($input));
        tools::log($input);
        // stop connection if we got wrong input
        if(!isset($ex[1])) {
            tools::log('no valid parameters');
            return false;
        }
        switch ($ex[1]) {
            // \getpd\\pid\37\ptype\3\dindex\0\keys\rank\lid\0\final\
            case 'login':
                return $this->gs_login($ex);
                break;
            case 'ka':
                return $this->gs_keep_alive($ex);
                break;
            case 'status':
                return $this->gs_status($ex);
                break;
            case 'getprofile':
                return $this->gs_getprofile($ex);
                break;
            case 'updatepro':
                return $this->gs_updatepro($ex);
                break;
            case 'newuser':
                return $this->gs_newuser($ex);
                break;
            case 'addbuddy':
                return $this->gs_addbuddy($ex);
                break;
            case 'authadd':
                return $this->gs_authadd($ex);
                break;
            case 'registercdkey':
                return $this->gs_registercdkey($ex);
                break;
            case 'pinvite':
                return $this->gs_pinvite($ex);
                break;
            case 'quiet':
                return $this->gs_quiet($ex);
                break;
            case 'logout':
                return false;
            default:
                return false;
                break;
        }
    }

    /**
     * 
     * @param type $username
     * @param type $cchallenge
     * @param type $schallenge
     * @param type $password
     * @return type
     */
    private function gs_login_validate_response($username, $cchallenge, $schallenge, $password) {
        $value = $password;
        for ($i = 0; $i < 48; $i++) {
            $value.= " ";
        }

        $value.= $username;
        $value.= $cchallenge;
        $value.= $schallenge;
        $value.= $password;

        return md5($value);
    }

    /**
     * 
     * @param type $username
     * @param type $cchallenge
     * @param type $schallenge
     * @param type $password
     * @return type
     */
    private function gs_login_valid_response($username, $cchallenge, $schallenge, $password) {
        $value = $password;
        for ($i = 0; $i < 48; $i++) {
            $value.= ' ';
        }
        $value.= $username;
        $value.= $schallenge;
        $value.= $cchallenge;
        $value.= $password;
        return md5($value);
    }

    /**
     * 
     * @param type $ex
     */
    private function gs_login($ex) {
        if (isset($ex[array_search('challenge', $ex) + 1]) AND
                isset($ex[array_search('response', $ex) + 1]) AND (
                isset($ex[array_search('user', $ex) + 1]) OR
                isset($ex[array_search('uniquenick', $ex) + 1]))) {

            // client challenge
            $cchallenge = $ex[array_search('challenge', $ex) + 1];
            // client response
            $response = $ex[array_search('response', $ex) + 1];
            // if uniquenick exists
            if (array_search('uniquenick', $ex)) {
                $user_name = $ex[array_search('uniquenick', $ex) + 1];
                $user_validate = $user_name;
                // query
                $query = "SELECT * FROM users WHERE name='" . database::esc($user_name) . "' LIMIT 0,1";
            } else {  // if user exists
                // get username
                $user = $ex[array_search('user', $ex) + 1];
                $user_validate = $user;
                $ex = explode('@', $user, 2);
                // user name
                $user_name = $ex[0];
                // user mail
                $user_mail = $ex[1];
                // query
                $query = "SELECT * FROM users WHERE name='" . database::esc($user_name) . "' AND email='" . database::esc($user_mail) . "' LIMIT 0,1";
            }
            tools::log($query);
            // user in database
            $sql = database::query($query);
            if (database::num_rows($sql) == 1) {
                $row = database::fetch_object($sql);

                if ($this->gs_login_validate_response($user_validate, $cchallenge, $this->challenge, $row->password) == $response) {
                    $this->user_id = $row->id;
                    $this->user_name = $row->name;
                    $response = '\lc\2' .
                            '\sesskey\\' . $this->session .
                            '\proof\\' . $this->gs_login_valid_response($user_validate, $cchallenge, $this->challenge, $row->password) .
                            '\userid\\' . $row->id .
                            '\profileid\\' . $row->id .
                            '\user\\' . $user_name .
                            '\uniquenick\\' . $user_name .
                            '\lt\\' . tools::generateRandomString(22) .
                            '\id\1' .
                            '\final\\';
                    tools::log("server: " . $response);
                    socket_write($this->socket, $response);
                    // update user session
                    database::query("UPDATE users SET"
                            . " session='" . database::esc($this->session, 'int') . "'"
                            . " WHERE id='" . intval($row->id) . "'");
                    return true;
                } else {
                    tools::log('wrong validation #gs_login');
                }
            } else {
                tools::log('user not found #gs_login');
            }
        } else {
            tools::log('wrong parameters #gs_login');
        }
        socket_write($this->socket, $this->error_msg_wrongregister);
        return false;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_keep_alive($ex) {
        $response = '\ka\\' .
                '\final\\';
        tools::log("server: " . $response);
        socket_write($this->socket, $response);

        return true;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_status($ex) {
        if (isset($ex[array_search('status', $ex) + 1]) AND
                isset($ex[array_search('sesskey', $ex) + 1]) AND
                isset($ex[array_search('statstring', $ex) + 1]) AND
                isset($ex[array_search('locstring', $ex) + 1])) {
            $client_session = $ex[array_search('sesskey', $ex) + 1];
            $client_status = $ex[array_search('statstring', $ex) + 1];
            $client_game = $ex[array_search('locstring', $ex) + 1];
            $status = $ex[array_search('status', $ex) + 1];
            if ($client_session == $this->session) {
                $sql = "UPDATE users SET"
                        . " game='" . database::esc($client_game) . "',"
                        . " status_string='" . database::esc($client_status) . "',"
                        . " status='" . intval($status) . "'"
                        . " WHERE session='" . intval($this->session) . "'";
                database::query($sql);
                tools::log($sql);
                return true;
            }
        }
        return false;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_getprofile($ex) {
        if (isset($ex[array_search('sesskey', $ex) + 1]) AND
                isset($ex[array_search('profileid', $ex) + 1])) {
            $profileID = $ex[array_search('profileid', $ex) + 1];
            $id = $ex[array_search('id', $ex) + 1];
            $query = "SELECT * FROM users WHERE id='" . intval($profileID) . "' LIMIT 0,1";
            $sql = database::query($query);
            if (database::num_rows($sql) == 1) {
                $row = database::fetch_object($sql);
                $response = '\pi\\' .
                        '\profileid\\' . $profileID .
                        '\nick\\' . $row->name .
                        '\userid\\' . $profileID .
                        '\email\\' . $row->email .
                        '\sig\\' . tools::generateRandomString(32) .
                        '\uniquenick\\' . $row->name .
                        '\pid\0' .
                        '\firstname\\' .
                        '\lastname\\' .
                        '\countrycode\US' .
                        '\birthday\16844722' .
                        '\lon\0.000000' .
                        '\lat\0.000000' .
                        '\loc\\' .
                        '\id\\' . ($id) .
                        '\final\\';
                tools::log("server: " . $response);
                socket_write($this->socket, $response);
            } else {
                return false;
            }
        }
        return true;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_updatepro($ex) {
        // \updatepro\
        // \sesskey\65619
        // \firstname\
        // \lastname\
        // \countrycode\US
        // \birthday\16844722
        // \final\
        // \updatepro\
        // \sesskey\59969
        // \publicmask\0
        // \partnerid\0
        // \final\

        return true;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_newuser($ex) {
        // \newuser\
        // \email\kalle21423@kandru.de
        // \nick\kalle21423
        // \passwordenc\VJOBn0q1gg__
        // \productid\10619
        // \gamename\swbfront2pc
        // \namespaceid\0
        // \uniquenick\
        // \id\1
        // \final\
        if (isset($ex[array_search('email', $ex) + 1]) AND
                isset($ex[array_search('nick', $ex) + 1]) AND
                isset($ex[array_search('email', $ex) + 1]) AND
                isset($ex[array_search('passwordenc', $ex) + 1]) AND
                isset($ex[array_search('productid', $ex) + 1]) AND
                isset($ex[array_search('gamename', $ex) + 1]) AND
                isset($ex[array_search('id', $ex) + 1])) {

            $user_name = $ex[array_search('nick', $ex) + 1];
            $user_mail = $ex[array_search('email', $ex) + 1];
            $user_pass = $ex[array_search('passwordenc', $ex) + 1];
            $query = "SELECT * FROM users WHERE name='" . database::esc($user_name) . "' OR email='" . database::esc($user_mail) . "'";
            $sql = database::query($query);
            if (database::num_rows($sql) == 0) {
                $query = "INSERT INTO users (name,password,email)VALUES('" . database::esc($user_name) . "','" . database::esc(md5($this->passdecode($user_pass))) . "','" . database::esc($user_mail) . "')";
                database::query($query);
                $user_user = $ex[array_search('user', $ex) + 1];
                $cchallenge = $ex[array_search('challenge', $ex) + 1];
                $response = $ex[array_search('response', $ex) + 1];
                $response = '\nur\\' .
                        '\userid\\' . intval(database::last_insert_id()) .
                        '\profileid\\' . intval(database::last_insert_id()) .
                        '\id\\' . '1' .
                        '\final\\';
                tools::log("server: " . $response);
                socket_write($this->socket, $response);
                return true;
            }
        }
        socket_write($this->socket, $this->error_msg_wrongregister);
        return false;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_addbuddy($ex) {
        //GPCM: Client:
        //\addbuddy\\sesskey\3281\newprofileid\451704125\reason\Battlefront2 Request\final\
        if (isset($ex[array_search('sesskey', $ex) + 1]) AND
                isset($ex[array_search('newprofileid', $ex) + 1])) {
            $profileID = $ex[array_search('newprofileid', $ex) + 1];
//            $query = "SELECT * FROM users WHERE id='" . intval($id) . "' LIMIT 0,1";
//            $sql = database::query($query);
//            if (database::num_rows($sql) == 1) {
//                
//            }
            tools::log('DEBUG: ADDBUDDY: ' . implode('|', $ex));
        }
        return true;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_authadd($ex) {
        //GPCM: Client:
        //\authadd\\sesskey\59184\fromprofileid\1\sig\234\autosync\true..false\final\
        if (isset($ex[array_search('sesskey', $ex) + 1]) AND
                isset($ex[array_search('fromprofileid', $ex) + 1])) {
            $profileID = $ex[array_search('sig', $ex) + 1];
//            $query = "SELECT * FROM users WHERE id='" . intval($id) . "' LIMIT 0,1";
//            $sql = database::query($query);
//            if (database::num_rows($sql) == 1) {
//                
//            }
            tools::log('DEBUG: authadd: ' . implode('|', $ex));
        }
        return true;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_registercdkey($ex) {
        //GPCM: Client:
        //\registercdkey\\sesskey\59184\cdkeyenc\2rfwejfigoerglreg\gameid\1\id\1\final\
        if (isset($ex[array_search('sesskey', $ex) + 1]) AND
                isset($ex[array_search('cdkeyenc', $ex) + 1])) {
            $cdkey = $ex[array_search('cdkeyenc', $ex) + 1];
//            $query = "SELECT * FROM users WHERE id='" . intval($id) . "' LIMIT 0,1";
//            $sql = database::query($query);
//            if (database::num_rows($sql) == 1) {
//                
//            }
            tools::log('DEBUG CDKEYENC: ' . implode('|', $ex));
        }
        return true;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_pinvite($ex) {
        //GPCM: Client:
        //\pinvite\\sesskey\59184\profileid\1\productid\1\location\1\final\
        if (isset($ex[array_search('sesskey', $ex) + 1]) AND
                isset($ex[array_search('profileid', $ex) + 1])) {
            $profileID = $ex[array_search('profileid', $ex) + 1];
//            $query = "SELECT * FROM users WHERE id='" . intval($id) . "' LIMIT 0,1";
//            $sql = database::query($query);
//            if (database::num_rows($sql) == 1) {
//                
//            }
            tools::log('DEBUG pinvite: ' . implode('|', $ex));
        }
        return true;
    }

    /**
     * 
     * @param type $ex
     * @return boolean
     */
    public function gs_quiet($ex) {
        tools::log('DEBUG quiet: ' . implode('|', $ex));
        return true;
    }

}
