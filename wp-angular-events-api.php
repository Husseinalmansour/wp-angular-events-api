<?php
/*
Plugin Name: WP Angular Events API
Description: Exposes a secure REST API for syncing Events and Workshops from Angular frontend to WordPress.
Version: 1.0
Author: Hussein Al-Mansour
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'cors.php';
require_once plugin_dir_path(__FILE__) . 'helper-functions.php';

if (!class_exists('WP_Angular_Events_API')) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-wp-angular-events-api.php';
    new WP_Angular_Events_API();
}
