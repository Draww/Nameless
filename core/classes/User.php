<?php
/*
 *	Made by Samerton
 *  https://github.com/NamelessMC/Nameless/
 *  NamelessMC version 2.0.0-pr2
 *
 *  User class
 */
class User {
	private $_db,
			$_data,
			$_sessionName,
			$_cookieName,
			$_isLoggedIn,
			$_admSessionName,
			$_isAdmLoggedIn;

	// Construct User class
	public function __construct($user = null) {
		$this->_db = DB::getInstance();
		$this->_sessionName = Config::get('session/session_name');
		$this->_cookieName = Config::get('remember/cookie_name');
		$this->_admSessionName = Config::get('session/admin_name');

		if(!$user){
			if(Session::exists($this->_sessionName)) {
				$user = Session::get($this->_sessionName);
				if($this->find($user)){
					$this->_isLoggedIn = true;
				} else {
					// process logout
				}
			}
			if(Session::exists($this->_admSessionName)) {
				$user = Session::get($this->_admSessionName);
				if($user == $this->data()->id && $this->find($user)){
					$this->_isAdmLoggedIn = true;
				} else {
					// process logout
				}
			}
		} else {
			$this->find($user);
		}

	}

	// Get name of group from an ID
	public function getGroupName($group_id) {
		$data = $this->_db->get('groups', array('id', '=', $group_id));
		if($data->count()) {
			$results = $data->results();
			return htmlspecialchars($results[0]->name);
		} else {
			return false;
		}
	}

	// Get a group's CSS class from an ID
	public function getGroupClass($user_id) {
		$user = $this->_db->get('users', array('id', '=', $user_id));

		// Check the user exists..
		if($user->count()){
			// Get the user's group
			$group_id = $user->results();
			$group_id = $group_id[0]->group_id;

			$data = $this->_db->get('groups', array('id', '=', $group_id));
			if($data->count()) {
				$results = $data->results();
				return 'color:' . htmlspecialchars($results[0]->group_username_css) . ';';
			}
		}

		return false;
	}

	// Get a user's IP address
	public function getIP() {
		if(!empty($_SERVER['HTTP_CLIENT_IP'])) {
		  $ip = $_SERVER['HTTP_CLIENT_IP'];
		} else if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		  $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
		  $ip = $_SERVER['REMOTE_ADDR'];
		}
		return $ip;
	}

	// Update a user's data
	public function update($fields = array(), $id = null) {

		if(!$id && $this->isLoggedIn()) {
			$id = $this->data()->id;
		}

		if(!$this->_db->update('users', $id, $fields)) {
			throw new Exception('There was a problem updating your details.');
		}
	}

	// Create a new user
	public function create($fields = array()) {
		if(!$this->_db->insert('users', $fields)) {
			throw new Exception('There was a problem creating an account.');
		}
	}

	// Find a specified user by username
	// Params: $user (mixed) - either username or user ID to search for
	//         $force_username (boolean) - if true, only search using username, not ID
	public function find($user = null, $force_username = false) {
		if ($user) {
			$field = ($force_username === false && is_numeric($user)) ? 'id' : 'username';
			$data = $this->_db->get('users', array($field, '=', $user));

			if($data->count()) {
				$this->_data = $data->first();
				return true;
			}
		}
		return false;
	}

	// Get username from ID
	public function IdToName($id = null){
		if($id){
			$data = $this->_db->get('users', array('id', '=', $id));

			if($data->count()){
				$results = $data->results();
				return $results[0]->username;
			}
		}
		return false;
	}

	// Get nickname from ID
	public function IdToNickname($id = null) {
		if($id){
			$data = $this->_db->get('users', array('id', '=', $id));

			if($data->count()) {
				$results = $data->results();
				return $results[0]->nickname;
			}
		}
		return false;
	}

	// Log the user in
	public function login($username = null, $password = null, $remember = false) {
		if(!$username && !$password && $this->exists()){
			Session::put($this->_sessionName, $this->data()->id);
			$this->_isLoggedIn = true;
		} else {
			$user = $this->find($username, true);
			if($user){
			    switch($this->data()->pass_method) {
                    case 'wordpress':
                        // phpass
                        $phpass = new PasswordHash(8, FALSE);

                        if($phpass->CheckPassword($password, $this->data()->password)){
                            Session::put($this->_sessionName, $this->data()->id);
                            if($remember) {
                                $hash = Hash::unique();
                                $hashCheck = $this->_db->get('users_session', array('user_id', '=', $this->data()->id));
                                if(!$hashCheck->count()) {
                                    $this->_db->insert('users_session', array(
                                        'user_id' => $this->data()->id,
                                        'hash' => $hash
                                    ));
                                } else
                                    $hash = $hashCheck->first()->hash;

                                Cookie::put($this->_cookieName, $hash, Config::get('remember/cookie_expiry'));
                            }
                            return true;
                        }
                        break;

                    case 'sha256':
                        $exploded = explode('$', $this->data()->password);

                        $salt = $exploded[0];
                        $pass = $exploded[1];

                        if($salt . hash('sha256', hash('sha256', $password) . $salt) == $salt . $pass) {
                            Session::put($this->_sessionName, $this->data()->id);

                            if($remember) {
                                $hash = Hash::unique();
                                $hashCheck = $this->_db->get('users_session', array('user_id', '=', $this->data()->id));
                                if(!$hashCheck->count()) {
                                    $this->_db->insert('users_session', array(
                                        'user_id' => $this->data()->id,
                                        'hash' => $hash
                                    ));
                                } else
                                    $hash = $hashCheck->first()->hash;

                                Cookie::put($this->_cookieName, $hash, Config::get('remember/cookie_expiry'));
                            }
                            return true;
                        }

                        break;

                    case 'pbkdf2':
                        $exploded = explode('$', $this->data()->password);

                        $iterations = $exploded[0];
                        $salt = $exploded[1];
                        $pass = $exploded[2];

                        $hashed = hash_pbkdf2('sha256', $password, $salt, $iterations, 64, true);

                        if($hashed == hex2bin($pass)){
                            Session::put($this->_sessionName, $this->data()->id);

                            if($remember) {
                                $hash = Hash::unique();
                                $hashCheck = $this->_db->get('users_session', array('user_id', '=', $this->data()->id));
                                if(!$hashCheck->count()) {
                                    $this->_db->insert('users_session', array(
                                        'user_id' => $this->data()->id,
                                        'hash' => $hash
                                    ));
                                } else
                                    $hash = $hashCheck->first()->hash;

                                Cookie::put($this->_cookieName, $hash, Config::get('remember/cookie_expiry'));
                            }
                            return true;
                        }

                        break;

                    case 'modernbb':
                    case 'sha1':
                        if(sha1($password) == $this->data()->password){
                            Session::put($this->_sessionName, $this->data()->id);

                            if($remember) {
                                $hash = Hash::unique();
                                $hashCheck = $this->_db->get('users_session', array('user_id', '=', $this->data()->id));
                                if(!$hashCheck->count()) {
                                    $this->_db->insert('users_session', array(
                                        'user_id' => $this->data()->id,
                                        'hash' => $hash
                                    ));
                                } else
                                    $hash = $hashCheck->first()->hash;

                                Cookie::put($this->_cookieName, $hash, Config::get('remember/cookie_expiry'));
                            }
                            return true;
                        }
                        break;

                    default:
                        // Default to bcrypt
                        if(password_verify($password, $this->data()->password)){
                            Session::put($this->_sessionName, $this->data()->id);

                            if($remember){
                                $hash = Hash::unique();
                                $hashCheck = $this->_db->get('users_session', array('user_id', '=', $this->data()->id));

                                if(!$hashCheck->count()){
                                    $this->_db->insert('users_session', array(
                                        'user_id' => $this->data()->id,
                                        'hash' => $hash
                                    ));
                                } else
                                    $hash = $hashCheck->first()->hash;

                                Cookie::put($this->_cookieName, $hash, Config::get('remember/cookie_expiry'));
                            }
                            return true;
                        }
                        break;
                }
			}
		}
		return false;
	}

	// Handle AdminCP logins
	public function adminLogin($username = null, $password = null) {
		if(!$username && !$password && $this->exists()){
			Session::put($this->_admSessionName, $this->data()->id);
		} else {
			$user = $this->find($username, true);
			if($user){
				if(password_verify($password, $this->data()->password)) {
					Session::put($this->_admSessionName, $this->data()->id);

					$hash = Hash::unique();
					$hashCheck = $this->_db->get('users_admin_session', array('user_id', '=', $this->data()->id));

					if(!$hashCheck->count()) {
						$this->_db->insert('users_admin_session', array(
							'user_id' => $this->data()->id,
							'hash' => $hash
						));
					} else {
						$hash = $hashCheck->first()->hash;
					}

					Cookie::put($this->_cookieName . "_adm", $hash, 3600);


					return true;
				}
			}
		}
		return false;
	}

	// Get a user's group from their ID. We can either return their ID only, their normal HTML display code, or their large HTML display code
	public function getGroup($id, $html = null, $large = null) {
		$data = $this->_db->get('users', array('id', '=', $id));
		if($html === null){
			if($large === null){
				$results = $data->results();
				return $results[0]->group_id;
			} else {
				$results = $data->results();
				$data = $this->_db->get('groups', array('id', '=', $results[0]->group_id));
				$results = $data->results();
				return $results[0]->group_html_lg;
			}
		} else {
			$results = $data->results();
			$data = $this->_db->get('groups', array('id', '=', $results[0]->group_id));
			$results = $data->results();
			return $results[0]->group_html;
		}
	}

	// Get a user's signature, by user ID
	public function getSignature($id) {
		$data = $this->_db->get('users', array('id', '=', $id));
		$results = $data->results();
		if(!empty($results[0]->signature)){
			return $results[0]->signature;
		} else {
			return "";
		}
	}

	// Get a user's avatar, based on user ID
	public function getAvatar($id, $path = null, $size = 50) {
		// Do they have an avatar?
		$data = $this->_db->get('users', array('id', '=', $id))->results();
		if(empty($data)){
			// User doesn't exist
			return false;
		} else {
			// Gravatar?
			if($data[0]->gravatar == 1){
				// Gravatar
				return "http://www.gravatar.com/avatar/" . md5( strtolower( trim( $data[0]->email ) ) ) . "?d=" . urlencode( 'https://cravatar.eu/avatar/Steve/200.png' ) . "&s=200";
			} else if($data[0]->has_avatar == 1){
				// Custom avatar
				$exts = array('gif','png','jpg');
				foreach($exts as $ext) {
					if(file_exists(ROOT_PATH . "/avatars/" . $id . "." . $ext)){
						$avatar_path = "/avatars/" . $id . "." . $ext;
						break;
					}
				}
				if(isset($avatar_path)){
					return $avatar_path;
				} else {
					return false;
				}
			} else {
				// Minecraft avatar
				$avatar_type = $this->_db->get('settings', array('name', '=', 'avatar_type'))->results();

				if(count($avatar_type)){
					$avatar_type = $avatar_type[0]->value;
					switch($avatar_type){
						case 'avatar':
							return 'https://cravatar.eu/avatar/' . htmlspecialchars($data[0]->username) . '/' . $size . '.png';
						break;
						case 'helmavatar':
							return 'https://cravatar.eu/helmavatar/' . htmlspecialchars($data[0]->username) . '/' . $size . '.png';
						break;
						default:
							return 'https://cravatar.eu/avatar/' . htmlspecialchars($data[0]->username) . '/' . $size . '.png';
						break;
					}
				} else {
					return 'https://cravatar.eu/avatar/' . htmlspecialchars($data[0]->username) . '/' . $size . '.png';
				}
			}
		}
	}

	// Does the user have any infractions?
	public function hasInfraction($user_id){
		$data = $this->_db->get('infractions', array('punished', '=', $user_id))->results();
		if(empty($data)){
			return false;
		} else {
			$return = array();
			$n = 0;
			foreach($data as $infraction){
				if($infraction->acknowledged == '0'){
					$return[$n]["id"] = $infraction->id;
					$return[$n]["staff"] = $infraction->staff;
					$return[$n]["reason"] = $infraction->reason;
					$return[$n]["date"] = $infraction->infraction_date;
					$n++;
				}
			}
			return $return;
		}
	}

	// Does the user exist?
	public function exists() {
		return (!empty($this->_data)) ? true : false;
	}

	// Log the user out
	public function logout() {

		$this->_db->delete('users_session', array('user_id', '=', $this->data()->id));

		Session::delete($this->_sessionName);
		Cookie::delete($this->_cookieName);
	}

	// Process logout if user is admin
	public function admLogout() {

		$this->_db->delete('users_admin_session', array('user_id', '=', $this->data()->id));

		Session::delete($this->_admSessionName);
		Cookie::delete($this->_cookieName . "_adm");
	}

	// Returns the currently logged in user's data
	public function data() {
		return $this->_data;
	}

	// Returns true if the current user is logged in
	public function isLoggedIn() {
		return $this->_isLoggedIn;
	}

	// Returns true if the current user is authenticated as an administrator
	public function isAdmLoggedIn() {
		return $this->_isAdmLoggedIn;
	}

	// Return a comma separated string of all users - this is for the new private message dropdown
	public function listAllUsers() {
		$data = $this->_db->get('users', array('id', '<>', '0'))->results();
		$return = "";
		$i = 1;

		foreach($data as $item){
			if($i != count($data)){
				$return .= '"' . $item->username . '",';
			} else {
				$return .= '"' . $item->username . '"';
			}
			$i++;
		}
		return $return;
	}

	// Return an ID from a username
	public function NameToId($name = null){
		if($name){
			$data = $this->_db->get('users', array('username', '=', $name));

			if($data->count()){
				$results = $data->results();
				return $results[0]->id;
			}
		}
		return false;
	}

	// Get a list of PMs a user has access to
	public function listPMs($user_id = null){
		if($user_id){
			$return = array(); // Array to return containing info of PMs

			// Get a list of PMs which the user is in
			$data = $this->_db->get('private_messages_users', array('user_id', '=', $user_id));

			if($data->count()){
				$data = $data->results();
				foreach($data as $result){
					// Get a list of users who are in this conversation and return them as an array
					$pms = $this->_db->get('private_messages_users', array('pm_id', '=', $result->pm_id))->results();
					$users = array(); // Array containing users with permission
					foreach($pms as $pm){
						$users[] = $pm->user_id;
					}

					// Get the PM data
					$pm = $this->_db->get('private_messages', array('id', '=', $result->pm_id))->results();
					$pm = $pm[0];

					$return[$pm->id]['id'] = $pm->id;
					$return[$pm->id]['title'] = Output::getClean($pm->title);
					$return[$pm->id]['created'] = $pm->created;
					$return[$pm->id]['updated'] = $pm->last_reply_date;
					$return[$pm->id]['user_updated'] = $pm->last_reply_user;
					$return[$pm->id]['users'] = $users;
				}
			}
			// Order the PMs by date updated - most recent first
			usort($return, function($a, $b) {
				return $b['updated'] - $a['updated'];
			});

			return $return;
		}
		return false;
	}

	// Get a specific private message, and see if the user actually has permission to view it
	public function getPM($pm_id = null, $user_id = null){
		if($user_id && $pm_id){
			// Get the PM - is the user the author?
			$data = $this->_db->get('private_messages', array('id', '=', $pm_id));
			if($data->count()){
				$data = $data->results();
				$data = $data[0];

				// Does the user have permission to view the PM?
				$pms = $this->_db->get('private_messages_users', array('pm_id', '=', $pm_id))->results();
				foreach($pms as $pm){
					if($pm->user_id == $user_id){
						$has_permission = true;
						$pm_user_id = $pm->id;
						break;
					}
				}

				if(!isset($has_permission)){
					return false; // User doesn't have permission
				}

				// Set message to "read"
				if($pm->read == 0){
					$this->_db->update('private_messages_users', $pm_user_id, array(
						'`read`' => 1
					));
				}

				// User has permission, return the PM information

				// Get a list of users in the conversation
				if(!isset($pms)){
					$pms = $this->_db->get('private_messages_users', array('pm_id', '=', $pm_id))->results();
				}

				$users = array(); // Array to store users
				foreach($pms as $pm){
					$users[] = $pm->user_id;
				}

				return array($data, $users);
			}
		}
		return false;
	}

	// Delete a user's access to view the PM, or if they're the author, the PM itself
	public function deletePM($pm_id = null, $user_id = null){
		if($user_id && $pm_id){
			// Is the user the author?
			$data = $this->_db->get('private_messages', array('id', '=', $pm_id));
			if($data->count()){
				$data = $data->results();
				$data = $data[0];
				if($data->author_id != $user_id){
					// User is not the author, only delete
					$pms = $this->_db->get('private_messages_users', array('pm_id', '=', $pm_id))->results();
					foreach($pms as $pm){
						if($pm->user_id == $user_id){
							// get the ID and delete
							$id = $pm->id;
							$this->_db->delete('private_messages_users', array('id', '=', $id));
							return true;
						}
					}
				} else {
					// User is the author, delete the PM altogether
					$this->_db->delete('private_messages_users', array('pm_id', '=', $pm_id));
					$this->_db->delete('private_messages', array('id', '=', $pm_id));
					return true;
				}
			}
		}
		return false;
	}

	// Get the number of unread PMs for the specified user
	public function getUnreadPMs($user_id = null){
		if($user_id){
			$pms = $this->_db->get('private_messages_users', array('user_id', '=', $user_id));
			if($pms->count()){
				$pms = $pms->results();
				$count = 0;
				foreach($pms as $pm){
					if($pm->read == 0){
						$count++;
					}
				}
				return $count;
			} else {
				return 0;
			}
		}
		return false;
	}

	// Can the specified user view the AdminCP?
	public function canViewACP(){
		if($this->isLoggedIn()){
			// Get whether the user can view the AdminCP from the groups table
			$data = $this->_db->get('groups', array('id', '=', $this->data()->group_id));
			if($data->count()){
				$data = $data->results();
				if($data[0]->admin_cp == 1){
					// Can view
					return true;
				}
			}
		}
		return false;
	}

	// Can the specified user view the ModCP?
	public function canViewMCP(){
		if($this->isLoggedIn()){
			// Get whether the user can view the ModCP from the groups table
			$data = $this->_db->get('groups', array('id', '=', $this->data()->group_id));
			if($data->count()){
				$data = $data->results();
				if($data[0]->mod_cp == 1){
					// Can view
					return true;
				}
			}
		}
		return false;
	}

	// Can the specified user view staff applications?
	public function canViewApps($user_id = null){
		if($user_id){
			$data = $this->_db->get('users', array('id', '=', $user_id));
			if($data->count()){
				$user_group = $data->results();
				$user_group = $user_group[0]->group_id;
				// Get whether the user can view applications from the groups table
				$data = $this->_db->get('groups', array('id', '=', $user_group));
				if($data->count()){
					$data = $data->results();
					if($data[0]->staff_apps == 1){
						// Can view
						return true;
					}
				}
			}
		}
		return false;
	}

	// Can the specified user accept staff applications?
	public function canAcceptApps($user_id = null){
		if($user_id){
			$data = $this->_db->get('users', array('id', '=', $user_id));
			if($data->count()){
				$user_group = $data->results();
				$user_group = $user_group[0]->group_id;
				// Get whether the user can accept applications from the groups table
				$data = $this->_db->get('groups', array('id', '=', $user_group));
				if($data->count()){
					$data = $data->results();
					if($data[0]->accept_staff_apps == 1){
						// Can view
						return true;
					}
				}
			}
		}
		return false;
	}

	// Return profile fields for specified user
	// Params:  $user_id (integer) - user id of user to retrieve fields from
	//			$public (boolean)  - whether to only return public fields or not (default true)
	//			$forum (boolean)   - whether to only return fields which display on forum posts, only if $public is true (default false)
	public function getProfileFields($user_id = null, $public = true, $forum = false){
		if($user_id){
			$data = $this->_db->get('users_profile_fields', array('user_id', '=', $user_id));

			if($data->count()){
				if($public == true){
					// Return public fields only
					$return = array();
					foreach($data->results() as $result){
						$is_public = $this->_db->get('profile_fields', array('id', '=', $result->field_id));
						if(!$is_public->count()) continue;
						else $is_public = $is_public->results();

						if($is_public[0]->public == 1){
							if($forum == true){
								if($is_public[0]->forum_posts == 1){
									$return[] = array(
										'name' => Output::getClean($is_public[0]->name),
										'value' => Output::getClean($result->value)
									);
								}
							} else {
								$return[] = array(
									'name' => Output::getClean($is_public[0]->name),
									'value' => Output::getClean($result->value)
								);
							}
						}
					}

					return $return;
				} else {
					// Return all fields
					$return = array();
					foreach($data->results() as $result){
						$name = $this->_db->get('profile_fields', array('id', '=', $result->field_id));
						if(!$name->count()) continue;
						else $name = $name->results();

						$return[] = array(
							'name' => Output::getClean($name[0]->name),
							'value' => Output::getClean($result->value)
						);
					}

					return $return;
				}
			} else return false;
		}
		return false;
	}
}
