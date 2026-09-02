<?php
/**
 * Safe VK ID account linking helpers.
 *
 * @package Presentonika_VK_ID
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find the WordPress account that owns a VK ID.
 */
function pnk_vkid_owner_id( string $vk_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value=%s ORDER BY user_id ASC LIMIT 1",
			'vk_id',
			$vk_id
		)
	);
}

/**
 * Atomically attach an unused VK ID to the current WordPress user.
 *
 * @return true|WP_Error
 */
function pnk_vkid_link_account( int $user_id, string $vk_id ) {
	$vk_id = trim( $vk_id );
	if ( $user_id <= 0 || 1 !== preg_match( '/^\d{1,24}$/', $vk_id ) ) {
		return new WP_Error( 'vkid_link_invalid', 'VK ID returned an invalid account identifier.' );
	}

	$lock_name = 'vkid_link_' . substr( hash( 'sha256', $vk_id ), 0, 32 );
	if ( ! function_exists( 'pnk_try_lock' ) || ! pnk_try_lock( $lock_name, 30 ) ) {
		return new WP_Error( 'vkid_link_busy', 'Привязка уже выполняется. Повторите попытку.' );
	}

	try {
		$current = trim( (string) get_user_meta( $user_id, 'vk_id', true ) );
		if ( '' !== $current && ! hash_equals( $current, $vk_id ) ) {
			return new WP_Error( 'vkid_link_already_linked', 'К аккаунту уже привязан другой VK ID.' );
		}

		$owner_id = pnk_vkid_owner_id( $vk_id );
		if ( $owner_id > 0 && $owner_id !== $user_id ) {
			return new WP_Error( 'vkid_link_conflict', 'Этот VK ID уже привязан к другому аккаунту.' );
		}

		if ( false === update_user_meta( $user_id, 'vk_id', $vk_id ) && ! hash_equals( $vk_id, $current ) ) {
			return new WP_Error( 'vkid_link_write', 'Не удалось сохранить привязку VK ID.' );
		}
		return true;
	} finally {
		pnk_release_lock( $lock_name );
	}
}

/**
 * Unlink VK ID only after the user proves another usable login method.
 *
 * @return true|WP_Error
 */
function pnk_vkid_unlink_account( int $user_id, string $current_password ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user || '' === (string) get_user_meta( $user_id, 'vk_id', true ) ) {
		return new WP_Error( 'vkid_unlink_missing', 'VK ID не привязан к этому аккаунту.' );
	}

	$password_verified = '' !== $current_password
		&& wp_check_password( $current_password, (string) $user->user_pass, $user_id );
	$alternative_login = (bool) apply_filters( 'pnk_vkid_has_alternative_login', $password_verified, $user );
	if ( ! $alternative_login ) {
		return new WP_Error(
			'vkid_unlink_no_fallback',
			'Сначала задайте и подтвердите пароль аккаунта, чтобы не потерять доступ.'
		);
	}

	delete_user_meta( $user_id, 'vk_id' );
	delete_user_meta( $user_id, 'vkid_access_token' );
	delete_user_meta( $user_id, 'vkid_refresh_token' );
	delete_user_meta( $user_id, 'vkid_expires_at' );
	return true;
}
