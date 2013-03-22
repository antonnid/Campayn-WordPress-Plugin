<?php
/*

Plugin Name: Campayn
Plugin URI: http://campayn.brow.hu/
Description: Plugin for using the campayn.com API with Wordpress
Version: 0.1
Author: Zoltan Lengyel
Author URI: http://brow.hu/
License: GPL2

*/

include('pest/PestJSON.php');
include('campayn_widget.php');
include('campayn_settings.php');
include('campayn_install.php');

function campayn_init() {
  global $campayn_api;
  $apikey = get_option('ob_campayn_apikey');
  $apikey = $apikey['text_string'];
  $campayn_api = new Pest('http://api.campayn.net/api/v1/');
  //$campayn_api = new Pest('http://localhost:6666');
  $campayn_api->curl_opts[CURLOPT_HTTPHEADER] = array('Authorization:TRUEREST apikey='.$apikey);
} add_action('init','campayn_init');

// downloads the form list from the campayn api
function api_get_forms() {
  global $wpdb;
  global $campayn_api;
  $apikey = get_option('ob_campayn_apikey');
  $apikey = $apikey['text_string'];
  if (empty($apikey)) {
    return;
  }

  try {
    $json = $campayn_api->get('/forms.json?filter[form_type]=1');
//  print_r($json);
    $forms = json_decode($json,true); // we will get the data as an array
    if (sizeof($forms)) {
      $ft = get_option('campayn_forms_table');
      $format = array('%d','%d','%s','%s','%s','%s');

      $wpdb->get_results('delete from '.$ft);
      foreach ($forms as $f) {
        $form = array('id'              => $f['id'], 
                      'contact_list_id' => $f['contact_list_id'],
                      'form_title'      => $f['form_title'],
                      'form_type'       => $f['form_type'],
                      'wp_form'         => $f['wp_form'],
                      'list_name'       => $f['list_name']); //just to make sure the order matches $formats
        $wpdb->insert($ft,$form,$format);
      }
    }
  }
  catch (Exception $e) {
    print _e("<br>Caught exception when sending message : " .  $e->getMessage());
  }
}

// grab the forms from the db and display them
function do_form_list() {
  global $wpdb;
  
  $apikey = get_option('ob_campayn_apikey');
  $apikey = $apikey['text_string'];
  $ft = get_option('campayn_forms_table');
  if (empty($apikey)) {
     print _e('You must enter your API key before lists are shown.');
     return;
  }
  $fs = $wpdb->get_results('select * from '.$ft);
  if (empty($fs)) {
    print _e('There are no forms in your campayn account.');
  }
  print '<table>';
  foreach ($fs as $f) {
    if (!empty($f->wp_form)) { // sometimes the form is null
      $shortcode = "[campayn form=\"{$f->id}\"]";
    } else {
      $shortcode = 'There is no wordpress version of this form';
    }
    print "<tr><td>{$f->form_title}</td><td>{$f->list_name}</td><td>{$shortcode}</td></tr>"; 
  }
  print '</table>';
}

// this will be called from the settings page, as the section 'Forms'
// downloads and displays the forms.
function ob_api_key_callback() {
  api_get_forms();
  do_form_list();
}

//return with the form of the given id
function campayn_get_form($id) {
  global $wpdb;


  if (!$id) return;

  $ft = get_option('campayn_forms_table');

  $f = $wpdb->get_row($wpdb->prepare('select * from '.$ft.' where id = %d',$id));
  if (!empty($_SERVER['HTTPS'])) {
    $proto = 'https://';
  } else {
    $proto = 'http://';
  }
  
  $url = preg_replace('/([?&])formError=[^&]+(&|$)/','$1',$_SERVER["REQUEST_URI"]);
  $url = preg_replace('/([?&])errorReason=[^&]+(&|$)/','$1',$url);
  $form = str_replace('{redirectUrl}',$proto . $_SERVER["HTTP_HOST"] . $uri ,$f->wp_form);
  return $form;
} 

// return with either the form or the appropriate thanks/error message in its stead
function campayn_get_form_message($id) {
  $rv = ''; // just to be sure
  if (is_array($id)) { //called from the shortcode
    $id = $id['form'];
  }
  if ($_GET['formId'] == $id) { // the get variables will contain messages to given (formId) forms
    if ($_GET['formSuccess'] == 1) {
      return $_GET['thanks'];
    }
    if ($_GET['formError']) {
      $rv = $_GET['errorReason']; // we don't return, because we need the message AND the form
    }
  }
  return campayn_get_form($id).' '.$rv; // as I said, form + error message (if there is one)
} add_shortcode('campayn',campayn_get_form_message);

function campayn_get_forms_as_options($selected) {
    global $wpdb;
    $ft = get_option('campayn_forms_table');
    $fs = $wpdb->get_results('select * from '.$ft);
    if (empty($fs)) {
      return;
    }
    foreach ($fs as $f) {
      if (!$f->wp_form) { // we need forms we can display
        continue;
      }
      if ($selected == $f->id) {
        $s = 'selected';
      } else {
        $s = '';
      }
      $rv .= "<option value=\"{$f->id}\" {$s}>{$f->form_title}</option>";
    }
    return $rv;
  }

// downloads the form list from the campayn api
function campayn_get_lists() {
  global $wpdb;
  global $campayn_api;
  $apikey = get_option('ob_campayn_apikey');
  $apikey = $apikey['text_string'];
  if (empty($apikey)) {
    return;
  }

  try {
    $json = $campayn_api->get('/lists.json');
    $forms = json_decode($json,true); // we will get the data as an array
    if (empty($forms)) {
      return NULL;
    }

    return $forms;
    //print_r($forms);
  }
  catch (Exception $e) {
   print _e("<br>Caught exception when sending message : " .  $e->getMessage());
  }
}

//creating the dropdown from the email lists ofr the settings page

function campayn_setting_dropdown() {
  $options = get_option('ob_campayn_list');                                                   
  $default_value = NULL;                                                                
  $current_value = $options['text_string'];                                                               
  $chooseFrom = array();                                                                                                                             
  $choices = campayn_get_lists(); // Array ( [0] => Array ( [id] => 1589 [list_name] => Sample Contact List [tags] => [contact_count] => 14 ) )
  if (!empty($choices)) foreach($choices AS $option) {
    $key = $option['id'];
    $option = $option['list_name'];
    $chooseFrom[]= sprintf('<option value="%s" %s>%s</option>',$key,($current_value == $key ) ? ' selected="selected"' : NULL,$option);                  }
  printf('<select id="%s" name="%1$s[text_string]">%2$s</select>%3$s','ob_campayn_list',implode("",$chooseFrom),(!empty ($value['description'])) ? sprintf
("<br /><em>%s</em>",$value['description']) : NULL);                                                                                                   
}   

function campayn_setting_checkbox() {
  $value = get_option('ob_campayn_enabled');                                                                                                 
  print '<input name="ob_campayn_enabled" id="ob_campayn_enabled" type="checkbox" value="1" class="code" '.checked(1,$value,false ).'/>';
}

function campayn_add_comment_fields($fields) {
  if ( 1 != get_option('ob_campayn_enabled')) {
    return $fields;
  }
  $text = get_option('ob_campayn_text');
  $text = $text['text_string'];
  $fields['subscribe'] = '<p class="comment-form-subscribe"><label for="subscribe">'.$text.'</label><input id="subscribe" name="subscribe" type="checkbox" value="1"/></p>';
  return $fields;
} add_filter('comment_form_default_fields','campayn_add_comment_fields');

function campayn_subscribe() {
  global $campayn_api;
  // just to be safe
  if ( 1 != get_option('ob_campayn_enabled')) {
    return;
  }
  if (1 != $_POST['subscribe']) {
    return;
  }

  $list = get_option('ob_campayn_list');
  $list = $list['text_string'];
  $email = array('email' => wp_filter_nohtml_kses($_POST['email']));
  // we could do the try-catch stuff here, but it would confuse the commenter so why bother
  $uri = '/lists/'.$list.'/contacts.json';
  $json = $campayn_api->post($uri,json_encode($email));
} add_action('wp_insert_comment',campayn_subscribe);

?>
