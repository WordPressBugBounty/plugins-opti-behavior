<?php
/**
 * MMDB reader (country lookup only)
 *
 * Small pure-PHP reader of the MaxMind DB file format (the format of the
 * DB-IP Lite country database). Written for this plugin, no third-party
 * code and no extension: another plugin can load its own MaxMind\Db\Reader
 * without any class conflict.
 *
 * Format: https://maxmind.github.io/MaxMind-DB/
 *
 * @package opti-behavior
 * @since   1.10.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Mmdb_Reader
 *
 * Every problem (missing, truncated or damaged file) throws an Exception:
 * the caller (Opti_Behavior_Geo_Country) catches it, a bad file is never a
 * fatal error.
 *
 * @since 1.10.0.1
 */
class Opti_Behavior_Mmdb_Reader {

	/** Bytes that open the metadata block, at the end of the file. */
	const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

	/** The metadata block is searched in the last bytes of the file only. */
	const METADATA_MAX_SIZE = 131072;

	/** Nested maps / pointers deeper than this = damaged file. */
	const MAX_DEPTH = 32;

	/** @var resource|null Open file handle. */
	private $handle = null;

	/** @var int File size in bytes. */
	private $size = 0;

	/** @var int Number of nodes of the search tree. */
	private $node_count = 0;

	/** @var int Bits of one record (24, 28 or 32). */
	private $record_size = 0;

	/** @var int 4 or 6. */
	private $ip_version = 0;

	/** @var int Size of the search tree in bytes. */
	private $tree_size = 0;

	/** @var int File offset of the data section. */
	private $data_start = 0;

	/** @var int|null Node where the IPv4 addresses start in an IPv6 tree. */
	private $ipv4_start = null;

	/** @var array Decoded metadata. */
	private $metadata = array();

	/**
	 * Open a database file.
	 *
	 * @param string $path Absolute path of the .mmdb file.
	 * @throws Exception When the file cannot be read or is not a valid database.
	 */
	public function __construct( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new Exception( 'MMDB file not readable' );
		}

		$size = filesize( $path );
		if ( false === $size || $size < 64 ) {
			throw new Exception( 'MMDB file too small' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only random access into a local binary database; WP_Filesystem has no seek.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new Exception( 'MMDB file cannot be opened' );
		}
		$this->handle = $handle;
		$this->size   = (int) $size;

		try {
			$this->read_metadata();
		} catch ( Exception $e ) {
			$this->close();
			throw $e;
		}
	}

	/**
	 * Close the file.
	 *
	 * @return void
	 */
	public function close() {
		if ( is_resource( $this->handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Handle opened above.
			fclose( $this->handle );
		}
		$this->handle = null;
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Metadata of the database (build_epoch, database_type, ...).
	 *
	 * @return array
	 */
	public function metadata() {
		return $this->metadata;
	}

	/**
	 * ISO 3166-1 alpha-2 country code of an IP address.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return string Two upper-case letters, or '' when the address is not in the database.
	 * @throws Exception When the file is damaged.
	 */
	public function country( $ip ) {
		$record = $this->get( $ip );
		if ( ! is_array( $record ) || ! isset( $record['country'] ) || ! is_array( $record['country'] ) || ! isset( $record['country']['iso_code'] ) ) {
			return '';
		}

		$code = strtoupper( (string) $record['country']['iso_code'] );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) || 'ZZ' === $code || 'XX' === $code ) {
			return '';
		}

		return $code;
	}

	/**
	 * Record stored for an IP address.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return mixed|null Decoded record, null when the address is not in the database.
	 * @throws Exception When the file is damaged.
	 */
	public function get( $ip ) {
		if ( ! is_resource( $this->handle ) ) {
			throw new Exception( 'MMDB reader is closed' );
		}
		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() warns on an address it cannot pack; validated above, belt and braces.
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}

		$length = strlen( $packed );
		if ( 4 === $length ) {
			$node = ( 6 === $this->ip_version ) ? $this->ipv4_start_node() : 0;
		} elseif ( 16 === $length && 6 === $this->ip_version ) {
			$node = 0;
		} else {
			return null;
		}

		$bits = $length * 8;
		for ( $i = 0; $i < $bits && $node < $this->node_count; $i++ ) {
			$bit  = ( ord( $packed[ $i >> 3 ] ) >> ( 7 - ( $i & 7 ) ) ) & 1;
			$node = $this->read_node( $node, $bit );
		}

		// node_count = "no data for this address"; lower = the address ended inside the tree.
		if ( $node <= $this->node_count ) {
			return null;
		}

		$offset = $node - $this->node_count + $this->tree_size;
		if ( $offset >= $this->size ) {
			throw new Exception( 'MMDB record points outside the file' );
		}

		$decoded = $this->decode( $offset, $this->data_start, 0 );
		return $decoded[0];
	}

	/**
	 * Find and decode the metadata block.
	 *
	 * @return void
	 * @throws Exception When the block is missing or incomplete.
	 */
	private function read_metadata() {
		$tail_size = min( $this->size, self::METADATA_MAX_SIZE );
		$tail      = $this->read_at( $this->size - $tail_size, $tail_size );
		$position  = strrpos( $tail, self::METADATA_MARKER );
		if ( false === $position ) {
			throw new Exception( 'MMDB metadata not found' );
		}

		$start    = $this->size - $tail_size + $position + strlen( self::METADATA_MARKER );
		$decoded  = $this->decode( $start, $start, 0 );
		$metadata = $decoded[0];
		if ( ! is_array( $metadata ) || ! isset( $metadata['node_count'], $metadata['record_size'], $metadata['ip_version'] ) ) {
			throw new Exception( 'MMDB metadata incomplete' );
		}

		$this->metadata    = $metadata;
		$this->node_count  = (int) $metadata['node_count'];
		$this->record_size = (int) $metadata['record_size'];
		$this->ip_version  = (int) $metadata['ip_version'];

		if ( $this->node_count < 1 || ! in_array( $this->record_size, array( 24, 28, 32 ), true ) || ! in_array( $this->ip_version, array( 4, 6 ), true ) ) {
			throw new Exception( 'MMDB metadata not supported' );
		}

		$this->tree_size  = (int) ( $this->node_count * $this->record_size / 4 );
		$this->data_start = $this->tree_size + 16;
		if ( $this->data_start >= $this->size ) {
			throw new Exception( 'MMDB search tree larger than the file' );
		}
	}

	/**
	 * Node reached after the 96 zero bits that prefix an IPv4 address in an IPv6 tree.
	 *
	 * @return int
	 * @throws Exception When the file is damaged.
	 */
	private function ipv4_start_node() {
		if ( null === $this->ipv4_start ) {
			$node = 0;
			for ( $i = 0; $i < 96 && $node < $this->node_count; $i++ ) {
				$node = $this->read_node( $node, 0 );
			}
			$this->ipv4_start = $node;
		}

		return $this->ipv4_start;
	}

	/**
	 * Read one record (left = 0, right = 1) of a node of the search tree.
	 *
	 * @param int $node Node number.
	 * @param int $bit  0 or 1.
	 * @return int
	 * @throws Exception When the file is damaged.
	 */
	private function read_node( $node, $bit ) {
		switch ( $this->record_size ) {
			case 24:
				$bytes = $this->read_at( $node * 6 + $bit * 3, 3 );
				return $this->to_int( $bytes );

			case 28:
				$bytes  = $this->read_at( $node * 7, 7 );
				$middle = ord( $bytes[3] );
				if ( 0 === $bit ) {
					return ( ( ( $middle & 0xF0 ) >> 4 ) << 24 ) | $this->to_int( substr( $bytes, 0, 3 ) );
				}
				return ( ( $middle & 0x0F ) << 24 ) | $this->to_int( substr( $bytes, 4, 3 ) );

			default: // 32.
				$bytes = $this->read_at( $node * 8 + $bit * 4, 4 );
				return $this->to_int( $bytes );
		}
	}

	/**
	 * Decode the value stored at a file offset.
	 *
	 * @param int $offset       File offset of the control byte.
	 * @param int $pointer_base File offset the pointers are relative to.
	 * @param int $depth        Nesting level.
	 * @return array array( value, offset after the value ).
	 * @throws Exception When the file is damaged.
	 */
	private function decode( $offset, $pointer_base, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			throw new Exception( 'MMDB data nested too deep' );
		}

		$control = ord( $this->read_at( $offset, 1 ) );
		$offset++;
		$type = $control >> 5;

		// Pointer: the value lives elsewhere, reading goes on after the pointer.
		if ( 1 === $type ) {
			$pointer_size = ( $control >> 3 ) & 0x3;
			$bytes        = $this->read_at( $offset, $pointer_size + 1 );
			$offset      += $pointer_size + 1;
			$high         = $control & 0x7;

			if ( 0 === $pointer_size ) {
				$pointer = ( $high << 8 ) | $this->to_int( $bytes );
			} elseif ( 1 === $pointer_size ) {
				$pointer = 2048 + ( ( $high << 16 ) | $this->to_int( $bytes ) );
			} elseif ( 2 === $pointer_size ) {
				$pointer = 526336 + ( ( $high << 24 ) | $this->to_int( $bytes ) );
			} else {
				$pointer = $this->to_int( $bytes );
			}

			$target = $this->decode( $pointer_base + $pointer, $pointer_base, $depth + 1 );
			return array( $target[0], $offset );
		}

		// Extended type: the real type is in the next byte.
		if ( 0 === $type ) {
			$type = 7 + ord( $this->read_at( $offset, 1 ) );
			$offset++;
		}

		$size = $control & 0x1F;
		if ( $size >= 29 ) {
			$extra   = $size - 28;
			$bytes   = $this->read_at( $offset, $extra );
			$offset += $extra;
			if ( 29 === $size ) {
				$size = 29 + $this->to_int( $bytes );
			} elseif ( 30 === $size ) {
				$size = 285 + $this->to_int( $bytes );
			} else {
				$size = 65821 + $this->to_int( $bytes );
			}
		}

		switch ( $type ) {
			case 2: // UTF-8 string.
			case 4: // Bytes.
				$value = $size > 0 ? $this->read_at( $offset, $size ) : '';
				return array( $value, $offset + $size );

			case 3: // Double.
				if ( 8 !== $size ) {
					throw new Exception( 'MMDB double of a wrong size' );
				}
				$parts = unpack( 'E', $this->read_at( $offset, 8 ) );
				return array( $parts[1], $offset + 8 );

			case 15: // Float.
				if ( 4 !== $size ) {
					throw new Exception( 'MMDB float of a wrong size' );
				}
				$parts = unpack( 'G', $this->read_at( $offset, 4 ) );
				return array( $parts[1], $offset + 4 );

			case 5: // Unsigned 16 bits.
			case 6: // Unsigned 32 bits.
			case 9: // Unsigned 64 bits.
			case 10: // Unsigned 128 bits.
				if ( $size > 16 ) {
					throw new Exception( 'MMDB integer too long' );
				}
				$value = $size > 0 ? $this->to_int( $this->read_at( $offset, $size ) ) : 0;
				return array( $value, $offset + $size );

			case 8: // Signed 32 bits.
				if ( $size > 4 ) {
					throw new Exception( 'MMDB integer too long' );
				}
				$value = $size > 0 ? $this->to_int( $this->read_at( $offset, $size ) ) : 0;
				if ( 4 === $size && $value >= 2147483648 ) {
					$value -= 4294967296;
				}
				return array( $value, $offset + $size );

			case 7: // Map.
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					$key    = $this->decode( $offset, $pointer_base, $depth + 1 );
					$offset = $key[1];
					$item   = $this->decode( $offset, $pointer_base, $depth + 1 );
					$offset = $item[1];
					if ( ! is_string( $key[0] ) ) {
						throw new Exception( 'MMDB map key is not a string' );
					}
					$map[ $key[0] ] = $item[0];
				}
				return array( $map, $offset );

			case 11: // Array.
				$list = array();
				for ( $i = 0; $i < $size; $i++ ) {
					$item   = $this->decode( $offset, $pointer_base, $depth + 1 );
					$offset = $item[1];
					$list[] = $item[0];
				}
				return array( $list, $offset );

			case 14: // Boolean: the size field is the value.
				return array( 0 !== $size, $offset );

			default:
				throw new Exception( 'MMDB data type not supported' );
		}
	}

	/**
	 * Big-endian bytes to an integer (a float above PHP_INT_MAX).
	 *
	 * @param string $bytes Raw bytes.
	 * @return int|float
	 */
	private function to_int( $bytes ) {
		$length = strlen( $bytes );
		$value  = 0;
		for ( $i = 0; $i < $length; $i++ ) {
			$value = ( $length > 7 ) ? ( $value * 256 + ord( $bytes[ $i ] ) ) : ( ( $value << 8 ) | ord( $bytes[ $i ] ) );
		}

		return $value;
	}

	/**
	 * Read bytes at a file offset.
	 *
	 * @param int $offset File offset.
	 * @param int $length Number of bytes.
	 * @return string
	 * @throws Exception When the bytes are outside the file.
	 */
	private function read_at( $offset, $length ) {
		if ( $offset < 0 || $length < 1 || $offset + $length > $this->size ) {
			throw new Exception( 'MMDB read outside the file' );
		}
		if ( 0 !== fseek( $this->handle, $offset ) ) {
			throw new Exception( 'MMDB seek failed' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Random access into a local binary database.
		$bytes = fread( $this->handle, $length );
		if ( false === $bytes || strlen( $bytes ) !== $length ) {
			throw new Exception( 'MMDB read failed' );
		}

		return $bytes;
	}
}
