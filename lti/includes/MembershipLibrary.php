<?php
/*
 *  wordpress-lti - WordPress module to add LTI support
 *  Copyright (C) 2013  Simon Booth, Stephen P Vickers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License along
 *  with this program; if not, write to the Free Software Foundation, Inc.,
 *  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 *  Contact: s.p.booth@stir.ac.uk
 *
 *  Version history:
 *    1.0.00  18-Apr-13  Initial release
 */

/*-------------------------------------------------------------------
 * Return a list of LTI_WP_User
 *
 * Parameters
 *  $lti_users - List of LTI_User (from LTI_Tool_Provider)
 ------------------------------------------------------------------*/
function lti_create_user_list ($lti_users) {
  $counter = 0;
  $all_users = array();

  foreach($lti_users as $user) {
    $ltiuser = new LTI_WP_User($user);

    $counter++;
    $ltiuser->id = $counter;

    $all_users[] = $ltiuser;
  }

  return $all_users;
}

/*-------------------------------------------------------------------
 * Build various synchronisation lists
 *
 * Parameters
 *  $membership_consumer - List of members from LTI consumer
 ------------------------------------------------------------------*/
function lti_create_membership_lists($membership_consumer) {

  global $blog_id;

  if (empty($membership_consumer)) return FALSE;

  foreach ($membership_consumer as $member) {

    // Get new users to WordPress
    $user = get_user_by('login', $member->username);

    if (empty($user)) {
      $member->provision = TRUE;
      // Check if new admin
      if ($member->staff === TRUE) $member->newadmin = TRUE;
    } else {

      // Existing users in WordPress but not members of this blog
      if (!is_user_member_of_blog($user->ID, $blog_id)) {
        $member->new_to_blog = TRUE;
        $member->id = $user->ID;
        // Administrator too!
        if ($member->staff === TRUE) $member->newadmin = TRUE;
      }

      // Changed users --- name
      $user_data = get_userdata($user->ID);
      if ($member->fullname != $user_data->display_name) {
        $member->changed = TRUE;
        $member->id = $user->ID;
      }

      // Changed role (student -> staff; staff -> student)
      $user = new WP_User('', $member->username, $blog_id);
      if ($member->staff === TRUE &&
          !$user->has_cap('administrator') &&
          is_user_member_of_blog($user->ID, $blog_id)) {
        $member->role_changed = 'administrator';
        $member->id = $user->ID;
      }

      if ($member->staff === FALSE &&
          $member->learner === TRUE &&
          $user->has_cap('administrator') &&
          is_user_member_of_blog($user->ID, $blog_id)) {

        $member->role_changed = 'author';
        $member->id = $user->ID;
      }

      if ($member->staff === FALSE &&
          $member->learner === TRUE &&
          !$user->has_cap('author') &&
          is_user_member_of_blog($user->ID, $blog_id)) {

        $member->role_changed = 'author';
        $member->id = $user->ID;
      }

      if ($member->staff === FALSE &&
          $member->learner === FALSE &&
          ($user->has_cap('author') || $user->has_cap('administrator')) &&
          is_user_member_of_blog($user->ID, $blog_id)) {

        $member->role_changed = 'subscriber';
        $member->id = $user->ID;
      }
    }
  }

  return $membership_consumer;
}

/*-------------------------------------------------------------------
 * Sort out any deleted members of the blog
 *
 * Parameters
 *  $membership_consumer - members from LTI consumer
 *  $current_members - members of the blog
 ------------------------------------------------------------------*/
function lti_deleted_members($membership_consumer, $current_members) {

  $users_to_delete = array();

  // Extract usernames from LTI consumer
  foreach ($membership_consumer as $member) {
    $from_consumer[] = $member->username;
  }

  // Extract usernames from blog memebrs for this context (in VLE terms: course)
  $currentmembers;
  foreach ($current_members as $blogmember) {
    $user_data = get_userdata($blogmember->ID);
    $pattern = '/^' . $_SESSION[LTI_SESSION_PREFIX . 'userkey'] . '/i';
    if (preg_match($pattern, strtolower($user_data->user_login))) {
      $currentmembers[] = $user_data->user_login;
    }

    if ($_SESSION[LTI_SESSION_PREFIX . 'userkey'] == $consumer_key[0]) $currentmembers[] = $user_data->user_login;
  }

  // If there are no current members there is nothing to delete.
  if (count($currentmembers) == 0) return $users_to_delete;

  // Get the difference between the list. array_diff returns an
  // array containing all the entries from the first array that
  // are not present in any of the other arrays
  $deleted_members = array_diff($currentmembers, $from_consumer);
  // Build a list of LTI_WP_User that are to be removed from the
  // blog
  foreach ($deleted_members as $deleted) {
    $lti_delete = new LTI_WP_User();
    $lti_delete->username = $deleted;
    $lti_delete->delete = TRUE;
    $user = get_user_by('login', $deleted);
    $lti_delete->fullname = $user->display_name;
    $lti_delete->email = $user->user_email;
    $users_to_delete[] = $lti_delete;
  }

  return $users_to_delete;
}

/*-------------------------------------------------------------------
 * From the list of users from the consumer derive various lists
 *
 * Parameters
 *  $users - the list of users
 *  $criteria - which list to create
 ------------------------------------------------------------------*/
function lti_get_members($users, $criteria) {
  $result = array();
  $user_data = unserialize($users);
  foreach ($user_data as $user) {
    switch ($criteria) {
      case 'provision':
        if ($user->provision === TRUE) $result[] = $user;
      break;
      case 'new_to_blog':
        if ($user->new_to_blog === TRUE) $result[] = $user;
      break;
      case 'newadmins':
        if ($user->newadmin === TRUE) $result[] = $user;
      break;
      // Name/Email change
      case 'changed':;
        if ($user->changed === TRUE) $result[] = $user;
      break;
      // Role changed
      case 'rchanged':
        if (!empty($user->role_changed)) $result[] = $user;
      break;
    }
  }
  return $result;
}

/*-------------------------------------------------------------------
 * Form wrapper for list of users
 *
 * Parameter
 *  $users - list of users to display
 *  $ltiuser - WP_User_List_Table object
 ------------------------------------------------------------------*/
function lti_display ($users, $ltiuser) {

  $user_data = unserialize($users);

  $ltiuser->prepare_items($user_data);
  ?>
  <!-- Forms are NOT created automatically, so you need to wrap the table in one to use features like bulk actions -->
  <form id="ltiuser-filter" method="get">
    <!-- For plugins, we also need to ensure that the form posts back to our current page -->
    <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
    <!-- Now we can render the completed list table -->
    <?php $ltiuser->views(); $ltiuser->display(); if ($_SESSION[LTI_SESSION_PREFIX . 'nochanges'] == 1) {?>
    <p class="submit">
    <input id="delete" class="button-primary" type="submit" value="<?php _e('Update with Deletions', 'lti-text'); ?>" name="delete">
    <input id="nodelete" class="button-primary" type="submit" value="<?php _e('Update without Deletions', 'lti-text'); ?>" name="nodelete">
    </p>
    <?php } ?>
  </form>
  <?php
}
?>
