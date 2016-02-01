<?php
/**
 * REST API Coupons Controler.
 *
 * Handles requests to the /coupons endpoint
 *
 * @author   WooThemes
 * @category API
 * @package  WooCommerce/API
 * @since    2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Coupons Controller class.
 *
 * @package WooCommerce/API
 * @extends WC_REST_Controller
 */
class WC_REST_Coupons_Controller extends WC_REST_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $base = 'coupons';

	/**
	 * Type of object.
	 *
	 * @var string
	 */
	protected $object = 'shop_coupon';

	/**
	 * Register the routes for coupons.
	 */
	public function register_routes() {
		register_rest_route( WC_API::REST_API_NAMESPACE, '/' . $this->base, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			),
		) );
	}

	/**
	 * Get a collection of coupons.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$args                   = array();
		$args['offset']         = $request['offset'];
		$args['order']          = $request['order'];
		$args['orderby']        = $request['orderby'];
		$args['paged']          = $request['page'];
		$args['post__in']       = $request['include'];
		$args['post__not_in']   = $request['exclude'];
		$args['posts_per_page'] = $request['per_page'];
		$args['name']           = $request['code'];
		$args['s']              = $request['search'];

		if ( is_array( $request['filter'] ) ) {
			$args = array_merge( $args, $request['filter'] );
			unset( $args['filter'] );
		}

		$args['post_type']   = $this->object;
		$args['fields']      = 'all';
		$args['post_status'] = 'publish';

		$query = new WP_Query();
		$query_result = $query->query( $args );

		$items = array();
		foreach ( $query_result as $item ) {
			$data    = $this->prepare_item_for_response( $item, $request );
			$items[] = $this->prepare_response_for_collection( $data );
		}

		$response = rest_ensure_response( $items );

		return $response;
	}

	/**
	 * Check if a given request has access to get items.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ) {
		$post_type = get_post_type_object( $this->object );

		if ( ! current_user_can( $post_type->cap->read_private_posts ) ) {
			return new WP_Error( 'rest_cannot_read', __( 'Sorry, you cannot read coupons.', 'woocommerce' ), array( 'status' => WC_API::authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Prepare a single coupon output for response.
	 *
	 * @param WP_Post $item Item data.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response $data
	 */
	public function prepare_item_for_response( $item, $request ) {
		global $wpdb;

		$coupon = new WC_Coupon( $item );

		$data = array(
			'id'                           => $coupon->id,
			'code'                         => $coupon->code,
			'type'                         => $coupon->type,
			'created_at'                   => $this->prepare_date_response( $item->post_date_gmt ),
			'updated_at'                   => $this->prepare_date_response( $item->post_modified_gmt ),
			'amount'                       => wc_format_decimal( $coupon->coupon_amount, 2 ),
			'individual_use'               => 'yes' === $coupon->individual_use,
			'product_ids'                  => array_map( 'absint', (array) $coupon->product_ids ),
			'exclude_product_ids'          => array_map( 'absint', (array) $coupon->exclude_product_ids ),
			'usage_limit'                  => ! empty( $coupon->usage_limit ) ? $coupon->usage_limit : null,
			'usage_limit_per_user'         => ! empty( $coupon->usage_limit_per_user ) ? $coupon->usage_limit_per_user : null,
			'limit_usage_to_x_items'       => (int) $coupon->limit_usage_to_x_items,
			'usage_count'                  => (int) $coupon->usage_count,
			'expiry_date'                  => ! empty( $coupon->expiry_date ) ? $this->prepare_date_response( $coupon->expiry_date ) : null,
			'enable_free_shipping'         => $coupon->enable_free_shipping(),
			'product_category_ids'         => array_map( 'absint', (array) $coupon->product_categories ),
			'exclude_product_category_ids' => array_map( 'absint', (array) $coupon->exclude_product_categories ),
			'exclude_sale_items'           => $coupon->exclude_sale_items(),
			'minimum_amount'               => wc_format_decimal( $coupon->minimum_amount, 2 ),
			'maximum_amount'               => wc_format_decimal( $coupon->maximum_amount, 2 ),
			'customer_emails'              => $coupon->customer_email,
			'description'                  => $item->post_excerpt,
		);

		// $taxonomies = wp_list_filter( get_object_taxonomies( $this->post_type, 'objects' ), array( 'show_in_rest' => true ) );
		// foreach ( $taxonomies as $taxonomy ) {
		// 	$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;
		// 	$data[ $base ] = wp_get_object_terms( $post->ID, $taxonomy->name, array( 'fields' => 'ids' ) );
		// }

		// $context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		// $data = $this->add_additional_fields_to_object( $data, $request );
		// $data = $this->filter_response_by_context( $data, $context );

		// // Wrap the data in a response object.
		$response = rest_ensure_response( $data );

		/**
		 * Filter the post data for a response.
		 *
		 * The dynamic portion of the hook name, $this->post_type, refers to post_type of the post being
		 * prepared for the response.
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param WP_Post          $post     Post object.
		 * @param WP_REST_Request  $request  Request object.
		 */
		return apply_filters( 'wc_rest_prepare_coupons', $response, $item, $request );
	}

	/**
	 * Check the post_date_gmt or modified_gmt and prepare any post or
	 * modified date for single post output.
	 *
	 * @param string       $date_gmt
	 * @param string|null  $date
	 * @return string|null ISO8601/RFC3339 formatted datetime.
	 */
	protected function prepare_date_response( $date_gmt, $date = null ) {
		// Use the date if passed.
		if ( isset( $date ) ) {
			return mysql_to_rfc3339( $date );
		}

		// Return null if $date_gmt is empty/zeros.
		if ( '0000-00-00 00:00:00' === $date_gmt ) {
			return null;
		}

		// Return the formatted datetime.
		return mysql_to_rfc3339( $date_gmt );
	}
}
