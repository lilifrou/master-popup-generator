<?php
/*
Plugin Name: Mapster Popup Generator
Description: Automatically create Popups of Locations on the Map
Version: 2.1
Author: Lilian Froutan
*/

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_notices', function () {
    if (isset($_GET['update_mapster'])) {
        echo '<div class="notice notice-warning"><p><strong>Mapster Popup Generator:</strong> plugin file is loaded, query param is present.</p></div>';
    }
});


// Run via: /wp-admin/?update_mapster=yes
add_action('admin_init', function () {
    if (isset($_GET['update_mapster']) && $_GET['update_mapster'] === 'yes') {
        update_all_mapster_locations();
    }
});

function update_all_mapster_locations() {
    // Ensure we are an admin and in the admin area
    if ( ! is_admin() ) return;

    // Use the *current* logged-in user; no need to switch users
    $current_user = wp_get_current_user();
    if ( ! $current_user || ! $current_user->exists() ) {
        error_log('❌ No current user.');
        return;
    }
    if ( ! user_can($current_user, 'manage_options') ) {
        error_log('❌ Current user is not an administrator.');
        return;
    }

    // Ensure ACF is available
    if ( ! function_exists('acf_save_post') ) {
        error_log('❌ ACF not loaded. Cannot call acf_save_post().');
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/post.php';

    $locations = get_posts([
        'post_type'   => 'mapster-wp-location',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);

    if (empty($locations)) {
        error_log('⚠️ No mapster locations found.');
        return;
    }

    foreach ($locations as $post_id) {
        simulate_acf_update($post_id);
        mapster_set_category($post_id, 'haendler');
    }

    error_log('✅ Finished updating all locations.');
}

function simulate_acf_update($post_id) {
    // Build popup body text from JSON
    $address_data = get_popup_body_text($post_id);

    // only what ACF needs
    $_POST['acf'] = [
        'field_616a60c610c96' => 1,            // enable popup
        'field_616a145a4f1eb' => 667,          // popup-style
        'field_6168d546268fb' => [             // popup fields group
            'field_6169fc8a6e649' => get_the_title($post_id), // header
            'field_61db0c22f9454' => 'feature-image',
            'field_6169fc9c6e64a' => $address_data ?: '',     // body
            'field_6169fda56e64f' => 'to-directions',
            'field_616a60fd2218f' => 'click',
            'field_6169fcbc6e64c' => 'Zum Händler',
        ],
    ];

    // Nonce is not required for acf_save_post(), but harmless
    $_POST['_wpnonce']   = wp_create_nonce('update-post_' . $post_id);
    $_POST['post_ID']    = $post_id;
    $_POST['post_title'] = get_the_title($post_id);
    $_POST['post_content'] = get_post_field('post_content', $post_id); // correct key

    acf_save_post($post_id);
    error_log("✅ Updated post ID: {$post_id}");
}

function get_popup_body_text($post_id) {
    // plugin-relative path so it doesn’t break if the folder name changes
    $jsonFilePath = trailingslashit(plugin_dir_path(__FILE__)) . 'output_converted.json';

    if ( ! file_exists($jsonFilePath) ) {
        error_log("❌ JSON file not found at: {$jsonFilePath}");
        return '';
    }

    $jsonData = file_get_contents($jsonFilePath);
    if ($jsonData === false) {
        error_log("❌ Could not read JSON file: {$jsonFilePath}");
        return '';
    }

    $decodedData = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('❌ JSON parsing error: ' . json_last_error_msg());
        return '';
    }

    $post_name = get_the_title($post_id);
    foreach ($decodedData as $entry) {
        if (isset($entry['name']) && $entry['name'] === $post_name) {
            $street     = $entry['address']['street']      ?? '';
            $city       = $entry['address']['city']        ?? '';
            $postalCode = $entry['address']['postal_code'] ?? '';
            $email      = $entry['contact']['email']       ?? '';
            $phone      = $entry['contact']['phone']       ?? '';

            
            return trim("$street\n$postalCode $city\n$email\n$phone");
        }
    }

    error_log("⚠️ No matching entry found for post title: {$post_name}");
    return '';
}


function mapster_set_category($post_id, $slug = 'haendler') {
    $taxonomy = 'wp-map-category';

    // Make sure the taxonomy exists
    if (!taxonomy_exists($taxonomy)) {
        error_log("❌ Taxonomy '$taxonomy' does not exist.");
        return;
    }

    // Try to find the term by slug first
    $term = get_term_by('slug', $slug, $taxonomy);

    // If it doesn't exist yet, create it
    if (!$term) {
        $term_result = wp_insert_term(ucfirst($slug), $taxonomy, ['slug' => $slug]);
        if (is_wp_error($term_result)) {
            error_log("❌ Failed to create term '$slug': " . $term_result->get_error_message());
            return;
        }
        $term_id = (int) $term_result['term_id'];
        error_log("✅ Created new term '$slug' ($term_id) in taxonomy '$taxonomy'.");
    } else {
        $term_id = (int) $term->term_id;
    }

    // Assign the term to the post (replace existing)
    $result = wp_set_object_terms($post_id, [$term_id], $taxonomy, false);

    if (is_wp_error($result)) {
        error_log("❌ Failed to assign term '$slug' to post $post_id: " . $result->get_error_message());
    } else {
        error_log("✅ Assigned taxonomy '$taxonomy' = '$slug' to post $post_id.");
    }
}
