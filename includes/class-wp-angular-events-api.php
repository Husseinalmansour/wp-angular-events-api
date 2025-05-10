<?php
/**
 * Class WP_Angular_Events_API
 *
 * Registers a custom REST API endpoint to allow external Angular apps
 * to create, update, or delete WordPress posts of type "event" or "workshop"
 * using JSON payloads. The endpoint supports ACF field updates and featured image handling.
 *
 * @package CustomEventsAPI
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WP_Angular_Events_API {

    /**
     * Base URL of the Angular portal used to generate instructor/creator links.
     *
     * @var string
     */
    private $portal_base_url = 'https://portal.example.com'; // Replace with your real portal domain.

    /**
     * Constructor. Registers REST API endpoint on initialization.
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_event_routes'));
    }

    /**
     * Registers the /wpangular/v1/events endpoint for handling event/workshop submissions.
     */
    public function register_event_routes() {
        register_rest_route('wpangular/v1', '/events', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_or_update_event'),
            'permission_callback' => '__return_true', // Adjust if authentication is needed.
        ));
    }

    /**
     * Handles incoming POST requests to create, update, or delete an event or workshop.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function create_or_update_event($request) {
        $params = $request->get_json_params();

        // Basic validation
        if (empty($params['title']) || empty($params['description'])) {
            return new WP_Error('missing_fields', __('Title and description are required.', 'wpangular'), array('status' => 400));
        }

        // Determine post type and status
        $type_map = [
            'WORKSHOP' => 'workshops',
            'EVENT' => 'events',
        ];
        $post_type_raw = strtoupper($params['type'] ?? '');
        $post_type = $type_map[$post_type_raw] ?? 'workshops';

        $status_map = [
            'PENDING' => 'draft',
            'ACCEPTED' => 'publish',
            'REJECTED' => 'delete',
        ];
        $incoming_status_raw = strtoupper($params['status'] ?? 'ACCEPTED');
        $incoming_status = $incoming_status_raw;
        $post_status = $status_map[$incoming_status_raw] ?? 'publish';

        // Check if this is an update
        $angular_id = isset($params['id']) ? (string) $params['id'] : null;
        $post_id = 0;

        if ($angular_id) {
            $query = new WP_Query([
                'post_type' => $post_type,
                'meta_key' => 'angularid',
                'meta_value' => $angular_id,
                'posts_per_page' => 1
            ]);

            if ($query->have_posts()) {
                $post_id = $query->posts[0]->ID;
            }
        }

        // Handle deletion if status is "REJECTED"
        if ($post_id && $incoming_status === 'REJECTED') {
            wp_delete_post($post_id, true);
            return rest_ensure_response([
                'message' => ucfirst($post_type) . ' has been deleted.',
                'id' => $post_id,
                'type' => $post_type
            ]);
        }

        $is_new = false;

        // Create or update post
        if (!$post_id) {
            $post_data = [
                'post_title'   => sanitize_text_field($params['title']),
                'post_content' => sanitize_textarea_field($params['description']),
                'post_status'  => $post_status,
                'post_type'    => $post_type,
            ];
            $post_id = wp_insert_post($post_data);
            $is_new = true;

            if (is_wp_error($post_id)) {
                return new WP_Error('create_failed', __('Failed to create item.', 'wpangular'), array('status' => 500));
            }
        } else {
            $post_data = [
                'ID'           => $post_id,
                'post_title'   => sanitize_text_field($params['title']),
                'post_content' => sanitize_textarea_field($params['description']),
                'post_status'  => $post_status,
            ];
            $post_id = wp_update_post($post_data);

            if (is_wp_error($post_id)) {
                return new WP_Error('update_failed', __('Failed to update item.', 'wpangular'), array('status' => 500));
            }
        }

        // Update ACF fields if ACF is active
        if (function_exists('update_field')) {
            update_field('minimum_date', $params['minimum_date'] ?? '', $post_id);
            update_field('maximum_date', $params['maximum_date'] ?? '', $post_id);

            // Instructors list formatting as HTML links
            $instructor_links = [];
            foreach ($params['instructorsList'] ?? [] as $instructor) {
                if (isset($instructor['id'], $instructor['name'])) {
                    $url = $this->portal_base_url . '/user/' . $instructor['id'];
                    $name = esc_html($instructor['name']);
                    $link = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . $name . '</a>';
                    $instructor_links[] = $link;
                }
            }

            // Creator link
            if (isset($params['creator']['id'], $params['creator']['name'])) {
                $creator_url = $this->portal_base_url . '/user/' . $params['creator']['id'];
                $creator_name = esc_html($params['creator']['name']);
                $creator_link = '<a href="' . esc_url($creator_url) . '" target="_blank" rel="noopener noreferrer">' . $creator_name . '</a>';
                update_field('creator', $creator_link, $post_id);
            }

            // Simple field updates
            if (!empty($params['sports']) && is_array($params['sports'])) {
                update_field('sports', implode(', ', $params['sports']), $post_id);
            }

            update_field('instructors', implode(', ', $instructor_links), $post_id);
            update_field('language', $params['language'][0] ?? '', $post_id);
            update_field('email', $params['email'] ?? '', $post_id);
            update_field('contact_number', $params['contact_number'] ?? '', $post_id);
            update_field('location', $params['location'] ?? '', $post_id);
            update_field('description', $params['description'] ?? '', $post_id);
            update_field('angularid', $angular_id, $post_id);
            update_field('status', $incoming_status, $post_id);

            if (!empty($params['social_links']) && is_array($params['social_links'])) {
                update_field('social_links', $params['social_links'], $post_id);
            }
        }

        // Set featured image from external URL if provided
        if (!empty($params['featured_image']) && filter_var($params['featured_image'], FILTER_VALIDATE_URL)) {
            $this->set_featured_image_from_url($params['featured_image'], $post_id);
        }

        return rest_ensure_response([
            'message' => ucfirst($post_type) . ($is_new ? ' created successfully.' : ' updated successfully.'),
            'id' => $post_id,
            'type' => $post_type,
            'status' => $incoming_status,
        ]);
    }

    /**
     * Downloads and sets a featured image for the post from an external URL.
     *
     * @param string $image_url Direct URL to the image.
     * @param int $post_id The ID of the post to attach the image to.
     */
    private function set_featured_image_from_url($image_url, $post_id) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) return;

        $file_array = [
            'name'     => basename($image_url),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, $post_id);
        if (!is_wp_error($id)) {
            set_post_thumbnail($post_id, $id);
        }
    }
}
