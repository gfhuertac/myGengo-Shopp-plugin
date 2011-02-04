<?php
/*  Copyright 2010  Gonzalo Huerta-Canepa  (email : gonzalo@huerta.cl)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
?>
<?php
/*
Plugin Name: MyGengo Translator for Shopp
Plugin URI: http://www.mygengo.com
Description: Adds machine and professional translation to the Shopp WordPress plugin
Version: 1.0
Author: Gonzalo Huerta-Canepa
Author URI: http://gonzalo.huerta.cl
License: GPL2
*/
?>
<?
/**
 *
 * myGengo shopp plugin
 *
 * @package myGengoShopp
 */
?>
<?php
	if (!function_exists ('add_action')): 
		header('Status: 403 Forbidden');
		header('HTTP/1.1 403 Forbidden');
		exit();
	endif;
?>
<?

require_once(dirname(__FILE__) . '/../mygengo/mygengo-common.php');
require_once(dirname(__FILE__) . '/../mygengo/mygengo-textsources.php');

/*
Wordpress hooks
*/
//Plugin activation
register_activation_hook  (__FILE__, 'mygengo_shopp_activate');
register_deactivation_hook(__FILE__, 'mygengo_shopp_deactivate');
add_action('plugins_loaded', 'mygengo_shopp_init');

global $productts_id;

function mygengo_shopp_activate() {
	global $wpdb;

	if (!is_plugin_active('mygengo/mygengo.php')) {
		wp_die(__('myGengo plugin for wordpress must be enabled'), __('Installation error'));
	}

	if (!is_plugin_active('Shopp/Shopp.php')) {
		wp_die(__('Shopp plugin for wordpress must be enabled'), __('Installation error'));
	}

	$tp = $wpdb->prefix;
	if ( ! empty($wpdb->charset) )
		$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
	if ( ! empty($wpdb->collate) )
		$charset_collate .= " COLLATE $wpdb->collate";

        if($wpdb->get_var("SHOW TABLES LIKE '{$tp}shopp_productmeta'") != $tp.'shopp_productmeta') {
                require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

		$query = "CREATE TABLE {$tp}shopp_productmeta (
		  meta_id bigint(20) unsigned NOT NULL auto_increment,
		  shopp_product_id bigint(20) unsigned NOT NULL default '0',
		  meta_key varchar(255) default NULL,
		  meta_value longtext,
		  PRIMARY KEY  (meta_id),
		  KEY shopp_product_id (shopp_product_id),
		  KEY meta_key (meta_key)
		) $charset_collate;
		";

		dbDelta($query);
	}

	do_action('mygengo_shopp_init');
}

function mygengo_shopp_deactivate() {
	global $wpdb;

	$tp = $wpdb->prefix;
	if ( ! empty($wpdb->charset) )
		$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
	if ( ! empty($wpdb->collate) )
		$charset_collate .= " COLLATE $wpdb->collate";

        if($wpdb->get_var("SHOW TABLES LIKE '{$tp}shopp_productmeta'") == $tp.'shopp_productmeta') {
                $sql = "DROP TABLE {$tp}shopp_productmeta;";
                $wpdb->query($sql);
        }

}

function mygengo_shopp_init() {
	global $wpdb;

	$tp = $wpdb->prefix;
	$wpdb->shopp_productmeta = $tp . 'shopp_productmeta';
}

function mygengo_generate_select_from_shopp_products($default=0,$first_option='') {
	global $wpdb;

	$tp = $wpdb->prefix;
	$where  = '';
	$query  = "SELECT id, name FROM {$tp}shopp_product {$where} ORDER BY name ASC";
	$field1 = 'id';
	$field2 = 'name';

	return mygengo_generate_select_from_sqlquery($query, $field1, $field2, $default, $first_option);
}

/** 
 * Parses text into an associate array representing a post.
 * It splits the texts according to three sections, title, content and excerpt.
 * if the title boundary does not exists, the text is returned as a single 
 * content without title and excerpt.
 *
 * @since 1.0 
 *  
 * @param a string $content containing the text to be parsed
 * @return an associate array with keys post_title, post_content and post_excerpt
 */ 
function mygengo_parse_shopp_content($content) {
	$title_meta = '[[[name__]]]';
	$content_meta = '[[[description__]]]';
	$excerpt_meta = '[[[summary__]]]';

	$use_mb = false;

	$title_pos = ($use_mb)?mb_stripos($content, $title_meta,'UTF-8'):stripos($content, $title_meta);
	if ($title_pos === FALSE) {
		return array('post_title' => '', 'post_content' => str_replace(array('[[[',']]]'), array('<','>'),$content), 'post_excerpt' => '');
	}
	$title_pos   += strlen($title_meta);
	$content_pos = ($use_mb)?mb_stripos($content, $content_meta, 'UTF-8'):stripos($content, $content_meta);
	$ptitle = ($use_mb)?mb_substr($content, $title_pos, $content_pos - $title_pos, 'UTF-8'):substr($content, $title_pos, $content_pos - $title_pos);

	$content_pos += strlen($content_meta);
	$excerpt_pos = ($use_mb)?mb_stripos($content, $excerpt_meta, 'UTF-8'):stripos($content, $excerpt_meta);
	$pcontent = ($use_mb)?mb_substr($content, $content_pos, $excerpt_pos - $content_pos, 'UTF-8'):substr($content, $content_pos, $excerpt_pos - $content_pos);

	$excerpt_pos += strlen($excerpt_meta);
	$pexcerpt = ($use_mb)?mb_substr($content, $excerpt_pos, 'UTF-8'):substr($content, $excerpt_pos);

	return array('post_title' => str_replace(array('[[[',']]]'), array('<','>'), $ptitle), 'post_content' => str_replace(array('[[[',']]]'), array('<','>'), $pcontent), 'post_excerpt' => str_replace(array('[[[',']]]'), array('<','>'), $pexcerpt));
}

/** 
 * Reads a post from the database and filters the results to be compatible with the 
 * myGengo format for jobs (basically it replaces the start and end tags of html
 * by triple-brackets so the content is not translated)
 *
 * @since 1.0 
 *  
 * @param int $post_id the id of the post that will be retrieved from the DB
 * @return an array $rv containing the title, content and excerpt of the post
 */
function mygengo_product($product_id) {
	global $wpdb;

	if (!product_id) { return array(); }

	$tp    = $wpdb->prefix;
	$where = " WHERE id = {$product_id} ";
	$query = "SELECT name, description, summary FROM {$tp}shopp_product {$where}";

        $rv = $wpdb->get_row($query, ARRAY_A);
        foreach($rv as $key=> $value) {
                $value = str_replace(array('<','>'), array('[[[',']]]'), $value);
                $rv[$key] = $value;
        }
        return $rv;
}

function mygengo_get_product_language_value($product_id) {
	$product_language_value = get_metadata('shopp_product', $product_id, "_post_language_value", true);
	
	if($product_language_value != "") {
		return $product_language_value;
	}
	else {
		return mygengo_get_primarylanguage();
	}

}

add_action('mygengo_shopp_init','mygengo_register_shoppsources',5);
function mygengo_register_shoppsources() {
	global $productts_id;
	$productts_id = mygengo_register_textsource('ShoppProductTextSource');
}

add_filter('mygengo_parse_translationshopp_product', 'mygengo_parse_shopp_content');
add_action('mygengo_echo_translationshopp_product', 'mygengo_echo_translation_shopp_product');
function mygengo_echo_translation_shopp_product($body_src) {
	$post_sections = mygengo_parse_shopp_content($body_src);
	if ($post_sections['post_title'] != '') {
		echo '<p><strong>' . __('Title') . ':</strong> ' . nl2br($post_sections['post_title']) . '</p>'; 
	}
	echo '<p><strong>' .__('Content') . ':</strong><br/>' . nl2br($post_sections['post_content']) . '</p>';
	if (trim($post_sections['post_excerpt']) != '') {
		echo '<p><strong>' . __('Excerpt') . ':</strong><br/> ' . nl2br($post_sections['post_excerpt']) . '</p>'; 
	}
}

global $shopp_product_meta_boxes;
$shopp_product_meta_boxes = $new_meta_boxes;

add_action('admin_menu', 'mygengo_shopp_create_meta_box');
function mygengo_shopp_product_meta_box($product) {
	global $shopp_product_meta_boxes, $wpdb, $table_name1, $table_name2;

	foreach($shopp_product_meta_boxes as $meta_box) {
		$meta_box_value = get_metadata('shopp_product', $product->id, '_'.$meta_box['name'].'_value', true);
		if($meta_box_value == "")
			$meta_box_value = $meta_box['std'];

		echo'<input type="hidden" name="'.$meta_box['name'].'_noncename" id="'.$meta_box['name'].'_noncename" value="'.wp_create_nonce( plugin_basename(__FILE__) ).'" />';

		echo $meta_box['title']."&nbsp";

		if($meta_box['name'] == "post_language") {
			$default_language = $meta_box_value;
			if($default_language == "") {
				$default_language = mygengo_get_primarylanguage();
			}
			echo '<select name="'.$meta_box['name'].'_value">';
			echo '<option value="">' . __('[Select]') . '</option>';
			echo mygengo_generate_select_from_sqlquery("SELECT language_code, language_name FROM ".$table_name1." ORDER BY language_name", "language_code", "language_name", $default_language);
			echo '</select>';
		}

		else if($meta_box['name'] == "post_parent") {
			$default_parent = $meta_box_value;
			if($_GET['post_parent'] != "") {
				echo '<select name="'.$meta_box['name'].'_value">';
				echo '<option value="">' . __('[Select]') . '</option>';
				echo mygengo_generate_select_from_shopp_products($_GET['post_parent']);
			}
			else {
				echo '<select name="'.$meta_box['name'].'_value">';
				echo '<option value="">[Select]</option>';
				echo mygengo_generate_select_from_shopp_products($default_parent);
			}
			echo '</select>';
		}

		else {
			echo'<input type="text" name="'.$meta_box['name'].'_value" value="'.$meta_box_value.'" size="55" /><br />';
		}

		echo'<p><label for="'.$meta_box['name'].'_value">'.$meta_box['description'].'</label></p>';
	}
	mygengo_new_shopp_product_translatebutton($product);
}

function mygengo_shopp_create_meta_box() {
	if ( function_exists('add_meta_box') ) {
		add_meta_box('product-settings', __('MyGengo Translator','Shopp'), 'mygengo_shopp_product_meta_box', 'admin_page_shopp-products-edit', 'normal', 'high');
	}
}

add_action('shopp_product_saved', 'mygengo_save_shopp_productdata');
function  mygengo_save_shopp_productdata( $Product ) {
	global $shopp_product_meta_boxes;

	foreach($shopp_product_meta_boxes as $meta_box) {
		// Verify
		if ( !wp_verify_nonce( $_POST[$meta_box['name'].'_noncename'], plugin_basename(__FILE__) )) {
			return $Product->id;
		}

		$data     = $_POST[$meta_box['name'].'_value'];
		$previous = get_metadata('shopp_product', $Product->id, '_'.$meta_box['name'].'_value', true);

		if($previous == "")
			add_metadata('shopp_product', $Product->id, '_'.$meta_box['name'].'_value', $data, true);
		elseif($data != $previous)
			update_metadata('shopp_product', $Product->id, '_'.$meta_box['name'].'_value', $data);
		elseif($data == "")
			delete_metadata('shopp_product', $Product->id, '_'.$meta_box['name'].'_value', $previous);
	}
}	

function mygengo_new_shopp_product_translatebutton($product) {
	global $wp_admin_url, $postts_id, $pagets_id;
	echo "<div id='translate_id'><input type='button' class='button-secondary' onclick='document.location=\"".$wp_admin_url."/admin.php?page=mygengo.phporder&mg_post_type=shopp_product&mg_post_id=".$product->id."\"' value='".__('Translate')."' /></div><div class='clear'></div>";
}

class ShoppProductTextSource extends BlogTextSource {  
	public function __construct($internalId) {
		parent::__construct($internalId);
		$this->type = 'shopp_product';
	}

	public function accept($post_type) {
		return (strcmp($post_type,$this->type)==0 || empty($post_type));
	}

	public function retrieveFormElements()  {
		eval("\$mg_select = mygengo_generate_select_from_{$this->type}s();");
		$mg_product_id = '<select name="mg_'.$this->type.'_id" id="mg_'.$this->type.'_id" style="width:300px;"><option value="0">[' . __('Select') . ']</option>' . $mg_select . '</select>';
	
		$elements = array('label'    => __('Insert job text from ') . __($this->type),
			  'elements' => array ('mg_'.$this->type.'_id' => $mg_product_id),
			  'names'    => array ('mg_'.$this->type.'_id' => 'mg_post_id')
			 );
		return $elements;
	}

	public function getAssignedTo() {
		return  __('Assigned to ').__($this->type).' ID '.$this->post_id. '<input type="hidden" id="mg_post_id" name="mg_post_id" value="' . $this->post_id . '" /><input type="hidden" id="mg_post_type" name="mg_post_type" value="' . $this->type . '" />';
	}  

	public function getTextToTranslate($requestvars) {
		global $wpdb;

		$this->post_id = $post_id = isset($requestvars['mg_post_id'])?$requestvars['mg_post_id']:0;
		if ($post_id == 0) {
			return array();
		}

		$tp = $wpdb->prefix;
		$texts = array();
		$this->primarylang = mygengo_get_product_language_value($product_id);
		$this->wordcount   = 0; //deprecated
		$job_title = '';
		$job_text  = '';

		$post_data = mygengo_product($post_id);
		if ($post_data) {
			$job_title=$post_data['name'];
			foreach($post_data as $key=>$value) {
				$job_text .= "\r\n[[[".$key . "__]]]\r\n";
				$job_text .= $value . "\r\n";
			}
			$texts[$job_title] = $job_text;
		}
		return $texts;
	}

	public  function getPrimaryLanguage() {
		return $this->primarylang;
	}

	public  function getWordcount($unit) {
		return $this->wordcount;
	}

	public function retrievePublishableAs($jobid) {  
		global $wpdb;
		$table_name3 = $wpdb->prefix . 'gengo_jobs';
		$job_post_id = $wpdb->get_var("SELECT job_post_id FROM ".$table_name3." WHERE job_id = ".$jobid);
		if (!$job_post_id) { $job_post_id = 0; }

		eval("\$mg_select = mygengo_generate_select_from_{$this->type}s({$job_post_id}, '<option value=\"\">[Select]</option>');");
		$html  = '<label><input type="radio" name="mg_publish_as" value="' . $this->internalId . '-new" /><span class="checkbox-title">'.__('Publish as new '.$this->type) . '</span></label> <br/>';
		$html .= __('Publish inside '.$this->type);
		$html .= '<select name="mg_'.$this->type.'">' . $mg_select . '</select><br/>';
		$html .= '<label><input type="radio" name="mg_publish_as" value="' . $this->internalId . '-translate" /><span class="checkbox-title">' . __('as translation') . '</span></label> <br/>';
		return $html;
	}  

	public function publishAs($jobid, $requestvars) { 
		list($id, $publish_as)  = split("-", $requestvars['mg_publish_as']);
		if (intval($id) != $this->getInternalId()) {
			wp_die( 'Error: accessing a text source with different id.', 'Error while creating the new post!' );
		}

		global $Shopp;
		$Admin = $Shopp->Flow->Admin;

		global $wpdb, $wp_admin_url, $table_name3;
		$job_body    = $wpdb->get_var("SELECT job_body_tgt FROM ".$table_name3." WHERE job_id = ".$jobid);

		$post_parent = $requestvars['mg_'.$this->type];
		$post_type   = $this->type;

		$userid       = $requestvars['mg_user_id'];
		$post_author  = (get_option('mygengo_use_mygengouser'))?get_option('mygengo_translator_id'):$userid;
		if (count(mygengo_getKeys(2)) == 2 && get_the_author_meta('mygengo_add_footer', $userid)) {
			$description_footer   = '<div>'.get_the_author_meta('mygengo_footer').'</div>';
		} elseif (count(mygengo_getKeys(2)) != 2 && get_option('mygengo_add_footer')) {
			$description_footer   = '<div>'.get_option('mygengo_footer').'</div>';
		} else {
			$description_footer   = '';
		}
		$post_language = $requestvars['mg_tgt_language'];
		$post_sections = mygengo_parse_shopp_content($job_body);

		$Product = new Product();
		if ($publish_as == 'translate') {
			$Product->load($post_parent);
			$Product->duplicate();
		}
		$Product->description = $post_sections['post_content'].$description_footer;
		$Product->name        = $post_sections['post_title'].'('.$post_language.')';
		$Product->summary     = $post_sections['post_excerpt'];
		$Product->save();

		add_metadata('shopp_product', $Product->id, "_post_language_value", $post_language, true);
		add_metadata('shopp_product', $Product->id, "_post_parent_value", $post_parent, true);

        if ( !$object_id = absint($Product->id) )
                wp_die('ID is: '.$Product->id, 'ID is: '.$Product->id);

        if ( ! $table = _get_meta_table('shopp_product') )
                wp_die('Table error shopp_product', 'Table error shopp_product');


		wp_redirect(add_query_arg('page',$Admin->products,$wp_admin_url.'/admin.php'));
		exit();
	}  
}
?>