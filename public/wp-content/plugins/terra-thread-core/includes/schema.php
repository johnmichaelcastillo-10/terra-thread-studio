<?php
/**
 * Custom tables for workshop sessions and seat reservations.
 *
 * Written in MySQL dialect; the SQLite Database Integration driver translates
 * it, so the same schema works on MySQL/MariaDB without changes.
 */

namespace TerraThread\Core\Schema;

defined( 'ABSPATH' ) || exit;

const DB_VERSION = '1.0.0';

function sessions_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'tt_sessions';
}

function bookings_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'tt_bookings';
}

function install(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset  = $wpdb->get_charset_collate();
	$sessions = sessions_table();
	$bookings = bookings_table();

	dbDelta(
		"CREATE TABLE $sessions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workshop_id bigint(20) unsigned NOT NULL,
			starts_at datetime NOT NULL,
			ends_at datetime NOT NULL,
			capacity smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			PRIMARY KEY  (id),
			KEY workshop_start (workshop_id,starts_at)
		) $charset;
		CREATE TABLE $bookings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			reference varchar(16) NOT NULL,
			name varchar(120) NOT NULL,
			email varchar(190) NOT NULL,
			seats tinyint(3) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			KEY session_status (session_id,status)
		) $charset;"
	);

	update_option( 'tt_db_version', DB_VERSION, false );
}

function maybe_upgrade(): void {
	if ( DB_VERSION !== get_option( 'tt_db_version' ) && ! wp_installing() ) {
		install();
	}
}
