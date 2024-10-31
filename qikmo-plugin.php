<?php
/*
Plugin Name: Qikmo Mobile Website Redirection
Description: Automatically redirects mobile users to mobile version of the site.
Author: Constantin V. Bosneaga
Author URI: http://a32.me/
Email: constantin@bosneaga.com
Version: 1.3
*/


$_QPOST = $_POST;
require_once dirname(__FILE__) . '/hd3.php';

class Qikmo_Plugin {

    public function __construct() {
        add_action('init', array($this, 'wp_init'));
        add_action('admin_init', array($this, 'wp_admin_init'));
        add_action('admin_menu', array($this, 'wp_admin_menu'));
        register_activation_hook(__FILE__, array($this, 'wp_activation_hook'));
    }

    /**
     * Init plugin
     */
    function wp_init() {
        add_action('template_redirect', array($this, 'wp_template_redirect'), 1);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'wp_action_links'));
    }

    function wp_admin_init() {
        wp_enqueue_script('jquery-deserialize', plugins_url('/jquery.deserialize.js', __FILE__), array('jquery'));
    }

    function wp_action_links($links) {
        $settings_link = '<a href="' . menu_page_url('qikmo_setup', false) . '">'
            . esc_html(__('Settings')) . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Install procedure
     */
    function wp_activation_hook() {
        $option = get_option('qikmo_options');
        if (!is_array($option)) $option = array();

        if (empty($option['tablet_redirect'])) $option['tablet_redirect'] = 'desktop';
        update_option('qikmo_options', $option);
    }

    /**
     * Registers admin menu for setup
     */
    function wp_admin_menu() {
        add_submenu_page('options-general.php', 'Qikmo Setup', 'Qikmo Setup', 'manage_options', 'qikmo_setup', array($this, 'wp_setup'));
    }

    /**
     * ADMIN Setup page
     *
     */
    function wp_setup() {
        $error = false;
        if (isset($_POST['version'])) {
            $option = array_map( 'stripslashes_deep', $_POST );

            $option['domain'] = str_replace(array('http://','https://'),array('',''), $option['domain']);

            if (!preg_match('_^(?:(?:https?|ftp)://)(?:\S+(?::\S*)?@)?(?:(?!10(?:\.\d{1,3}){3})(?!127(?:\.\d{1,3}){3})(?!169\.254(?:\.\d{1,3}){2})(?!192\.168(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)(?:\.(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)*(?:\.(?:[a-z\x{00a1}-\x{ffff}]{2,})))(?::\d{2,5})?(?:/[^\s]*)?$_iuS', 'http://'.$option['domain']))
                $error = 'Mobile domain is not valid';

            if (empty($option['username'])) $error = 'Enter API username';
            if (empty($option['secret'])) $error = 'Enter API secret';

            // Verify API credentials
            if (!$error) {
                $hd3 = new HD3(array(
                    'username' => $option['username'], // f358e5de9f
                    'secret' => $option['secret'],
                    'site_id' => $option['site_id'],
                ));
                $hd3->deviceVendors(); // test API calls
                $reply = $hd3->getReply();
                if ($reply['status'] != 0) $error = $reply['message'];
            }

            if (!$error) update_option('qikmo_options', $option);
        }

        // get current options
        $option = get_option('qikmo_options');
        // Guess mobile domain
        if (empty($option['domain'])) $option['domain'] = 'm.' . str_replace('www.', '', $_SERVER['SERVER_NAME']);

        require_once "setup.php";
    }


    function wp_template_redirect() {
        global $wp;

        // Disable redirect
        if (isset($_REQUEST['no_redirect'])) return;

        // Desktop device detected, no further detect attempts
        if (isset($_COOKIE['mobile'])) return;

        $option = get_option('qikmo_options');
        if (empty($option['domain'])) return;

        // Detect browser version
        $hd3 = new HD3(array(
            'username' => $option['username'],
            'secret' => $option['secret'],
            'site_id' => $option['site_id'],
        ));

        //$agent = 'Mozilla/5.0 (Linux; U; Android 2.2.1; fr-fr; GT-I9003 Build/FROYO) AppleWebKit/525.10+ (KHTML, like Gecko) Version/3.0.4 Mobile Safari/523.12.2 (AdMob-ANDROID-20091123)';
        //$agent = 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.0; Trident/4.0; GTB7.1; SLCC1; .NET CLR 2.0.50727; Media Center PC 5.0; InfoPath.2; .NET CLR 3.5.30729; .NET4.0C; .NET CLR 3.0.30729; AskTbFWV5/5.12.2.16749; 978803803)';
        ///$agent = 'Mozilla/5.0 (iPad; U; CPU OS 4_3_3 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Mobile/8J2 Safari/528.16 Kikin/1.5.0';
        $agent = $_SERVER["HTTP_USER_AGENT"];
        $hd3->setup();
        $hd3->setDetectVar('User-Agent', $agent);
        $hd3->setDetectVar('x-wap-profile', $_SERVER["HTTP_X_WAP_PROFILE"]);
        $hd3->siteDetect();
        $reply = $hd3->getReply();

        // Decide if to do a redirect
        $redirect = false;
        if (strtolower($reply["class"]) == 'mobile') $redirect = true;
        if (strtolower($reply["class"]) == 'tablet' && $option['tablet_redirect'] == 'mobile') $redirect = true;
        if (!$redirect) {
            // Save detection decison for curent session
            setcookie('mobile', 'no');
            return;
        }

        // Do a redirect
        $mobileUrl = 'http';
        if (is_ssl()) $mobileUrl .= 's';
        $mobileUrl .= '://'.$option['domain'];
        $mobileUrl .= sprintf('?url=%s&dm_redirected=true',urlencode(add_query_arg( $wp->query_string, '', home_url( $wp->request ) )));
        header("Location: " . $mobileUrl);
        exit;
    }
}

new Qikmo_Plugin();

