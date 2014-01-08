<?php
/**
 * LTI_Tool_Provider - PHP class to include in an external tool to handle connections with an LTI 1 compliant tool consumer
 * Copyright (C) 2013  Stephen P Vickers
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * Contact: stephen@spvsoftwareproducts.com
 *
 * Version history:
 *   2.0.00  30-Jun-12  Initial release (replacing version 1.1.01 of BasicLTI_Tool_Provider)
 *   2.1.00   3-Jul-12  Added option to restrict use of consumer key based on tool consumer GUID value
 *                      Added field to record day of last access for each consumer key
 *   2.2.00  16-Oct-12  Added option to return parameters sent in last extension request
 *                      Released under GNU Lesser General Public License, version 3
 *   2.3.00   2-Jan-13  Removed autoEnable property from LTI_Tool_Provider class (including constructor parameter)
 *                      Added LTI_Tool_Provider->setParameterConstraint() method
 *                      Changed references to $_REQUEST to $_POST
 *                      Added LTI_Tool_Consumer->getIsAvailable() method
 *                      Deprecated LTI_Context (use LTI_Resource_Link instead), other references to Context deprecated in favour of Resource_Link
 *   2.3.01   2-Feb-13  Added error callback option to LTI_Tool_Provider class
 *                      Fixed typo in setParameterConstraint function
 *                      Updated to use latest release of OAuth dependent library
 *                      Added message property to LTI_Tool_Provider class to override default message returned on error
 *   2.3.02  18-Apr-13  Tightened up checking of roles - now case sensitive and checks fully qualified URN
 *                      Fixed bug with not updating a resource link before redirecting to a shared resource link
 */

/**
 * OAuth libaray file
 */
require_once('OAuth.php');

/**
 * Class to represent an LTI Tool Provider
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Tool_Provider {

  const CONNECTION_ERROR_MESSAGE = 'Sorry, there was an error connecting you to the application.';

/**
 * LTI version for messages.
 */
  const LTI_VERSION = 'LTI-1p0';
/**
 * Use ID value only.
 */
  const ID_SCOPE_ID_ONLY = 0;
/**
 * Prefix an ID with the consumer key.
 */
  const ID_SCOPE_GLOBAL = 1;
/**
 * Prefix the ID with the consumer key and context ID.
 */
  const ID_SCOPE_CONTEXT = 2;
/**
 * Prefix the ID with the consumer key and resource ID.
 */
  const ID_SCOPE_RESOURCE = 3;
/**
 * Character used to separate each element of an ID.
 */
  const ID_SCOPE_SEPARATOR = ':';

/**
 *  True if the last request was successful.
 */
  public $isOK = TRUE;

/**
 *  LTI_Tool_Consumer object.
 */
  public $consumer = NULL;
/**
 *  Return URL provided by tool consumer.
 */
  public $return_url = NULL;
/**
 *  LTI_User object.
 */
  public $user = NULL;
/**
 *  LTI_Resource_Link object.
 */
  public $resource_link = NULL;
/**
 *  LTI_Context object.
 *
 *  @deprecated Use resource_link instead
 *  @see LTI_Tool_Provider::$resource_link
 */
  public $context = NULL;
/**
 *  Data connector object.
 */
  public $data_connector = NULL;
/**
 *  Default email domain.
 */
  public $defaultEmail = '';
/**
 *  Scope to use for user IDs.
 */
  public $id_scope = self::ID_SCOPE_ID_ONLY;
/**
 *  True if shared resource link arrangements are permitted.
 */
  public $allowSharing = FALSE;
/**
 *  Message for last request processed.
 */
  public $message = self::CONNECTION_ERROR_MESSAGE;
/**
 *  Error message for last request processed.
 */
  public $reason = NULL;

/**
 *  URL to redirect user to on successful completion of the request.
 */
  private $redirectURL = NULL;
/**
 *  Callback functions for handling requests.
 */
  private $callbackHandler = NULL;
/**
 *  HTML to be displayed on successful completion of the request.
 */
  private $output = NULL;
/**
 *  URL to redirect user to if the request is not successful.
 */
  private $error = NULL;
/**
 *  True if debug messages explaining the cause of errors are to be returned to the tool consumer.
 */
  private $debugMode = FALSE;
/**
 *  Array of LTI parameter constraints for auto validation checks.
 */
  private $constraints = NULL;
/**
 *  Names of LTI parameters to be retained in the settings property.
 */
  private $lti_settings_names = array('ext_resource_link_content', 'ext_resource_link_content_signature',
                                      'lis_result_sourcedid', 'lis_outcome_service_url',
                                      'ext_ims_lis_basic_outcome_url', 'ext_ims_lis_resultvalue_sourcedids',
                                      'ext_ims_lis_memberships_id', 'ext_ims_lis_memberships_url',
                                      'ext_ims_lti_tool_setting', 'ext_ims_lti_tool_setting_id', 'ext_ims_lti_tool_setting_url');

/**
 * Class constructor
 *
 * @param mixed   $callbackHandler String containing name of callback function for connect request, or associative array of callback functions for each request type
 * @param mixed   $data_connector  String containing table name prefix, or database connection object, or array containing one or both values (optional, default is a blank prefix and MySQL)
 */
  function __construct($callbackHandler, $data_connector = '') {

    if (!is_array($callbackHandler)) {
      $this->callbackHandler['connect'] = $callbackHandler;
    } else if (isset($callbackHandler['connect'])) {
      $this->callbackHandler = $callbackHandler;
    } else if (count($callbackHandler) > 0) {
      $callbackHandlers = array_values($callbackHandler);
      $this->callbackHandler['connect'] = $callbackHandlers[0];
    }
    $this->data_connector = $data_connector;
    $this->constraints = array();
    $this->context = &$this->resource_link;


  }

/**
 * Process a launch request
 *
 * @return mixed Returns TRUE or FALSE, a redirection URL or HTML
 */
  public function execute() {

#
### Initialise data connector
#
    $this->data_connector = LTI_Data_Connector::getDataConnector($this->data_connector);
#
### Set return URL if available
#
    if (isset($_POST['launch_presentation_return_url'])) {
      $this->return_url = $_POST['launch_presentation_return_url'];
    }
#
### Perform action
#
    if ($this->authenticate()) {
      $this->doCallback();
    }
    $this->result();

  }

/**
 * Add a parameter constraint to be checked on launch
 *
 * @param string Name of parameter to be checked
 * @param boolean True if parameter is required
 * @param int Maximum permitted length of parameter value (optional, default is NULL)
 */
  public function setParameterConstraint($name, $required, $max_length = NULL) {

    $name = trim($name);
    if (strlen($name) > 0) {
      $this->constraints[$name] = array('required' => $required, 'max_length' => $max_length);
    }

  }

/**
 * Get an array of defined tool consumers
 *
 * @return array Array of LTI_Tool_Consumer objects
 */
  public function getConsumers() {

#
### Initialise data connector
#
    $this->data_connector = LTI_Data_Connector::getDataConnector($this->data_connector);

    return $this->data_connector->Tool_Consumer_list();

  }

/**
 * Get an array of fully qualified user roles
 *
 * @param string Comma-separated list of roles
 *
 * @return array Array of roles
 */
  static function parseRoles($rolesString) {

    $rolesArray = explode(',', $rolesString);
    $roles = array();
    foreach ($rolesArray as $role) {
      $role = trim($role);
      if (!empty($role)) {
        if (substr($role, 0, 4) != 'urn:') {
          $role = 'urn:lti:role:ims/lis/' . $role;
        }
        $roles[] = $role;
      }
    }

    return $roles;

  }

###
###  PRIVATE METHODS
###

/**
 * Call any callback function for the requested action.
 *
 * This function may set the redirectURL and output properties.
 *
 * @return boolean True if no error reported
 */
  private function doCallback() {

    if (isset($this->callbackHandler['connect'])) {
      $result = call_user_func($this->callbackHandler['connect'], $this);

#
### Callback function may return HTML, a redirect URL, or a boolean value
#
      if (is_string($result)) {
        if ((substr($result, 0, 7) == 'http://') || (substr($result, 0, 8) == 'https://')) {
          $this->redirectURL = $result;
        } else {
          if (is_null($this->output)) {
            $this->output = '';
          }
          $this->output .= $result;
        }
      } else if (is_bool($result)) {
        $this->isOK = $result;
      }
    }

    return $this->isOK;

  }

/**
 * Perform the result of an action.
 *
 * This function may redirect the user to another URL rather than returning a value.
 *
 * @return string Output to be displayed (redirection, or display HTML or message)
 */
  private function result() {

    $ok = FALSE;
    if (!$this->isOK && isset($this->callbackHandler['error'])) {
      $ok = call_user_func($this->callbackHandler['error'], $this);
    }
    if (!$ok) {
      if (!$this->isOK) {
#
### If not valid, return an error message to the tool consumer if a return URL is provided
#
        if (!empty($this->return_url)) {
          $this->error = $this->return_url;
          if (strpos($this->error, '?') === FALSE) {
            $this->error .= '?';
          } else {
            $this->error .= '&';
          }
          if ($this->debugMode && !is_null($this->reason)) {
            $this->error .= 'lti_errormsg=' . urlencode("Debug error: $this->reason");
          } else {
            $this->error .= 'lti_errormsg=' . urlencode($this->message);
            if (!is_null($this->reason)) {
              $this->error .= '&lti_errorlog=' . urlencode("Debug error: $this->reason");
            }
          }
        } else if ($this->debugMode) {
          $this->error = $this->reason;
        }
        if (is_null($this->error)) {
          $this->error = $this->message;
        }
        if ((substr($this->error, 0, 7) == 'http://') || (substr($this->error, 0, 8) == 'https://')) {
          header("Location: {$this->error}");
        } else {
          echo "Error: {$this->error}";
        }
      } else if (!is_null($this->redirectURL)) {
        header("Location: {$this->redirectURL}");
      } else if (!is_null($this->output)) {
        echo $this->output;
      }
    }

  }

/**
 * Check the authenticity of the LTI launch request.
 *
 * The consumer, resource link and user objects will be initialised if the request is valid.
 *
 * @return boolean True if the request has been successfully validated.
 */
  private function authenticate() {

#
### Set debug mode
#
    $this->debugMode = isset($_POST['custom_debug']) && (strtolower($_POST['custom_debug']) == 'true');
#
### Get the consumer
#
    $doSaveConsumer = FALSE;
// Check all required launch parameter constraints
    $this->isOK = isset($_POST['oauth_consumer_key']);
    if ($this->isOK) {
      $this->isOK = isset($_POST['lti_message_type']) && ($_POST['lti_message_type'] == 'basic-lti-launch-request');
    }
    if ($this->isOK) {
      $this->isOK = isset($_POST['lti_version']) && ($_POST['lti_version'] == self::LTI_VERSION);
    }
    if ($this->isOK) {
      $this->isOK = isset($_POST['resource_link_id']) && (strlen(trim($_POST['resource_link_id'])) > 0);
    }
// Check consumer key
    if ($this->isOK) {
      $this->consumer = new LTI_Tool_Consumer($_POST['oauth_consumer_key'], $this->data_connector);
      $this->isOK = !is_null($this->consumer->created);
      if ($this->debugMode && !$this->isOK) {
        $this->reason = 'Invalid consumer key.';
      }
    }
    $now = time();
    if ($this->isOK) {
      $today = date('Y-m-d', $now);
      if (is_null($this->consumer->last_access)) {
        $doSaveConsumer = TRUE;
      } else {
        $last = date('Y-m-d', $this->consumer->last_access);
        $doSaveConsumer = $doSaveConsumer || ($last != $today);
      }
      $this->consumer->last_access = $now;
      $this->isOK = $this->consumer->enabled;
      if ($this->debugMode && !$this->isOK) {
        $this->reason = 'Tool consumer has not been enabled by the tool provider.';
      }
    }
    if ($this->isOK && $this->consumer->protected) {
      if (!is_null($this->consumer->consumer_guid)) {
        $this->isOK = isset($_POST['tool_consumer_instance_guid']) && !empty($_POST['tool_consumer_instance_guid']) &&
           ($this->consumer->consumer_guid == $_POST['tool_consumer_instance_guid']);
        if ($this->debugMode && !$this->isOK) {
          $this->reason = 'Request is from an invalid tool consumer.';
        }
      } else {
        $this->isOK = isset($_POST['tool_consumer_instance_guid']);
        if ($this->debugMode && !$this->isOK) {
          $this->reason = 'A tool consumer GUID must be included in the launch request.';
        }
      }
    }
    if ($this->isOK) {
      $this->isOK = is_null($this->consumer->enable_from) || ($this->consumer->enable_from <= $now);
      if ($this->isOK) {
        $this->isOK = is_null($this->consumer->enable_until) || ($this->consumer->enable_until > $now);
        if ($this->debugMode && !$this->isOK) {
          $this->reason = 'Tool consumer access has expired.';
        }
      } else if ($this->debugMode) {
        $this->reason = 'Tool consumer access is not yet available.';
      }
    }

    if ($this->isOK) {

      try {

        $store = new LTI_OAuthDataStore($this);
        $server = new OAuthServer($store);

        $method = new OAuthSignatureMethod_HMAC_SHA1();
        $server->add_signature_method($method);
        $request = OAuthRequest::from_request();
        $res = $server->verify_request($request);

      } catch (Exception $e) {

        $this->isOK = FALSE;
        if (empty($this->reason)) {
          $this->reason = 'OAuth signature check failed - perhaps an incorrect secret or timestamp.';
        }

      }

    }
#
### Validate launch parameters
#
    if ($this->isOK) {
      $invalid_parameters = array();
      foreach ($this->constraints as $name => $constraint) {
        $ok = TRUE;
        if ($constraint['required']) {
          if (!isset($_POST[$name]) || (strlen(trim($_POST[$name])) <= 0)) {
            $invalid_parameters[] = $name;
            $ok = FALSE;
          }
        }
        if ($ok && !is_null($constraint['max_length']) && isset($_POST[$name])) {
          if (strlen(trim($_POST[$name])) > $constraint['max_length']) {
            $invalid_parameters[] = $name;
          }
        }
      }
      if (count($invalid_parameters) > 0) {
        $this->isOK = FALSE;
        if (empty($this->reason)) {
          $this->reason = 'Invalid parameter(s): ' . implode(', ', $invalid_parameters) . '.';
        }
      }
    }

    if ($this->isOK) {
      $this->consumer->defaultEmail = $this->defaultEmail;
#
### Set the request context/resource link
#
      $this->resource_link = new LTI_Resource_Link($this->consumer, trim($_POST['resource_link_id']));
      if (isset($_POST['context_id'])) {
        $this->resource_link->lti_context_id = trim($_POST['context_id']);
      }
      $this->resource_link->lti_resource_id = trim($_POST['resource_link_id']);
      $title = '';
      if (isset($_POST['context_title'])) {
        $title = trim($_POST['context_title']);
      }
      if (isset($_POST['resource_link_title']) && (strlen(trim($_POST['resource_link_title'])) > 0)) {
        if (!empty($title)) {
          $title .= ': ';
        }
        $title .= trim($_POST['resource_link_title']);
      }
      if (empty($title)) {
        $title = "Course {$this->resource_link->getId()}";
      }
      $this->resource_link->title = $title;
// Save LTI parameters
      foreach ($this->lti_settings_names as $name) {
        if (isset($_POST[$name])) {
          $this->resource_link->setSetting($name, $_POST[$name]);
        } else {
          $this->resource_link->setSetting($name, NULL);
        }
      }
// Delete any existing custom parameters
      foreach ($this->resource_link->getSettings() as $name => $value) {
        if (strpos($name, 'custom_') === 0) {
          $this->resource_link->setSetting($name);
        }
      }
// Save custom parameters
      foreach ($_POST as $name => $value) {
        if (strpos($name, 'custom_') === 0) {
          $this->resource_link->setSetting($name, $value);
        }
      }
#
### Set the user instance
#
      $user_id = '';
      if (isset($_POST['user_id'])) {
        $user_id = trim($_POST['user_id']);
      }
      $this->user = new LTI_User($this->resource_link, $user_id);
#
### Set the user name
#
      $firstname = (isset($_POST['lis_person_name_given'])) ? $_POST['lis_person_name_given'] : '';
      $lastname = (isset($_POST['lis_person_name_family'])) ? $_POST['lis_person_name_family'] : '';
      $fullname = (isset($_POST['lis_person_name_full'])) ? $_POST['lis_person_name_full'] : '';
      $this->user->setNames($firstname, $lastname, $fullname);
#
### Set the user email
#
      $email = (isset($_POST['lis_person_contact_email_primary'])) ? $_POST['lis_person_contact_email_primary'] : '';
      $this->user->setEmail($email, $this->defaultEmail);
#
### Set the user roles
#
      if (isset($_POST['roles'])) {
        $this->user->roles = LTI_Tool_Provider::parseRoles($_POST['roles']);
      }
#
### Save the user instance
#
      if (isset($_POST['lis_result_sourcedid'])) {
        if ($this->user->lti_result_sourcedid != $_POST['lis_result_sourcedid']) {
          $this->user->lti_result_sourcedid = $_POST['lis_result_sourcedid'];
          $this->user->save();
        }
      } else if (!empty($this->user->lti_result_sourcedid)) {
        $this->user->delete();
      }
#
### Initialise the consumer and check for changes
#
      if ($this->consumer->lti_version != $_POST['lti_version']) {
        $this->consumer->lti_version = $_POST['lti_version'];
        $doSaveConsumer = TRUE;
      }
      if (isset($_POST['tool_consumer_instance_name'])) {
        if ($this->consumer->consumer_name != $_POST['tool_consumer_instance_name']) {
          $this->consumer->consumer_name = $_POST['tool_consumer_instance_name'];
          $doSaveConsumer = TRUE;
        }
      }
      if (isset($_POST['tool_consumer_info_product_family_code'])) {
        $version = $_POST['tool_consumer_info_product_family_code'];
        if (isset($_POST['tool_consumer_info_version'])) {
          $version .= "-{$_POST['tool_consumer_info_version']}";
        }
// do not delete any existing consumer version if none is passed
        if ($this->consumer->consumer_version != $version) {
          $this->consumer->consumer_version = $version;
          $doSaveConsumer = TRUE;
        }
      } else if (isset($_POST['ext_lms']) && ($this->consumer->consumer_name != $_POST['ext_lms'])) {
        $this->consumer->consumer_version = $_POST['ext_lms'];
        $doSaveConsumer = TRUE;
      }
      if (isset($_POST['tool_consumer_instance_guid']) && is_null($this->consumer->consumer_guid)) {
        $this->consumer->consumer_guid = $_POST['tool_consumer_instance_guid'];
        $doSaveConsumer = TRUE;
      }
      if (isset($_POST['launch_presentation_css_url'])) {
        if ($this->consumer->css_path != $_POST['launch_presentation_css_url']) {
          $this->consumer->css_path = $_POST['launch_presentation_css_url'];
          $doSaveConsumer = TRUE;
        }
      } else if (isset($_POST['ext_launch_presentation_css_url']) &&
         ($this->consumer->css_path != $_POST['ext_launch_presentation_css_url'])) {
        $this->consumer->css_path = $_POST['ext_launch_presentation_css_url'];
        $doSaveConsumer = TRUE;
      } else if (!empty($this->consumer->css_path)) {
        $this->consumer->css_path = NULL;
        $doSaveConsumer = TRUE;
      }
    }
#
### Persist changes to consumer
#
    if ($doSaveConsumer) {
      $this->consumer->save();
    }

    if ($this->isOK) {
#
### Check if a share arrangement is in place for this resource link
#
      $this->isOK = $this->checkForShare();
#
### Persist changes to resource link
#
      $this->resource_link->save();
    }

    return $this->isOK;

  }

/**
 * Check if a share arrangement is in place.
 *
 * @return boolean True if no error is reported
 */
  private function checkForShare() {

    $ok = TRUE;
    $doSaveResourceLink = TRUE;

    $key = $this->resource_link->primary_consumer_key;
    $id = $this->resource_link->primary_resource_link_id;

    $shareRequest = isset($_POST['custom_share_key']) && !empty($_POST['custom_share_key']);
    if ($shareRequest) {
      if (!$this->allowSharing) {
        $ok = FALSE;
        $this->reason = 'Your sharing request has been refused because sharing is not being permitted.';
      } else {
// Check if this is a new share key
        $share_key = new LTI_Resource_Link_Share_Key($this->resource_link, $_POST['custom_share_key']);
        if (!is_null($share_key->primary_consumer_key) && !is_null($share_key->primary_resource_link_id)) {
// Update resource link with sharing primary resource link details
          $key = $share_key->primary_consumer_key;
          $id = $share_key->primary_resource_link_id;
          $ok = ($key != $this->consumer->getKey()) || ($id != $this->resource_link->getId());
          if ($ok) {
            $this->resource_link->primary_consumer_key = $key;
            $this->resource_link->primary_resource_link_id = $id;
            $this->resource_link->share_approved = $share_key->auto_approve;
            $ok = $this->resource_link->save();
            if ($ok) {
              $doSaveResourceLink = FALSE;
              $this->user->getResourceLink()->primary_consumer_key = $key;
              $this->user->getResourceLink()->primary_resource_link_id = $id;
              $this->user->getResourceLink()->share_approved = $share_key->auto_approve;
              $this->user->getResourceLink()->updated = time();
// Remove share key
              $share_key->delete();
            } else {
              $this->reason = 'An error occurred initialising your share arrangement.';
            }
          } else {
            $this->reason = 'It is not possible to share your resource link with yourself.';
          }
        }
        if ($ok) {
          $ok = !is_null($key);
          if (!$ok) {
            $this->reason = 'You have requested to share a resource link but none is available.';
          } else {
            $ok = (!is_null($this->user->getResourceLink()->share_approved) && $this->user->getResourceLink()->share_approved);
            if (!$ok) {
              $this->reason = 'Your share request is waiting to be approved.';
            }
          }
        }
      }
    } else {
// Check no share is in place
      $ok = is_null($key);
      if (!$ok) {
        $this->reason = 'You have not requested to share a resource link but an arrangement is currently in place.';
      }
    }

// Look up primary resource link
    if ($ok && !is_null($key)) {
      $consumer = new LTI_Tool_Consumer($key, $this->data_connector);
      $ok = !is_null($consumer->created);
      if ($ok) {
        $resource_link = new LTI_Resource_Link($consumer, $id);
        $ok = !is_null($resource_link->created);
      }
      if ($ok) {
        if ($doSaveResourceLink) {
          $this->resource_link->save();
        }
        $this->resource_link = $resource_link;
      } else {
        $this->reason = 'Unable to load resource link being shared.';
      }
    }

    return $ok;

  }

}


/**
 * Class to represent a tool consumer
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Tool_Consumer {

/**
 * Local name of tool consumer.
 */
  public $name = NULL;
/**
 * Shared secret.
 */
  public $secret = NULL;
/**
 * LTI version (as reported by last tool consumer connection).
 */
  public $lti_version = NULL;
/**
 * Name of tool consumer (as reported by last tool consumer connection).
 */
  public $consumer_name = NULL;
/**
 * Tool consumer version (as reported by last tool consumer connection).
 */
  public $consumer_version = NULL;
/**
 * Tool consumer GUID (as reported by first tool consumer connection).
 */
  public $consumer_guid = NULL;
/**
 * Optional CSS path (as reported by last tool consumer connection).
 */
  public $css_path = NULL;
/**
 * True if the tool consumer instance is protected by matching the consumer_guid value in incoming requests.
 */
  public $protected = FALSE;
/**
 * True if the tool consumer instance is enabled to accept incoming connection requests.
 */
  public $enabled = FALSE;
/**
 * Date/time from which the the tool consumer instance is enabled to accept incoming connection requests.
 */
  public $enable_from = NULL;
/**
 * Date/time until which the tool consumer instance is enabled to accept incoming connection requests.
 */
  public $enable_until = NULL;
/**
 * Date of last connection from this tool consumer.
 */
  public $last_access = NULL;
/**
 * Default scope to use when generating an Id value for a user.
 */
  public $id_scope = LTI_Tool_Provider::ID_SCOPE_ID_ONLY;
/**
 * Default email address (or email domain) to use when no email address is provided for a user.
 */
  public $defaultEmail = '';
/**
 * Date/time when the object was created.
 */
  public $created = NULL;
/**
 * Date/time when the object was last updated.
 */
  public $updated = NULL;

/**
 * Consumer key value.
 */
  private $key = NULL;
/**
 * Data connector object or string.
 */
  private $data_connector = NULL;

/**
 * Class constructor.
 *
 * @param string  $key             Consumer key
 * @param mixed   $data_connector  String containing table name prefix, or database connection object, or array containing one or both values (optional, default is MySQL with an empty table name prefix)
 * @param boolean $autoEnable      true if the tool consumers is to be enabled automatically (optional, default is false)
 */
  public function __construct($key = NULL, $data_connector = '', $autoEnable = FALSE) {

    $this->data_connector = LTI_Data_Connector::getDataConnector($data_connector);
    if (!empty($key)) {
      $this->load($key, $autoEnable);
    } else {
      $this->secret = LTI_Data_Connector::getRandomString(32);
    }

  }

/**
 * Initialise the tool consumer.
 */
  public function initialise() {

    $this->key = NULL;
    $this->name = NULL;
    $this->secret = NULL;
    $this->lti_version = NULL;
    $this->consumer_name = NULL;
    $this->consumer_version = NULL;
    $this->consumer_guid = NULL;
    $this->css_path = NULL;
    $this->protected = FALSE;
    $this->enabled = FALSE;
    $this->enable_from = NULL;
    $this->enable_until = NULL;
    $this->last_access = NULL;
    $this->id_scope = LTI_Tool_Provider::ID_SCOPE_ID_ONLY;
    $this->defaultEmail = '';
    $this->created = NULL;
    $this->updated = NULL;

  }

/**
 * Save the tool consumer to the database.
 *
 * @return boolean True if the object was successfully saved
 */
  public function save() {

    return $this->data_connector->Tool_Consumer_save($this);

  }

/**
 * Delete the tool consumer from the database.
 *
 * @return boolean True if the object was successfully deleted
 */
  public function delete() {

    return $this->data_connector->Tool_Consumer_delete($this);

  }

/**
 * Get the tool consumer key.
 *
 * @return string Consumer key value
 */
  public function getKey() {

    return $this->key;

  }

/**
 * Get the data connector.
 *
 * @return mixed Data connector object or string
 */
  public function getDataConnector() {

    return $this->data_connector;

  }

/**
 * Is the consumer key available to accept launch requests?
 *
 * @return boolean True if the consumer key is enabled and within any date constraints
 */
  public function getIsAvailable() {

    $ok = $this->enabled;

    $now = time();
    if ($ok && !is_null($this->enable_from)) {
      $ok = $this->enable_from <= $now;
    }
    if ($ok && !is_null($this->enable_until)) {
      $ok = $this->enable_until > $now;
    }

    return $ok;

  }

###
###  PRIVATE METHOD
###

/**
 * Load the tool consumer from the database.
 *
 * @param string  $key        The consumer key value
 * @param boolean $autoEnable True if the consumer should be enabled (optional, default if false)
 *
 * @return boolean True if the consumer was successfully loaded
 */
  private function load($key, $autoEnable = FALSE) {

    $this->initialise();
    $this->key = $key;
    $ok = $this->data_connector->Tool_Consumer_load($this);
    if (!$ok) {
      $this->enabled = $autoEnable;
    }

    return $ok;

  }

}


/**
 * Class to represent a tool consumer resource link
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Resource_Link {

/**
 * Read action.
 */
  const EXT_READ = 1;
/**
 * Write (create/update) action.
 */
  const EXT_WRITE = 2;
/**
 * Delete action.
 */
  const EXT_DELETE = 3;

/**
 * Decimal outcome type.
 */
  const EXT_TYPE_DECIMAL = 'decimal';
/**
 * Percentage outcome type.
 */
  const EXT_TYPE_PERCENTAGE = 'percentage';
/**
 * Ratio outcome type.
 */
  const EXT_TYPE_RATIO = 'ratio';
/**
 * Letter (A-F) outcome type.
 */
  const EXT_TYPE_LETTER_AF = 'letteraf';
/**
 * Letter (A-F) with optional +/- outcome type.
 */
  const EXT_TYPE_LETTER_AF_PLUS = 'letterafplus';
/**
 * Pass/fail outcome type.
 */
  const EXT_TYPE_PASS_FAIL = 'passfail';
/**
 * Free text outcome type.
 */
  const EXT_TYPE_TEXT = 'freetext';

/**
 * Context ID as supplied in the last connection request.
 */
  public $lti_context_id = NULL;
/**
 * Resource link ID as supplied in the last connection request.
 */
  public $lti_resource_id = NULL;
/**
 * Context title.
 */
  public $title = NULL;
/**
 * Associative array of setting values (LTI parameters, custom parameters and local parameters).
 */
  public $settings = NULL;
/**
 * Associative array of user group sets (NULL if the consumer does not support the groups enhancement)
 */
  public $group_sets = NULL;
/**
 * Associative array of user groups (NULL if the consumer does not support the groups enhancement)
 */
  public $groups = NULL;
/**
 * Request for last extension service request.
 */
  public $ext_request = NULL;
/**
 * Response from last extension service request.
 */
  public $ext_response = NULL;
/**
 * Consumer key value for resource link being shared (if any).
 */
  public $primary_consumer_key = NULL;
/**
 * ID value for resource link being shared (if any).
 */
  public $primary_resource_link_id = NULL;
/**
 * True if the sharing request has been approved by the primary resource link.
 */
  public $share_approved = NULL;
/**
 * Date/time when the object was created.
 */
  public $created = NULL;
/**
 * Date/time when the object was last updated.
 */
  public $updated = NULL;

/**
 * LTI_Tool_Consumer object for this resource link.
 */
  private $consumer = NULL;
/**
 * ID for this resource link.
 */
  private $id = NULL;
/**
 * True if the settings value have changed since last saved.
 */
  private $settings_changed = FALSE;
/**
 * The XML document for the last extension service request.
 */
  private $ext_doc = NULL;
/**
 * The XML node array for the last extension service request.
 */
  private $ext_nodes = NULL;

/**
 * Class constructor.
 *
 * @param string $consumer Consumer key value
 * @param string $id       Resource link ID value
 */
  public function __construct($consumer, $id) {

    $this->consumer = $consumer;
    $this->id = $id;
    if (!empty($id)) {
      $this->load();
    } else {
      $this->initialise();
    }

  }

/**
 * Initialise the resource link.
 */
  public function initialise() {

    $this->lti_context_id = NULL;
    $this->lti_resource_id = NULL;
    $this->title = '';
    $this->settings = array();
    $this->group_sets = NULL;
    $this->groups = NULL;
    $this->primary_consumer_key = NULL;
    $this->primary_resource_link_id = NULL;
    $this->share_approved = NULL;
    $this->created = NULL;
    $this->updated = NULL;

  }

/**
 * Save the resource link to the database.
 *
 * @return boolean True if the resource link was successfully saved.
 */
  public function save() {

    $ok = $this->consumer->getDataConnector()->Resource_Link_save($this);
    if ($ok) {
      $this->settings_changed = FALSE;
    }

    return $ok;

  }

/**
 * Delete the resource link from the database.
 *
 * @return boolean True if the resource link was successfully deleted.
 */
  public function delete() {

    return $this->consumer->getDataConnector()->Resource_Link_delete($this);

  }

/**
 * Get tool consumer.
 *
 * @return object LTI_Tool_Consumer object for this resource link.
 */
  public function getConsumer() {

    return $this->consumer;

  }

/**
 * Get tool consumer key.
 *
 * @return string Consumer key value for this resource link.
 */
  public function getKey() {

    return $this->consumer->getKey();

  }

/**
 * Get resource link ID.
 *
 * @return string ID for this resource link.
 */
  public function getId() {

    return $this->id;

  }

/**
 * Get a setting value.
 *
 * @param string $name    Name of setting
 * @param string $default Value to return if the setting does not exist (optional, default is an empty string)
 *
 * @return string Setting value
 */
  public function getSetting($name, $default = '') {

    if (array_key_exists($name, $this->settings)) {
      $value = $this->settings[$name];
    } else {
      $value = $default;
    }

    return $value;

  }

/**
 * Set a setting value.
 *
 * @param string $name  Name of setting
 * @param string $value Value to set, use an empty value to delete a setting (optional, default is null)
 */
  public function setSetting($name, $value = NULL) {

    $old_value = $this->getSetting($name);
    if ($value != $old_value) {
      if (!empty($value)) {
        $this->settings[$name] = $value;
      } else {
        unset($this->settings[$name]);
      }
      $this->settings_changed = TRUE;
    }

  }

/**
 * Get an array of all setting values.
 *
 * @return array Associative array of setting values
 */
  public function getSettings() {

    return $this->settings;

  }

/**
 * Save setting values.
 *
 * @return boolean True if the settings were successfully saved
 */
  public function saveSettings() {

    if ($this->settings_changed) {
      $ok = $this->save();
    } else {
      $ok = TRUE;
    }

    return $ok;

  }

/**
 * Check if the Outcomes service is supported.
 *
 * @return boolean True if this resource link supports the Outcomes service (either the LTI 1.1 or extension service)
 */
  public function hasOutcomesService() {

    $url = $this->getSetting('ext_ims_lis_basic_outcome_url') . $this->getSetting('lis_outcome_service_url');

    return !empty($url);

  }

/**
 * Check if the Memberships service is supported.
 *
 * @return boolean True if this resource link supports the Memberships service
 */
  public function hasMembershipsService() {

    $url = $this->getSetting('ext_ims_lis_memberships_url');

    return !empty($url);

  }

/**
 * Check if the Setting service is supported.
 *
 * @return boolean True if this resource link supports the Setting service
 */
  public function hasSettingService() {

    $url = $this->getSetting('ext_ims_lti_tool_setting_url');

    return !empty($url);

  }

/**
 * Perform an Outcomes service request.
 *
 * @param int $action The action type constant
 * @param LTI_Outcome $lti_outcome Outcome object
 *
 * @return boolean True if the request was successfully processed
 */
  public function doOutcomesService($action, $lti_outcome) {

    $response = FALSE;
    $this->ext_response = NULL;
#
### Use LTI 1.1 service in preference to extension service if it is available
#
    $urlLTI11 = $this->getSetting('lis_outcome_service_url');
    $urlExt = $this->getSetting('ext_ims_lis_basic_outcome_url');
    if ($urlExt || $urlLTI11) {
      switch ($action) {
        case self::EXT_READ:
          if ($urlLTI11 && ($lti_outcome->type == self::EXT_TYPE_DECIMAL)) {
            $do = 'readResult';
          } else if ($urlExt) {
            $urlLTI11 = NULL;
            $do = 'basic-lis-readresult';
          }
          break;
        case self::EXT_WRITE:
          if ($urlLTI11 && $this->checkValueType($lti_outcome, array(self::EXT_TYPE_DECIMAL))) {
            $do = 'replaceResult';
          } else if ($this->checkValueType($lti_outcome)) {
            $urlLTI11 = NULL;
            $do = 'basic-lis-updateresult';
          }
          break;
        case self::EXT_DELETE:
          if ($urlLTI11 && ($lti_outcome->type == self::EXT_TYPE_DECIMAL)) {
            $do = 'deleteResult';
          } else if ($urlExt) {
            $urlLTI11 = NULL;
            $do = 'basic-lis-deleteresult';
          }
          break;
      }
    }
    if (isset($do)) {
      $value = $lti_outcome->getValue();
      if (is_null($value)) {
        $value = '';
      }
      if ($urlLTI11) {
        $xml = <<<EOF
      <resultRecord>
        <sourcedGUID>
          <sourcedId>{$lti_outcome->getSourcedid()}</sourcedId>
        </sourcedGUID>
        <result>
          <resultScore>
            <language>{$lti_outcome->language}</language>
            <textString>{$value}</textString>
          </resultScore>
        </result>
      </resultRecord>
EOF;
        if ($this->doLTI11Service($do, $urlLTI11, $xml)) {
          switch ($action) {
            case self::EXT_READ:
              if (!isset($this->ext_nodes['imsx_POXBody']["{$do}Response"]['result']['resultScore']['textString'])) {
                break;
              } else {
                $lti_outcome->setValue($this->ext_nodes['imsx_POXBody']["{$do}Response"]['result']['resultScore']['textString']);
              }
            case self::EXT_WRITE:
            case self::EXT_DELETE:
              $response = TRUE;
              break;
          }
        }
      } else {
        $params = array();
        $params['sourcedid'] = $lti_outcome->getSourcedid();
        $params['result_resultscore_textstring'] = $value;
        if (!empty($lti_outcome->language)) {
          $params['result_resultscore_language'] = $lti_outcome->language;
        }
        if (!empty($lti_outcome->status)) {
          $params['result_statusofresult'] = $lti_outcome->status;
        }
        if (!empty($lti_outcome->date)) {
          $params['result_date'] = $lti_outcome->date;
        }
        if (!empty($lti_outcome->type)) {
          $params['result_resultvaluesourcedid'] = $lti_outcome->type;
        }
        if (!empty($lti_outcome->data_source)) {
          $params['result_datasource'] = $lti_outcome->data_source;
        }
        if ($this->doService($do, $urlExt, $params)) {
          switch ($action) {
            case self::EXT_READ:
              if (isset($this->ext_nodes['result']['resultscore']['textstring'])) {
                $response = $this->ext_nodes['result']['resultscore']['textstring'];
              }
              break;
            case self::EXT_WRITE:
            case self::EXT_DELETE:
              $response = TRUE;
              break;
          }
        }
      }
      if (is_array($response) && (count($response) <= 0)) {
        $response = '';
      }
    }

    return $response;

  }

/**
 * Perform a Memberships service request.
 *
 * The user table is updated with the new list of user objects.
 *
 * @param boolean $withGroups True is group information is to be requested as well
 *
 * @return mixed Array of LTI_User objects or False if the request was not successful
 */
  public function doMembershipsService($withGroups = FALSE) {
    $users = array();
    $old_users = $this->getUserResultSourcedIDs(TRUE, LTI_Tool_Provider::ID_SCOPE_RESOURCE);
    $this->ext_response = NULL;
    $url = $this->getSetting('ext_ims_lis_memberships_url');
    $params = array();
    $params['id'] = $this->getSetting('ext_ims_lis_memberships_id');
    $ok = FALSE;
    if ($withGroups) {
      $ok = $this->doService('basic-lis-readmembershipsforcontextwithgroups', $url, $params);
    }
    if ($ok) {
      $this->group_sets = array();
      $this->groups = array();
    } else {
      $ok = $this->doService('basic-lis-readmembershipsforcontext', $url, $params);
    }

    if ($ok) {
      if (!isset($this->ext_nodes['memberships']['member'])) {
        $members = array();
      } else if (!isset($this->ext_nodes['memberships']['member'][0])) {
        $members = array();
        $members[0] = $this->ext_nodes['memberships']['member'];
      } else {
        $members = $this->ext_nodes['memberships']['member'];
      }

      for ($i = 0; $i < count($members); $i++) {

        $user = new LTI_User($this, $members[$i]['user_id']);
#
### Set the user name
#
        $firstname = (isset($members[$i]['person_name_given'])) ? $members[$i]['person_name_given'] : '';
        $lastname = (isset($members[$i]['person_name_family'])) ? $members[$i]['person_name_family'] : '';
        $fullname = (isset($members[$i]['person_name_full'])) ? $members[$i]['person_name_full'] : '';
        $user->setNames($firstname, $lastname, $fullname);
#
### Set the user email
#
        $email = (isset($members[$i]['person_contact_email_primary'])) ? $members[$i]['person_contact_email_primary'] : '';
        $user->setEmail($email, $this->consumer->defaultEmail);
#
### Set the user roles
#
        if (isset($members[$i]['roles'])) {
          $user->roles = LTI_Tool_Provider::parseRoles($members[$i]['roles']);
        }
#
### Set the user groups
#
        if (!isset($members[$i]['groups']['group'])) {
          $groups = array();
        } else if (!isset($members[$i]['groups']['group'][0])) {
          $groups = array();
          $groups[0] = $members[$i]['groups']['group'];
        } else {
          $groups = $members[$i]['groups']['group'];
        }
        for ($j = 0; $j < count($groups); $j++) {
          $group = $groups[$j];
          if (isset($group['set'])) {
            $set_id = $group['set']['id'];
            if (!isset($this->group_sets[$set_id])) {
              $this->group_sets[$set_id] = array('title' => $group['set']['title'], 'groups' => array(),
                 'num_members' => 0, 'num_staff' => 0, 'num_learners' => 0);
            }
            $this->group_sets[$set_id]['num_members']++;
            if ($user->isStaff()) {
              $this->group_sets[$set_id]['num_staff']++;
            }
            if ($user->isLearner()) {
              $this->group_sets[$set_id]['num_learners']++;
            }
            if (!in_array($group['id'], $this->group_sets[$set_id]['groups'])) {
              $this->group_sets[$set_id]['groups'][] = $group['id'];
            }
            $this->groups[$group['id']] = array('title' => $group['title'], 'set' => $set_id);
          } else {
            $this->groups[$group['id']] = array('title' => $group['title']);
          }
          $user->groups[] = $group['id'];
        }
#
### If a result sourcedid is provided save the user
#
        if (isset($members[$i]['lis_result_sourcedid'])) {
          $user->lti_result_sourcedid = $members[$i]['lis_result_sourcedid'];
          $user->save();
        }
        $users[] = $user;
#
### Remove old user (if it exists)
#
        unset($old_users[$user->getId(LTI_Tool_Provider::ID_SCOPE_RESOURCE)]);
      }
#
### Delete any old users which were not in the latest list from the tool consumer
#
      foreach ($old_users as $id => $user) {
        $user->delete();
      }
    } else {
      $users = FALSE;
    }

    return $users;

  }

/**
 * Perform a Setting service request.
 *
 * @param int    $action The action type constant
 * @param string $value  The setting value (optional, default is null)
 *
 * @return mixed The setting value for a read action, true if a write or delete action was successful, otherwise false
 */
  public function doSettingService($action, $value = NULL) {

    $response = FALSE;
    $this->ext_response = NULL;
    switch ($action) {
      case self::EXT_READ:
        $do = 'basic-lti-loadsetting';
        break;
      case self::EXT_WRITE:
        $do = 'basic-lti-savesetting';
        break;
      case self::EXT_DELETE:
        $do = 'basic-lti-deletesetting';
        break;
    }
    if (isset($do)) {

      $url = $this->getSetting('ext_ims_lti_tool_setting_url');
      $params = array();
      $params['id'] = $this->getSetting('ext_ims_lti_tool_setting_id');
      if (is_null($value)) {
        $value = '';
      }
      $params['setting'] = $value;

      if ($this->doService($do, $url, $params)) {
        switch ($action) {
          case self::EXT_READ:
            if (isset($this->ext_nodes['setting']['value'])) {
              $response = $this->ext_nodes['setting']['value'];
              if (is_array($response)) {
                $response = '';
              }
            }
            break;
          case self::EXT_WRITE:
            $this->setSetting('ext_ims_lti_tool_setting', $value);
            $this->saveSettings();
            $response = TRUE;
            break;
          case self::EXT_DELETE:
            $response = TRUE;
            break;
        }
      }

    }

    return $response;

  }

/**
 * Obtain an array of LTI_User objects for users with a result sourcedId.
 *
 * The array may include users from other resource links which are sharing this resource link.
 * It may also be optionally indexed by the user ID of a specified scope.
 *
 * @param boolean $local_only True if only users from this resource link are to be returned, not users from shared resource links (optional, default is false)
 * @param int     $id_scope     Scope to use for ID values (optional, default is null for consumer default)
 *
 * @return
 */
  public function getUserResultSourcedIDs($local_only = FALSE, $id_scope = NULL) {

    return $this->consumer->getDataConnector()->Resource_Link_getUserResultSourcedIDs($this, $local_only, $id_scope);

  }

/**
 * Get an array of LTI_Resource_Link_Share objects for each resource link which is sharing this context.
 *
 * @return array Array of LTI_Resource_Link_Share objects
 */
  public function getShares() {

    return $this->consumer->getDataConnector()->Resource_Link_getShares($this);

  }

###
###  PRIVATE METHODS
###

/**
 * Load the resource link from the database.
 *
 * @return boolean True if resource link was successfully loaded
 */
  private function load() {

    $this->initialise();
    return $this->consumer->getDataConnector()->Resource_Link_load($this);

  }

/**
 * Convert data type of value to a supported type if possible.
 *
 * @param LTI_Outcome $lti_outcome     Outcome object
 * @param string[]    $supported_types Array of outcome types to be supported (optional, default is null to use supported types reported in the last launch for this resource link)
 *
 * @return boolean True if the type/value are valid and supported
 */
  private function checkValueType($lti_outcome, $supported_types = NULL) {

    if (empty($supported_types)) {
      $supported_types = explode(',', str_replace(' ', '', strtolower($this->getSetting('ext_ims_lis_resultvalue_sourcedids', self::EXT_TYPE_DECIMAL))));
    }
    $type = $lti_outcome->type;
    $value = $lti_outcome->getValue();
// Check whether the type is supported or there is no value
    $ok = in_array($type, $supported_types) || (strlen($value) <= 0);
    if (!$ok) {
// Convert numeric values to decimal
      if ($type == self::EXT_TYPE_PERCENTAGE) {
        if (substr($value, -1) == '%') {
          $value = substr($value, 0, -1);
        }
        $ok = is_numeric($value) && ($value >= 0) && ($value <= 100);
        if ($ok) {
          $lti_outcome->setValue($value / 100);
          $lti_outcome->type = self::EXT_TYPE_DECIMAL;
        }
      } else if ($type == self::EXT_TYPE_RATIO) {
        $parts = explode('/', $value, 2);
        $ok = (count($parts) == 2) && is_numeric($parts[0]) && is_numeric($parts[1]) && ($parts[0] >= 0) && ($parts[1] > 0);
        if ($ok) {
          $lti_outcome->setValue($parts[0] / $parts[1]);
          $lti_outcome->type = self::EXT_TYPE_DECIMAL;
        }
// Convert letter_af to letter_af_plus or text
      } else if ($type == self::EXT_TYPE_LETTER_AF) {
        if (in_array(self::EXT_TYPE_LETTER_AF_PLUS, $supported_types)) {
          $ok = TRUE;
          $lti_outcome->type = self::EXT_TYPE_LETTER_AF_PLUS;
        } else if (in_array(self::EXT_TYPE_TEXT, $supported_types)) {
          $ok = TRUE;
          $lti_outcome->type = self::EXT_TYPE_TEXT;
        }
// Convert letter_af_plus to letter_af or text
      } else if ($type == self::EXT_TYPE_LETTER_AF_PLUS) {
        if (in_array(self::EXT_TYPE_LETTER_AF, $supported_types) && (strlen($value) == 1)) {
          $ok = TRUE;
          $lti_outcome->type = self::EXT_TYPE_LETTER_AF;
        } else if (in_array(self::EXT_TYPE_TEXT, $supported_types)) {
          $ok = TRUE;
          $lti_outcome->type = self::EXT_TYPE_TEXT;
        }
// Convert text to decimal
      } else if ($type == self::EXT_TYPE_TEXT) {
        $ok = is_numeric($value) && ($value >= 0) && ($value <=1);
        if ($ok) {
          $lti_outcome->type = self::EXT_TYPE_DECIMAL;
        } else if (substr($value, -1) == '%') {
          $value = substr($value, 0, -1);
          $ok = is_numeric($value) && ($value >= 0) && ($value <=100);
          if ($ok) {
            if (in_array(self::EXT_TYPE_PERCENTAGE, $supported_types)) {
              $lti_outcome->type = self::EXT_TYPE_PERCENTAGE;
            } else {
              $lti_outcome->setValue($value / 100);
              $lti_outcome->type = self::EXT_TYPE_DECIMAL;
            }
          }
        }
      }
    }

    return $ok;

  }

/**
 * Send a service request to the tool consumer.
 *
 * @param string $type   Message type value
 * @param string $url    URL to send request to
 * @param array  $params Associative array of parameter values to be passed
 *
 * @return boolean True if the request successfully obtained a response
 */
  private function doService($type, $url, $params) {

    $this->ext_response = NULL;
    if (!empty($url)) {
// Check for query parameters which need to be included in the signature
      $query_params = array();
      $query_string = parse_url($url, PHP_URL_QUERY);
      if (!is_null($query_string)) {
        $query_items = explode('&', $query_string);
        foreach ($query_items as $item) {
          if (strpos($item, '=') !== FALSE) {
            list($name, $value) = explode('=', $item);
            $query_params[$name] = $value;
          } else {
            $query_params[$name] = '';
          }
        }
      }
      $params = $params + $query_params;
// Add standard parameters
      $params['oauth_consumer_key'] = $this->consumer->getKey();
      $params['lti_version'] = LTI_Tool_Provider::LTI_VERSION;
      $params['lti_message_type'] = $type;
// Add OAuth signature
      $hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
      $consumer = new OAuthConsumer($this->consumer->getKey(), $this->consumer->secret, NULL);
      $req = OAuthRequest::from_consumer_and_token($consumer, NULL, 'POST', $url, $params);
      $req->sign_request($hmac_method, $consumer, NULL);
      $params = $req->get_parameters();
// Remove parameters being passed on the query string
      foreach (array_keys($query_params) as $name) {
        unset($params[$name]);
      }
// Connect to tool consumer
      $this->ext_response = $this->do_post_request($url, $params);
// Parse XML response
      if ($this->ext_response) {
        try {
          $this->ext_doc = new DOMDocument();
          $this->ext_doc->loadXML($this->ext_response);
          $this->ext_nodes = $this->domnode_to_array($this->ext_doc->documentElement);
          if (!isset($this->ext_nodes['statusinfo']['codemajor']) || ($this->ext_nodes['statusinfo']['codemajor'] != 'Success')) {
            $this->ext_response = NULL;
          }
        } catch (Exception $e) {
          $this->ext_response = NULL;
        }
      } else {
        $this->ext_response = NULL;
      }
    }

    return !is_null($this->ext_response);

  }

/**
 * Send a service request to the tool consumer.
 *
 * @param string $type Message type value
 * @param string $url  URL to send request to
 * @param string $xml  XML of message request
 *
 * @return boolean True if the request successfully obtained a response
 */
  private function doLTI11Service($type, $url, $xml) {

    $this->ext_response = NULL;
    if (!empty($url)) {
      $id = uniqid();
      $xmlRequest = <<<EOF
<?xml version = "1.0" encoding = "UTF-8"?>
<imsx_POXEnvelopeRequest xmlns = "http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0">
  <imsx_POXHeader>
    <imsx_POXRequestHeaderInfo>
      <imsx_version>V1.0</imsx_version>
      <imsx_messageIdentifier>{$id}</imsx_messageIdentifier>
    </imsx_POXRequestHeaderInfo>
  </imsx_POXHeader>
  <imsx_POXBody>
    <{$type}Request>
{$xml}
    </{$type}Request>
  </imsx_POXBody>
</imsx_POXEnvelopeRequest>
EOF;
// Calculate body hash
      $hash = base64_encode(sha1($xmlRequest, TRUE));
      $params = array('oauth_body_hash' => $hash);

// Add OAuth signature
      $hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
      $consumer = new OAuthConsumer($this->consumer->getKey(), $this->consumer->secret, NULL);
      $req = OAuthRequest::from_consumer_and_token($consumer, NULL, 'POST', $url, $params);
      $req->sign_request($hmac_method, $consumer, NULL);
      $params = $req->get_parameters();
      $header = $req->to_header();
      $header .= "\nContent-Type: application/xml";
// Connect to tool consumer
      $this->ext_response = $this->do_post_request($url, $xmlRequest, $header);
// Parse XML response
      if ($this->ext_response) {
        try {
          $this->ext_doc = new DOMDocument();
          $this->ext_doc->loadXML($this->ext_response);
          $this->ext_nodes = $this->domnode_to_array($this->ext_doc->documentElement);
          if (!isset($this->ext_nodes['imsx_POXHeader']['imsx_POXResponseHeaderInfo']['imsx_statusInfo']['imsx_codeMajor']) ||
              ($this->ext_nodes['imsx_POXHeader']['imsx_POXResponseHeaderInfo']['imsx_statusInfo']['imsx_codeMajor'] != 'success')) {
            $this->ext_response = NULL;
          }
        } catch (Exception $e) {
          $this->ext_response = NULL;
        }
      } else {
        $this->ext_response = NULL;
      }
    }

    return !is_null($this->ext_response);

  }

/**
 * Get the response from an HTTP POST request.
 *
 * @param string $url    URL to send request to
 * @param array  $params Associative array of parameter values to be passed
 * @param string $header Values to include in the request header (optional, default is none)
 *
 * @return string response contents, empty if the request was not successfull
 */
  private function do_post_request($url, $params, $header = NULL) {

    $ok = FALSE;
    if (is_array($params)) {
      $data = http_build_query($params);
    } else {
      $data = $params;
    }
    $this->ext_request = $data;
// Try using curl if available
    if (function_exists('curl_init')) {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      if (!empty($header)) {
        $headers = explode("\n", $header);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      }
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      $resp = curl_exec($ch);
      $ok = $resp !== FALSE;
      curl_close($ch);
    }
// Try using fopen if curl was not available or did not work (could have been an SSL certificate issue)
    if (!$ok) {
      $opts = array('method' => 'POST',
                    'content' => $data
                   );
      if (!empty($header)) {
        $opts['header'] = $header;
      }
      $ctx = stream_context_create(array('http' => $opts));
      $fp = @fopen($url, 'rb', false, $ctx);
      if ($fp) {
        $resp = @stream_get_contents($fp);
        $ok = $resp !== FALSE;
      }
    }
    if ($ok) {
      $response = $resp;
    } else {
      $response = '';
    }

    return $response;

  }

/**
 * Convert DOM nodes to array.
 *
 * @param DOMElement $node XML element
 *
 * @return array Array of XML document elements
 */
  private function domnode_to_array($node) {

    $output = array();
    switch ($node->nodeType) {
      case XML_CDATA_SECTION_NODE:
      case XML_TEXT_NODE:
        $output = trim($node->textContent);
        break;
      case XML_ELEMENT_NODE:
        for ($i=0, $m=$node->childNodes->length; $i<$m; $i++) {
          $child = $node->childNodes->item($i);
          $v = $this->domnode_to_array($child);
          if (isset($child->tagName)) {
            $t = $child->tagName;
            if (!isset($output[$t])) {
              $output[$t] = array();
            }
            $output[$t][] = $v;
          } else if($v) {
            $output = (string) $v;
          }
        }
        if (is_array($output)) {
          if ($node->attributes->length) {
            $a = array();
            foreach ($node->attributes as $attrName => $attrNode) {
              $a[$attrName] = (string) $attrNode->value;
            }
            $output['@attributes'] = $a;
          }
          foreach ($output as $t => $v) {
            if (is_array($v) && count($v)==1 && $t!='@attributes') {
              $output[$t] = $v[0];
            }
          }
        }
        break;
    }

    return $output;

  }

}

/**
 * Class to represent a tool consumer context
 *
 * @deprecated Use LTI_Resource_Link instead
 * @see LTI_Resource_Link
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Context extends LTI_Resource_Link {

/**
 * ID value for context being shared (if any).
 *
 * @deprecated Use primary_resource_link_id instead
 * @see LTI_Resource_Link::$primary_resource_link_id
 */
  public $primary_context_id = NULL;

/**
 * Class constructor.
 *
 * @param string $consumer Consumer key value
 * @param string $id       Resource link ID value
 */
  public function __construct($consumer, $id) {

    parent::__construct($consumer, $id);
    $this->primary_context_id = &$this->primary_resource_link_id;

  }

}


/**
 * Class to represent an outcome
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Outcome {

/**
 * Language value.
 */
  public $language = NULL;
/**
 * Outcome status value.
 */
  public $status = NULL;
/**
 * Outcome date value.
 */
  public $date = NULL;
/**
 * Outcome type value.
 */
  public $type = NULL;
/**
 * Outcome data source value.
 */
  public $data_source = NULL;

/**
 * Result sourcedid.
 */
  private $sourcedid = NULL;
/**
 * Outcome value.
 */
  private $value = NULL;

/**
 * Class constructor.
 *
 * @param string $sourcedid Result sourcedid value for the user/resource link
 * @param string $value     Outcome value (optional, default is none)
 */
  public function __construct($sourcedid, $value = NULL) {

    $this->sourcedid = $sourcedid;
    $this->value = $value;
    $this->language = 'en-US';
    $this->date = gmdate('Y-m-d\TH:i:s\Z', time());
    $this->type = 'decimal';

  }

/**
 * Get the result sourcedid value.
 *
 * @return string Result sourcedid value
 */
  public function getSourcedid() {

    return $this->sourcedid;

  }

/**
 * Get the outcome value.
 *
 * @return string Outcome value
 */
  public function getValue() {

    return $this->value;

  }

/**
 * Set the outcome value.
 *
 * @param string Outcome value
 */
  public function setValue($value) {

    $this->value = $value;

  }

}


/**
 * Class to represent a tool consumer nonce
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Consumer_Nonce {

/**
 * Maximum age nonce values will be retained for (in minutes).
 */
  const MAX_NONCE_AGE = 30;  // in minutes

/**
 * Date/time when the nonce value expires.
 */
  public  $expires = NULL;

/**
 * LTI_Tool_Consumer object to which this nonce applies.
 */
  private $consumer = NULL;
/**
 * Nonce value.
 */
  private $value = NULL;

/**
 * Class constructor.
 *
 * @param LTI_Tool_Consumer $consumer Consumer object
 * @param string            $value    Nonce value (optional, default is null)
 */
  public function __construct($consumer, $value = NULL) {

    $this->consumer = $consumer;
    $this->value = $value;
    $this->expires = time() + (self::MAX_NONCE_AGE * 60);

  }

/**
 * Load a nonce value from the database.
 *
 * @return boolean True if the nonce value was successfully loaded
 */
  public function load() {

    return $this->consumer->getDataConnector()->Consumer_Nonce_load($this);

  }

/**
 * Save a nonce value in the database.
 *
 * @return boolean True if the nonce value was successfully saved
 */
  public function save() {

    return $this->consumer->getDataConnector()->Consumer_Nonce_save($this);

  }

/**
 * Get tool consumer.
 *
 * @return LTI_Tool_Consumer Consumer for this nonce
 */
  public function getConsumer() {

    return $this->consumer;

  }

/**
 * Get tool consumer key.
 *
 * @return string Consumer key value
 */
  public function getKey() {

    return $this->consumer->getKey();

  }

/**
 * Get outcome value.
 *
 * @return string Outcome value
 */
  public function getValue() {

    return $this->value;

  }

}


/**
 * Class to represent a tool consumer resource link share key
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Resource_Link_Share_Key {

/**
 * Maximum permitted life for a share key value.
 */
  const MAX_SHARE_KEY_LIFE = 168;  // in hours (1 week)
/**
 * Default life for a share key value.
 */
  const DEFAULT_SHARE_KEY_LIFE = 24;  // in hours
/**
 * Minimum length for a share key value.
 */
  const MIN_SHARE_KEY_LENGTH = 5;
/**
 * Maximum length for a share key value.
 */
  const MAX_SHARE_KEY_LENGTH = 32;

/**
 * Consumer key for resource link being shared.
 */
  public $primary_consumer_key = NULL;
/**
 * ID for resource link being shared.
 */
  public $primary_resource_link_id = NULL;
/**
 * Length of share key.
 */
  public $length = NULL;
/**
 * Life of share key.
 */
  public $life = NULL;  // in hours
/**
 * True if the sharing arrangement should be automatically approved when first used.
 */
  public $auto_approve = FALSE;
/**
 * Date/time when the share key expires.
 */
  public $expires = NULL;

/**
 * Share key value.
 */
  private $id = NULL;
/**
 * Data connector.
 */
  private $data_connector = NULL;

/**
 * Class constructor.
 *
 * @param LTI_Resource_Link $resource_link  Resource_Link object
 * @param string      $id      Value of share key (optional, default is null)
 */
  public function __construct($resource_link, $id = NULL) {

    $this->initialise();
    $this->data_connector = $resource_link->getConsumer()->getDataConnector();
    $this->id = $id;
    $this->primary_context_id = &$this->primary_resource_link_id;
    if (!empty($id)) {
      $this->load();
    } else {
      $this->primary_consumer_key = $resource_link->getKey();
      $this->primary_resource_link_id = $resource_link->getId();
    }

  }

/**
 * Initialise the resource link share key.
 */
  public function initialise() {

    $this->primary_consumer_key = NULL;
    $this->primary_resource_link_id = NULL;
    $this->length = NULL;
    $this->life = NULL;
    $this->auto_approve = FALSE;
    $this->expires = NULL;

  }

/**
 * Save the resource link share key to the database.
 *
 * @return boolean True if the share key was successfully saved
 */
  public function save() {

    if (empty($this->life)) {
      $this->life = self::DEFAULT_SHARE_KEY_LIFE;
    } else {
      $this->life = max(min($this->life, self::MAX_SHARE_KEY_LIFE), 0);
    }
    $this->expires = time() + ($this->life * 60 * 60);
    if (empty($this->id)) {
      if (empty($this->length) || !is_numeric($this->length)) {
        $this->length = self::MAX_SHARE_KEY_LENGTH;
      } else {
        $this->length = max(min($this->length, self::MAX_SHARE_KEY_LENGTH), self::MIN_SHARE_KEY_LENGTH);
      }
      $this->id = LTI_Data_Connector::getRandomString($this->length);
    }

    return $this->data_connector->Resource_Link_Share_Key_save($this);

  }

/**
 * Delete the resource link share key from the database.
 *
 * @return boolean True if the share key was successfully deleted
 */
  public function delete() {

    return $this->data_connector->Resource_Link_Share_Key_delete($this);

  }

/**
 * Get share key value.
 *
 * @return string Share key value
 */
  public function getId() {

    return $this->id;

  }

###
###  PRIVATE METHOD
###

/**
 * Load the resource link share key from the database.
 */
  private function load() {

    $this->initialise();
    $this->data_connector->Resource_Link_Share_Key_load($this);
    if (!is_null($this->id)) {
      $this->length = strlen($this->id);
    }
    if (!is_null($this->expires)) {
      $this->life = ($this->expires - time()) / 60 / 60;
    }

  }

}

/**
 * Class to represent a tool consumer context share key
 *
 * @deprecated Use LTI_Resource_Link_Share_Key instead
 * @see LTI_Resource_Link_Share_Key
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Context_Share_Key extends LTI_Resource_Link_Share_Key {

/**
 * ID for context being shared.
 *
 * @deprecated Use LTI_Resource_Link_Share_Key->primary_resource_link_id instead
 * @see LTI_Resource_Link_Share_Key::$primary_resource_link_id
 */
  public $primary_context_id = NULL;

/**
 * Class constructor.
 *
 * @param LTI_Resource_Link $resource_link  Resource_Link object
 * @param string      $id      Value of share key (optional, default is null)
 */
  public function __construct($resource_link, $id = NULL) {

    parent::__construct($resource_link, $id);
    $this->primary_context_id = &$this->primary_resource_link_id;

  }

}


/**
 * Class to represent a tool consumer resource link share
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Resource_Link_Share {

/**
 * Consumer key value.
 */
  public $consumer_key = NULL;
/**
 * Resource link ID value.
 */
  public $resource_link_id = NULL;
/**
 * Title of sharing context.
 */
  public $title = NULL;
/**
 * True if sharing request is to be automatically approved on first use.
 */
  public $approved = NULL;

/**
 * Class constructor.
 */
  public function __construct() {
    $this->context_id = &$this->resource_link_id;
  }

}

/**
 * Class to represent a tool consumer context share
 *
 * @deprecated Use LTI_Resource_Link_Share instead
 * @see LTI_Resource_Link_Share
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Context_Share extends LTI_Resource_Link_Share {

/**
 * Context ID value.
 *
 * @deprecated Use LTI_Resource_Link_Share->resource_link_id instead
 * @see LTI_Resource_Link_Share::$resource_link_id
 */
  public $context_id = NULL;

/**
 * Class constructor.
 */
  public function __construct() {

    parent::__construct();
    $this->context_id = &$this->resource_link_id;

  }

}


/**
 * Class to represent a tool consumer user
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_User {

/**
 * User's first name.
 */
  public $firstname = '';
/**
 * User's last name (surname or family name).
 */
  public $lastname = '';
/**
 * User's fullname.
 */
  public $fullname = '';
/**
 * User's email address.
 */
  public $email = '';
/**
 * Array of roles for user.
 */
  public $roles = array();
/**
 * Array of groups for user.
 */
  public $groups = array();
/**
 * User's result sourcedid.
 */
  public $lti_result_sourcedid = NULL;
/**
 * Date/time the record was created.
 */
  public $created = NULL;
/**
 * Date/time the record was last updated.
 */
  public $updated = NULL;

/**
 * LTI_Resource_Link object.
 */
  private $resource_link = NULL;
/**
 * LTI_Context object.
 */
  private $context = NULL;
/**
 * User ID value.
 */
  private $id = NULL;

/**
 * Class constructor.
 *
 * @param LTI_Resource_Link $resource_link Resource_Link object
 * @param string      $id      User ID value
 */
  public function __construct($resource_link, $id) {

    $this->initialise();
    $this->resource_link = $resource_link;
    $this->context = &$this->resource_link;
    $this->id = $id;
    $this->load();

  }

/**
 * Initialise the user.
 */
  public function initialise() {

    $this->firstname = '';
    $this->lastname = '';
    $this->fullname = '';
    $this->email = '';
    $this->roles = array();
    $this->groups = array();
    $this->lti_result_sourcedid = NULL;
    $this->created = NULL;
    $this->updated = NULL;

  }

/**
 * Load the user from the database.
 *
 * @return boolean True if the user object was successfully loaded
 */
  public function load() {

    $this->initialise();
    $this->resource_link->getConsumer()->getDataConnector()->User_load($this);

  }

/**
 * Save the user to the database.
 *
 * @return boolean True if the user object was successfully saved
 */
  public function save() {

    if (!empty($this->lti_result_sourcedid)) {
      $ok = $this->resource_link->getConsumer()->getDataConnector()->User_save($this);
    } else {
      $ok = TRUE;
    }

    return $ok;

  }

/**
 * Delete the user from the database.
 *
 * @return boolean True if the user object was successfully deleted
 */
  public function delete() {

    return $this->resource_link->getConsumer()->getDataConnector()->User_delete($this);

  }

/**
 * Get resource link.
 *
 * @return LTI_Resource_Link Resource link object
 */
  public function getResourceLink() {

    return $this->resource_link;

  }

/**
 * Get context.
 *
 * @deprecated Use getResourceLink() instead
 * @see LTI_User::getResourceLink()
 *
 * @return LTI_Resource_Link Context object
 */
  public function getContext() {

    return $this->resource_link;

  }

/**
 * Get the user ID (which may be a compound of the tool consumer and resource link IDs).
 *
 * @param int $id_scope Scope to use for user ID (optional, default is null for consumer default setting)
 *
 * @return string User ID value
 */
  public function getId($id_scope = NULL) {

    if (empty($id_scope)) {
      $id_scope = $this->resource_link->getConsumer()->id_scope;
    }
    switch ($id_scope) {
      case LTI_Tool_Provider::ID_SCOPE_GLOBAL:
        $id = $this->resource_link->getKey() . LTI_Tool_Provider::ID_SCOPE_SEPARATOR . $this->id;
        break;
      case LTI_Tool_Provider::ID_SCOPE_CONTEXT:
        $id = $this->resource_link->getKey();
        if ($this->resource_link->lti_context_id) {
          $id .= LTI_Tool_Provider::ID_SCOPE_SEPARATOR . $this->resource_link->lti_context_id;
        }
        $id .= LTI_Tool_Provider::ID_SCOPE_SEPARATOR . $this->id;
        break;
      case LTI_Tool_Provider::ID_SCOPE_RESOURCE:
        $id = $this->resource_link->getKey();
        if ($this->resource_link->lti_resource_id) {
          $id .= LTI_Tool_Provider::ID_SCOPE_SEPARATOR . $this->resource_link->lti_resource_id;
        }
        $id .= LTI_Tool_Provider::ID_SCOPE_SEPARATOR . $this->id;
        break;
      default:
        $id = $this->id;
        break;
    }

    return $id;

  }

/**
 * Set the user's name.
 *
 * @param string $firstname User's first name.
 * @param string $lastname User's last name.
 * @param string $fullname User's full name.
 */
  public function setNames($firstname, $lastname, $fullname) {

    $names = array(0 => '', 1 => '');
    if (!empty($fullname)) {
      $this->fullname = trim($fullname);
      $names = preg_split("/[\s]+/", $this->fullname, 2);
    }
    if (!empty($firstname)) {
      $this->firstname = trim($firstname);
      $names[0] = $this->firstname;
    } else if (!empty($names[0])) {
      $this->firstname = $names[0];
    } else {
      $this->firstname = 'User';
    }
    if (!empty($lastname)) {
      $this->lastname = trim($lastname);
      $names[1] = $this->lastname;
    } else if (!empty($names[1])) {
      $this->lastname = $names[1];
    } else {
      $this->lastname = $this->id;
    }
    if (empty($this->fullname)) {
      $this->fullname = "{$this->firstname} {$this->lastname}";
    }

  }

/**
 * Set the user's email address.
 *
 * @param string $email        Email address value
 * @param string $defaultEmail Value to use if no email is provided (optional, default is none)
 */
  public function setEmail($email, $defaultEmail = NULL) {

    if (!empty($email)) {
      $this->email = $email;
    } else if (!empty($defaultEmail)) {
      $this->email = $defaultEmail;
      if (substr($this->email, 0, 1) == '@') {
        $this->email = $this->getId() . $this->email;
      }
    } else {
      $this->email = '';
    }

  }

/**
 * Check if the user is an administrator (at any of the system, institution or context levels).
 *
 * @return boolean True if the user has a role of administrator
 */
  public function isAdmin() {

    return $this->hasRole('Administrator') || $this->hasRole('urn:lti:sysrole:ims/lis/SysAdmin') ||
           $this->hasRole('urn:lti:sysrole:ims/lis/Administrator') || $this->hasRole('urn:lti:instrole:ims/lis/Administrator');

  }

/**
 * Check if the user is staff.
 *
 * @return boolean True if the user has a role of instructor, contentdeveloper or teachingassistant
 */
  public function isStaff() {

    return ($this->hasRole('Instructor') || $this->hasRole('ContentDeveloper') || $this->hasRole('TeachingAssistant'));

  }

/**
 * Check if the user is a learner.
 *
 * @return boolean True if the user has a role of learner
 */
  public function isLearner() {

    return $this->hasRole('Learner');

  }

###
###  PRIVATE METHODS
###

/**
 * Check whether the user has a specified role name.
 *
 * @param string $role Name of role
 *
 * @return boolean True if the user has the specified role
 */
  private function hasRole($role) {

    if (substr($role, 0, 4) != 'urn:') {
      $role = 'urn:lti:role:ims/lis/' . $role;
    }

    return in_array($role, $this->roles);

  }

}


/**
 * Class to represent an OAuth datastore
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_OAuthDataStore extends OAuthDataStore {

/**
 * LTI_Tool_Provider object.
 */
  private $tool_provider = NULL;

/**
 * Class constructor.
 *
 * @param LTI_Tool_Provider $tool_provider Tool_Provider object
 */
  public function __construct($tool_provider) {

    $this->tool_provider = $tool_provider;

  }

/**
 * Create an OAuthConsumer object for the tool consumer.
 *
 * @param string $consumer_key Consumer key value
 *
 * @return OAuthConsumer OAuthConsumer object
 */
  function lookup_consumer($consumer_key) {

    return new OAuthConsumer($this->tool_provider->consumer->getKey(),
       $this->tool_provider->consumer->secret);

  }

/**
 * Create an OAuthToken object for the tool consumer.
 *
 * @param string $consumer   OAuthConsumer object
 * @param string $token_type Token type
 * @param string $token      Token value
 *
 * @return OAuthToken OAuthToken object
 */
  function lookup_token($consumer, $token_type, $token) {

    return new OAuthToken($consumer, "");

  }

/**
 * Lookup nonce value for the tool consumer.
 *
 * @param OAuthConsumer $consumer  OAuthConsumer object
 * @param string        $token     Token value
 * @param string        $value     Nonce value
 * @param string        $timestamp Date/time of request
 *
 * @return boolean True if the nonce value already exists
 */
  function lookup_nonce($consumer, $token, $value, $timestamp) {

    $nonce = new LTI_Consumer_Nonce($this->tool_provider->consumer, $value);
    $ok = !$nonce->load();
    if ($ok) {
      $ok = $nonce->save();
    }
    if (!$ok) {
      $this->tool_provider->reason = 'Invalid nonce.';
    }

    return !$ok;

  }

/**
 * Get new request token.
 *
 * @param OAuthConsumer $consumer  OAuthConsumer object
 * @param string        $callback  Callback URL
 *
 * @return string Null value
 */
  function new_request_token($consumer, $callback = NULL) {

    return NULL;

  }

/**
 * Get new access token.
 *
 * @param string        $token     Token value
 * @param OAuthConsumer $consumer  OAuthConsumer object
 * @param string        $verifier  Verification code
 *
 * @return string Null value
 */
  function new_access_token($token, $consumer, $verifier = NULL) {

    return NULL;

  }

}


/**
 * Abstract class to provide a connection to a persistent store for LTI objects
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.3.02
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
abstract class LTI_Data_Connector {

/**
 * Default name for database table used to store tool consumers.
 */
  const CONSUMER_TABLE_NAME = 'lti_consumer';
/**
 * Default name for database table used to store resource links.
 */
  const RESOURCE_LINK_TABLE_NAME = 'lti_context';
/**
 * Default name for database table used to store users.
 */
  const USER_TABLE_NAME = 'lti_user';
/**
 * Default name for database table used to store resource link share keys.
 */
  const RESOURCE_LINK_SHARE_KEY_TABLE_NAME = 'lti_share_key';
/**
 * Default name for database table used to store nonce values.
 */
  const NONCE_TABLE_NAME = 'lti_nonce';

/**
 * Load tool consumer object.
 *
 * @param mixed $consumer LTI_Tool_Consumer object
 *
 * @return boolean True if the tool consumer object was successfully loaded
 */
  abstract public function Tool_Consumer_load($consumer);
/**
 * Save tool consumer object.
 *
 * @param LTI_Tool_Consumer $consumer Consumer object
 *
 * @return boolean True if the tool consumer object was successfully saved
 */
  abstract public function Tool_Consumer_save($consumer);
/**
 * Delete tool consumer object.
 *
 * @param LTI_Tool_Consumer $consumer Consumer object
 *
 * @return boolean True if the tool consumer object was successfully deleted
 */
  abstract public function Tool_Consumer_delete($consumer);
/**
 * Load tool consumer objects.
 *
 * @return array Array of all defined LTI_Tool_Consumer objects
 */
  abstract public function Tool_Consumer_list();

/**
 * Load resource link object.
 *
 * @param LTI_Resource_Link $resource_link Resource_Link object
 *
 * @return boolean True if the resource link object was successfully loaded
 */
  abstract public function Resource_Link_load($resource_link);
/**
 * Save resource link object.
 *
 * @param LTI_Resource_Link $resource_link Resource_Link object
 *
 * @return boolean True if the resource link object was successfully saved
 */
  abstract public function Resource_Link_save($resource_link);
/**
 * Delete resource link object.
 *
 * @param LTI_Resource_Link $resource_link Resource_Link object
 *
 * @return boolean True if the Resource_Link object was successfully deleted
 */
  abstract public function Resource_Link_delete($resource_link);
/**
 * Get array of user objects.
 *
 * @param LTI_Resource_Link $resource_link      Resource link object
 * @param boolean     $local_only True if only users within the resource link are to be returned (excluding users sharing this resource link)
 * @param int         $id_scope     Scope value to use for user IDs
 *
 * @return array Array of LTI_User objects
 */
  abstract public function Resource_Link_getUserResultSourcedIDs($resource_link, $local_only, $id_scope);
/**
 * Get array of shares defined for this resource link.
 *
 * @param LTI_Resource_Link $resource_link Resource_Link object
 *
 * @return array Array of LTI_Resource_Link_Share objects
 */
  abstract public function Resource_Link_getShares($resource_link);

/**
 * Load nonce object.
 *
 * @param LTI_Consumer_Nonce $nonce Nonce object
 *
 * @return boolean True if the nonce object was successfully loaded
 */
  abstract public function Consumer_Nonce_load($nonce);
/**
 * Save nonce object.
 *
 * @param LTI_Consumer_Nonce $nonce Nonce object
 *
 * @return boolean True if the nonce object was successfully saved
 */
  abstract public function Consumer_Nonce_save($nonce);

/**
 * Load resource link share key object.
 *
 * @param LTI_Resource_Link_Share_Key $share_key Resource_Link share key object
 *
 * @return boolean True if the resource link share key object was successfully loaded
 */
  abstract public function Resource_Link_Share_Key_load($share_key);
/**
 * Save resource link share key object.
 *
 * @param LTI_Resource_Link_Share_Key $share_key Resource link share key object
 *
 * @return boolean True if the resource link share key object was successfully saved
 */
  abstract public function Resource_Link_Share_Key_save($share_key);
/**
 * Delete resource link share key object.
 *
 * @param LTI_Resource_Link_Share_Key $share_key Resource link share key object
 *
 * @return boolean True if the resource link share key object was successfully deleted
 */
  abstract public function Resource_Link_Share_Key_delete($share_key);

/**
 * Load user object.
 *
 * @param LTI_User $user User object
 *
 * @return boolean True if the user object was successfully loaded
 */
  abstract public function User_load($user);
/**
 * Save user object.
 *
 * @param LTI_User $user User object
 *
 * @return boolean True if the user object was successfully saved
 */
  abstract public function User_save($user);
/**
 * Delete user object.
 *
 * @param LTI_User $user User object
 *
 * @return boolean True if the user object was successfully deleted
 */
  abstract public function User_delete($user);

/**
 * Create data connector object.
 *
 * A type and table name prefix are required to make a database connection.  The default is to use MySQL with no prefix.
 *
 * If a data connector object is passed, then this is returned unchanged.
 *
 * If the $data_connector parameter is a string, this is used as the prefix.
 *
 * If the $data_connector parameter is an array, the first entry should be a prefix string and an optional second entry
 * being a string containing the database type or a database connection object (e.g. the value returned by a call to
 * mysqli_connect() or a PDO object).  A bespoke data connector class can be specified in the optional third parameter.
 *
 * @param mixed  $data_connector A data connector object, string or array
 * @param mixed  $db             A database connection object or string (optional)
 * @param string $type           The type of data connector (optional)
 *
 * @return LTI_Data_Connector Data connector object
 */
  static function getDataConnector($data_connector, $db = NULL, $type = NULL) {

    if (!is_object($data_connector) || !is_subclass_of($data_connector, get_class())) {
      $prefix = NULL;
      if (is_string($data_connector)) {
        $prefix = $data_connector;
      } else if (is_array($data_connector)) {
        for ($i = 0; $i < min(count($data_connector), 3); $i++) {
          if (is_string($data_connector[$i])) {
            if (is_null($prefix)) {
              $prefix = $data_connector[$i];
            } else if (is_null($type)) {
              $type = $data_connector[$i];
            }
          } else if (is_null($db)) {
            $db = $data_connector[$i];
          }
        }
      } else if (is_object($data_connector)) {
        $db = $data_connector;
      }
      if (is_null($prefix)) {
        $prefix = '';
      }
      if (!is_null($db)) {
        if (is_string($db)) {
          $type = $db;
        } else if (is_null($type)) {
          if (is_object($db)) {
            $type = get_class($db);
          } else {
            $type = 'mysql';
          }
        }
      }
      if (is_null($type)) {
        $type = 'mysql';
      }
      $type = strtolower($type);
      $type = "LTI_Data_Connector_{$type}";
      require_once("{$type}.php");
      if (is_null($db)) {
        $data_connector = new $type($prefix);
      } else {
        $data_connector = new $type($db, $prefix);
      }
    }

    return $data_connector;

  }

/**
 * Generate a random string.
 *
 * The generated string will only comprise letters (upper- and lower-case) and digits.
 *
 * @param int $length Length of string to be generated (optional, default is 8 characters)
 *
 * @return string Random string
 */
  static function getRandomString($length = 8) {

    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    $value = '';
    $charsLength = strlen($chars) - 1;

    for ($i = 1 ; $i <= $length; $i++) {
      $value .= $chars[rand(0, $charsLength)];
    }

    return $value;

  }

/**
 * Quote a string for use in a database query.
 *
 * Any single quotes in the value passed will be replaced with two single quotes.  If a null value is passed, a string
 * of 'NULL' is returned (which will never be enclosed in quotes irrespective of the value of the $addQuotes parameter.
 *
 * @param string $value     Value to be quoted
 * @param string $addQuotes If true the returned string will be enclosed in single quotes (optional, default is true)
 *
 * @return boolean True if the user object was successfully deleted
 */
  static function quoted($value, $addQuotes = TRUE) {

    if (is_null($value)) {
      $value = 'NULL';
    } else {
      $value = str_replace('\'', '\'\'', $value);
      if ($addQuotes) {
        $value = "'{$value}'";
      }
    }

    return $value;

  }

}

?>
