<?phpclass Admin_model extends CI_Model{    /**     * Holds an array of tables used     *     * @var array     **/    public $tables = array();    /**     * activation code     *     * @var string     **/    public $activation_code;    /**     * forgotten password key     *     * @var string     **/    public $forgotten_password_code;    /**     * new password     *     * @var string     **/    public $new_password;    /**     * Identity     *     * @var string     **/    public $identity;    /**     * Where     *     * @var array     **/    public $_ion_where = array();    /**     * Select     *     * @var array     **/    public $_ion_select = array();    /**     * Like     *     * @var array     **/    public $_ion_like = array();    /**     * Limit     *     * @var string     **/    public $_ion_limit = NULL;    /**     * Offset     *     * @var string     **/    public $_ion_offset = NULL;    /**     * Order By     *     * @var string     **/    public $_ion_order_by = NULL;    /**     * Order     *     * @var string     **/    public $_ion_order = NULL;    /**     * Hooks     *     * @var object     **/    protected $_ion_hooks;    /**     * Response     *     * @var string     **/    protected $response = NULL;    /**     * message (uses lang file)     *     * @var string     **/    protected $messages;    /**     * error message (uses lang file)     *     * @var string     **/    protected $errors;    /**     * error start delimiter     *     * @var string     **/    protected $error_start_delimiter;    /**     * error end delimiter     *     * @var string     **/    protected $error_end_delimiter;    /**     * caching of users and their groups     *     * @var array     **/    public $_cache_user_in_group = array();    /**     * caching of groups     *     * @var array     **/    protected $_cache_groups = array();    public function __construct()    {        parent::__construct();        $this->load->database();        $this->load->config('ion_auth', TRUE);        $this->load->helper('cookie');        $this->load->helper('date');        $this->lang->load('ion_auth');        // initialize db tables data        $this->tables = $this->config->item('tables', 'ion_auth');        //initialize data        $this->identity_column = $this->config->item('identity', 'ion_auth');        $this->store_salt = $this->config->item('store_salt', 'ion_auth');        $this->salt_length = $this->config->item('salt_length', 'ion_auth');        $this->join = $this->config->item('join', 'ion_auth');        // initialize hash method options (Bcrypt)        $this->hash_method = $this->config->item('hash_method', 'ion_auth');        $this->default_rounds = $this->config->item('default_rounds', 'ion_auth');        $this->random_rounds = $this->config->item('random_rounds', 'ion_auth');        $this->min_rounds = $this->config->item('min_rounds', 'ion_auth');        $this->max_rounds = $this->config->item('max_rounds', 'ion_auth');        // initialize messages and error        $this->messages = array();        $this->errors = array();        $delimiters_source = $this->config->item('delimiters_source', 'ion_auth');        if ($delimiters_source === 'form_validation') {            $this->load->library('form_validation');            $form_validation_class = new ReflectionClass("CI_Form_validation");            $error_prefix = $form_validation_class->getProperty("_error_prefix");            $error_prefix->setAccessible(TRUE);            $this->error_start_delimiter = $error_prefix->getValue($this->form_validation);            $this->message_start_delimiter = $this->error_start_delimiter;            $error_suffix = $form_validation_class->getProperty("_error_suffix");            $error_suffix->setAccessible(TRUE);            $this->error_end_delimiter = $error_suffix->getValue($this->form_validation);            $this->message_end_delimiter = $this->error_end_delimiter;       } else {            // use delimiters from config            $this->message_start_delimiter = $this->config->item('message_start_delimiter', 'ion_auth');            $this->message_end_delimiter = $this->config->item('message_end_delimiter', 'ion_auth');            $this->error_start_delimiter = $this->config->item('error_start_delimiter', 'ion_auth');            $this->error_end_delimiter = $this->config->item('error_end_delimiter', 'ion_auth');        }        // initialize our hooks object        $this->_ion_hooks = new stdClass;        // load the bcrypt class if needed      if ($this->hash_method == 'bcrypt') {          if ($this->random_rounds) {              $rand = rand($this->min_rounds, $this->max_rounds);              $params = array('rounds' => $rand);          } else {              $params = array('rounds' => $this->default_rounds);          }          $params['salt_prefix'] = $this->config->item('salt_prefix', 'ion_auth');          $this->load->library('bcrypt', $params);      }    }    function getConfigs()    {        return 1;    }    public function login($identity, $password, $remember = FALSE)    {        if (empty($identity) || empty($password)) {            return FALSE;        }        $query = $this->db->select('' . $this->tables['users'] . '.*,' . $this->tables['user_details_mst'] . '.fname,' . $this->tables['user_details_mst'] . '.lname')            ->from('' . $this->tables['users'] . '')            ->join('' . $this->tables['user_details_mst'] . '',                '' . $this->tables['user_details_mst'] . '.userId = ' . $this->tables['users'] . '.userId', 'inner')            ->where('' . $this->tables['users'] . '.email', $identity)            ->where('' . $this->tables['users'] . '.userType', 'super_admin')            ->where('' . $this->tables['users'] . '.status', 'active')            ->limit(1)            ->order_by('' . $this->tables['users'] . '.userId', 'desc')            ->get();        //print_r($this->db->last_query());exit;        if ($query->num_rows() === 1) {            //print_r($query->row());exit;            $user = $query->row();            $password = $this->hash_password_db($user->userId, $password);            if ($password === TRUE) {                //print_r($password);exit;                if ($user->status == 'inActive') {                    return FALSE;                }                $this->set_session($user);                $this->update_last_login($user->userId);                $this->clear_login_attempts($identity);                if ($remember && $this->config->item('remember_users', 'ion_auth')) {                    $this->remember_user($user->userId);                }                return TRUE;            }        }        // Hash something anyway, just to take up time        $this->hash_password($password);        $this->increase_login_attempts($identity);        return FALSE;    }    public function is_time_locked_out($identity)    {        return $this->is_max_login_attempts_exceeded($identity) && $this->get_last_attempt_time($identity) > time() - $this->config->item('lockout_time', 'ion_auth');    }    public function get_last_attempt_time($identity)    {        if ($this->config->item('track_login_attempts', 'ion_auth')) {            $ip_address = $this->_prepare_ip($this->input->ip_address());            $this->db->select_max('time');            if ($this->config->item('track_login_ip_address', 'ion_auth')) $this->db->where('ip_address', $ip_address);            else if (strlen($identity) > 0) $this->db->or_where('login', $identity);            $qres = $this->db->get($this->tables['login_attempts'], 1);            if ($qres->num_rows() > 0) {                return $qres->row()->time;            }        }        return 0;    }    public function is_max_login_attempts_exceeded($identity)    {        if ($this->config->item('track_login_attempts', 'ion_auth')) {            $max_attempts = $this->config->item('maximum_login_attempts', 'ion_auth');            if ($max_attempts > 0) {                $attempts = $this->get_attempts_num($identity);                return $attempts >= $max_attempts;            }        }        return FALSE;    }    function get_attempts_num($identity)    {        if ($this->config->item('track_login_attempts', 'ion_auth')) {            $ip_address = $this->_prepare_ip($this->input->ip_address());            $this->db->select('1', FALSE);            if ($this->config->item('track_login_ip_address', 'ion_auth')) {                $this->db->where('ip_address', $ip_address);                $this->db->where('login', $identity);            } else if (strlen($identity) > 0) $this->db->or_where('login', $identity);            $qres = $this->db->get($this->tables['login_attempts']);            return $qres->num_rows();        }        return 0;    }    public function increase_login_attempts($identity)    {        if ($this->config->item('track_login_attempts', 'ion_auth')) {            $ip_address = $this->_prepare_ip($this->input->ip_address());            return $this->db->insert($this->tables['login_attempts'], array('ip_address' => $ip_address, 'login' => $identity, 'time' => time()));        }        return FALSE;    }    public function hash_password($password, $salt = false, $use_sha1_override = FALSE)    {        if (empty($password)) {            return FALSE;        }        if ($use_sha1_override === FALSE && $this->hash_method == 'bcrypt') {            return $this->bcrypt->hash($password);        }        if ($this->store_salt && $salt) {            return sha1($password . $salt);        } else {            $salt = $this->salt();            return $salt . substr(sha1($salt . $password), 0, -$this->salt_length);        }    }    /**     * This function takes a password and validates it     * against an entry in the users table.     *     * @return void     * @author Mathew     **/    public function hash_password_db($id, $password, $use_sha1_override = FALSE)    {        if (empty($id) || empty($password)) {            return FALSE;        }        $query = $this->db->select('password')            ->where('userId', $id)            ->limit(1)            ->order_by('userId', 'desc')            ->get($this->tables['users']);        $hash_password_db = $query->row();        $userPassword = $this->encryption->decrypt(trim($hash_password_db->password));        //print_r($userPassword);exit;        if ($query->num_rows() != 1) {            return FALSE;        }        if ($password == $userPassword) {            return TRUE;        } else {            return FALSE;        }    }    /**     * set_session     *     * @return bool     * @author jrmadsen67     **/    public function set_session($user)    {        $session_data = array(            'identity' => $user->{$this->identity_column},            'email' => $user->email,            'user_id' => $user->userId,            'name' => $user->name, //everyone likes to overwrite id so we'll use user_id            'status'=>$user->status,            'old_last_login' => $user->dateUpdated        );        $this->session->set_userdata('userSession', $session_data);        return TRUE;    }    /**     * update_last_login     *     * @return bool     * @author Ben Edmunds     **/    public function update_last_login($id)    {        $this->load->helper('date');        $this->db->update($this->tables['users'], array('dateUpdated' => date('Y-m-d h:s:i')), array('userId' => $id));        return $this->db->affected_rows() == 1;    }    /**     * clear_login_attempts     * Based on code from Tank Auth, by Ilya Konyukhov (https://github.com/ilkon/Tank-Auth)     *     * @param string $identity     **/    public function clear_login_attempts($identity, $expire_period = 86400)    {        if ($this->config->item('track_login_attempts', 'ion_auth')) {            $ip_address = $this->_prepare_ip($this->input->ip_address());            $this->db->where(array('ip_address' => $ip_address, 'login' => $identity));            // Purge obsolete login attempts            $this->db->or_where('time <', time() - $expire_period, FALSE);            return $this->db->delete($this->tables['login_attempts']);        }        return FALSE;    }    /**     * remember_user     *     * @return bool     * @author Ben Edmunds     **/    public function remember_user($id)    {        if (!$id) {            return FALSE;        }        $user = $this->user($id)->row();        $salt = $this->salt();        $this->db->update($this->tables['users'], array('remember_code' => $salt), array('id' => $id));        if ($this->db->affected_rows() > -1) {            if ($this->config->item('user_expire', 'ion_auth') === 0) {                $expire = (60 * 60 * 24 * 365 * 2);            } // otherwise use what is set            else {                $expire = $this->config->item('user_expire', 'ion_auth');            }            set_cookie(array(                'name' => $this->config->item('identity_cookie_name', 'ion_auth'),                'value' => $user->{$this->identity_column},                'expire' => $expire            ));            set_cookie(array(                'name' => $this->config->item('remember_cookie_name', 'ion_auth'),                'value' => $salt,                'expire' => $expire            ));            return TRUE;        }        return FALSE;    }    public function change_password($identity, $old, $new)    {        $query = $this->db->select('userId')            ->where($this->identity_column, $identity)            ->limit(1)            ->order_by('userId', 'desc')            ->get($this->tables['users'])->row();        if (!$query) {            return FALSE;        }        if ($query) {          $data = array(                'password' => $this->encryption->encrypt(trim($new)),                'remember_code' => NULL,                'ip_address'=>$this->input->ip_address(),                'dateUpdated'=>date('Y-m-d h:i:s')            );            $successfully_changed_password_in_db = $this->db->update($this->tables['users'], $data, array($this->identity_column => $identity));            if ($successfully_changed_password_in_db) {                return TRUE;            } else {                return FALSE;            }        }        return FALSE;    }    protected function _prepare_ip($ip_address)    {        return $ip_address;    }} // fecha a model