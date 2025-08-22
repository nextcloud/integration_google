<?php

namespace OCA\Google\Service\Utils;

use OCP\Files\EmptyFileNameException;
use OCP\Files\FileNameTooLongException;
use OCP\Files\InvalidCharacterInPathException;
use OCP\Files\InvalidDirectoryException;
use OCP\Files\ReservedWordException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

final class FileUtils {

	public function __construct(
		private \OCP\Files\IFilenameValidator $validator,
		private \OCP\IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Sanitize the filename to ensure it is valid, does not exceed length limits.
	 *
	 * @param string $filename The original filename to sanitize.
	 * @param string $id A unique ID to append if necessary to ensure uniqueness.
	 * @param int $recursionDepth The current recursion depth (used to prevent infinite loops).
	 * @param string|null $originalFilename The original filename for logging.
	 * @return string The sanitized and validated filename.
	 */
	public function sanitizeFilename(
		string $filename,
		string $id,
		int $recursionDepth = 0,
		?string $originalFilename = null,
	): string {
		if ($recursionDepth > 15) {
			$filename = 'Untitled_' . $id;
			$this->logger->warning('Maximum recursion depth reached while sanitizing filename: ' . ($originalFilename ?? $filename) . ' renaming to ' . $filename);
			return $filename;
		}

		if ($originalFilename === null) {
			$originalFilename = $filename;
		}

		// Use Nextcloud 32+ validator if available
		if (version_compare($this->config->getSystemValueString('version', '0.0.0'), '32.0.0', '>=')) {
			$this->logger->debug('Using Nextcloud 32+ filename validator for sanitization.');
			try {
				return $this->validator->sanitizeFilename($filename);
			} catch (\InvalidArgumentException|NotFoundExceptionInterface|ContainerExceptionInterface $exception) {
				$this->logger->error('Unable to sanitize filename: ' . $filename, ['exception' => $exception]);
				return 'Untitled_' . $id;
			}
		} else {
			$this->logger->debug('Using legacy filename sanitization method.');
		}

		// Trim whitespace and trailing dots
		$filename = rtrim(trim($filename), '.');

		// Append ID if needed
		if ($originalFilename !== $filename && strpos($filename, $id) === false) {
			$filename = self::appendIdBeforeExtension($filename, $id);
		}

		// Enforce max length
		$maxLength = 254;
		if (mb_strlen($filename) > $maxLength) {
			$filename = self::truncateAndAppendId($filename, $id, $maxLength);
		}

		try {
			$this->validator->validateFilename($filename);
			if ($recursionDepth > 0) {
				$this->logger->info('Filename sanitized successfully: "' . $filename . '" (original: "' . $originalFilename . '")');
			}
			return $filename;
		} catch (\Throwable $exception) {
			$this->logger->warning('Exception during filename validation: ' . $filename, ['exception' => $exception]);
			$filename = self::handleFilenameException($filename, $id, $exception, $this->logger);
			if (strpos($filename, $id) === false) {
				$filename = self::appendIdBeforeExtension($filename, $id);
			}
			return $this->sanitizeFilename($filename, $id, $recursionDepth + 1, $originalFilename);
		}
	}

	private static function appendIdBeforeExtension(string $filename, string $id): string {
		$pathInfo = pathinfo($filename);
		if (isset($pathInfo['extension'])) {
			return $pathInfo['filename'] . '_' . $id . '.' . $pathInfo['extension'];
		}
		return $filename . '_' . $id;
	}

	private static function truncateAndAppendId(string $filename, string $id, int $maxLength): string {
		$pathInfo = pathinfo($filename);
		$baseLength = $maxLength - mb_strlen($id) - 2;
		if (isset($pathInfo['extension'])) {
			$baseLength -= mb_strlen($pathInfo['extension']);
			return mb_substr($pathInfo['filename'], 0, $baseLength) . '_' . $id . '.' . $pathInfo['extension'];
		}
		return mb_substr($filename, 0, $baseLength) . '_' . $id;
	}

	private static function handleFilenameException(string $filename, string $id, \Throwable $exception, LoggerInterface $logger): string {
		if ($exception instanceof FileNameTooLongException) {
			return mb_substr($filename, 0, 254 - mb_strlen($id) - 2);
		}
		if ($exception instanceof EmptyFileNameException) {
			return 'Untitled';
		}
		if ($exception instanceof InvalidCharacterInPathException) {
			if (preg_match('/"(.*?)"/', $exception->getMessage(), $matches)) {
				$invalidChars = array_merge(str_split($matches[1]), ['"']);
				return str_replace($invalidChars, '-', $filename);
			}
		}
		if ($exception instanceof InvalidDirectoryException) {
			$logger->error('Invalid directory detected in filename: ' . $exception->getMessage());
			return 'Untitled';
		}
		if ($exception instanceof ReservedWordException) {
			if (preg_match('/"(.*?)"/', $exception->getMessage(), $matches)) {
				$reservedWord = $matches[1];
				return str_ireplace($reservedWord, '-' . $reservedWord . '-', $filename);
			}
		}
		$logger->error('Unknown exception encountered during filename sanitization: ' . $filename);
		return 'Untitled';
	}
}
