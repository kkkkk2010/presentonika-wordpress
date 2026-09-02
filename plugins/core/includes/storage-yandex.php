<?php
/**
 * Yandex Object Storage provider for presentation archives.
 *
 * @package Presentonika_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the configured storage provider.
 */
function pnk_storage_provider(): string {
	$provider = defined( 'PRESENTONIKA_STORAGE_PROVIDER' )
		? strtolower( trim( (string) PRESENTONIKA_STORAGE_PROVIDER ) )
		: 'local';

	return in_array( $provider, array( 'local', 'yandex_object_storage' ), true ) ? $provider : 'local';
}

/**
 * Return non-secret Yandex Object Storage configuration plus credentials.
 *
 * @return array<string,string>
 */
function pnk_yandex_storage_config(): array {
	return array(
		'endpoint'   => defined( 'PRESENTONIKA_YANDEX_STORAGE_ENDPOINT' )
			? rtrim( (string) PRESENTONIKA_YANDEX_STORAGE_ENDPOINT, '/' )
			: 'https://storage.yandexcloud.net',
		'region'     => defined( 'PRESENTONIKA_YANDEX_STORAGE_REGION' )
			? trim( (string) PRESENTONIKA_YANDEX_STORAGE_REGION )
			: 'ru-central1',
		'bucket'     => defined( 'PRESENTONIKA_YANDEX_STORAGE_BUCKET' )
			? trim( (string) PRESENTONIKA_YANDEX_STORAGE_BUCKET )
			: '',
		'access_key' => defined( 'PRESENTONIKA_YANDEX_STORAGE_ACCESS_KEY_ID' )
			? trim( (string) PRESENTONIKA_YANDEX_STORAGE_ACCESS_KEY_ID )
			: '',
		'secret_key' => defined( 'PRESENTONIKA_YANDEX_STORAGE_SECRET_ACCESS_KEY' )
			? trim( (string) PRESENTONIKA_YANDEX_STORAGE_SECRET_ACCESS_KEY )
			: '',
	);
}

/**
 * Validate the provider configuration without returning credentials.
 */
function pnk_yandex_storage_configured(): bool {
	$config = pnk_yandex_storage_config();
	$host   = (string) wp_parse_url( $config['endpoint'], PHP_URL_HOST );
	$scheme = strtolower( (string) wp_parse_url( $config['endpoint'], PHP_URL_SCHEME ) );

	return 'https' === $scheme
		&& '' !== $host
		&& '' !== $config['bucket']
		&& 1 === preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $config['bucket'] )
		&& '' !== $config['access_key']
		&& '' !== $config['secret_key']
		&& extension_loaded( 'curl' );
}

/**
 * Encode an S3 URI path without losing slash separators.
 */
function pnk_yandex_storage_encode_path( string $path ): string {
	$segments = explode( '/', ltrim( $path, '/' ) );
	return '/' . implode( '/', array_map( 'rawurlencode', $segments ) );
}

/**
 * Build a canonical RFC3986 query string.
 *
 * @param array<string,string|int> $query Query arguments.
 */
function pnk_yandex_storage_query( array $query ): string {
	ksort( $query, SORT_STRING );
	$pairs = array();
	foreach ( $query as $key => $value ) {
		$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}
	return implode( '&', $pairs );
}

/**
 * Derive an AWS Signature Version 4 key.
 */
function pnk_yandex_storage_signing_key( string $secret, string $date, string $region ): string {
	$date_key    = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
	$region_key  = hash_hmac( 'sha256', $region, $date_key, true );
	$service_key = hash_hmac( 'sha256', 's3', $region_key, true );
	return hash_hmac( 'sha256', 'aws4_request', $service_key, true );
}

/**
 * Create a unique cloud object name.
 */
function pnk_yandex_storage_object_name( int $user_id, int $presentation_id ): string {
	$version = gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 10, false, false ) );
	return sprintf( 'presentations/%d/%d/%s.out.zip', $user_id, $presentation_id, $version );
}

/**
 * Convert an object name into a database storage key.
 */
function pnk_yandex_storage_key( string $object_name ): string {
	if ( 1 !== preg_match( '~^presentations/\d+/\d+/[a-zA-Z0-9._-]+\.out\.zip$~', $object_name ) ) {
		return '';
	}
	return 'yandex:v1/' . $object_name;
}

/**
 * Parse a cloud storage key.
 *
 * @return array{user_id:int,presentation_id:int,object_name:string}|null
 */
function pnk_yandex_storage_parse_key( string $storage_key ): ?array {
	if ( 1 !== preg_match( '~^yandex:v1/(presentations/(\d+)/(\d+)/[a-zA-Z0-9._-]+\.out\.zip)$~', $storage_key, $matches ) ) {
		return null;
	}
	return array(
		'user_id'         => (int) $matches[2],
		'presentation_id' => (int) $matches[3],
		'object_name'     => $matches[1],
	);
}

/**
 * Create a signed S3 request description.
 *
 * @param array<string,string|int> $query   Query arguments.
 * @param array<string,string>     $headers Request headers.
 * @return array{url:string,headers:array<int,string>}|WP_Error
 */
function pnk_yandex_storage_signed_request(
	string $method,
	string $object_name = '',
	array $query = array(),
	array $headers = array(),
	string $payload_hash = 'UNSIGNED-PAYLOAD'
) {
	$config = pnk_yandex_storage_config();
	if ( ! pnk_yandex_storage_configured() ) {
		return new WP_Error( 'storage_cloud_config', 'Cloud storage is not configured.' );
	}

	$host = (string) wp_parse_url( $config['endpoint'], PHP_URL_HOST );
	$path = '/' . $config['bucket'] . ( '' !== $object_name ? '/' . ltrim( $object_name, '/' ) : '' );
	$uri  = pnk_yandex_storage_encode_path( $path );
	$now  = time();
	$date = gmdate( 'Ymd', $now );
	$time = gmdate( 'Ymd\THis\Z', $now );

	$canonical_headers = array(
		'host'                 => $host,
		'x-amz-content-sha256' => $payload_hash,
		'x-amz-date'           => $time,
	);
	foreach ( $headers as $name => $value ) {
		$canonical_headers[ strtolower( trim( $name ) ) ] = preg_replace( '/\s+/', ' ', trim( $value ) );
	}
	ksort( $canonical_headers, SORT_STRING );
	$signed_headers = implode( ';', array_keys( $canonical_headers ) );
	$header_string  = '';
	foreach ( $canonical_headers as $name => $value ) {
		$header_string .= $name . ':' . $value . "\n";
	}

	$canonical_request = strtoupper( $method ) . "\n"
		. $uri . "\n"
		. pnk_yandex_storage_query( $query ) . "\n"
		. $header_string . "\n"
		. $signed_headers . "\n"
		. $payload_hash;
	$scope             = $date . '/' . $config['region'] . '/s3/aws4_request';
	$string_to_sign    = "AWS4-HMAC-SHA256\n" . $time . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
	$signature         = hash_hmac(
		'sha256',
		$string_to_sign,
		pnk_yandex_storage_signing_key( $config['secret_key'], $date, $config['region'] )
	);
	$authorization     = 'AWS4-HMAC-SHA256 Credential=' . $config['access_key'] . '/' . $scope
		. ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

	$request_headers = array();
	foreach ( $canonical_headers as $name => $value ) {
		$request_headers[] = $name . ': ' . $value;
	}
	$request_headers[] = 'Authorization: ' . $authorization;
	$url               = $config['endpoint'] . $uri;
	$query_string      = pnk_yandex_storage_query( $query );
	if ( '' !== $query_string ) {
		$url .= '?' . $query_string;
	}

	return array(
		'url'     => $url,
		'headers' => $request_headers,
	);
}

/**
 * Execute a bounded, signed S3 request.
 *
 * @param array<string,string|int> $query   Query arguments.
 * @param array<string,string>     $headers Request headers.
 * @return array{status:int,body:string,headers:array<string,string>}|WP_Error
 */
function pnk_yandex_storage_request(
	string $method,
	string $object_name = '',
	array $query = array(),
	array $headers = array(),
	string $payload_hash = 'UNSIGNED-PAYLOAD',
	?string $upload_file = null
) {
	$signed = pnk_yandex_storage_signed_request( $method, $object_name, $query, $headers, $payload_hash );
	if ( is_wp_error( $signed ) ) {
		return $signed;
	}

	$retry_statuses = array( 429, 500, 502, 503, 504 );
	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$response_headers = array();
		$handle           = curl_init( $signed['url'] );
		if ( false === $handle ) {
			return new WP_Error( 'storage_cloud_curl', 'Cloud storage request could not start.' );
		}

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_CUSTOMREQUEST   => strtoupper( $method ),
				CURLOPT_HTTPHEADER      => $signed['headers'],
				CURLOPT_RETURNTRANSFER  => true,
				CURLOPT_CONNECTTIMEOUT  => 8,
				CURLOPT_TIMEOUT         => 90,
				CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
				CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_FOLLOWLOCATION  => false,
				CURLOPT_HEADERFUNCTION  => static function ( $curl, string $line ) use ( &$response_headers ): int {
					$length = strlen( $line );
					$parts  = explode( ':', $line, 2 );
					if ( 2 === count( $parts ) ) {
						$response_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
					}
					return $length;
				},
			)
		);

		$stream = null;
		if ( null !== $upload_file ) {
			$stream = fopen( $upload_file, 'rb' );
			if ( false === $stream ) {
				curl_close( $handle );
				return new WP_Error( 'storage_source_missing', 'Storage source is not readable.' );
			}
			curl_setopt( $handle, CURLOPT_UPLOAD, true );
			curl_setopt( $handle, CURLOPT_INFILE, $stream );
			curl_setopt( $handle, CURLOPT_INFILESIZE, (int) filesize( $upload_file ) );
		}

		$body       = curl_exec( $handle );
		$curl_error = curl_error( $handle );
		$status     = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );
		if ( is_resource( $stream ) ) {
			fclose( $stream );
		}

		if ( false !== $body && ! in_array( $status, $retry_statuses, true ) ) {
			return array(
				'status'  => $status,
				'body'    => (string) $body,
				'headers' => $response_headers,
			);
		}
		if ( $attempt < 3 ) {
			usleep( 200000 * ( 2 ** ( $attempt - 1 ) ) );
		}
	}

	pnk_log( 'storage.cloud_transport_failed', array( 'error_code' => 'transport' ), 'error' );
	return new WP_Error( 'storage_cloud_transport', 'Cloud storage request failed.', array( 'detail' => $curl_error ) );
}

/**
 * Upload a private presentation archive and verify its metadata.
 *
 * @return string|WP_Error
 */
function pnk_yandex_storage_store_file( int $user_id, int $presentation_id, string $source_file ) {
	if ( ! is_readable( $source_file ) ) {
		return new WP_Error( 'storage_source_missing', 'Storage source is not readable.' );
	}
	$size = (int) filesize( $source_file );
	if ( $size <= 0 || $size > 50 * 1024 * 1024 ) {
		return new WP_Error( 'storage_size_invalid', 'Presentation archive must be between 1 byte and 50 MB.' );
	}
	$sha256 = hash_file( 'sha256', $source_file );
	if ( ! is_string( $sha256 ) ) {
		return new WP_Error( 'storage_hash_failed', 'Presentation archive could not be hashed.' );
	}

	$object_name = pnk_yandex_storage_object_name( $user_id, $presentation_id );
	$headers     = array(
		'content-length'    => (string) $size,
		'content-type'      => 'application/zip',
		'x-amz-meta-sha256' => $sha256,
		'x-amz-meta-size'   => (string) $size,
	);
	$response    = pnk_yandex_storage_request( 'PUT', $object_name, array(), $headers, $sha256, $source_file );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( $response['status'] < 200 || $response['status'] >= 300 ) {
		return new WP_Error( 'storage_cloud_upload', 'Cloud storage rejected the upload.', array( 'status' => $response['status'] ) );
	}

	$verified = pnk_yandex_storage_head( $object_name );
	if ( is_wp_error( $verified )
		|| (int) ( $verified['size'] ?? -1 ) !== $size
		|| ! hash_equals( $sha256, (string) ( $verified['sha256'] ?? '' ) ) ) {
		pnk_yandex_storage_delete_object( $object_name );
		return new WP_Error( 'storage_cloud_verify', 'Cloud upload metadata verification failed.' );
	}

	return pnk_yandex_storage_key( $object_name );
}

/**
 * Fetch cloud object metadata.
 *
 * @return array{size:int,sha256:string}|WP_Error
 */
function pnk_yandex_storage_head( string $object_name ) {
	$response = pnk_yandex_storage_request( 'HEAD', $object_name );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== $response['status'] ) {
		return new WP_Error( 'storage_cloud_head', 'Cloud object metadata is unavailable.', array( 'status' => $response['status'] ) );
	}
	return array(
		'size'   => (int) ( $response['headers']['content-length'] ?? -1 ),
		'sha256' => strtolower( (string) ( $response['headers']['x-amz-meta-sha256'] ?? '' ) ),
	);
}

/**
 * Delete a cloud object. Deletion is idempotent.
 */
function pnk_yandex_storage_delete_object( string $object_name ): void {
	$response = pnk_yandex_storage_request( 'DELETE', $object_name, array(), array(), hash( 'sha256', '' ) );
	if ( is_wp_error( $response ) || ! in_array( $response['status'], array( 200, 204, 404 ), true ) ) {
		pnk_log( 'storage.cloud_delete_failed', array( 'error_code' => 'delete' ), 'warning' );
	}
}

/**
 * Return a five-minute private GET URL for the editor bridge.
 *
 * @return string|WP_Error
 */
function pnk_yandex_storage_signed_url( string $storage_key, int $ttl = 300 ) {
	$parsed = pnk_yandex_storage_parse_key( $storage_key );
	$config = pnk_yandex_storage_config();
	if ( null === $parsed || ! pnk_yandex_storage_configured() ) {
		return new WP_Error( 'storage_key_mismatch', 'Invalid cloud storage key.' );
	}

	$ttl                      = min( 300, max( 60, $ttl ) );
	$host                     = (string) wp_parse_url( $config['endpoint'], PHP_URL_HOST );
	$path                     = '/' . $config['bucket'] . '/' . $parsed['object_name'];
	$uri                      = pnk_yandex_storage_encode_path( $path );
	$now                      = time();
	$date                     = gmdate( 'Ymd', $now );
	$time                     = gmdate( 'Ymd\THis\Z', $now );
	$scope                    = $date . '/' . $config['region'] . '/s3/aws4_request';
	$query                    = array(
		'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
		'X-Amz-Credential'    => $config['access_key'] . '/' . $scope,
		'X-Amz-Date'          => $time,
		'X-Amz-Expires'       => $ttl,
		'X-Amz-SignedHeaders' => 'host',
	);
	$canonical_request        = "GET\n{$uri}\n" . pnk_yandex_storage_query( $query ) . "\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
	$string_to_sign           = "AWS4-HMAC-SHA256\n{$time}\n{$scope}\n" . hash( 'sha256', $canonical_request );
	$query['X-Amz-Signature'] = hash_hmac(
		'sha256',
		$string_to_sign,
		pnk_yandex_storage_signing_key( $config['secret_key'], $date, $config['region'] )
	);

	return $config['endpoint'] . $uri . '?' . pnk_yandex_storage_query( $query );
}

/**
 * List object versions for a presentation.
 *
 * @return array<int,array{key:string,modified:int}>|WP_Error
 */
function pnk_yandex_storage_list_versions( int $user_id, int $presentation_id ) {
	$prefix   = sprintf( 'presentations/%d/%d/', $user_id, $presentation_id );
	$response = pnk_yandex_storage_request(
		'GET',
		'',
		array(
			'list-type' => '2',
			'prefix'    => $prefix,
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== $response['status'] ) {
		return new WP_Error( 'storage_cloud_list', 'Cloud object listing failed.', array( 'status' => $response['status'] ) );
	}
	$xml = simplexml_load_string( $response['body'] );
	if ( false === $xml ) {
		return new WP_Error( 'storage_cloud_list_xml', 'Cloud object listing was malformed.' );
	}
	$objects = array();
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML field names are fixed.
	foreach ( $xml->Contents as $item ) {
		$key = (string) $item->Key;
		if ( str_starts_with( $key, $prefix ) ) {
			$objects[] = array(
				'key'      => $key,
				'modified' => (int) strtotime( (string) $item->LastModified ),
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	return $objects;
}

/**
 * Keep only the newest cloud object versions.
 */
function pnk_yandex_storage_cleanup_versions(
	int $user_id,
	int $presentation_id,
	int $keep,
	string $preserve_storage_key
): void {
	$objects = pnk_yandex_storage_list_versions( $user_id, $presentation_id );
	if ( is_wp_error( $objects ) ) {
		pnk_log( 'storage.cloud_cleanup_failed', array( 'pid' => $presentation_id ), 'warning' );
		return;
	}
	$preserve = pnk_yandex_storage_parse_key( $preserve_storage_key );
	usort(
		$objects,
		static fn( array $left, array $right ): int => $right['modified'] <=> $left['modified']
	);
	$kept = 0;
	foreach ( $objects as $object ) {
		if ( null !== $preserve && $object['key'] === $preserve['object_name'] ) {
			continue;
		}
		++$kept;
		if ( $kept >= max( 1, $keep ) ) {
			pnk_yandex_storage_delete_object( $object['key'] );
		}
	}
}

/**
 * Test bucket connectivity and public ACL exposure without returning secrets.
 *
 * @return array{configured:bool,connected:bool,private:bool,status:int}
 */
function pnk_yandex_storage_doctor(): array {
	$result = array(
		'configured' => pnk_yandex_storage_configured(),
		'connected'  => false,
		'private'    => false,
		'status'     => 0,
	);
	if ( ! $result['configured'] ) {
		return $result;
	}
	$head = pnk_yandex_storage_request( 'HEAD' );
	if ( is_wp_error( $head ) ) {
		return $result;
	}
	$result['status']    = $head['status'];
	$result['connected'] = 200 === $head['status'];
	$acl                 = pnk_yandex_storage_request( 'GET', '', array( 'acl' => '' ) );
	if ( is_wp_error( $acl ) || 200 !== $acl['status'] ) {
		return $result;
	}
	$result['private'] = ! str_contains( $acl['body'], 'AllUsers' )
		&& ! str_contains( $acl['body'], 'AuthenticatedUsers' );
	return $result;
}

/**
 * Migrate one local private archive using a DB compare-and-swap update.
 *
 * @return string|WP_Error
 */
function pnk_yandex_storage_migrate_one( int $presentation_id, int $user_id, string $local_key, bool $dry_run = false ) {
	$local_file = pnk_storage_file_path( $local_key );
	if ( ! $local_file || ! is_readable( $local_file ) ) {
		return new WP_Error( 'storage_migrate_source', 'Local presentation archive is unavailable.' );
	}
	if ( $dry_run ) {
		return 'dry-run';
	}
	$cloud_key = pnk_yandex_storage_store_file( $user_id, $presentation_id, $local_file );
	if ( is_wp_error( $cloud_key ) ) {
		return $cloud_key;
	}

	global $wpdb;
	$table = pnk_table_name();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted plugin setting.
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET path=%s, updated_at=%s WHERE presentationID=%d AND userid=%d AND path=%s AND status=%s",
			$cloud_key,
			current_time( 'mysql' ),
			$presentation_id,
			$user_id,
			$local_key,
			'done'
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( 1 !== $updated ) {
		$parsed = pnk_yandex_storage_parse_key( (string) $cloud_key );
		if ( null !== $parsed ) {
			pnk_yandex_storage_delete_object( $parsed['object_name'] );
		}
		return new WP_Error( 'storage_migrate_race', 'Presentation storage changed during migration.' );
	}

	@unlink( $local_file );
	return (string) $cloud_key;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'presentonika storage-cloud-migrate',
		static function ( array $args, array $assoc_args ): void {
			global $wpdb;
			$limit    = min( 500, max( 1, (int) ( $assoc_args['batch-size'] ?? 100 ) ) );
			$cursor   = max( 0, (int) ( $assoc_args['after-id'] ?? 0 ) );
			$dry_run  = array_key_exists( 'dry-run', $assoc_args );
			$failed   = 0;
			$migrated = 0;

			do {
				$table = pnk_table_name();
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted plugin setting.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT presentationID, userid, path FROM {$table}"
						. ' WHERE presentationID>%d AND status=%s AND path LIKE %s ORDER BY presentationID ASC LIMIT %d',
						$cursor,
						'done',
						'private:v1/%',
						$limit
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row_count = count( (array) $rows );
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Existing DB column name.
				foreach ( (array) $rows as $row ) {
					$cursor = (int) $row->presentationID;
					$result = pnk_yandex_storage_migrate_one(
						$cursor,
						(int) $row->userid,
						(string) $row->path,
						$dry_run
					);
					if ( is_wp_error( $result ) ) {
						$failed++;
						WP_CLI::warning( sprintf( 'Presentation #%d: %s.', $cursor, $result->get_error_code() ) );
					} else {
						$migrated++;
					}
				}
				// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				WP_CLI::log( sprintf( 'Cursor: %d; processed: %d; failed: %d.', $cursor, $migrated, $failed ) );
			} while ( $row_count === $limit );

			if ( $failed > 0 ) {
				WP_CLI::error( 'Cloud migration stopped with failed items. Re-run from the reported cursor after correction.' );
			}
			WP_CLI::success( $dry_run ? 'Cloud migration dry-run completed.' : 'Cloud migration completed.' );
		}
	);
}
