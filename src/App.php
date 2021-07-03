<?php


namespace My\PageAccess;

class App
{
    const NONCE_NAME = 'my_page_access_nonce';

    public static function init()
    {
        add_action('init', [__CLASS__, 'loadTextdomain']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'adminEnqueueScripts']);
        add_action('add_meta_boxes', [__CLASS__, 'addMetaBoxes']);
        add_action('save_post', [__CLASS__, 'savePost']);
        add_action('pre_get_posts', [__CLASS__, 'preGetPosts']);

        add_action('init', function () {
            $post_types = self::getPostTypes();
            foreach ($post_types as $post_type) {
                add_filter("manage_{$post_type}_posts_columns", [__CLASS__, 'addAdminColumns']);
                add_filter("manage_{$post_type}_posts_custom_column", [__CLASS__, 'renderAdminColumnContent'], 10, 2);
            }
        });
    }

    public static function loadTextdomain()
    {
        load_plugin_textdomain(
            'my-page-access',
            false,
            dirname(plugin_basename(MY_PAGE_ACCESS_PLUGIN_FILE)) . '/languages'
        );
    }

    public static function getPostTypes()
    {
        $post_types = get_post_types(['public' => true]);
        
        return apply_filters('my_page_access/post_types', $post_types);
    }

    public static function adminEnqueueScripts()
    {
        $screen = get_current_screen();

        if ($screen->base !== 'post' || ! in_array($screen->post_type, self::getPostTypes())) {
            return;
        }

        wp_enqueue_script(
            'my-page-access-script',
            plugins_url('main.js', MY_PAGE_ACCESS_PLUGIN_FILE),
            ['jquery'],
            false,
            true
        );

        wp_enqueue_style(
            'my-page-access-style',
            plugins_url('main.css', MY_PAGE_ACCESS_PLUGIN_FILE)
        );
    }

    public static function addMetaBoxes($post_type)
    {
        if (in_array($post_type, self::getPostTypes())) {
            add_meta_box(
                'my-page-access-meta-box',
                __('Access', 'my-page-access'),
                [__CLASS__, 'renderMetaBox'],
                $post_type,
                'side',
                'high'
            );
        }
    }

    public static function getRoles()
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        return get_editable_roles();
    }

    public static function getUserRoles($user_id)
    {
        return get_userdata($user_id)->roles;
    }

    public static function getAllowedRoles($post_id)
    {
        $roles = get_post_meta($post_id, 'access_allowed_roles', true);

        if (! is_array($roles)) {
            $roles = [];
        }

        if (! in_array('administrator', $roles)) {
            $roles[] = 'administrator';
        }

        return $roles;
    }

    public static function isAccessRestricted($post_id)
    {
        // TODO : Check post type.
        return get_post_meta($post_id, 'is_access_restricted', true) ? true : false;
    }

    public static function canAccessPost($post_id, $user_id = null)
    {
        if (!self::isAccessRestricted($post_id)) {
            return true;
        }

        if (! is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();

        $allowed_roles = self::getAllowedRoles($post_id);
        $user_roles = self::getUserRoles($user_id);

        return array_intersect($allowed_roles, $user_roles) ? true : false;
    }

    public static function renderMetaBox($post)
    {
        wp_nonce_field('metabox', self::NONCE_NAME);

        $all_roles = self::getRoles();
        $is_access_restricted = self::isAccessRestricted($post->ID);
        $allowed_roles = self::getAllowedRoles($post->ID);

        printf(
            '<div class="my-page-access-settings%s">',
            $is_access_restricted ? ' has-restrictions' : ''
        );

        printf(
            '<p><label><input type="checkbox" name="is_access_restricted" value="1"%1$s> %2$s</label></p>',
            checked($is_access_restricted, true, false),
            esc_html__('Set restrictions', 'my-page-access')
        );

        echo '<div class="my-page-access-roles">';

        printf(
            '<p><strong>%1$s</strong></p>',
            esc_html__('Limit access to:', 'my-page-access')
        );

        echo '<ul>';
        foreach ($all_roles as $role_id => $role) {
            printf(
                '<li><label><input type="checkbox" name="access_allowed_roles[]" value="%1$s"%2$s%3$s> %4$s</label></li>',
                esc_attr($role_id),
                checked(in_array($role_id, $allowed_roles), true, false),
                $role_id == 'administrator' ? ' disabled="disabled"' : '',
                esc_html($role['name'])
            );
        }
        echo '</ul>';

        echo '</div>';

        echo '</div>';
    }

    public static function savePost($post_id)
    {
        // Check if our nonce is set.
        if (! isset($_POST[self::NONCE_NAME])) {
            return $post_id;
        }

        // Verify that the nonce is valid.
        if (! wp_verify_nonce($_POST[self::NONCE_NAME], 'metabox')) {
            return $post_id;
        }

        /*
         * If this is an autosave, our form has not been submitted,
         * so we don't want to do anything.
         */
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        // Check the user's permissions.
        if ('page' == $_POST['post_type']) {
            if (! current_user_can('edit_page', $post_id)) {
                return $post_id;
            }
        } else {
            if (! current_user_can('edit_post', $post_id)) {
                return $post_id;
            }
        }

        /* OK, it's safe for us to save the data now. */

        $is_access_restricted = ! empty($_POST['is_access_restricted']);
        $allowed_roles = isset($_POST['access_allowed_roles']) && is_array($_POST['access_allowed_roles']) ? $_POST['access_allowed_roles'] : [];

        if (! $is_access_restricted) {
            $allowed_roles = [];
        }

        update_post_meta($post_id, 'is_access_restricted', $is_access_restricted);
        update_post_meta($post_id, 'access_allowed_roles', $allowed_roles);
    }

    public static function addAdminColumns($columns)
    {
        return $columns + [
            'allow_access_for_roles' => __('Access allowed for', 'my-page-access'),
        ];
    }

    public static function renderAdminColumnContent($column, $post_id)
    {
        $is_access_restricted = self::isAccessRestricted($post_id);

        if ($is_access_restricted) {
            $allowed_roles = array_intersect_key(self::getRoles(), array_flip(self::getAllowedRoles($post_id)));
            $allowed_roles = wp_list_pluck($allowed_roles, 'name');
            $text = implode(', ', $allowed_roles);
        } else {
            $text = '–';
        }

        switch ($column) {
            case 'allow_access_for_roles':
                echo esc_html($text);
                break;
        }
    }

    public static function preGetPosts($query)
    {
        if (is_admin()) {
            return;
        }

        $post_types = array_intersect(self::getPostTypes(), (array) $query->get('post_type'));

        if (! $post_types) {
            return;
        }

        remove_action('pre_get_posts', [__CLASS__, __FUNCTION__]);

        $post_ids = get_posts([
            'post_type'   => $post_types,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'numberposts' => 9999,
            'meta_query'  => [
                [
                    'key'     => 'is_access_restricted',
                    'value'   => true,
                    'compare' => '=',
                ],
            ],
        ]);

        add_action('pre_get_posts', [__CLASS__, __FUNCTION__]);

        $exclude = $query->get('post__not_in');

        if (! is_array($exclude)) {
            $exclude = [];
        }

        foreach ($post_ids as $post_id) {
            if (! self::canAccessPost($post_id)) {
                $exclude[] = $post_id;
            }
        }

        $query->set('post__not_in', $exclude);
    }
}
