<?php
class Op5Captcha {
	const SECRET_KEY = 'DF354JS';
	const DEFAULT_LENGTH = 6;

	protected $registry;
	protected $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	protected $legacy_alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	public function __construct($registry = null) {
		$this->registry = $registry;
	}

	public function createChallenge($length = self::DEFAULT_LENGTH, $code = '') {
		$code = $this->sanitizeCustomCode($code);

		if ($code === '') {
			$length = $this->sanitizeLength($length);
			$code = $this->generateRandomString($length);
		}

		$offset = mt_rand(1, 9);
		$shifted = $this->applyOffset($code, $offset);
		$timestamp = time();
		$payload = json_encode(array(
			'code64'  => base64_encode($code),
			'shifted' => $shifted,
			'offset'  => $offset,
			'time'    => $timestamp
		));

		$cipher = $this->encrypt($payload);
		$image_binary = $this->buildImageBinary($code);

		return array(
			'code' => $code,
			'shifted' => $shifted,
			'offset' => $offset,
			'timestamp' => $timestamp,
			'cipher' => $cipher,
			'image_binary' => $image_binary,
			'image_mime' => 'image/png',
			'image_base64' => $image_binary !== false ? 'data:image/png;base64,' . base64_encode($image_binary) : ''
		);
	}

	public function decryptChallenge($cipher) {
		$decrypted = $this->decrypt($cipher);

		if ($decrypted === false || $decrypted === '') {
			return false;
		}

		$data = json_decode($decrypted, true);

		if (!is_array($data)) {
			return false;
		}

		$offset = isset($data['offset']) ? (int)$data['offset'] : 0;
		$shifted = isset($data['shifted']) ? (string)$data['shifted'] : '';

		if (isset($data['code64'])) {
			$code = base64_decode((string)$data['code64'], true);

			if ($code === false) {
				return false;
			}
		} elseif (isset($data['code'])) {
			$code = (string)$data['code'];
		} elseif (isset($data['shifted']) && isset($data['offset'])) {
			$shifted = $this->normalizeCode($data['shifted']);
			$code = $this->reverseOffset($shifted, $offset, $this->legacy_alphabet);
		} else {
			return false;
		}

		if ($code === '') {
			return false;
		}

		return array(
			'code' => $code,
			'shifted' => $shifted,
			'offset' => $offset,
			'timestamp' => isset($data['time']) ? (int)$data['time'] : 0
		);
	}

	public function encrypt($value) {
		if (!function_exists('openssl_encrypt')) {
			return false;
		}

		$encrypted = openssl_encrypt($value, 'AES-128-CBC', $this->getCipherKey(), 0, $this->getCipherIv());

		if ($encrypted === false) {
			return false;
		}

		return strtr($encrypted, '+/=', '-_,');
	}

	public function decrypt($value) {
		if (!function_exists('openssl_decrypt')) {
			return false;
		}

		$value = $this->normalizeCipherValue($value);
		$value = strtr($value, '-_,', '+/=');
		$decrypted = openssl_decrypt($value, 'AES-128-CBC', $this->getCipherKey(), 0, $this->getCipherIv());

		if ($decrypted === false) {
			return false;
		}

		return $decrypted;
	}

	protected function normalizeCipherValue($value) {
		$value = trim((string)$value);
		$value = stripcslashes($value);

		if (strlen($value) >= 2) {
			$first = $value[0];
			$last = $value[strlen($value) - 1];

			if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
				$value = substr($value, 1, -1);
			}
		}

		return $value;
	}

	public function normalizeCode($value) {
		return trim((string)$value);
	}

	protected function sanitizeCustomCode($value) {
		return (string)$value;
	}

	protected function sanitizeLength($length) {
		$length = (int)$length;

		if ($length < 1) {
			$length = self::DEFAULT_LENGTH;
		}

		return $length;
	}

	protected function generateRandomString($length) {
		$string = '';
		$max_index = strlen($this->alphabet) - 1;

		while (strlen($string) < $length) {
			$string .= $this->alphabet[mt_rand(0, $max_index)];
		}

		return $string;
	}

	protected function applyOffset($value, $offset, $alphabet = null) {
		$result = '';
		$alphabet = $alphabet === null ? $this->getTransformAlphabet() : (string)$alphabet;
		$alphabet_length = strlen($alphabet);
		$value = (string)$value;

		for ($index = 0; $index < strlen($value); $index++) {
			$character = $value[$index];
			$position = strpos($alphabet, $character);

			if ($position === false) {
				$result .= $character;
			} else {
				$result .= $alphabet[($position + $offset) % $alphabet_length];
			}
		}

		return $result;
	}

	protected function reverseOffset($value, $offset, $alphabet = null) {
		$result = '';
		$alphabet = $alphabet === null ? $this->getTransformAlphabet() : (string)$alphabet;
		$alphabet_length = strlen($alphabet);
		$value = (string)$value;
		$offset = (int)$offset % $alphabet_length;

		for ($index = 0; $index < strlen($value); $index++) {
			$character = $value[$index];
			$position = strpos($alphabet, $character);

			if ($position === false) {
				$result .= $character;
			} else {
				$new_position = $position - $offset;

				while ($new_position < 0) {
					$new_position += $alphabet_length;
				}

				$result .= $alphabet[$new_position];
			}
		}

		return $result;
	}

	protected function getTransformAlphabet() {
		$alphabet = '';

		for ($character = 32; $character <= 126; $character++) {
			$alphabet .= chr($character);
		}

		return $alphabet;
	}

	protected function buildImageBinary($code) {
		if (!function_exists('imagecreatetruecolor')) {
			return false;
		}

		$font = 5;
		$text_width = imagefontwidth($font) * strlen($code);
		$width = max(160, $text_width + 40);
		$height = 46;
		$image = imagecreatetruecolor($width, $height);

		if (!$image) {
			return false;
		}

		$white = imagecolorallocate($image, 255, 255, 255);
		$black = imagecolorallocate($image, 0, 0, 0);
		$gray = imagecolorallocate($image, 150, 150, 150);
		$red = imagecolorallocatealpha($image, 255, 80, 80, 80);
		$green = imagecolorallocatealpha($image, 80, 200, 120, 80);
		$blue = imagecolorallocatealpha($image, 80, 120, 255, 80);

		imagefilledrectangle($image, 0, 0, $width, $height, $white);

		for ($index = 0; $index < 10; $index++) {
			imageline(
				$image,
				mt_rand(0, $width),
				mt_rand(0, $height),
				mt_rand(0, $width),
				mt_rand(0, $height),
				$gray
			);
		}

		imagefilledellipse($image, mt_rand(20, 140), mt_rand(8, 38), 30, 30, $red);
		imagefilledellipse($image, mt_rand(20, 140), mt_rand(8, 38), 26, 26, $green);
		imagefilledellipse($image, mt_rand(20, 140), mt_rand(8, 38), 24, 24, $blue);

		$text_x = (int)(($width - $text_width) / 2);
		$text_y = (int)(($height - imagefontheight($font)) / 2);

		imagestring($image, $font, $text_x, $text_y, $code, $black);
		imagerectangle($image, 0, 0, $width - 1, $height - 1, $black);

		ob_start();
		imagepng($image);
		$binary = ob_get_clean();

		imagedestroy($image);

		return $binary;
	}

	protected function getCipherKey() {
		return substr(md5(self::SECRET_KEY), 0, 16);
	}

	protected function getCipherIv() {
		return substr(sha1(self::SECRET_KEY), 0, 16);
	}
}
