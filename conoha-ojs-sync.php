<?php

/**
 * Plugin Name: ConoHa Object Sync Bobby Edit.
 * Plugin URI: https://github.com/bobby9az/conoha-ojs-sync
 * Description: This WordPress plugin allows you to upload files from the library to ConoHa Object Storage or other OpenStack Swift-based Object Store.
 * Author: Atsuhiro Azuma
 * Author URI: https://github.com/bobby9az
 * Text Domain: conoha-ojs-sync
 * Version: 0.3.1
 * License: GPLv2
*/
use OpenStack\OpenStack;
use OpenStack\Common\Transport\Utils as TransportUtils;
use OpenStack\Common\Error\BadResponseError;
use OpenStack\Identity\v2\Service;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Exception\RequestException;

// Text Domain
load_plugin_textdomain('conoha-ojs-sync', false, basename(dirname(__FILE__)). DIRECTORY_SEPARATOR . 'lang');
// Composer autoload
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * Add menu
 */
function add_pages() {
    $r = add_submenu_page('options-general.php', "ConoHa Object Store", "ConoHa Object Sync", 'administrator', __FILE__, 'option_page');
}

/**
 * Plugin page
 */
function option_page() {
    wp_enqueue_script('conohaojs-script', plugins_url( '/script/conohaojs.js' , __FILE__ ), [ 'jquery' ], '1.2.4', true);

    wp_enqueue_style('conohaojs-style', plugins_url('style/conohaojs.css', __FILE__));

    // Default options
    if (get_option('conohaojs-region', null) === null) {
        update_option('conohaojs-region', 'tyo2');
    }
    if (get_option('conohaojs-servicename', null) === null) {
        update_option('conohaojs-servicename', 'Object Storage Service');
    }
    if (get_option('conohaojs-auth-url', null) === null) {
        update_option('conohaojs-auth-url', 'https://identity.tyo2.conoha.io/v2.0');
    }
    if (get_option('conohaojs-delobject', null) === null) {
        update_option('conohaojs-delobject', 1);
    }

    // If it has messages...
    $messages = [];
    if (isset($_POST['resync']) && $_POST['resync']) {
        $files = conohaojs_resync();
        foreach ($files as $file => $stat) {
            $messages[] = match ($stat['source']) {
                true    => $file. ' uploaded.',
                false   => $file. ' upload failed.',
                default => $file. ' skiped.',
            };
            if (! empty($stat['thumbs']) ) {
                foreach ($stat['thumbs'] as $i => $thumb) {
                    $messages[] = match ($thumb['conoha_ojs_created']) {
                        true    => '-> '. $thumb['file']. ' uploaded.',
                        false   => '-> '. $thumb['file']. ' upload failed.',
                        default => '-> '. $thumb['file']. ' skiped.',
                    };
                }
            }
        }
    }

    // templates
    include "tpl/setting.php";
}


function conohaojs_options()
{
    // Informations for API authentication.
    register_setting('conohaojs-options', 'conohaojs-username', 'strval');
    register_setting('conohaojs-options', 'conohaojs-password', 'strval');
    register_setting('conohaojs-options', 'conohaojs-tenant-id', 'strval');
    register_setting('conohaojs-options', 'conohaojs-tenant-name', 'strval');
    register_setting('conohaojs-options', 'conohaojs-auth-url', 'esc_url');
    register_setting('conohaojs-options', 'conohaojs-region', 'strval');
    register_setting('conohaojs-options', 'conohaojs-servicename', 'strval');
    // Container name that media files will be uploaded.
    register_setting('conohaojs-options', 'conohaojs-container', 'strval');
    // Extensions
    register_setting('conohaojs-options', 'conohaojs-extensions', 'strval');
    // Synchronization option.
    register_setting('conohaojs-options', 'conohaojs-delafter', 'boolval');
    register_setting('conohaojs-options', 'conohaojs-delobject', 'boolval');
    // CDN option.
    register_setting('conohaojs-options', 'conohaojs-cdnhostname', 'strval');
}

/**
 *  Connection test
 *
 *  via async
 */
function conohaojs_connect_test()
{
    $username = '';
    if(isset($_POST['username'])) {
        $username = sanitize_text_field($_POST['username']);
    }
    $password = '';
    if(isset($_POST['password'])) {
        $password = sanitize_text_field($_POST['password']);
    }
    $tenant_id = '';
    if(isset($_POST['tenantId'])) {
        $tenant_id = sanitize_text_field($_POST['tenantId']);
    }
    $tenant_name = '';
    if(isset($_POST['tenantName'])) {
        $tenant_id = sanitize_text_field($_POST['tenantName']);
    }
    $auth_url = '';
    if(isset($_POST['authUrl'])) {
        $auth_url = sanitize_url($_POST['authUrl']);
    }
    $region = '';
    if(isset($_POST['region'])) {
        $region = sanitize_text_field($_POST['region']);
    }
    $servicename = '';
    if(isset($_POST['servicename'])) {
        $servicename = sanitize_text_field($_POST['servicename']);
    }

    try {
        $ojs = __get_object_store_service([
            'authUrl'    => $auth_url,  // Identity Service の URL
            'username'   => $username,  // API ユーザーのユーザー名
            'password'   => $password,      // API ユーザーのパスワード
            'tenantId'   => $tenant_id,  // テナント情報のテナントID
            'tenantName' => $tenant_name,  // テナント情報のテナント名
            'region'     => $region,      // エンドポイントのリージョン
        ]);

        echo json_encode([
            'message' => "Connection was Successfully.",
            'is_error' => false,
        ]);
        exit;

    } catch (\Exception $e) {
        echo json_encode([
            'message' => "ERROR: ".$ex->getMessage(),
            'is_error' => true,
        ]);
        exit;
    }
}

/**
 * Re-sync
 *
 *  Resync files that exist in wordpress but not in the container
 *  or have different timestamps.
 */
function conohaojs_resync() {

    $args = [
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'post_status' => 'any',
        'post_parent' => null,
        'orderby' => null,
        'order' => null,
        'exclude' => null,
    ];

    $attachments = get_posts($args);
    if ( ! $attachments) {
        return [];
    }

    $retval = [];
    foreach($attachments as $attach) {
        $path = get_attached_file($attach->ID);
        $name = __generate_object_name_from_path($path);
        $obj = __head_object($path);

        $do_upload = false;
        if ($obj === false && file_exists($path)) {
            $do_upload = true;
        } else {
            $container_mod = new \DateTime($obj->LastModified);
            $attachment_mod = new \DateTime($attach->post_modified_gmt);
            if ($attachment_mod->diff($container_mod)->invert === 1) {
                $do_upload = true;
            }
        }

        // Upload object if it isn't exists.
        if ($do_upload) {
            $retval[$name] = conohaojs_upload_file($attach->ID);
        } else {
            $retval[$name] = null;
        }
    }
    return $retval;
}

/**
 *  Upload a media file.
 */
function conohaojs_upload_file($file_id) {

    // get file path
    $path = get_attached_file($file_id);
    if( ! __file_has_upload_extensions($path)) {
        return null;
    }
    $result['source'] = __upload_object($path);
    // Get thumbnails of each size
    $metadatas = wp_get_attachment_metadata($file_id) ?? null;
    if (! empty($metadatas['sizes'])) {
        $result['thumbs'] = conohaojs_thumb_upload($metadatas)['sizes'] ?? null;
    }
    return $result;
}

/**
 * Upload each sizes thumbnails
 */
function conohaojs_thumb_upload($metadatas) {

    if( empty($metadatas['sizes'])) {
        return $metadatas;
    }

    // Init
    $result = [];
    $ym = preg_replace('/^.*([1-3][0-9]{3}\/[0-9]{2}).*$/', '$1', $metadatas['file']);
    $dir = wp_upload_dir($ym);

    foreach ($metadatas['sizes'] as $thumbId => $thumb) {
        $file = __generate_object_name_from_path($thumb['file']);
        if( ! __file_has_upload_extensions($file)) {
            $result[$file] = null;
            continue;
        }
        $filepath = $dir['path']. '/'. ($thumb['file']);
        $metadatas['sizes'][$thumbId]['file'] = $file;
        $metadatas['sizes'][$thumbId]['conoha_ojs_created'] = (__upload_object($filepath)) ? true : false;
    }
    return $metadatas;
}

/**
 * Delete an object
 */
function conohaojs_delete_object($file_id) {
    $path = get_attached_file($file_id);
    if( ! __file_has_upload_extensions($path)) {
        return true;
    }
    return __delete_object($file_id);
}

/**
 * Return object URL
 */
function conohaojs_object_storage_url($wpurl) {

    $file_id = __get_attachment_id_from_url($wpurl);
    $path = get_attached_file($file_id);

    if( ! __file_has_upload_extensions($path)) {
        return $wpurl;
    }

    $object_name = __generate_object_name_from_path($path);

    $container_name = get_option('conohaojs-container');
    if (preg_match('/[1-3][0-9]{3}\/[0-9]{2}/', $path, $matches) ) {
        $container_name .= '/'. $matches[0];
    }

    $cdn = get_option('conohaojs-cdnhostname') ?? false;
    if ( $cdn ) {
        // Via CDN (Cloudflare)
        $url = $cdn . '/' . $container_name . '/' .  $object_name;
    } else {
        // Via ConoHa endpoint
        $url = get_option('conohaojs-endpoint-url') . '/' . $container_name . '/' .  $object_name;
    }
    return $url;
}

/**
 * Replace image base URL
 */
function conohaojs_thumbnail_url($sources) {
    $image_baseurl = trailingslashit( wp_get_upload_dir()['baseurl'] );
    $cdn = get_option('conohaojs-cdnhostname') ?? false;
    if ( $cdn ) {
        $object_baseurl = trailingslashit( $cdn ) . '/'. trailingslashit( get_option('conohaojs-container') );
    } else {
        $object_baseurl = trailingslashit( get_option("conohaojs-endpoint-url"). '/'. get_option('conohaojs-container') );
    }
    if (is_array($sources)) {
        foreach($sources as $i => $source) {
            $sources[$i]['url'] = str_replace($image_baseurl, $object_baseurl, $source['url']);
        }
    }
    return $sources;
}

// -------------------- WordPress hooks --------------------

add_action('admin_menu', 'add_pages');
add_action('admin_init', 'conohaojs_options' );
add_action('wp_ajax_conohaojs_connect_test', 'conohaojs_connect_test');

add_action('add_attachment', 'conohaojs_upload_file');
add_action('edit_attachment', 'conohaojs_upload_file');
add_action('delete_attachment', 'conohaojs_delete_object');
add_filter('wp_update_attachment_metadata', 'conohaojs_thumb_upload');
if (get_option("conohaojs-delobject") == 1) {
    add_filter('wp_delete_file', 'conohaojs_delete_object');
}

add_filter('wp_get_attachment_url', 'conohaojs_object_storage_url');

add_filter('wp_calculate_image_srcset', 'conohaojs_thumbnail_url');


// -------------------- internal functions --------------------

/**
 * generate the object name from the filepath.
 */
function __generate_object_name_from_path($path) {
    $dir = wp_upload_dir();
    $name = basename($path);
    $name = str_replace($dir['basedir'] . DIRECTORY_SEPARATOR, '', $name);
    $name = str_replace(DIRECTORY_SEPARATOR, '-', $name);
    return $name;
}

/**
 *  Check for file extensions that need to be uploaded.
 */
function __file_has_upload_extensions($file) {
    $extensions = get_option('conohaojs-extensions');
    if($extensions == '') {
        return true;
    }

    $f = new \SplFileInfo($file);
    if( ! $f->isFile()) {
        return false;
    }

    $fileext = $f->getExtension();
    $fileext = strtolower($fileext);

    foreach(explode(',', $extensions) as $ext) {
        if($fileext == strtolower($ext)) {
            return true;
        }
    }
    return false;
}

/**
 * Get postID from URL
 */
function __get_attachment_id_from_url($url) {
    global $wpdb;

    $upload_dir = wp_upload_dir();
    if(strpos($url, $upload_dir['baseurl']) === false){
        return null;
    }

    $url = str_replace($upload_dir['baseurl'] . '/', '', $url);

    $attachment_id = $wpdb->get_var($wpdb->prepare( "SELECT wposts.ID FROM $wpdb->posts wposts, $wpdb->postmeta wpostmeta WHERE wposts.ID = wpostmeta.post_id AND wpostmeta.meta_key = '_wp_attached_file' AND wpostmeta.meta_value = '%s' AND wposts.post_type = 'attachment'", $url));
    return $attachment_id;
}

/**
 * Object upload
 */
function __upload_object($filepath) {

    // Get container name
    $container_name = get_option('conohaojs-container');
    if (preg_match('/[1-3][0-9]{3}\/[0-9]{2}/', $filepath, $matches) ) {
        $container_name .= '/'. $matches[0];
    }
    // Get instance
    $service = __get_object_store_service();
    // Get or Post container
    try {
        if (! $service->containerExists($container_name)) {
            $service->createContainer([
                'name' => $container_name
            ]);
        }
        $container = $service->getContainer($container_name);
    } catch (RequestException | BadResponseError $e) {
        error_log("Can not create the container.");
        return false;
    }
    // Set container ACL
    $prop = $container->getMetadata()['Read'] ?? null;
    if (empty($prop) || (bool) preg_match('/\.r\:\*/', $prop) === false) {
        $container->mergeMetadata(['Read' => '.r:*,.rlisting']);
        $container->retrieve();
    }
    // Upload file
    if ( is_readable($filepath) ) {
        $object_name = __generate_object_name_from_path($filepath);
        // Create from stream
        $object = $container->createObject([
            'name'   => $object_name,
            'stream' => new Stream(fopen($filepath, 'r')),
        ]);
        $object->retrieve();
        // Add Header (ACL)
        $container->getObject($object_name)->mergeMetadata([
            'Read' => '.r:*'
        ]);
        $object->retrieve();
    } else {
        return null;
    }

    return true;
}

/**
 * Get object head
 */
function __head_object($filepath) {
    // Init.
    $head = null;
    // Get container name
    $container_name = get_option('conohaojs-container');
    if (preg_match('/[1-3][0-9]{3}\/[0-9]{2}/', $filepath, $matches) ) {
        $container_name .= '/'. $matches[0];
    }
    // Get instance
    $service = __get_object_store_service();
    // Get container
    try {
        if (! $service->containerExists($container_name)) {
            throw new \Exception('Container was not found.');
        }
        $container = $service->getContainer($container_name);
    } catch (RequestException | BadResponseError | \Exception $e) {
        error_log($e->getMessage());
        return false;
    }
    // Get header only object
    $object_name = __generate_object_name_from_path($filepath);
    try {
        if (! $container->objectExists($object_name)) {
            throw new \Exception('Object was not found.');
        }
        $object = $container->getObject($object_name);
        $object->retrieve();
        $head = (Object) [
            'hash'          => $object->hash,
            'contentLength' => $object->contentLength,
            'lastModified'  => $object->lastModified,
            'contentType'   => $object->contentType,
            'metadata'      => $object->getMetaData()
        ];
    } catch (RequestException | BadResponseError | \Exception $e) {
        return false;
    }
    return $head;
}

/**
 * Object deletion
 */
function __delete_object($post_id) {
    // Init.
    $object_names = [];
    // Get container name
    $filepath = get_attached_file($post_id);
    $container_name = get_option('conohaojs-container');
    if (preg_match('/[1-3][0-9]{3}\/[0-9]{2}/', $filepath, $matches) ) {
        $container_name .= '/'. $matches[0];
    }

    // Get thumbnails of each size
    $sizes = wp_get_attachment_metadata($post_id)['sizes'] ?? null;
    if (! empty($sizes)) {
        foreach($sizes as $thumb) {
            $object_names[] = $thumb['file'];
        }
    }

    // Get instance
    $service = __get_object_store_service();
    // Get container
    try {
        if (! $service->containerExists($container_name)) {
            throw new \Exception("Container was not found.");
        }
        $container = $service->getContainer($container_name);
    } catch (RequestException | BadResponseError | \Exception $e) {
        error_log($e->getMessage());
        return false;
    }
    // Get object name
    $object_names[] = __generate_object_name_from_path($filepath);
    // Loop
    foreach($object_names as $object_name) {
        if ($container->objectExists($object_name)) {
            // Get object
            $object = $container->getObject($object_name);
            // Exec
            $object->delete();
        }
    }
    return true;
}

/**
 * Get object store service
 */
function __get_object_store_service(Array $options=[]) {
    static $service = null;

    if( ! $service) {
        if( empty($options['username'])) {
            $options['username'] = get_option('conohaojs-username');
        }
        if( empty($options['password'])) {
            $options['password'] = get_option('conohaojs-password');
        }
        if( empty($options['tenantId'])) {
            $options['tenantId'] = get_option('conohaojs-tenant-id');
        }
        if( empty($options['tenantName'])) {
            $options['tenantName'] = get_option('conohaojs-tenant-name');
        }
        if( empty($options['authUrl'])) {
            $options['authUrl'] = get_option('conohaojs-auth-url');
        }
        if( empty($options['region'])) {
            $options['region']  = get_option('conohaojs-region');
        }
        /*
        if($options['servicename']  == null) {
            $options['servicename']  = get_option('conohaojs-servicename');
        }
        */
        $httpClient = new Client([
            'base_uri' => TransportUtils::normalizeUrl($options['authUrl']),
            'handler'  => HandlerStack::create(),
        ]);

        $options['identityService'] = Service::factory($httpClient);

        $client = new OpenStack($options);
        $identityService = $client->identityV2();

        $service = $client->objectStoreV1([
            'identityService' => $identityService,
            'catalogName' => 'Object Storage Service',
        ]);

        // Set endpoint URL to option
        update_option('conohaojs-endpoint-url', trim((String) $service->getContainer()->getObject(null)->getPublicUri(), '/'));
    }
    return $service;
}

/**
 * WP-Cli Commands
 */
if (defined('WP_CLI') && WP_CLI != null) {
    function conohaojs_cli_resync( $args ) {
        WP_CLI::log('Invoke: Conoha Object Storage: Resync');

        $args = [
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'post_parent' => null,
            'orderby' => null,
            'order' => null,
            'exclude' => null,
        ];

        $attachments = get_posts($args);
        if( ! $attachments) {
            return [];
        }

        $attach_count = count($attachments);
        WP_CLI::log( sprintf( 'Found %d item(s)', $attach_count ) );

        foreach ($attachments as $attach) {
            $path = get_attached_file($attach->ID);
            $name = __generate_object_name_from_path($path);
            $obj = __head_object($path);

            $do_upload = false;
            if ($obj === false && file_exists($path)) {
                $do_upload = true;
            } else {
                $container_mod = new \DateTime($obj->LastModified);
                $attachment_mod = new \DateTime($attach->post_modified_gmt);
                if ($attachment_mod->diff($container_mod)->invert === 1) {
                    $do_upload = true;
                }
            }

            // Upload object if it isn't exists.
            if ($do_upload) {
                $results = conohaojs_upload_file($attach->ID);
                $message = match ($results['source']) {
                    true    => ['method' => 'success', 'message' => sprintf( '%s: Uploaded.', $name )],
                    false   => ['method' => 'line', 'message' => sprintf( '%s: Upload failed.', $name )],
                    default => ['method' => 'success', 'message' => sprintf( '%s: Already uploaded.', $name )],
                };
                WP_CLI::{$message['method']}( $message['message'] );
                if (! empty($results['thumbs']) ) {
                    foreach ($results['thumbs'] as $i => $thumb) {
                        $message = match ($thumb['conoha_ojs_created']) {
                            true    => ['method' => 'success', 'message' => sprintf( '%s: Uploaded.', $thumb['file'] )],
                            false   => ['method' => 'line', 'message' => sprintf( '%s: Upload failed.', $thumb['file'] )],
                            default => ['method' => 'success', 'message' => sprintf( '%s: Already uploaded.', $thumb['file'] )],
                        };
                        WP_CLI::{$message['method']}( $message['message'] );
                    }
                }
            } else {
                WP_CLI::success( sprintf( '%s: Already uploaded.', $name ) );
            }
        }
        //*/
        WP_CLI::log( 'Running "wp media regenerate" for upload thumbnails to ConoHa Object Storage...' );
        WP_CLI::runcommand( 'media regenerate --yes' );
    }

    WP_CLI::add_command( 'conoha-ojs-resync', 'conohaojs_cli_resync');
}