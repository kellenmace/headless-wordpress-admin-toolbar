<?php

/**
 * Plugin Name: Headless WordPress Admin Toolbar
 * Description: Enable the WordPress admin toolbar on headless WordPress sites.
 * Version:     0.1.0
 * Author:      WP Engine
 * Author URI:  https://wpengine.com/
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: faust-admin-bar
 */

require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

$admin_bar_menu_item_type = 'AdminBarMenuItem';
$admin_bar_menu_item_meta_type = 'AdminBarMenuItemMeta';
$admin_bar_menu_items_field_name = 'adminBarMenuItems';

add_action('graphql_register_types', function () {
    global $admin_bar_menu_item_type, $admin_bar_menu_item_meta_type, $admin_bar_menu_items_field_name;

    register_graphql_object_type($admin_bar_menu_item_meta_type, [
        'description' => __('A single node from the admin bar menu', 'faust-admin-bar'),
        'fields' => [
            'class' => [
                'type' => 'String',
                'description' => __('The class(es) for a given admin menu item', 'faust-admin-bar'),
            ],
            'tabindex' => [
                'type' => 'String',
                'description' => __('The tabindex for a given admin menu item', 'faust-admin-bar'),
            ],
        ],
    ]);

    register_graphql_object_type($admin_bar_menu_item_type, [
        'description' => __('A single node from the admin bar menu', 'faust-admin-bar'),
        'fields' => [
            'id' => [
                'type' => 'String',
                'description' => __('The slug for a given admin menu item', 'faust-admin-bar'),
            ],
            'title' => [
                'type' => 'String',
                'description' => __('The link text for a given admin menu item', 'faust-admin-bar'),
            ],
            'parent' => [
                'type' => 'String',
                'description' => __('The slug of the parent menu item or null if a root item', 'faust-admin-bar'),
            ],
            'href' => [
                'type' => 'String',
                'description' => __('The absolute URL for the given menu item', 'faust-admin-bar'),
            ],
            'group' => [
                'type' => 'Bool',
                'description' => __('Determines if a link is for grouping other links', 'faust-admin-bar'),
            ],
            'meta' => [
                'type' => $admin_bar_menu_item_meta_type,
                'description' => __('Additional link information including class and tabindex', 'faust-admin-bar'),
            ],
        ],
    ]);

    register_graphql_field('ContentNode', $admin_bar_menu_items_field_name, [
        'type' => ['list_of' => $admin_bar_menu_item_type],
        'resolve' => 'resolve_admin_bar_menu_nodes',
    ]);

    register_graphql_field('TermNode', $admin_bar_menu_items_field_name, [
        'type' => ['list_of' => $admin_bar_menu_item_type],
        'resolve' => 'resolve_admin_bar_menu_nodes',
    ]);

    register_graphql_field('User', $admin_bar_menu_items_field_name, [
        'type' => ['list_of' => $admin_bar_menu_item_type],
        'resolve' => 'resolve_admin_bar_menu_nodes',
    ]);

    register_graphql_field('RootQueryToMediaItemConnection', $admin_bar_menu_items_field_name, [
        'type' => ['list_of' => $admin_bar_menu_item_type],
        'resolve' => 'resolve_admin_bar_menu_nodes',
    ]);

    register_graphql_field('RootQueryToUserConnection', $admin_bar_menu_items_field_name, [
        'type' => ['list_of' => $admin_bar_menu_item_type],
        'resolve' => 'resolve_admin_bar_menu_nodes',
    ]);

    $graphql_post_types = get_post_types(['show_in_graphql' => true], 'objects');

    foreach ($graphql_post_types as $graphql_post_type) {
        $single_name = ucfirst($graphql_post_type->graphql_single_name);

        register_graphql_field("RootQueryTo{$single_name}Connection", $admin_bar_menu_items_field_name, [
            'type' => ['list_of' => $admin_bar_menu_item_type],
            'resolve' => 'resolve_admin_bar_menu_nodes',
        ]);
    }
});

function resolve_admin_bar_menu_nodes($source)
{
    global $wp_admin_bar, $wp_the_query, $wp_query;

    if (!is_user_logged_in() || !_get_admin_bar_pref()) {
        return null;
    }

    add_filter('graphql_request_results', 'headless_wordpress_admin_bar_url_replacement', 11);

    $nodes = [];
    $admin_bar_class = apply_filters('wp_admin_bar_class', 'WP_Admin_Bar');

    if (empty($wp_admin_bar) && class_exists($admin_bar_class)) {
        if (!empty($source->setup)) {
            $source->setup();
        }
        $wp_the_query = $wp_query;
        $wp_admin_bar = new $admin_bar_class;
        $wp_admin_bar->initialize();
        $wp_admin_bar->add_menus();
        do_action('admin_bar_menu', $wp_admin_bar);
        $nodes = $wp_admin_bar->get_nodes();
    }

    $value = array_map(function ($node) {
        $meta = array_map(fn ($v) => empty($v) ? null : $v, $node->meta);

        return [
            'id' => $node->id,
            'title' => $node->title ?? null,
            'parent' => $node->parent ?? null,
            'href' => $node->href ?? null,
            'group' => $node->group,
            'meta' => $meta
        ];
    }, $nodes);

    return $value;
}

function headless_wordpress_admin_bar_url_replacement($response)
{
    if (!function_exists('WPE\FaustWP\Replacement\domain_replacement_enabled') || !WPE\FaustWP\Replacement\domain_replacement_enabled()) {
        return $response;
    }

    if (
        is_object($response) &&
        property_exists($response, 'data') &&
        is_array($response->data)
    ) {
        headless_wordpress_admin_bar_url_replace_recursive($response->data);
    }

    return $response;
}

function headless_wordpress_admin_bar_url_replace_recursive(&$data)
{
    global $admin_bar_menu_items_field_name;

    foreach ($data as $key => &$value) {
        if ($key === $admin_bar_menu_items_field_name) {
            foreach ($value as &$item) {
                foreach ($item as $item_key => &$item_value) {
                    if (('href' === $item_key) && is_string($item_value)) {
                        $replacement = site_url();
                        $item_value  = str_replace(WPE\FaustWP\Settings\faustwp_get_setting('frontend_uri', '/'), $replacement, $item_value);
                    }
                }
            }
        } elseif (is_array($value)) {
            headless_wordpress_admin_bar_url_replace_recursive($value);
        }
    }
}
