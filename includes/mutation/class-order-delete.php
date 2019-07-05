<?php
/**
 * Mutation - deleteOrder
 *
 * Registers mutation for delete an order.
 *
 * @package WPGraphQL\Extensions\WooCommerce\Mutation
 * @since 0.2.0
 */

namespace WPGraphQL\Extensions\WooCommerce\Mutation;

use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQLRelay\Relay;
use WPGraphQL\AppContext;
use WPGraphQL\Extensions\WooCommerce\Data\Mutation\Order_Mutation;
use WPGraphQL\Extensions\WooCommerce\Model\Order;

/**
 * Class Order_Delete
 */
class Order_Delete {
	/**
	 * Registers mutation
	 */
	public static function register_mutation() {
		register_graphql_mutation(
			'deleteOrder',
			array(
				'inputFields'         => self::get_input_fields(),
				'outputFields'        => self::get_output_fields(),
				'mutateAndGetPayload' => self::mutate_and_get_payload(),
			)
		);
	}

	/**
	 * Defines the mutation input field configuration
	 *
	 * @return array
	 */
	public static function get_input_fields() {
		$input_fields = array_merge(
			array(
				'id'          => array(
					'type'        => 'ID',
					'description' => __( 'Order global ID', 'wp-graphql-woocommerce' ),
				),
				'orderId'     => array(
					'type'        => 'Int',
					'description' => __( 'Order WP ID', 'wp-graphql-woocommerce' ),
				),
				'forceDelete' => array(
					'type'        => 'Boolean',
					'description' => __( 'Delete or simply place in trash.', 'wp-graphql-woocommerce' ),
				),
			)
		);

		return $input_fields;
	}

	/**
	 * Defines the mutation output field configuration
	 *
	 * @return array
	 */
	public static function get_output_fields() {
		return array(
			'order' => array(
				'type'    => 'Order',
				'resolve' => function( $payload ) {
					return $payload['order'];
				},
			),
		);
	}

	/**
	 * Defines the mutation data modification closure.
	 *
	 * @return callable
	 */
	public static function mutate_and_get_payload() {
		return function( $input, AppContext $context, ResolveInfo $info ) {
			$post_type_object = get_post_type_object( 'shop_order' );

			if ( ! current_user_can( $post_type_object->cap->create_posts ) ) {
				throw new UserError( __( 'User does not have the capabilities necessary to delete an order.', 'wp-graphql-woocommerce' ) );
			}

			// Retrieve order ID.
			$order_id = null;
			if ( ! empty( $input['id'] ) ) {
				$id_components = Relay::fromGlobalId( $input['id'] );
				if ( empty( $id_components['id'] ) || empty( $id_components['type'] ) ) {
					throw new UserError( __( 'The "id" provided is invalid', 'wp-graphql-woocommerce' ) );
				}
				$order_id = absint( $id_components['id'] );
			} elseif ( ! empty( $input['orderId'] ) ) {
				$order_id = absint( $input['orderId'] );
			} else {
				throw new UserError( __( 'No order ID provided.', 'wp-graphql-woocommerce' ) );
			}

			$force_delete = false;
			if ( ! empty( $input['forceDelete'] ) ) {
				$force_delete = $input['forceDelete'];
			}

			// Get Order model instance for output.
			$order = new Order( $order_id );

			// Cache items to prevent null value errors.
			// @codingStandardsIgnoreStart
			$order->downloadableItems;
			$order->get_items();
			$order->get_items( 'fee' );
			$order->get_items( 'shipping' );
			$order->get_items( 'tax' );
			$order->get_items( 'coupon' );
			// @codingStandardsIgnoreEnd.

			/**
			 * Action called before order is deleted.
			 *
			 * @param WC_Order    $order   WC_Order instance.
			 * @param array       $props   Order props array.
			 * @param AppContext  $context Request AppContext instance.
			 * @param ResolveInfo $info    Request ResolveInfo instance.
			 */
			do_action( 'woocommerce_graphql_before_order_delete', $order, $context, $info );

			// Delete order.
			$success = Order_Mutation::purge( \WC_Order_Factory::get_order( $order->ID ), $force_delete );

			if ( ! $success ) {
				throw new UserError(
					sprintf(
						/* translators: Deletion failed message */
						__( 'Removal of Order %d failed', 'wp-graphql-woocommerce' ),
						$order->get_id()
					)
				);
			}

			return array( 'order' => $order );
		};
	}
}
