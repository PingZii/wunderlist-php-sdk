<?php

/*
 * Unofficial Wunderlist PHP SDK
 *
 * @author: Eymen Gunay
 * @mail: eymen@egunay.com
 * @web: egunay.com
 * @github: https://github.com/james-mountford/wunderlist-php-sdk
 * @note: build for codeigniter 2.1.2, php 5.1+
 */
class Wunderlist_Lib
{
	var $email;
	var $password;
	var $cookie_file_path = './application/cache/'; # codeigniter cache path
	var $cookie_file      = "";
	var $user_agent	      = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_0) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.79 Safari/537.1";
	var $lists            = array();
	var $lists_task       = array();
	var $curl_count       = 0;
	var $wunderlist_url   = 'http://www.wunderlist.com';
	var $marker           = array(); # List of all benchmark markers and when they were added

	// --------------------------------------------------------------------
	function __construct($params)
	{
		extract($params);
		// Set username & password
		if (isset($email) && isset($password))
		{
			$this->email    = $email;
			$this->password = $password;
			if(empty($this->cookie_file) && empty($cookie_file)) {
				$this->cookie_file = $this->cookie_file_path.'wunderlist_'.md5($email.$password).'.txt';
			}
		}
		else
		{
			die("username_password_missing");
		}

		if(!empty($cookie_file)) {
			$this->cookie_file = $cookie_file;
		}
		// Check cookie file and if login is necessary
		$login_required = TRUE;
		if (is_file($this->cookie_file) && is_readable($this->cookie_file))
		{
			$expr = file_get_contents($this->cookie_file);
			list(,,,,$expr) = explode("\t", $expr);
			if (is_numeric($expr) && $_SERVER['REQUEST_TIME'] < $expr)
			{
				$login_required = FALSE;
			}
		}
		else
		{
			if (file_put_contents($this->cookie_file, "") === false) {
				return "cookie_file_create_error";
			}
		}

		# wunderlist cookies accept check
		if($login_required === false) {
			$html = $this->_curl($this->wunderlist_url."/home", NULL, "GET", FALSE);
			if(!empty($html)) {
				$this->get_lists(false, $html);
				return true;
			}
		}

		$login = $this->_login();
		$login = $this->_json_result($login);
		if ($login['code'] == 202)
		{
			return "auth_error";
		}
		elseif ($login['code'] != 200)
		{
			return "error";
		}
		return true;
	}

	private function _curl($url, $params = NULL, $method = "POST", $ajax = TRUE)
	{
		$this->mark($this->curl_count);
		$options = array(
			CURLOPT_URL            => $url,
			CURLOPT_USERAGENT      => $this->user_agent,
			CURLOPT_COOKIEJAR      => $this->cookie_file,
			CURLOPT_COOKIEFILE     => $this->cookie_file,
			CURLOPT_RETURNTRANSFER => 1,
		);
		if ($method == "POST")
		{
			$options[CURLOPT_POST] = 1;
			if ($params != NULL)
			{
				$options[CURLOPT_POSTFIELDS] = $params;
			}
		}
		if ($ajax === TRUE)
		{
			$options[CURLOPT_HTTPHEADER] = array("X-Requested-With: XMLHttpRequest");
		}

		$ch = curl_init();
		curl_setopt_array($ch, $options);
		$result = curl_exec($ch);
		curl_close ($ch);

		$this->mark($this->curl_count++);
		return $result;
	}

	private function _json_result($json, $return=array(), $check_success=false)
	{
		$data =  json_decode($json, true);
		if(!is_array($data)) {
			return 'error';
		}

		if($check_success === true) {
			if (empty($data['status']) || $data['status'] != "success") {
				return 'error';
			}
			if(empty($return)) {
				return 'success';
			}
		}

		if(empty($return) || $return === 'ALL') {
			return $data;
		}

		if(is_string($return)) {
			if(!empty($data[$return])) {
				return $data[$return];
			}
		}

		if(is_array($return)) {
			$output = array();
			foreach ($return as $key => $value) {
				$output[$value] = isset($data[$value]) ? $data[$value] : NULL;
			}
			return $output;
		}

		return 'error';
	}

	private function _login()
	{
		$params = array(
			'email'    => $this->email,
			'password' => md5($this->password),
		);

		return $this->_curl($this->wunderlist_url."/ajax/user", $this->_serialize($params));
	}

	private function _serialize($array)
	{
		if (is_array($array))
		{
			$string = "";
			foreach ($array as $key => $val)
			{
				$string .= urlencode($key) . "=" . urlencode($val) . "&";
			}
			$string = substr($string, 0, -1);
			return $string;
		}
	}

	/*
	* Adds a new list
	*
	* Usage: add_list(name)
	* Date param is optional. Use it only if you want to set a due date
	* Example Usage: add_task("123456", "My new task name", "1323415672");
	*
	* @param: string
	* @return: string
	* @author: James Mountford
	*/
	public function add_list($name)
	{
		$params = array(
			"name" => $name,
		);
		$json = json_encode($params);
		$params = array("list" => $json);
		$serialized = $this->_serialize($params);
		$return = $this->_curl($this->wunderlist_url."/ajax/lists/insert/", $serialized);
		return $this->_json_result($return, 'id', true);
	}

	/*
	 * Adds a new task
	 *
	 * Usage: add_task(list_id, name, date)
	 * Date param is optional. Use it only if you want to set a due date
	 * Example Usage: add_task("123456", "My new task name", "1323415672");
	 *
	 * @param: string
	 * @param: string
	 * @param: string
	 * @return: string
	 */
	public function add_task($list_id, $name, $date = NULL)
	{
		$params = array(
			"list_id" => "" . $list_id . "",
			"name" => "" . $name . ""
		);
		if ($date != NULL)
		{
			$params['date'] = "" . intval($date) . "";
		}
		$json = json_encode($params);
		$params = array("task" => $json);

		$serialized = $this->_serialize($params);
		$return = $this->_curl($this->wunderlist_url."/ajax/tasks/insert/", $serialized);
		return $this->_json_result($return, array('name','id'), true);
	}

	/*
	 * Returns badge counts
	 *
	 * Usage: count_badge()
	 * It will return an associative array with "overdue" and "today" keys
	 *
	 * @return: array
	 */
	public function count_badge()
	{
		$url = $this->wunderlist_url."/ajax/tasks/badgecounts/";
		$params = array("date" => time());
		$data = $this->_curl($url, $this->_serialize($params));
		return $this->_json_result($data, array('overdue','today'), true);
	}

	/*
	 * Returns task count of a specified list
	 *
	 * Usage: count_list(list_id)
	 * It will return an integer
	 *
	 * @return: int
	 */
	public function count_list($id)
	{
		$url = $this->wunderlist_url."/ajax/lists/count/" . $id;
		$data = $this->_curl($url, NULL, "GET");
		return $this->_json_result($data, 'count', true);
	}

	/**
	 * Return curl connection info
	 * @return string     connection info
	 */
	public function debug()
	{
		$elapsed_time = $this->elapsed_time();
		$total = 0;
		foreach ($elapsed_time as $key => $value) {
			$total += $value;
		}
		return array('elapsed_time'=>$elapsed_time/* Remote */, 'elapsed_time_total'=>$total, 'queries'=>$this->curl_count);
	}

	/*
	 * Deletes a task
	 *
	 * Usage: delete_task(task_id, list_id)
	 * You have to specify both task and list ids.
	 * It will return "success" or "error"
	 *
	 * @param: string
	 * @param: string
	 * @return: string
	 */
	public function delete_task($list_id, $task_id)
	{
		$params = array(
			"id"      => $task_id,
			"list_id" => $list_id,
			"deleted" => 1
		);
		$json = json_encode($params);
		$params = array("task" => $json);
		$serialized = $this->_serialize($params);
		$return = $this->_curl($this->wunderlist_url."/ajax/tasks/update/", $serialized);
		var_dump($return);
		return $this->_json_result($return, '', true);
	}

	/**
	 * Calculates the time difference between two marked points.
	 *
	 * @access	public
	 * @param	integer	the number of decimal places
	 * @return	mixed
	 */
	function elapsed_time($decimals = 4)
	{
		$output = array();
		foreach ($this->marker as $key => $value) {
			$output[$key] = number_format($value[1] - $value[0], $decimals);
		}
		return $output;
	}

	/*
	 * Get list tasks
	 *
	 * Usage: get_list(list_id)
	 * It will return an associative array with all information.
	 * Example output:
	 * Array
	 *		todo
	 *			0
	 *				task      => task_id
	 *				note      => task_note
	 *				date      => task_due_date
	 *				name      => task_name
	 *				important => task_important
	 *		done
	 *			0
	 *				task      => task_id
	 *				note      => task_note
	 *				date      => task_due_date
	 *				name      => task_name
	 *				important => task_important
	 *
	 * @return: array
	 */
	public function get_list($list_id, $use_cache=true, $html_data='')
	{
		if($use_cache === true) {
			if(!empty($this->lists_task[$list_id])) {
				return $this->lists_task[$list_id];
			}
		}
		if(empty($html_data)) {
			$url = $this->wunderlist_url."/ajax/lists/id/" . $list_id;
			$data = $this->_curl($url, NULL, "GET");
			$data = $this->_json_result($data);
		} else {
			$data = array();
			$data['status'] = 'success';
			$data['data'] = $html_data;
		}

		if ($data['status'] !== "success") {
			return "error";
		}

		if(empty($data['data'])) {
			return 'empty';
		}

		$dom = new DOMDocument();
		$dom->strictErrorChecking = false;
		libxml_use_internal_errors(true);

		$html = mb_convert_encoding($data['data'], 'HTML-ENTITIES', "UTF-8"); // UTF8 Support
		$dom->loadHTML($html);
		$uls = $dom->getElementsByTagName("ul");
		$i = 0;
		$return = array();
		foreach ($uls as $ul)
		{
			$list = "todo";
			$ul_class = $ul->getAttribute("class");
			if ($ul_class == "donelist") # mainlist -> todo, donelist -> done
			{
				$list = "done";
			}

			foreach ($ul->getElementsByTagName("li") as $li)
			{
				$id = $li->getAttribute("id");
				if(empty($id) || !is_numeric($id)) continue(1);
				$tmp = array( 'task'=>$id, 'note'=>NULL, 'date'=>NULL );
				foreach ($li->getElementsByTagName("span") as $span)
				{
					$class = $span->getAttribute("class");
					if (strpos($class, "description") !== FALSE)
					{
						$tmp['name'] = $span->nodeValue;
					}
					elseif (strpos($class, "activenote") !== FALSE)
					{
						$tmp['note'] = $span->nodeValue;
					}
					elseif (strpos($class, "showdate") !== FALSE)
					{
						$tmp['date'] = $span->getAttribute("rel");
					}
					elseif (strpos($class, "icon fav") !== FALSE)
					{
						$tmp['important'] = strpos($class, "icon favina") !== false ? false : true;
					}
				}
				if ($ul_class == "donelist")
				{
					$tmp['done'] = str_replace("donelist_", "", $ul->getAttribute("id"));
				}
				$return[$list][$i++] = $tmp;
			}
			if (strpos($ul_class, "mainlist") !== FALSE)
			{
				$i = 0;
			}
		}
		return $return;
	}

	/*
	 * Returns an array of all available lists
	 *
	 * Usage: get_lists()
	 * It will return an array
	 * Example Output:
	 * Array
	 *		0
	 *			name => List name
	 *			id		 => List id
	 *
	 * @return: array
	 */
	public function get_lists($use_cache=true, $html='')
	{
		if($use_cache === true) {
			if(!empty($this->lists)) {
				return $this->lists;
			}
		}
		if(empty($html)) {
			$html = $this->_curl($this->wunderlist_url."/home", NULL, "GET", FALSE);
		}

		$dom = new DOMDocument();
		$dom->strictErrorChecking = false;
		libxml_use_internal_errors(true);

		$html = mb_convert_encoding($html, 'HTML-ENTITIES', "UTF-8"); // UTF8 Support
		$dom->loadHTML($html);
		$lists = $dom->getElementById("lists");
		$bs = $lists->getElementsByTagName("b");
		$i = 0;
		foreach ($bs as $b)
		{
			$return[$i]['name'] = $b->nodeValue;
			$i++;
		}
		$as = $lists->getElementsByTagName("a");
		$i = 0;
		foreach ($as as $a)
		{
			$return[$i]['id'] = str_replace("list", "" , $a->getAttribute("id"));
			$i++;
		}

		$inbox_tasks = $this->get_list($return[0]['id'], false, $html);
		$this->lists_task[$return[0]['id']] = $inbox_tasks;
		return $this->lists = $return;
	}

	// --------------------------------------------------------------------

	/**
	 * Set a benchmark marker
	 *
	 * Multiple calls to this function can be made so that several
	 * execution points can be timed
	 *
	 * @access	public
	 * @param	string	$name	name of the marker
	 * @return	void
	 */
	function mark($name)
	{
		$this->marker[$name][] = microtime(true);
	}

	/*
	* Removes a list
	*
	* Usage: remove_list(list_id)
	* Date param is optional. Use it only if you want to set a due date
	* Example Usage: remove_list(123456);
	*
	* @param: string
	* @return: string
	* @author: James Mountford
	*/
	public function remove_list($id)
	{
		$params = array(
			"id" => $id,
			"deleted" => 1,
		);
		$json = json_encode($params);
		$params = array("list" => $json);
		$serialized = $this->_serialize($params);
		$return = $this->_curl($this->wunderlist_url."/ajax/lists/update/", $serialized);
		return $this->_json_result($return, '', true);
	}

	/*
	* Updates a list
	*
	* Usage: update_list(list_id, name)
	* Date param is optional. Use it only if you want to set a due date
	* Example Usage: update_list(123456, "Test");
	*
	* @param: string
	* @param: string
	* @return: string
	* @author: James Mountford
	*/
	public function update_list($id, $name)
	{
		$params = array(
			"id" => $id,
			"name" => $name,
		);
		$json = json_encode($params);
		$params = array("list" => $json);
		$serialized = $this->_serialize($params);
		$return = $this->_curl($this->wunderlist_url."/ajax/lists/update/", $serialized);
		return $this->_json_result($return, 'id', true);
	}

	/*
	 * Updates a task
	 *
	 * Usage: update_task(task_id, param array)
	 * Param array accepts: name, note, date and important
	 * Example Usage: update_task("123456", array("name" => "New Name", "note" => "Some note", "date" => "1323415672", "important" => "1"));
	 *
	 * @param: string
	 * @param: array
	 * @return: string
	 */
	public function update_task($id, $params)
	{
		$params = $params + array('id'=>$id);
		if(isset($params['done'])) {
			$params += array("done_date"=>0);
			var_dump('$params[done]'. $params['done']);
			if($params['done'] == 1) {
				if(empty($params['done_date'])) {
					$params['done_date'] = time();
				}
			}
		}
		$json = json_encode($params);
		$params = array("task" => $json);
		$serialized = $this->_serialize($params);
		$return = $this->_curl($this->wunderlist_url."/ajax/tasks/update/", $serialized);
		return $this->_json_result($return, '', true);
	}
}
/* Note:  */
/* End of file  */