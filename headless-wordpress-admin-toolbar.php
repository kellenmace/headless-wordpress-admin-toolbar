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

add_action('graphql_register_types', function () {
    $admin_bar_menu_item_type = 'AdminBarMenuItem';
    $admin_bar_menu_item_meta_type = 'AdminBarMenuItemMeta';
    $field_name = 'adminBarMenuItems';

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

    register_graphql_field('ContentNode', $field_name, [
        'type' => ['list_of' => $admin_bar_menu_item_type],
        'resolve' => 'resolve_admin_bar_menu_nodes',
    ]);

    register_graphql_field('TermNode', $field_name, [
        'type' => ['list_of' => $admin_bar_menu_item_type],
        'resolve' => 'resolve_admin_bar_menu_nodes',
    ]);

    register_graphql_field('User', $field_name, [
        'type' => ['list_of' => $admin_bar_menu_item_type],
        'resolve' => 'resolve_admin_bar_menu_nodes',
    ]);

    $graphql_post_types = get_post_types(['show_in_graphql' => true], 'objects');

    foreach ($graphql_post_types as $graphql_post_type) {
        $single_name = ucfirst($graphql_post_type->graphql_single_name);

        register_graphql_field("RootQueryTo{$single_name}Connection", $field_name, [
            'type' => ['list_of' => $admin_bar_menu_item_type],
            'resolve' => 'resolve_admin_bar_menu_nodes',
        ]);
    }
});

function resolve_admin_bar_menu_nodes($source)
{
    global $wp_admin_bar, $wp_the_query, $wp_query;

    if (!_get_admin_bar_pref()) {
        return null;
    }

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
