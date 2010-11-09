<?php

/**
 * @package 	Aspen_Framework
 * @subpackage 	Modules.Base
 * @author 		Michael Botsko
 * @copyright 	2009 Trellis Development, LLC
 * @since 		1.0
 */

/**
 * Handles forms for user accounts
 * @package Aspen_Framework
 * @uses Module
 */
class Users_Admin extends Module {


	/**
	 * Displays the list of users
	 * @access public
	 */
	public function view(){

		$model = model()->open('users');
		$model->contains('groups');
		$model->orderBy('username', 'ASC');
		$data['users'] = $model->results();

		template()->display($data);

	}


	/**
	 * Displays and processes the add a new user form
	 * @access public
	 */
	public function add(){
		$this->edit();
	}


	/**
	 * Displays and processes the edit user form
	 * @access public
	 * @param $id The id of the user record
	 */
	public function edit($id = false){

		if(user()->edit($id)){
			sml()->say('User account changes have been saved successfully.', true);
			router()->redirect('view');
		}

		$data['groups'] = user()->groupList();

		template()->display($data);

	}


	/**
	 * Displays and processes the my account form
	 * @access public
	 */
	public function my_account(){

		if(user()->my_account()){
			sml()->say('Your account has been updated successfully.', true);
			router()->redirect('view', false, 'Index');
		}

		template()->display();

	}


	/**
	 * Deletes a user record
	 * @param integer $id The record id of the user
	 * @access public
	 */
	public function delete($id = false){
		if(user()->delete($id)){
			sml()->say('User account has been deleted successfully.', true);
			router()->redirect('view');
		}
	}


	/**
	 * Displays the user login page
	 * @access public
	 */
	public function login(){
		user()->login();
		template()->display();
	}


	/**
	 * Displays and processes the forgotten password reset form
	 * @access public
	 */
	public function forgot(){

		if(user()->forgot() == 1){
			sml()->say('Your password has been reset. Please check your email.', true);
			router()->redirect('login');
		}
		elseif(user()->forgot() == -1){
			sml()->say('We were unable to find any accounts matching that username.', false);
			router()->redirect('forgot');
		}

		template()->display();

	}


	/**
	 * Runs the authentication process on the login form data
	 * @access public
	 */
	public function authenticate(){
		if(user()->authenticate()){
			router()->redirectToUrl(user()->postLoginRedirect());
		} else {
			user()->login_failed();
		}
	}


	/**
	 * Processes a logout
	 * @access public
	 */
	public function logout(){
		user()->logout();
		router()->redirectToUrl(router()->interfaceUrl());
	}


	/**
	 * Displays a permission denied error message
	 * @access public
	 */
	public function denied(){
		$this->setPageTitle(text('users:denied:head-title'));
		template()->display();
	}
}
?>