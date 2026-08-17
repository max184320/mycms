<?php

namespace IP2Location;

/**
 * Minimal DB reader used by DLECountry.
 */
class Database
{
	private const MAX_IPV4_RANGE = 4294967295;
	private const ROW_VALUE_OFFSET = 8;
	private const ASN_DATABASE_MARKER = 'DLE-ASN-DAT1';

	private $resource = false;
	private $asnDatabase = false;
	private $columnWidth4 = 0;
	private $columnWidth6 = 0;
	private $ipCount4 = 0;
	private $ipCount6 = 0;
	private $ipBase4 = 0;
	private $ipBase6 = 0;
	private $indexBaseAddr4 = 0;
	private $indexBaseAddr6 = 0;

	/**
	 * Constructor.
	 *
	 * @param string $file Filename of the BIN database to load
	 *
	 * @throws \Exception
	 */
	public function __construct($file)
	{
		$realPath = realpath($file);

		if ($realPath === false) {
			throw new \Exception(__CLASS__ . ": Database file '{$file}' does not seem to exist.");
		}

		$this->resource = @fopen($realPath, 'rb');

		if ($this->resource === false) {
			throw new \Exception(__CLASS__ . ": Unable to open file '{$realPath}'.");
		}

		$headers = $this->read(0, 512);

		if (strlen($headers) < 32) {
			throw new \Exception(__CLASS__ . ': Incorrect IP2Location BIN file format.');
		}

		$header = unpack(
			'Ctype/Ccolumns/Cyear/Cmonth/Cday/VipCount4/VipBase4/VipCount6/VipBase6/VindexBaseAddr4/VindexBaseAddr6/CproductCode',
			$headers
		);

		if (!$header || (int) $header['productCode'] === 0) {
			throw new \Exception(__CLASS__ . ': Incorrect IP2Location BIN file format.');
		}

		$this->asnDatabase = substr($headers, 32, strlen(self::ASN_DATABASE_MARKER)) === self::ASN_DATABASE_MARKER;
		$this->columnWidth4 = (int) $header['columns'] * 4;
		$this->columnWidth6 = $this->columnWidth4 + 12;
		$this->ipCount4 = (int) $header['ipCount4'];
		$this->ipCount6 = (int) $header['ipCount6'];
		$this->ipBase4 = (int) $header['ipBase4'];
		$this->ipBase6 = (int) $header['ipBase6'];
		$this->indexBaseAddr4 = (int) $header['indexBaseAddr4'];
		$this->indexBaseAddr6 = (int) $header['indexBaseAddr6'];
	}

	/**
	 * Destructor.
	 */
	public function __destruct()
	{
		if ($this->resource !== false) {
			fclose($this->resource);
			$this->resource = false;
		}
	}

	/**
	 * Look up country name and ISO code by IPv4 or IPv6 address.
	 *
	 * @param string $ip
	 *
	 * @return array|bool
	 */
	public function lookup($ip)
	{
		list($ipVersion, $ipNumber) = $this->ipVersionAndNumber($ip);

		if (!$ipVersion) {
			return false;
		}

		$pointer = $this->binSearch($ipVersion, $ipNumber);

		if ($pointer === false) {
			return false;
		}

		$result = $this->readCountryNameAndCode($pointer);

		if ($result === false) {
			return false;
		}

		return [
			'countryName' => $result[0],
			'countryCode' => $result[1],
		];
	}

	/**
	 * Check whether an IP address belongs to an ASN stored in a DLE ASN DAT database.
	 *
	 * @param string $ip
	 *
	 * @return bool
	 */
	public function contains_asn($ip)
	{
		if (!$this->asnDatabase) {
			return false;
		}

		list($ipVersion, $ipNumber) = $this->ipVersionAndNumber($ip);

		if (!$ipVersion) {
			return false;
		}

		$pointer = $this->binSearch($ipVersion, $ipNumber);

		if ($pointer === false) {
			return false;
		}

		return $this->readUint32($pointer + self::ROW_VALUE_OFFSET - 1) > 0;
	}

	/**
	 * Get IP version and normalized search value.
	 *
	 * @param string $ip
	 *
	 * @return array
	 */
	private function ipVersionAndNumber($ip)
	{
		if (!is_string($ip) || $ip === '') {
			return [false, false];
		}

		$packed = @inet_pton($ip);

		if ($packed === false) {
			return [false, false];
		}

		$length = strlen($packed);

		if ($length === 4) {
			return [4, $this->normalizeIpv4($this->unpackUint32N($packed))];
		}

		if ($length !== 16) {
			return [false, false];
		}

		if (substr($packed, 0, 2) === "\x20\x02") {
			return [4, $this->normalizeIpv4($this->unpackUint32N(substr($packed, 2, 4)))];
		}

		if (substr($packed, 0, 4) === "\x20\x01\x00\x00" && substr($packed, 12, 4) !== "\x00\x00\x00\x00") {
			return [4, $this->normalizeIpv4($this->unpackUint32N(~substr($packed, 12, 4)))];
		}

		if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
			return [4, $this->normalizeIpv4($this->unpackUint32N(substr($packed, 12, 4)))];
		}

		return [6, $packed];
	}

	/**
	 * Perform a binary search and return pointer to the matched data row.
	 *
	 * @param int        $version
	 * @param int|string $ipNumber
	 *
	 * @return bool|int
	 */
	private function binSearch($version, $ipNumber)
	{
		if ($version === 4) {
			$base = $this->ipBase4;
			$width = $this->columnWidth4;
			$high = $this->ipCount4;
			$indexBaseStart = $this->indexBaseAddr4;
			$rowOffset = -4;
		} else {
			$base = $this->ipBase6;
			$width = $this->columnWidth6;
			$high = $this->ipCount6;
			$indexBaseStart = $this->indexBaseAddr6;
			$rowOffset = 8;
		}

		if ($base < 1 || $width < 1 || $high < 1) {
			return false;
		}

		$low = 0;

		if ($indexBaseStart > 1) {
			if ($version === 4) {
				$index = (int) ($ipNumber / 65536);
			} else {
				$index = unpack('n', substr($ipNumber, 0, 2))[1];
			}

			$section = $this->read($indexBaseStart + ($index << 3) - 1, 8);

			if (strlen($section) === 8) {
				$low = $this->unpackUint32V($section, 0);
				$high = $this->unpackUint32V($section, 4);
			}
		}

		while ($low <= $high) {
			$mid = (int) ($low + (($high - $low) >> 1));
			$position = $base + $width * $mid - 1;
			$section = $this->read($position, $width + ($version === 4 ? 4 : 16));

			if ($version === 4) {
				if (strlen($section) < $width + 4) {
					return false;
				}

				$ipStart = $this->unpackUint32V($section, 0);
				$ipEnd = $this->unpackUint32V($section, $width);

				if ($ipNumber >= $ipStart) {
					if ($ipNumber < $ipEnd) {
						return $base + $rowOffset + $mid * $width;
					}

					$low = $mid + 1;
				} else {
					$high = $mid - 1;
				}
			} else {
				if (strlen($section) < $width + 16) {
					return false;
				}

				$ipStart = strrev(substr($section, 0, 16));
				$ipEnd = strrev(substr($section, $width, 16));

				if (strcmp($ipNumber, $ipStart) >= 0) {
					if (strcmp($ipNumber, $ipEnd) < 0) {
						return $base + $rowOffset + $mid * $width;
					}

					$low = $mid + 1;
				} else {
					$high = $mid - 1;
				}
			}
		}

		return false;
	}

	/**
	 * Read country name and code from the matched row.
	 *
	 * @param int $pointer
	 *
	 * @return array|bool
	 */
	private function readCountryNameAndCode($pointer)
	{
		$countryPointer = $this->readUint32($pointer + self::ROW_VALUE_OFFSET - 1);

		if ($countryPointer < 1) {
			return false;
		}

		$head = $this->read($countryPointer, 4);

		if (strlen($head) < 4) {
			return false;
		}

		$countryCodeLength = ord($head[0]);
		$countryCode = substr($head, 1, $countryCodeLength);
		$countryNameLengthOffset = $countryPointer + 3;
		$countryNameLength = ord($head[3]);
		$countryName = $this->read($countryNameLengthOffset + 1, $countryNameLength);

		return [$countryName, $countryCode];
	}

	/**
	 * Read unsigned 32-bit little-endian integer from the database file.
	 *
	 * @param int $position
	 *
	 * @return int
	 */
	private function readUint32($position)
	{
		$data = $this->read($position, 4);

		if (strlen($data) !== 4) {
			return 0;
		}

		return $this->unpackUint32V($data, 0);
	}

	/**
	 * Low level file read.
	 *
	 * @param int $position
	 * @param int $length
	 *
	 * @return string
	 */
	private function read($position, $length)
	{
		if ($this->resource === false || fseek($this->resource, $position, SEEK_SET) !== 0) {
			return '';
		}

		$data = fread($this->resource, $length);

		return is_string($data) ? $data : '';
	}

	/**
	 * Unpack unsigned 32-bit little-endian integer.
	 *
	 * @param string $data
	 * @param int    $offset
	 *
	 * @return int
	 */
	private function unpackUint32V($data, $offset)
	{
		$value = unpack('V', substr($data, $offset, 4))[1];

		return $value < 0 ? $value + 4294967296 : $value;
	}

	/**
	 * Unpack unsigned 32-bit big-endian integer.
	 *
	 * @param string $data
	 *
	 * @return int
	 */
	private function unpackUint32N($data)
	{
		$value = unpack('N', $data)[1];

		return $value < 0 ? $value + 4294967296 : $value;
	}

	/**
	 * Match the old library behaviour for the top IPv4 boundary.
	 *
	 * @param int $number
	 *
	 * @return int
	 */
	private function normalizeIpv4($number)
	{
		return ($number == self::MAX_IPV4_RANGE) ? self::MAX_IPV4_RANGE - 1 : $number;
	}
}
